<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
// Reglas de vacaciones y solicitudes: son de Incidencias (asistencia registra incidencias desde su calendario).
require __DIR__ . '/../incidencias/lib_vacaciones.php';
require __DIR__ . '/../incidencias/lib_solicitudes.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Metodo no permitido.'], 405);
    // JSON normalmente; multipart/form-data cuando se adjunta un archivo (incidencias).
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) respond(['error' => 'El archivo es demasiado grande.'], 413);
        $body = $_POST;
    } else {
        $body = json_decode(file_get_contents('php://input'), true);
    }
    if (!is_array($body)) respond(['error' => 'Solicitud no valida.'], 400);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error' => 'La sesion cambio. Recarga la pagina.'], 403);
    $action = $body['action'] ?? '';

    // En la intranet se entra solo por el inicio de sesión de tickets (contraseña + código por correo).
    // El login propio de asistencia queda cerrado para no dejar una puerta con menos seguridad.

    // Una cuenta dada de baja pierde la sesión en su siguiente petición.
    if (empty($_SESSION['user_id']) || !currentUser($db)) {
        unset($_SESSION['user_id']);
        respond(['error' => 'Inicia sesion para continuar.'], 401);
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
    }
    if ($action === 'status') respond(['punches' => todayPunches($db, (int)$_SESSION['user_id']), 'visits' => todayVisits($db, (int)$_SESSION['user_id']), 'day' => date('Y-m-d')]);
    // Supervisión y superusuario administran asistencia; no registran jornada ni visitas.
    // Registran jornada y visitas solo representantes médicos y dermoconsejeras (comun/acceso.php).
    if (in_array($action, ['punch', 'visit_start', 'visit_end', 'status'], true) && !currentUser($db)['registra']) {
        respond(['error' => 'Tu perfil no registra jornada.'], 403);
    }
    if ($action === 'visit_start' || $action === 'visit_end') {
        $me = currentUser($db);
        if (!$me['representante']) respond(['error' => 'Solo los representantes medicos registran visitas.'], 403);
        $doctor = trim((string)($body['doctor'] ?? ''));
        $comments = trim((string)($body['comments'] ?? ''));
        if ($action === 'visit_start' && ($doctor === '' || mb_strlen($doctor) > 150)) respond(['error' => 'Escribe el nombre del medico (hasta 150 caracteres).'], 422);
        if (mb_strlen($comments) > 2000) respond(['error' => 'Los comentarios admiten hasta 2000 caracteres.'], 422);
        [$lat, $lng, $accuracy] = readLocation($body);
        $db->beginTransaction();
        $now = new DateTimeImmutable();
        $db->prepare('SELECT id FROM asistencia_usuarios WHERE id = ? FOR UPDATE')->execute([$me['id']]);
        $open = $db->prepare('SELECT id FROM visitas WHERE usuario_id = ? AND fin_en IS NULL');
        $open->execute([$me['id']]);
        $openId = $open->fetchColumn();
        if ($action === 'visit_start') {
            $kinds = $db->prepare('SELECT tipo FROM registros WHERE usuario_id = ? AND fecha = ?');
            $kinds->execute([$me['id'], $now->format('Y-m-d')]);
            $done = $kinds->fetchAll(PDO::FETCH_COLUMN);
            $error = match (true) {
                (bool)$openId => 'Ya tienes una visita en curso; finalizala primero.',
                !in_array('entrada', $done, true) => 'Primero registra tu entrada.',
                in_array('salida', $done, true) => 'Tu jornada de hoy ya termino.',
                in_array('salida_comida', $done, true) && !in_array('regreso_comida', $done, true) => 'Registra tu regreso de comida antes de iniciar una visita.',
                default => null,
            };
            if ($error) { $db->rollBack(); respond(['error' => $error], 409); }
            $db->prepare('INSERT INTO visitas (usuario_id, fecha, medico, comentarios, inicio_en, inicio_latitud, inicio_longitud, inicio_precision) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$me['id'], $now->format('Y-m-d'), $doctor, $comments ?: null, $now->format('Y-m-d H:i:s'), $lat, $lng, $accuracy]);
        } else {
            if (!$openId) { $db->rollBack(); respond(['error' => 'No tienes una visita en curso.'], 409); }
            // Al finalizar se guardan los comentarios tal como quedaron en la ventana (pueden completarse durante la visita).
            $db->prepare('UPDATE visitas SET fin_en = ?, fin_latitud = ?, fin_longitud = ?, fin_precision = ?, comentarios = ? WHERE id = ?')
                ->execute([$now->format('Y-m-d H:i:s'), $lat, $lng, $accuracy, $comments ?: null, $openId]);
        }
        $db->commit();
        respond(['ok' => true, 'visits' => todayVisits($db, (int)$me['id'])]);
    }
    // Vacaciones del propio empleado: consultar saldo, solicitar y cancelar sus solicitudes pendientes.
    if (str_starts_with($action, 'my_vacation')) {
        if (!feature('vacaciones')) respond(['error' => 'Las vacaciones no estan disponibles.'], 404);
        $myId = (int)$_SESSION['user_id'];
        if ($action === 'my_vacations') {
            $year = (int)date('Y');
            respond(['today' => date('Y-m-d'), 'me' => vacationSummaries($db, $myId)[0], 'holidays' => mxHolidays($year) + mxHolidays($year + 1)]);
        }
        if ($action === 'my_vacation_request') {
            $result = createVacationRequest($db, $myId, (string)($body['from'] ?? ''), (string)($body['to'] ?? ''), trim((string)($body['comment'] ?? '')), 'pendiente', $myId);
            if (isset($result['error'])) respond(['error' => $result['error']], $result['code']);
            respond(['ok' => true, 'days' => $result['days'], 'me' => vacationSummaries($db, $myId)[0]]);
        }
        if ($action === 'my_vacation_cancel') {
            $cancel = $db->prepare("UPDATE vacaciones_solicitudes SET estado = 'cancelada', revisado_por = ?, revisado_en = NOW(), motivo_revision = 'Cancelada por el empleado' WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'");
            $cancel->execute([$myId, (int)($body['id'] ?? 0), $myId]);
            if (!$cancel->rowCount()) respond(['error' => 'Solo puedes cancelar tus solicitudes pendientes.'], 409);
            respond(['ok' => true, 'me' => vacationSummaries($db, $myId)[0]]);
        }
        respond(['error' => 'Accion no valida.'], 400);
    }
    // Vacaciones del equipo de un gerente: solo lectura (las valida el administrador).
    if ($action === 'team_vacations') {
        if (!feature('vacaciones')) respond(['error' => 'Las vacaciones no estan disponibles.'], 404);
        $me = currentUser($db);
        if (!$me || $me['rol'] !== 'gerente') respond(['error' => 'No tienes permiso para ver esta informacion.'], 403);
        $team = teamIds($db, (int)$me['id']);
        $users = array_values(array_filter(vacationSummaries($db), fn($u) => in_array($u['id'], $team, true)));
        // Los días de permisos o incapacidades no se muestran aquí.
        $users = array_map(function ($u) { unset($u['busy']); return $u; }, $users);
        respond(['today' => date('Y-m-d'), 'users' => $users]);
    }
    if (str_starts_with($action, 'admin_')) {
        $me = currentUser($db);
        // El gerente solo consulta su equipo (página equipo.php); todo lo demás es del administrador.
        $managerAllowed = $action === 'admin_data';
        if (!$me || !($me['rol'] === 'admin' || ($me['rol'] === 'gerente' && $managerAllowed))) respond(['error' => 'No tienes permiso para ver esta informacion.'], 403);
        // null = sin restricción (administrador); arreglo = ids de las personas que el gerente tiene a su cargo.
        $team = $me['rol'] === 'admin' ? null : teamIds($db, (int)$me['id']);
    }
    if ($action === 'admin_users') respond(['users' => adminUsers($db), 'me' => (int)$_SESSION['user_id']]);
    // Reporte de consumo de datos por persona (consumo.php; lo miden comun/consumo.js y comun/registrar_consumo.php).
    // Todas las cuentas activas de la intranet: el consumo se mide en tickets, asistencia e incidencias.
    if ($action === 'admin_usage') {
        $from = (string)($body['from'] ?? '');
        $to = (string)($body['to'] ?? '');
        // Un dispositivo con el reloj adelantado puede pedir "mañana": se recorta a hoy (hora de Ciudad de México).
        $today = date('Y-m-d');
        if (validDate($from, '2020-01-01', '2100-12-31') && $from > $today) $from = $today;
        if (validDate($to, '2020-01-01', '2100-12-31') && $to > $today) $to = $today;
        if (!validDate($from, '2020-01-01', $today) || !validDate($to, $from, $today)) respond(['error' => 'Revisa el periodo.'], 422);
        $rows = $db->prepare("SELECT u.id, u.nombre_usuario AS nombre, u.correo_usuario AS correo, u.area, u.rol, u.puesto,
                COALESCE(SUM(c.bytes), 0) AS bytes, COALESCE(SUM(c.bytes_celular), 0) AS celular, COALESCE(SUM(c.aperturas), 0) AS aperturas,
                COALESCE(SUM(c.solicitudes), 0) AS solicitudes, COUNT(c.fecha) AS dias, MAX(c.fecha) AS ultimo,
                (SELECT c2.dispositivo FROM consumo_datos c2 WHERE c2.usuario_id = u.id AND c2.fecha BETWEEN ? AND ? ORDER BY c2.fecha DESC LIMIT 1) AS dispositivo
            FROM usuarios u LEFT JOIN consumo_datos c ON c.usuario_id = u.id AND c.fecha BETWEEN ? AND ?
            WHERE u.activo = 1 GROUP BY u.id, u.nombre_usuario, u.correo_usuario, u.area, u.rol, u.puesto ORDER BY bytes DESC, u.nombre_usuario");
        $rows->execute([$from, $to, $from, $to]);
        $days = $db->prepare('SELECT fecha, SUM(bytes) AS bytes, COUNT(*) AS personas FROM consumo_datos WHERE fecha BETWEEN ? AND ? GROUP BY fecha ORDER BY fecha');
        $days->execute([$from, $to]);
        $roles = ['empleado' => 'Empleado', 'gerente' => 'Gerente', 'soporte' => 'Soporte', 'supervision' => 'Supervisión', 'superusuario' => 'Superusuario'];
        respond(['from' => $from, 'to' => $to, 'today' => date('Y-m-d'),
            'users' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['nombre'], 'email' => $r['correo'], 'area' => $r['area'],
                'role' => $roles[$r['rol']] ?? $r['rol'], 'puesto' => intranet_puestos()[$r['puesto']] ?? '',
                'bytes' => (int)$r['bytes'], 'cellular' => (int)$r['celular'], 'loads' => (int)$r['aperturas'], 'requests' => (int)$r['solicitudes'],
                'days' => (int)$r['dias'], 'last' => $r['ultimo'], 'device' => $r['dispositivo']], $rows->fetchAll()),
            'daily' => array_map(fn($r) => ['day' => $r['fecha'], 'bytes' => (int)$r['bytes'], 'people' => (int)$r['personas']], $days->fetchAll())]);
    }
    // Incidencias apagadas en este servidor (config.php > features).
    if (in_array($action, ['admin_user_incidents', 'admin_delete_incident', 'admin_save_incident'], true) && !feature('incidencias')) {
        respond(['error' => 'Las incidencias no estan disponibles.'], 404);
    }
    if ($action === 'admin_user_incidents') {
        $userId = (int)($body['user_id'] ?? 0);
        if ($team !== null && !in_array($userId, $team, true)) respond(['error' => 'Esa persona no forma parte de tu equipo.'], 403);
        $vacations = feature('vacaciones') ? vacationIncidents($db, '1950-01-01', '2100-12-31', $userId) : [];
        // Días ocupados para el calendario: vacaciones e incidencias aprobadas o en revisión.
        $busy = $db->prepare("SELECT f.fecha FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id
            WHERE f.usuario_id = ? AND i.estado IN ('aprobada', 'pendiente', 'autorizada')
            UNION SELECT d.fecha FROM vacaciones_dias d JOIN vacaciones_solicitudes s ON s.id = d.solicitud_id
            WHERE d.usuario_id = ? AND s.estado IN ('aprobada', 'pendiente', 'autorizada')");
        $busy->execute([$userId, $userId]);
        $year = (int)date('Y');
        respond(['incidents' => [...userIncidents($db, $userId), ...array_reverse($vacations)], 'busy' => $busy->fetchAll(PDO::FETCH_COLUMN),
            'holidays' => mxHolidays($year - 1) + mxHolidays($year) + mxHolidays($year + 1)]);
    }
    if ($action === 'admin_delete_incident') {
        // Borrar una incidencia equivale a deshacer su validación: solo quien valida incidencias.
        if (!currentUser($db)['valida']) respond(['error' => 'Solo quien valida incidencias puede borrarlas.'], 403);
        $query = $db->prepare('SELECT usuario_id, archivo_ruta FROM incidencias WHERE id = ?');
        $query->execute([(int)($body['id'] ?? 0)]);
        $incident = $query->fetch();
        if (!$incident) respond(['error' => 'La incidencia no existe.'], 404);
        $db->prepare('DELETE FROM incidencias WHERE id = ?')->execute([(int)$body['id']]);
        if ($incident['archivo_ruta']) @unlink(UPLOADS_DIR . '/' . $incident['archivo_ruta']);
        respond(['ok' => true, 'incidents' => userIncidents($db, (int)$incident['usuario_id'])]);
    }
    if ($action === 'admin_save_incident') {
        $userId = (int)($body['user_id'] ?? 0);
        $exists = $db->prepare('SELECT COUNT(*) FROM asistencia_usuarios WHERE id = ? AND eliminado_en IS NULL');
        $exists->execute([$userId]);
        if (!$exists->fetchColumn()) respond(['error' => 'El empleado no existe.'], 404);
        $dates = json_decode((string)($body['dates'] ?? '[]'), true);
        $file = $_FILES['file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_NO_FILE) $file = null;
        // Justificar desde el panel: aprobada si la registra quien valida incidencias; si no, espera su confirmación
        // en Incidencias → Por revisar (ya viene de Administración, así que no pasa por el jefe).
        $valida = currentUser($db)['valida'];
        $result = createIncident($db, $userId, (string)($body['type'] ?? ''), trim((string)($body['description'] ?? '')), is_array($dates) ? $dates : [], $file,
            (int)$_SESSION['user_id'], $valida ? 'aprobada' : 'autorizada');
        if (isset($result['error'])) respond(['error' => $result['error']], $result['code']);
        if (!$valida) notifyRequest($db, 'registrada', 'incidencia', $result['id']);
        respond(['ok' => true, 'incidents' => userIncidents($db, $userId)]);
    }
    if ($action === 'admin_data') {
        $parse = function (string $value): ?DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date && $date->format('Y-m-d') === $value ? $date : null;
        };
        $from = $parse((string)($body['from'] ?? ''));
        $to = $parse((string)($body['to'] ?? ''));
        if (!$from || !$to || $from > $to) respond(['error' => 'Rango de fechas no valido.'], 422);
        if ($from->diff($to)->days > 92) respond(['error' => 'El rango maximo es de 93 dias.'], 422);
        // Incluye cuentas eliminadas para mostrar su historial.
        $users = $db->query('SELECT id, nombre AS name, correo AS email, area, rol, gerente_id, representante AS rep, DATE(creado_en) AS created_on, eliminado_en IS NOT NULL AS deleted FROM asistencia_usuarios ORDER BY nombre')->fetchAll();
        $query = $db->prepare('SELECT usuario_id, fecha, tipo, registrado_en, latitud, longitud, precision_m FROM registros WHERE fecha BETWEEN ? AND ? ORDER BY fecha DESC, registrado_en, id');
        $query->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $punches = array_map(fn(array $row) => ['user_id' => (int)$row['usuario_id'], 'day' => $row['fecha']] + formatPunch($row), $query->fetchAll());
        $query = $db->prepare('SELECT * FROM visitas WHERE fecha BETWEEN ? AND ? ORDER BY inicio_en, id');
        $query->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $iso = fn(?string $v) => $v ? (new DateTimeImmutable($v))->format(DATE_ATOM) : null;
        $point = fn(array $v, string $p) => $v[$p . '_latitud'] === null ? null
            : ['latitude' => (float)$v[$p . '_latitud'], 'longitude' => (float)$v[$p . '_longitud'], 'accuracy' => (float)$v[$p . '_precision']];
        $visits = array_map(fn(array $v) => [
            'id' => (int)$v['id'], 'user_id' => (int)$v['usuario_id'], 'day' => $v['fecha'], 'doctor' => $v['medico'], 'comments' => $v['comentarios'],
            'started_at' => $iso($v['inicio_en']), 'ended_at' => $iso($v['fin_en']), 'start' => $point($v, 'inicio'), 'end' => $point($v, 'fin'),
        ], $query->fetchAll());
        $incidents = feature('incidencias') ? rangeIncidents($db, $from->format('Y-m-d'), $to->format('Y-m-d')) : [];
        if (feature('vacaciones')) $incidents = [...$incidents, ...vacationIncidents($db, $from->format('Y-m-d'), $to->format('Y-m-d'))];
        // Un gerente solo recibe datos de su equipo, sin ubicaciones ni archivos (solo horarios, médicos y justificaciones).
        if ($team !== null) {
            $mine = fn(array $rows, string $key) => array_values(array_filter($rows, fn($r) => in_array((int)$r[$key], $team, true)));
            [$users, $punches, $visits, $incidents] = [$mine($users, 'id'), $mine($punches, 'user_id'), $mine($visits, 'user_id'), $mine($incidents, 'user_id')];
            $punches = array_map(fn($p) => array_diff_key($p, ['latitude' => 0, 'longitude' => 0, 'accuracy' => 0]), $punches);
            $visits = array_map(fn($v) => array_merge($v, ['start' => null, 'end' => null]), $visits);
            $incidents = array_map(fn($i) => array_merge($i, ['file' => null]), $incidents);
        }
        respond([
            'today' => date('Y-m-d'),
            'users' => array_map(fn(array $u) => ['id' => (int)$u['id'], 'rep' => (bool)$u['rep'], 'deleted' => (bool)$u['deleted'],
                'manager_id' => $u['gerente_id'] === null ? null : (int)$u['gerente_id']] + $u, $users),
            'punches' => $punches,
            'visits' => $visits,
            'incidents' => $incidents,
        ]);
    }
    if ($action !== 'punch') respond(['error' => 'Accion no valida.'], 400);

    $kind = $body['kind'] ?? '';
    if (!in_array($kind, PUNCH_KINDS, true)) respond(['error' => 'Tipo de registro no valido.'], 422);
    [$lat, $lng, $accuracy] = readLocation($body);

    // Bloquea la fila del usuario para serializar la validación y el insert (doble clic, peticiones simultáneas).
    $db->beginTransaction();
    $now = new DateTimeImmutable();
    $db->prepare('SELECT id FROM asistencia_usuarios WHERE id = ? FOR UPDATE')->execute([$_SESSION['user_id']]);
    $query = $db->prepare('SELECT tipo FROM registros WHERE usuario_id = ? AND fecha = ?');
    $query->execute([$_SESSION['user_id'], $now->format('Y-m-d')]);
    $error = punchError($kind, $query->fetchAll(PDO::FETCH_COLUMN));
    if (!$error && in_array($kind, ['salida_comida', 'salida'], true)) {
        $open = $db->prepare('SELECT COUNT(*) FROM visitas WHERE usuario_id = ? AND fin_en IS NULL');
        $open->execute([$_SESSION['user_id']]);
        if ($open->fetchColumn()) $error = 'Finaliza tu visita en curso antes de registrar este movimiento.';
    }
    if ($error) {
        $db->rollBack();
        respond(['error' => $error], 409);
    }
    $db->prepare('INSERT INTO registros (usuario_id, fecha, tipo, registrado_en, latitud, longitud, precision_m) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$_SESSION['user_id'], $now->format('Y-m-d'), $kind, $now->format('Y-m-d H:i:s'), $lat, $lng, $accuracy]);
    $db->commit();
    respond(['ok' => true, 'punches' => todayPunches($db, (int)$_SESSION['user_id']), 'day' => date('Y-m-d')]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log((string)$e);
    respond(['error' => 'No se pudo completar la solicitud. Intenta nuevamente.'], 500);
}
