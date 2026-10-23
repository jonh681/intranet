<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');
// Sesión compartida con tickets: quien entra a la intranet (login de tickets) ya está dentro de asistencia.
require_once __DIR__ . '/../comun/sesion.php';
require_once __DIR__ . '/../comun/acceso.php';
intranet_session_start();
// El código de asistencia usa $_SESSION['user_id']; se toma siempre de la sesión de la intranet.
if (isset($_SESSION['user']['id'])) $_SESSION['user_id'] = (int)$_SESSION['user']['id'];
else unset($_SESSION['user_id']);
// Menos datos (asistencia v3): PHP comprime páginas y respuestas de la API. Los archivos (evidencias, PDF) no, porque
// mandan su tamaño exacto. Las respuestas que mandan correos después (comun/segundo_plano.php) salen sin comprimir.
if (PHP_SAPI !== 'cli' && extension_loaded('zlib') && !headers_sent() && !ini_get('zlib.output_compression') && !in_array('ob_gzhandler', ob_list_handlers(), true)
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['archivo.php', 'evidencia.php', 'formato.php'], true)) {
    ob_start('ob_gzhandler');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: geolocation=(self)");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

// Base de datos MySQL (ver db.php y config.example.php). Esquema en database/schema.sql.
require_once __DIR__ . '/db.php';
try {
    $db = connectDb();
} catch (PDOException $e) {
    // Sin detalles al usuario: el motivo real queda en el log de PHP.
    error_log('asistencia: sin conexión a MySQL: ' . $e->getMessage());
    $unavailable = 'El servicio no está disponible por el momento. Intenta de nuevo en unos minutos.';
    if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) respond(['error' => $unavailable], 503);
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    // Con versión (?v=): .htaccess guarda los .css un año en el navegador.
    require_once __DIR__ . '/ui.php';
    exit('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Asistencia | Fedele 360</title><link rel="stylesheet" href="' . esc(asset('app.css')) . '"><body class="min-h-screen grid place-items-center bg-base-200 text-base-content font-sans p-4"><div class="card bg-base-100 border border-base-300 shadow-sm max-w-sm"><div class="card-body"><h1 class="text-xl font-bold">Servicio no disponible</h1><p class="text-base-content/60">' . $unavailable . '</p></div></div></body></html>');
}
$db->exec("SET time_zone = '-06:00'");

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// Usuario de la sesión, leído de la base en cada petición para que un cambio de rol, una baja o una cuenta
// desactivada en tickets apliquen de inmediato. El rol es el efectivo en asistencia (comun/acceso.php).
function currentUser(PDO $db): ?array {
    $u = intranet_usuario($db);
    $rol = $u ? intranet_rol_asistencia($u) : null;
    if ($rol === null) return null;
    // representante: registra visitas a médicos. registra: marca su jornada (un gerente de oficina solo ve a su equipo).
    return ['id' => (int)$u['id'], 'name' => $u['nombre_usuario'], 'email' => $u['correo_usuario'], 'rol' => $rol,
        'representante' => $u['puesto'] === 'representante' && intranet_registra_asistencia($u) ? 1 : 0,
        'registra' => intranet_registra_asistencia($u), 'valida' => intranet_valida_incidencias($u)];
}

// Fecha AAAA-MM-DD real dentro de un rango.
function validDate(string $value, string $min, string $max): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value && $value >= $min && $value <= $max;
}

// Ids de las personas a cargo de un gerente (incluye cuentas dadas de baja, para su historial).
function teamIds(PDO $db, int $managerId): array {
    $query = $db->prepare('SELECT id FROM asistencia_usuarios WHERE gerente_id = ?');
    $query->execute([$managerId]);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

// Lista de usuarios para la administración, con su último día registrado y total de registros.
function adminUsers(PDO $db): array {
    $rows = $db->query('SELECT u.id, u.nombre, u.correo, u.area, u.fecha_ingreso, u.rol, u.gerente_id, u.representante, u.creado_en, MAX(r.fecha) AS ultimo, COUNT(r.id) AS total
        FROM asistencia_usuarios u LEFT JOIN registros r ON r.usuario_id = u.id WHERE u.eliminado_en IS NULL GROUP BY u.id ORDER BY u.nombre')->fetchAll();
    return array_map(fn(array $u) => [
        'id' => (int)$u['id'], 'name' => $u['nombre'], 'email' => $u['correo'], 'area' => $u['area'],
        'rol' => $u['rol'], 'manager_id' => $u['gerente_id'] === null ? null : (int)$u['gerente_id'], 'hire_date' => $u['fecha_ingreso'],
        'rep' => (bool)$u['representante'], 'created_at' => $u['creado_en'], 'last_day' => $u['ultimo'], 'punches' => (int)$u['total'],
    ], $rows);
}

// Evidencias de incidencias: fuera del alcance del navegador (uploads/.htaccess) y solo por archivo.php.
const UPLOADS_DIR = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
const UPLOAD_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];

// Agrupa filas incidencia+fecha en incidencias con su lista de días.
function groupIncidents(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[$id] ??= [
            'id' => $id, 'user_id' => (int)$r['usuario_id'], 'type' => $r['tipo'], 'status' => $r['estado'], 'description' => $r['descripcion'],
            'file' => $r['archivo_ruta'] ? ['name' => $r['archivo_nombre'], 'size' => (int)$r['archivo_tamano']] : null,
            'created_at' => $r['creado_en'], 'dates' => [],
        ];
        $out[$id]['dates'][] = $r['fecha'];
    }
    return array_values($out);
}

// Incidencias aprobadas de un empleado (las más recientes primero). Las solicitudes en revisión están en Incidencias.
function userIncidents(PDO $db, int $userId): array {
    $query = $db->prepare("SELECT i.*, f.fecha FROM incidencias i JOIN incidencia_fechas f ON f.incidencia_id = i.id
        WHERE i.usuario_id = ? AND i.estado = 'aprobada' AND i.id IN (SELECT id FROM (SELECT id FROM incidencias WHERE usuario_id = ? AND estado = 'aprobada' ORDER BY id DESC LIMIT 50) t)
        ORDER BY i.id DESC, f.fecha");
    $query->execute([$userId, $userId]);
    return groupIncidents($query->fetchAll());
}

// Incidencias aprobadas con al menos un día dentro del periodo; solo se incluyen los días del periodo.
function rangeIncidents(PDO $db, string $from, string $to): array {
    // Solo las aprobadas justifican un día; las que están en revisión aún no.
    $query = $db->prepare("SELECT i.*, f.fecha FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id
        WHERE f.fecha BETWEEN ? AND ? AND i.estado = 'aprobada' ORDER BY i.id, f.fecha");
    $query->execute([$from, $to]);
    return groupIncidents($query->fetchAll());
}

// Orden de la jornada; la comida es opcional.
const PUNCH_KINDS = ['entrada', 'salida_comida', 'regreso_comida', 'salida'];

function formatPunch(array $row): array {
    return [
        'kind' => $row['tipo'],
        'recorded_at' => (new DateTimeImmutable($row['registrado_en']))->format(DATE_ATOM),
        'latitude' => (float)$row['latitud'],
        'longitude' => (float)$row['longitud'],
        'accuracy' => (float)$row['precision_m'],
    ];
}

// Registros de hoy para el propio empleado: solo tipo y hora (la ubicación solo la ve el administrador).
function todayPunches(PDO $db, int $userId): array {
    $query = $db->prepare('SELECT tipo, registrado_en FROM registros WHERE usuario_id = ? AND fecha = ? ORDER BY registrado_en, id');
    $query->execute([$userId, date('Y-m-d')]);
    return array_map(fn(array $row) => [
        'kind' => $row['tipo'],
        'recorded_at' => (new DateTimeImmutable($row['registrado_en']))->format(DATE_ATOM),
    ], $query->fetchAll());
}

// Visitas de hoy para el propio representante, más una que haya quedado abierta otro día (para poder cerrarla).
// Solo médico y horas: la ubicación solo la ve el administrador.
function todayVisits(PDO $db, int $userId): array {
    $query = $db->prepare('SELECT id, medico, comentarios, inicio_en, fin_en FROM visitas WHERE usuario_id = ? AND (fecha = ? OR fin_en IS NULL) ORDER BY inicio_en, id');
    $query->execute([$userId, date('Y-m-d')]);
    $iso = fn(?string $v) => $v ? (new DateTimeImmutable($v))->format(DATE_ATOM) : null;
    return array_map(fn(array $v) => ['id' => (int)$v['id'], 'doctor' => $v['medico'], 'comments' => $v['comentarios'], 'started_at' => $iso($v['inicio_en']), 'ended_at' => $iso($v['fin_en'])], $query->fetchAll());
}

// Lee y valida la ubicación enviada por el navegador; corta la petición si no es válida.
function readLocation(array $body): array {
    foreach (['latitude', 'longitude', 'accuracy'] as $field) {
        if (!isset($body[$field]) || !is_numeric($body[$field]) || !is_finite((float)$body[$field])) respond(['error' => 'No se recibio una ubicacion valida.'], 422);
    }
    [$lat, $lng, $accuracy] = [(float)$body['latitude'], (float)$body['longitude'], (float)$body['accuracy']];
    if (abs($lat) > 90 || abs($lng) > 180 || $accuracy < 0 || $accuracy > 100000) respond(['error' => 'Coordenadas o precision no validas.'], 422);
    return [$lat, $lng, $accuracy];
}

// Devuelve null si el registro es válido en este momento, o el motivo del rechazo.
function punchError(string $kind, array $existing): ?string {
    $has = fn(string $k) => in_array($k, $existing, true);
    if ($has($kind)) return 'Este registro ya existe hoy.';
    if ($kind !== 'entrada' && !$has('entrada')) return 'Primero registra tu entrada.';
    if ($has('salida')) return 'Tu jornada de hoy ya terminó.';
    if ($kind === 'regreso_comida' && !$has('salida_comida')) return 'Primero registra tu salida a comida.';
    if ($kind === 'salida' && $has('salida_comida') && !$has('regreso_comida')) return 'Registra tu regreso de comida antes de salir.';
    if ($kind === 'salida_comida' && $has('regreso_comida')) return 'Ya registraste tu comida hoy.';
    return null;
}
