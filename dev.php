<?php
/*
 * MODO DESARROLLO: entrar a la intranet eligiendo el tipo de usuario, sin contraseña ni código.
 * Solo funciona con INTRANET_DEV=1 en el .env de tickets y desde esta misma computadora (127.0.0.1 / ::1);
 * en cualquier otro caso responde 404. Nunca activar INTRANET_DEV en el servidor.
 */
require_once __DIR__ . '/tickets/php/bootstrap.php';
require_once __DIR__ . '/tickets/php/seguridad.php';
require_once __DIR__ . '/tickets/php/soporte_helpers.php';
require_once __DIR__ . '/comun/acceso.php';

if (!intranet_dev()) {
    http_response_code(404);
    exit;
}

// Tipos de usuario y quién los representa en la base (condición sobre la tabla usuarios).
$types = [
    'superusuario' => ['Superusuario', 'Todo: Tickets (con Seguridad), Asistencia, Incidencias y Cotizaciones (con productos, precios y clientes).', "rol = 'superusuario'"],
    'soporte' => ['Soporte', 'Solo Tickets: Mis tickets y Panel de soporte.', "rol = 'soporte'"],
    'supervision' => ['Supervisión', 'Reportes de tickets, administración de asistencia y todo Incidencias (valida solo quien tiene el permiso).', "rol = 'supervision'"],
    'gerente' => ['Gerente de ventas', 'Lo de su puesto, más su equipo (quienes lo tienen como jefe directo) en Asistencia e Incidencias.', "rol = 'gerente' AND puesto IN ('representante', 'dermoconsejera')"],
    'gerente_oficina' => ['Gerente de oficina', 'Tickets e Incidencias, más su equipo en Incidencias (no ve Asistencia).', "rol = 'gerente' AND puesto = 'oficina'"],
    'representante' => ['Representante médico', 'Tickets, Asistencia (jornada y visitas), Incidencias y Cotizaciones.', "rol = 'empleado' AND puesto = 'representante'"],
    'dermoconsejera' => ['Dermoconsejera', 'Asistencia e Incidencias (sin Tickets).', "rol = 'empleado' AND puesto = 'dermoconsejera'"],
    'empleado' => ['Oficina', 'Tickets e Incidencias (no registra asistencia).', "rol = 'empleado' AND puesto = 'oficina'"],
];

$db = database();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string)($_POST['tipo'] ?? '');
    if (!valid_csrf() || !isset($types[$type])) {
        $error = 'La página expiró. Intenta de nuevo.';
    } else {
        // Solo se puede entrar como alguien activo que de verdad tenga ese tipo de usuario.
        $query = $db->prepare('SELECT id, nombre_usuario name, correo_usuario email, rol FROM usuarios WHERE id = ? AND activo = 1 AND ' . $types[$type][2]);
        $query->execute([(int)($_POST['usuario'] ?? 0)]);
        $user = $query->fetch();
        if (!$user) {
            $error = 'Esa persona ya no tiene ese tipo de usuario.';
        } else {
            $account = ['id' => (int)$user['id'], 'name' => (string)$user['name'], 'email' => (string)$user['email'], 'rol' => (string)$user['rol']];
            login_complete($account, true);
            audit('login_ok', 'Modo desarrollo: ' . $types[$type][0] . ' sin contraseña');
            header('Location: ./', true, 303); // index.php lo lleva a su primera app
            exit;
        }
    }
}

$people = [];
foreach ($types as $key => [, , $where]) {
    $people[$key] = $db->query("SELECT id, nombre_usuario, correo_usuario FROM usuarios WHERE activo = 1 AND $where ORDER BY nombre_usuario LIMIT 100")->fetchAll();
}
$current = $_SESSION['user'] ?? null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar como… | Fedele 360 (modo desarrollo)</title>
    <link rel="stylesheet" href="comun/dev.css?v=<?= (int)filemtime(__DIR__ . '/comun/dev.css') ?>">
    <link rel="stylesheet" href="comun/barra.css?v=<?= (int)filemtime(__DIR__ . '/comun/barra.css') ?>">
    <script src="comun/acciones.js?v=<?= (int)filemtime(__DIR__ . '/comun/acciones.js') ?>" defer></script>
</head>
<body>
<main class="dev">
    <img class="dev-logo" src="tickets/img/logo-mediano.png" alt="Fedele">
    <p class="dev-flag">Modo desarrollo: sin contraseña, solo en esta computadora</p>
    <h1>¿Con qué tipo de usuario quieres entrar?</h1>
    <p class="dev-lead">Entras como una persona real de la base con ese tipo de usuario. Siempre empiezas en Tickets.
        <?php if ($current): ?>Ahora estás como <strong><?= escape((string)$current['name']) ?></strong>.<?php endif; ?>
        <?php if (!$current): ?><br><a class="dev-real" href="tickets/modulos/login.php?real">Ver el inicio de sesión real</a> (con correo y contraseña, para capturas de los manuales).<?php endif; ?></p>
    <?php if ($error): ?><p class="dev-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <div class="dev-grid">
    <?php foreach ($types as $key => [$label, $summary]): ?>
        <form class="dev-card" method="post">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="tipo" value="<?= $key ?>">
            <h2><?= escape($label) ?></h2>
            <p><?= escape($summary) ?></p>
            <?php if ($people[$key]): ?>
                <select name="usuario" aria-label="Persona con el tipo <?= escape($label) ?>">
                <?php foreach ($people[$key] as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= escape($p['nombre_usuario'] . ' · ' . $p['correo_usuario']) ?></option>
                <?php endforeach; ?>
                </select>
                <button type="submit">Entrar como <?= escape($label) ?></button>
            <?php else: ?>
                <p class="dev-empty">No hay nadie con este tipo de usuario en la base.</p>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>
    </div>
</main>
</body>
</html>
