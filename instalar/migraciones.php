<?php
declare(strict_types=1);

/*
 * Cambios de estructura de la base unida de la intranet. Cada cambio revisa si ya se aplicó,
 * así que se puede ejecutar las veces que sea (no borra datos).
 *
 *   C:\xampp\php\php.exe instalar\migraciones.php
 *
 * fusionar.php también los aplica al crear la base.
 */

function intranet_migrar(PDO $db): array
{
    $done = [];
    $hasColumn = function (string $table, string $column) use ($db): bool {
        $query = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $query->execute([$table, $column]);
        return (bool)$query->fetchColumn();
    };
    $hasIndex = function (string $table, string $index) use ($db): bool {
        $query = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $query->execute([$table, $index]);
        return (bool)$query->fetchColumn();
    };

    // 0. Tablas de seguridad de la intranet: intentos de acceso, bitácora y enlaces para crear o recuperar la contraseña
    //    (solo se guarda el hash SHA-256 del token).
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (email VARCHAR(254) NOT NULL PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
        last_attempt BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS auditoria (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, fecha DATETIME NOT NULL, usuario_id INT NULL,
        correo VARCHAR(254) NULL, ip VARCHAR(45) NOT NULL, accion VARCHAR(40) NOT NULL, detalle VARCHAR(500) NULL,
        KEY idx_auditoria_fecha (fecha), KEY idx_auditoria_usuario (usuario_id), KEY idx_auditoria_accion (accion)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS password_resets (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
        tipo VARCHAR(10) NOT NULL, creado_en DATETIME NOT NULL, expira DATETIME NOT NULL, usado_en DATETIME NULL, creado_por INT NULL,
        UNIQUE KEY idx_reset_token (token_hash), KEY idx_reset_usuario (usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 1. Permisos e incapacidades con revisión: el empleado los pide y administración los aprueba o rechaza.
    //    Las que ya existían las registró un administrador, así que quedan aprobadas.
    if (!$hasColumn('incidencias', 'estado')) {
        $db->exec("ALTER TABLE incidencias
            ADD estado ENUM('pendiente', 'aprobada', 'rechazada', 'cancelada') NOT NULL DEFAULT 'aprobada' AFTER tipo,
            ADD goce TINYINT(1) NULL COMMENT 'Permiso: 1 con goce de sueldo, 0 sin goce' AFTER estado,
            ADD subtipo VARCHAR(40) NULL COMMENT 'Incapacidad: enfermedad general, riesgo de trabajo o maternidad' AFTER goce,
            ADD folio VARCHAR(30) NULL COMMENT 'Folio IMSS de la incapacidad' AFTER subtipo,
            ADD revisado_por INT NULL AFTER creado_en,
            ADD revisado_en DATETIME NULL AFTER revisado_por,
            ADD motivo_revision VARCHAR(255) NULL COMMENT 'Motivo de rechazo o cancelación' AFTER revisado_en,
            ADD KEY idx_incidencias_estado (estado),
            ADD CONSTRAINT fk_incidencias_revisor FOREIGN KEY (revisado_por) REFERENCES usuarios (id)");
        $done[] = 'incidencias: estado, goce, subtipo, folio y revisión';
    }
    // Un día rechazado o cancelado se puede volver a pedir: la regla de "un día, una incidencia" ahora la revisa el código
    // solo contra incidencias pendientes o aprobadas.
    if ($hasIndex('incidencia_fechas', 'uq_incidencia_usuario_fecha')) {
        $db->exec('ALTER TABLE incidencia_fechas DROP INDEX uq_incidencia_usuario_fecha, ADD KEY idx_incidencia_fechas_usuario (usuario_id, fecha)');
        $done[] = 'incidencia_fechas: sin índice único por persona y día';
    }
    // Los pasos 2, 3 y 6 usan columnas que el paso 7 quita: solo corren mientras existan (base recién fusionada).
    $legacy = $hasColumn('usuarios', 'rol_asistencia');

    // 2. Un solo perfil de administración: "Admin de asistencia" y "Supervisión" son lo mismo (Supervisión).
    //    Quien era empleado en tickets y admin en asistencia pasa a Supervisión (reportes de tickets, asistencia e incidencias).
    $promoted = $legacy ? $db->exec("UPDATE usuarios SET rol = 'supervision' WHERE rol = 'empleado' AND rol_asistencia = 'admin'") : 0;
    if ($promoted) $done[] = "$promoted persona(s) de Admin de asistencia a Supervisión";

    // 3. Quién valida (aprueba o rechaza) incidencias: un permiso por persona que da el superusuario en Seguridad.
    //    Al crearlo, solo Angélica López (alopez@fedele.com.mx) lo tiene.
    if ($legacy && !$hasColumn('usuarios', 'valida_incidencias')) {
        $db->exec("ALTER TABLE usuarios ADD valida_incidencias TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Puede aprobar o rechazar incidencias' AFTER representante");
        $db->exec("UPDATE usuarios SET valida_incidencias = 1 WHERE correo_usuario = 'alopez@fedele.com.mx'");
        $done[] = 'usuarios: permiso valida_incidencias (asignado a alopez@fedele.com.mx)';
    }

    // 4. Toda Supervisión administra las cuentas (pestaña Usuarios): el permiso por persona que se probó antes ya no se usa.
    if ($hasColumn('usuarios', 'administra_usuarios')) {
        $db->exec('ALTER TABLE usuarios DROP COLUMN administra_usuarios');
        $done[] = 'usuarios: sin la columna administra_usuarios (toda Supervisión administra usuarios)';
    }
    // 5. Validación en dos pasos (asistencia v2):
    //    pendiente (espera al jefe directo) -> autorizada (espera a quien valida incidencias) -> aprobada.
    //    Las incidencias guardan su rango (desde/hasta) porque al rechazarlas o cancelarlas se liberan sus días.
    if (!$hasColumn('vacaciones_solicitudes', 'autorizado_por')) {
        $db->exec("ALTER TABLE vacaciones_solicitudes
            MODIFY estado ENUM('pendiente', 'autorizada', 'aprobada', 'rechazada', 'cancelada') NOT NULL DEFAULT 'pendiente',
            ADD autorizado_por INT NULL COMMENT 'Jefe que la autorizó' AFTER creado_en,
            ADD autorizado_en DATETIME NULL AFTER autorizado_por,
            ADD CONSTRAINT fk_vac_solicitudes_autorizador FOREIGN KEY (autorizado_por) REFERENCES usuarios (id)");
        $done[] = 'vacaciones_solicitudes: estado «autorizada» y quién la autorizó';
    }
    if (!$hasColumn('incidencias', 'autorizado_por')) {
        $db->exec("ALTER TABLE incidencias
            MODIFY estado ENUM('pendiente', 'autorizada', 'aprobada', 'rechazada', 'cancelada') NOT NULL DEFAULT 'aprobada',
            ADD desde DATE NULL COMMENT 'Primer día (se conserva aunque se rechace)' AFTER estado,
            ADD hasta DATE NULL COMMENT 'Último día' AFTER desde,
            ADD autorizado_por INT NULL COMMENT 'Jefe que la autorizó' AFTER creado_en,
            ADD autorizado_en DATETIME NULL AFTER autorizado_por,
            ADD CONSTRAINT fk_incidencias_autorizador FOREIGN KEY (autorizado_por) REFERENCES usuarios (id)");
        $db->exec('UPDATE incidencias i JOIN (SELECT incidencia_id, MIN(fecha) a, MAX(fecha) b FROM incidencia_fechas GROUP BY incidencia_id) f
            ON f.incidencia_id = i.id SET i.desde = f.a, i.hasta = f.b WHERE i.desde IS NULL');
        // Lo que ya estaba en revisión: si la persona no tiene jefe directo (gerente activo), ya espera a quien valida.
        $noBoss = "usuario_id NOT IN (SELECT u.id FROM usuarios u JOIN usuarios g ON g.id = u.gerente_id
            WHERE g.activo = 1 AND g.rol_asistencia = 'gerente' AND g.eliminado_en IS NULL)";
        $moved = $db->exec("UPDATE incidencias SET estado = 'autorizada' WHERE estado = 'pendiente' AND $noBoss")
            + $db->exec("UPDATE vacaciones_solicitudes SET estado = 'autorizada' WHERE estado = 'pendiente' AND $noBoss");
        // Los días de lo rechazado o cancelado quedan libres (así lo hace la v2 al decidir).
        $db->exec("DELETE f FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id WHERE i.estado IN ('rechazada', 'cancelada')");
        $done[] = "incidencias: estado «autorizada», rango desde/hasta y quién la autorizó ($moved en revisión sin jefe pasan a autorizada)";
    }
    // 6. Puesto de cada empleado; de él salen sus accesos (comun/acceso.php):
    //      representante   Tickets, Asistencia e Incidencias (registra jornada y visitas)
    //      dermoconsejera  Asistencia e Incidencias (sin Tickets)
    //      otro            Tickets e Incidencias (no registra jornada)
    //    rol_asistencia queda como dato derivado: empleado o gerente si registra jornada, NULL si no.
    if ($legacy && !$hasColumn('usuarios', 'puesto')) {
        $db->exec("ALTER TABLE usuarios ADD puesto ENUM('representante', 'dermoconsejera', 'otro') NOT NULL DEFAULT 'otro'
            COMMENT 'Define los accesos: ver comun/acceso.php' AFTER area");
        $db->exec("UPDATE usuarios SET puesto = 'representante' WHERE representante = 1");
        // En la asistencia original, Kharla Lemus tenía el área «Dermoconsejera».
        $db->exec("UPDATE usuarios SET puesto = 'dermoconsejera' WHERE correo_usuario = 'nlemus@fedele.com.mx' AND puesto = 'otro'");
        $db->exec("UPDATE usuarios SET rol_asistencia = NULL WHERE rol IN ('empleado', 'soporte') AND puesto = 'otro'");
        $db->exec("UPDATE usuarios SET rol_asistencia = 'empleado' WHERE rol = 'empleado' AND puesto <> 'otro' AND rol_asistencia IS NULL");
        $done[] = 'usuarios: puesto (representante, dermoconsejera u otro) que define los accesos';
    }
    // 7. Tabla usuarios con solo lo necesario:
    //      id, nombre_usuario, correo_usuario, contrasena_usuario, area, puesto (oficina, dermoconsejera, representante),
    //      jefe_directo, correo_jefe, activo, rol (empleado, soporte, supervision, gerente, superusuario) y fecha_ingreso.
    //    Lo que antes se guardaba aparte ahora se deduce (comun/acceso.php):
    //      rol_asistencia      del rol y el puesto          gerente_id   el equipo = quienes lo tienen como jefe directo (correo_jefe)
    //      representante       puesto = representante      eliminado_en  cuenta desactivada (activo = 0)
    //      valida_incidencias  correo en asistencia/config.php > mail > cc (Administración)
    if ($hasColumn('usuarios', 'rol_asistencia')) {
        $db->exec("UPDATE usuarios SET rol = 'gerente' WHERE rol = 'empleado' AND rol_asistencia = 'gerente'");
        $db->exec("ALTER TABLE usuarios MODIFY puesto ENUM('representante', 'dermoconsejera', 'otro', 'oficina') NOT NULL DEFAULT 'oficina'");
        $db->exec("UPDATE usuarios SET puesto = 'oficina' WHERE puesto = 'otro'");
        $hasFk = (bool)$db->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND constraint_name = 'fk_usuarios_gerente'")->fetchColumn();
        if ($hasFk) $db->exec('ALTER TABLE usuarios DROP FOREIGN KEY fk_usuarios_gerente');
        if ($hasIndex('usuarios', 'idx_usuarios_gerente')) $db->exec('ALTER TABLE usuarios DROP INDEX idx_usuarios_gerente');
        $drop = array_values(array_filter(['rol_asistencia', 'gerente_id', 'representante', 'valida_incidencias', 'creado_en', 'eliminado_en'], fn($c) => $hasColumn('usuarios', $c)));
        $db->exec('ALTER TABLE usuarios ' . implode(', ', array_map(fn($c) => "DROP COLUMN $c", $drop)) . ",
            MODIFY puesto ENUM('oficina', 'dermoconsejera', 'representante') NOT NULL DEFAULT 'oficina' COMMENT 'Define los accesos: ver comun/acceso.php',
            MODIFY rol ENUM('empleado', 'soporte', 'supervision', 'gerente', 'superusuario') NOT NULL DEFAULT 'empleado',
            MODIFY fecha_ingreso DATE NULL COMMENT 'Base para antigüedad y vacaciones'");
        $done[] = 'usuarios: solo id, nombre, correo, contraseña, área, puesto, jefe directo, correo del jefe, activo, rol y fecha de ingreso (' . implode(', ', $drop) . ' ya no existen)';
    }

    // 8. Consumo de datos (asistencia v3): lo que usa Fedele 360 en el dispositivo de cada persona, por día.
    //    Lo mide el navegador (comun/consumo.js) y lo suma comun/registrar_consumo.php; reporte en asistencia/consumo.php.
    $hasTable = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'consumo_datos'")->fetchColumn();
    if (!$hasTable) {
        $db->exec("CREATE TABLE consumo_datos (
            usuario_id    INT NOT NULL,
            fecha         DATE NOT NULL,
            bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bytes descargados y enviados',
            bytes_celular BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Parte medida con datos celulares (solo navegadores que lo informan)',
            aperturas     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Veces que se abrió una página',
            solicitudes   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Llamadas al servidor',
            dispositivo   VARCHAR(60) NULL COMMENT 'Último dispositivo del día',
            PRIMARY KEY (usuario_id, fecha),
            KEY idx_consumo_fecha (fecha),
            CONSTRAINT fk_consumo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done[] = 'consumo_datos: consumo de datos por persona y día';
    }

    // 9. Cotizaciones (cotizaciones/): productos con sus listas de precios, clientes, la lista de precios que consultan los
    //    representantes y el historial de pedidos y cotizaciones de cada quien. Los datos iniciales los carga
    //    instalar/importar_cotizaciones.php. Precios en pesos, sin IVA.
    $tables = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cot\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $price = 'DECIMAL(10,2) NOT NULL DEFAULT 0';
    if (!in_array('cot_productos', $tables, true)) {
        // Productos para generar pedidos: una columna por lista de precios (cotizaciones/lib.php > COT_LISTAS).
        $db->exec("CREATE TABLE cot_productos (
            id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            numero_articulo VARCHAR(20) NOT NULL COMMENT 'Número de artículo (SAP)',
            descripcion     VARCHAR(200) NOT NULL,
            fabricante      VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Marca',
            codigo_barras   VARCHAR(40) NOT NULL DEFAULT '',
            precio_base $price, precio_15 $price, precio_20 $price, precio_25 $price, precio_30 $price, precio_33 $price,
            precio_35 $price, precio_40 $price, dermatologica_angelopolis $price, gema_munoz $price, medae $price,
            actualizado_en  DATETIME NULL,
            actualizado_por INT NULL,
            UNIQUE KEY uq_cot_productos_articulo (numero_articulo),
            KEY idx_cot_productos_fabricante (fabricante)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done[] = 'cot_productos: productos para pedidos y cotizaciones';
    }
    if (!in_array('cot_clientes', $tables, true)) {
        $db->exec("CREATE TABLE cot_clientes (
            id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            codigo          INT NULL COMMENT 'Código de cliente (SAP)',
            nombre          VARCHAR(150) NOT NULL,
            lista_precios   VARCHAR(60) NOT NULL DEFAULT 'Lista de precios 25%',
            actualizado_en  DATETIME NULL,
            actualizado_por INT NULL,
            KEY idx_cot_clientes_nombre (nombre),
            KEY idx_cot_clientes_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done[] = 'cot_clientes: clientes con su lista de precios';
    }
    if (!in_array('cot_lista_precios', $tables, true)) {
        // Pestaña «Precios» del cotizador: precio público y con 25% de descuento, sin y con IVA (vacío = sin precio).
        $db->exec("CREATE TABLE cot_lista_precios (
            id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            marca           VARCHAR(60) NOT NULL,
            linea           VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Línea o grupo dentro de la marca',
            sap             VARCHAR(20) NOT NULL DEFAULT '',
            codigo_barras   VARCHAR(40) NOT NULL DEFAULT '',
            descripcion     VARCHAR(200) NOT NULL,
            publico         DECIMAL(10,2) NULL, publico_iva DECIMAL(10,2) NULL,
            descuento       DECIMAL(10,2) NULL, descuento_iva DECIMAL(10,2) NULL,
            orden           INT NOT NULL DEFAULT 0,
            actualizado_en  DATETIME NULL,
            actualizado_por INT NULL,
            KEY idx_cot_lista_marca (marca, orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done[] = 'cot_lista_precios: lista de precios por marca';
    }
    if (!in_array('cot_pedidos', $tables, true)) {
        // Historial: lo que envió cada representante (pedidos a Compras y cotizaciones a clientes), con los precios
        // calculados por el servidor al enviarlo.
        $db->exec("CREATE TABLE cot_pedidos (
            id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id  INT NOT NULL,
            tipo        ENUM('pedido', 'cotizacion') NOT NULL,
            modalidad   ENUM('general', 'mostrador') NOT NULL,
            cliente_id  INT NULL,
            cliente     VARCHAR(150) NOT NULL,
            correo      VARCHAR(200) NOT NULL DEFAULT '',
            telefono    VARCHAR(40) NOT NULL DEFAULT '',
            direccion   VARCHAR(300) NOT NULL DEFAULT '',
            comentarios TEXT NULL,
            piezas      INT NOT NULL DEFAULT 0,
            subtotal    DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Sin IVA',
            iva         DECIMAL(12,2) NOT NULL DEFAULT 0,
            descuento   DECIMAL(12,2) NOT NULL DEFAULT 0,
            total       DECIMAL(12,2) NOT NULL DEFAULT 0,
            enviado     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = el correo salió',
            creado_en   DATETIME NOT NULL,
            KEY idx_cot_pedidos_usuario (usuario_id, creado_en),
            KEY idx_cot_pedidos_fecha (creado_en),
            CONSTRAINT fk_cot_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE cot_pedido_partidas (
            id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pedido_id       INT NOT NULL,
            numero_articulo VARCHAR(20) NOT NULL,
            descripcion     VARCHAR(200) NOT NULL,
            codigo_barras   VARCHAR(40) NOT NULL DEFAULT '',
            cantidad        INT NOT NULL,
            precio_publico  DECIMAL(10,2) NOT NULL DEFAULT 0,
            precio          DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Precio del cliente, sin IVA',
            bonificacion    TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_cot_partidas_pedido (pedido_id),
            CONSTRAINT fk_cot_partidas_pedido FOREIGN KEY (pedido_id) REFERENCES cot_pedidos (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done[] = 'cot_pedidos y cot_pedido_partidas: historial de pedidos y cotizaciones';
    }
    // 10. Una cotización se puede convertir en pedido: el pedido guarda de qué cotización salió.
    if (!$hasColumn('cot_pedidos', 'origen_id')) {
        $db->exec("ALTER TABLE cot_pedidos ADD origen_id INT NULL COMMENT 'Cotización de la que salió este pedido' AFTER modalidad,
            ADD KEY idx_cot_pedidos_origen (origen_id)");
        $done[] = 'cot_pedidos: origen_id (cotización convertida en pedido)';
    }

    // Vistas con la forma que espera el código de asistencia e incidencias (asistencia/api.php, incidencias/lib_*.php).
    // El «gerente» de cada persona es su jefe directo (la cuenta activa con el correo de correo_jefe, que no sea ella misma).
    $boss ="(SELECT j.id FROM usuarios j WHERE j.correo_usuario = TRIM(u.correo_jefe) AND j.activo = 1 AND j.id <> u.id LIMIT 1)";
    $columns = "u.id, u.nombre_usuario AS nombre, u.correo_usuario AS correo, u.area, u.fecha_ingreso, u.contrasena_usuario AS contrasena,
        CASE WHEN u.rol IN ('superusuario', 'supervision') THEN 'admin' WHEN u.rol = 'gerente' THEN 'gerente' ELSE 'empleado' END AS rol,
        $boss AS gerente_id, u.puesto = 'representante' AS representante, CAST(NULL AS DATETIME) AS creado_en,
        IF(u.activo = 1, NULL, CAST('2000-01-01 00:00:00' AS DATETIME)) AS eliminado_en";
    // Asistencia: quien registra jornada (representantes y dermoconsejeras), los gerentes (ven a su equipo) y la administración.
    $db->exec("CREATE OR REPLACE VIEW asistencia_usuarios AS SELECT $columns FROM usuarios u
        WHERE u.rol IN ('superusuario', 'supervision') OR (u.rol IN ('empleado', 'gerente') AND u.puesto IN ('dermoconsejera', 'representante'))");
    // Vacaciones e incidencias: todo el personal, registre o no jornada (soporte no).
    $db->exec("CREATE OR REPLACE VIEW personal_usuarios AS SELECT $columns FROM usuarios u WHERE u.rol <> 'soporte'");
    return $done;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/../tickets/php/env.php';
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('TICKETS_DB_HOST', 'localhost'), env('TICKETS_DB_PORT', '3306'), env('TICKETS_DB_NAME', 'intranet')),
        env('TICKETS_DB_USER', 'root'), env('TICKETS_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $done = intranet_migrar($db);
    echo $done ? "Aplicado:\n· " . implode("\n· ", $done) . "\n" : "La base ya estaba al día.\n";
} elseif (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
