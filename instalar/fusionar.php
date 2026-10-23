<?php
declare(strict_types=1);

/*
 * Crea la base unida de la intranet a partir de las bases de tickets y de asistencia.
 * Las bases originales solo se leen: nunca se modifican.
 *
 *   C:\xampp\php\php.exe instalar\fusionar.php              crea la base (falla si ya tiene tablas)
 *   C:\xampp\php\php.exe instalar\fusionar.php --rehacer    la borra y la vuelve a crear desde las originales
 *
 * Opciones: --tickets=tickets --asistencia=asistencia --destino=intranet
 * La conexión (host, puerto, usuario y contraseña) se lee del .env de tickets (TICKETS_DB_*).
 *
 * Qué hace:
 *  1. Copia todas las tablas de tickets tal cual (mismos ids: tickets, evidencias y bitácora no cambian).
 *  2. A `usuarios` le agrega los datos de asistencia: rol_asistencia, gerente_id, representante,
 *     fecha_ingreso, creado_en y eliminado_en (baja solo en asistencia).
 *  3. Une a las personas por correo. Las que solo existían en asistencia se agregan como empleados de tickets.
 *  4. Copia las tablas de asistencia cambiando sus ids de usuario por los de la tabla unida.
 *  5. Crea la vista `asistencia_usuarios`, con los nombres de columna que usa el código de asistencia.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../tickets/php/env.php';

$options = getopt('', ['rehacer', 'tickets::', 'asistencia::', 'destino::']);
$source = ['tickets' => $options['tickets'] ?? 'tickets', 'asistencia' => $options['asistencia'] ?? 'asistencia'];
$target = $options['destino'] ?? 'intranet';
foreach ([...array_values($source), $target] as $name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) exit("Nombre de base no válido: $name\n");
}
if (in_array($target, $source, true)) exit("La base destino no puede ser una de las originales.\n");

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', env('TICKETS_DB_HOST', 'localhost'), env('TICKETS_DB_PORT', '3306')),
    env('TICKETS_DB_USER', 'root'),
    env('TICKETS_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);

$tables = fn(string $schema): array => $db->query("SHOW FULL TABLES FROM `$schema` WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$count = fn(string $schema, string $table): int => (int)$db->query("SELECT COUNT(*) FROM `$schema`.`$table`")->fetchColumn();
$step = function (string $text): void { echo "· $text\n"; };

// ---------- 0. Base destino ----------
$exists = (bool)$db->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ' . $db->quote($target))->fetchColumn();
if ($exists && $tables($target)) {
    if (!isset($options['rehacer'])) exit("La base `$target` ya tiene tablas. Usa --rehacer para borrarla y crearla de nuevo.\n");
    $db->exec("DROP DATABASE `$target`");
    $step("Base `$target` anterior borrada");
}
$db->exec("CREATE DATABASE IF NOT EXISTS `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$target`");
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$step("Base `$target` creada");

// ---------- 1. Tablas de tickets, tal cual ----------
foreach ($tables($source['tickets']) as $table) {
    $ddl = $db->query("SHOW CREATE TABLE `{$source['tickets']}`.`$table`")->fetch()['Create Table'];
    $db->exec($ddl);
    $db->exec("INSERT INTO `$table` SELECT * FROM `{$source['tickets']}`.`$table`");
}
$step('Tablas de tickets copiadas');

// ---------- 2. Usuarios con los datos de asistencia ----------
$db->exec("ALTER TABLE usuarios CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("ALTER TABLE usuarios
    MODIFY area VARCHAR(80) NOT NULL DEFAULT '',
    ADD rol_asistencia ENUM('empleado', 'gerente', 'admin') NULL DEFAULT NULL COMMENT 'NULL = sin acceso a asistencia' AFTER rol,
    ADD gerente_id INT NULL COMMENT 'Gerente a cargo (asistencia)' AFTER rol_asistencia,
    ADD representante TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Representante médico: registra visitas' AFTER gerente_id,
    ADD fecha_ingreso DATE NULL COMMENT 'Base para antigüedad y vacaciones' AFTER representante,
    ADD creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER fecha_ingreso,
    ADD eliminado_en DATETIME NULL COMMENT 'Baja en asistencia: conserva su historial' AFTER creado_en,
    ADD KEY idx_usuarios_gerente (gerente_id)");
$duplicates = $db->query("SELECT LOWER(TRIM(correo_usuario)) correo, COUNT(*) n FROM usuarios GROUP BY 1 HAVING n > 1")->fetchAll();
if ($duplicates) {
    $step('AVISO: correos repetidos en tickets, no se agrega el índice único: ' . implode(', ', array_column($duplicates, 'correo')));
} else {
    $db->exec('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_correo (correo_usuario)');
}

$map = [];          // id en asistencia => id en la tabla unida
$added = $merged = 0;
$find = $db->prepare('SELECT id, contrasena_usuario FROM usuarios WHERE LOWER(TRIM(correo_usuario)) = LOWER(TRIM(?))');
$update = $db->prepare('UPDATE usuarios SET rol_asistencia = ?, representante = ?, fecha_ingreso = ?, creado_en = ?, eliminado_en = ?,
    contrasena_usuario = COALESCE(NULLIF(contrasena_usuario, \'\'), ?) WHERE id = ?');
$insert = $db->prepare("INSERT INTO usuarios (nombre_usuario, correo_usuario, contrasena_usuario, area, activo, rol,
    rol_asistencia, representante, fecha_ingreso, creado_en, eliminado_en) VALUES (?, ?, ?, ?, ?, 'empleado', ?, ?, ?, ?, ?)");
$people = $db->query("SELECT * FROM `{$source['asistencia']}`.usuarios ORDER BY id")->fetchAll();
foreach ($people as $p) {
    $find->execute([$p['correo']]);
    $row = $find->fetch();
    if ($row) {
        // Misma persona: se conservan sus datos de tickets y se le agregan los de asistencia.
        // Si en tickets aún no tenía contraseña, conserva la de asistencia (mismo formato password_hash).
        $update->execute([$p['rol'], $p['representante'], $p['fecha_ingreso'], $p['creado_en'], $p['eliminado_en'], $p['contrasena'], $row['id']]);
        $map[(int)$p['id']] = (int)$row['id'];
        $merged++;
    } else {
        $insert->execute([$p['nombre'], strtolower(trim($p['correo'])), $p['contrasena'], (string)$p['area'], $p['eliminado_en'] ? 0 : 1,
            $p['rol'], $p['representante'], $p['fecha_ingreso'], $p['creado_en'], $p['eliminado_en']]);
        $map[(int)$p['id']] = (int)$db->lastInsertId();
        $added++;
    }
}
$manager = $db->prepare('UPDATE usuarios SET gerente_id = ? WHERE id = ?');
foreach ($people as $p) {
    if ($p['gerente_id'] !== null) $manager->execute([$map[(int)$p['gerente_id']] ?? null, $map[(int)$p['id']]]);
}
// Superusuario y supervisión ven asistencia como administradores; soporte no entra a asistencia.
$db->exec("UPDATE usuarios SET rol_asistencia = 'admin' WHERE rol IN ('superusuario', 'supervision') AND rol_asistencia IS NULL");
$db->exec("UPDATE usuarios SET rol_asistencia = NULL WHERE rol = 'soporte'");
$db->exec('ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_gerente FOREIGN KEY (gerente_id) REFERENCES usuarios (id) ON DELETE SET NULL');
$step("Usuarios: $merged unidos por correo, $added agregados desde asistencia");

// ---------- 3. Tablas de asistencia con los ids nuevos ----------
const USER_COLUMNS = ['usuario_id', 'creado_por', 'revisado_por'];
foreach ($tables($source['asistencia']) as $table) {
    if (in_array($table, ['usuarios', 'intentos_login'], true)) continue; // usuarios ya se unió; el login de asistencia ya no se usa
    $ddl = $db->query("SHOW CREATE TABLE `{$source['asistencia']}`.`$table`")->fetch()['Create Table'];
    // Los ids de usuario de asistencia eran INT UNSIGNED; los de la tabla unida son INT (los de tickets).
    $ddl = preg_replace('/`(' . implode('|', USER_COLUMNS) . ')` int\(\d+\) unsigned/', '`$1` int(11)', $ddl);
    $db->exec($ddl);
    $rows = $db->query("SELECT * FROM `{$source['asistencia']}`.`$table`")->fetchAll();
    if (!$rows) continue;
    $columns = array_keys($rows[0]);
    $stmt = $db->prepare("INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    foreach ($rows as $row) {
        foreach (USER_COLUMNS as $column) {
            if (isset($row[$column])) {
                $row[$column] = $map[(int)$row[$column]] ?? throw new RuntimeException("$table.$column: usuario {$row[$column]} sin correspondencia");
            }
        }
        $stmt->execute(array_values($row));
    }
}
$step('Tablas de asistencia copiadas');

// ---------- 4. Vista para el código de asistencia ----------
// Solo las personas con acceso a asistencia; se puede leer, insertar y actualizar como si fuera la tabla original.
$db->exec("CREATE OR REPLACE VIEW asistencia_usuarios AS
    SELECT id, nombre_usuario AS nombre, correo_usuario AS correo, area, fecha_ingreso, contrasena_usuario AS contrasena,
           rol_asistencia AS rol, gerente_id, representante, creado_en, eliminado_en
    FROM usuarios WHERE rol_asistencia IS NOT NULL");
$step('Vista asistencia_usuarios creada');

$db->exec('SET FOREIGN_KEY_CHECKS = 1');

// Cambios de estructura posteriores a la fusión (instalar/migraciones.php).
require_once __DIR__ . '/migraciones.php';
foreach (intranet_migrar($db) as $change) $step("Migración: $change");

// ---------- 5. Comprobación ----------
echo "\nTabla                       Original   Unida\n";
$problems = 0;
foreach ($source as $app => $schema) {
    foreach ($tables($schema) as $table) {
        if ($app === 'asistencia' && in_array($table, ['usuarios', 'intentos_login'], true)) continue;
        $a = $count($schema, $table);
        $b = $count($target, $table);
        if ($table !== 'usuarios' && $a !== $b) $problems++;
        printf("%-27s %8d %7d%s\n", $table, $a, $b, $table !== 'usuarios' && $a !== $b ? '  <-- NO COINCIDE' : '');
    }
}
printf("%-27s %8d %7d  (tickets %d + asistencia nuevos %d)\n", 'usuarios', $count($source['tickets'], 'usuarios') + count($people),
    $count($target, 'usuarios'), $count($source['tickets'], 'usuarios'), $added);
// Cada id de usuario de las tablas de asistencia debe existir en la tabla unida.
foreach (['registros', 'visitas', 'incidencias', 'incidencia_fechas', 'vacaciones_solicitudes', 'vacaciones_dias', 'vacaciones_ajustes'] as $table) {
    foreach (USER_COLUMNS as $column) {
        $has = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
        if (!$has) continue;
        $orphans = (int)$db->query("SELECT COUNT(*) FROM `$table` t LEFT JOIN usuarios u ON u.id = t.`$column` WHERE t.`$column` IS NOT NULL AND u.id IS NULL")->fetchColumn();
        if ($orphans) { $problems++; echo "AVISO: $table.$column tiene $orphans ids sin usuario\n"; }
    }
}
echo $problems ? "\nTerminó con $problems avisos.\n" : "\nListo: todo coincide.\n";
exit($problems ? 1 : 0);
