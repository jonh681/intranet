<?php
// Suma lo que reporta comun/consumo.js: datos que usó Fedele 360 en el dispositivo de cada persona, por día
// (tabla consumo_datos; reporte en asistencia/consumo.php). Basado en la acción 'usage' de asistencia v3.
declare(strict_types=1);
// El reporte es automático (cada 10 minutos con la página abierta): no cuenta como actividad de la persona.
const INTRANET_SIN_ACTIVIDAD = true;
require_once __DIR__ . '/../tickets/php/bootstrap.php';
require_once __DIR__ . '/acceso.php';

header('Content-Type: application/json; charset=utf-8');
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$userId || !hash_equals((string)$_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    exit('{"ok":false}');
}
$body = json_decode((string)file_get_contents('php://input', false, null, 0, 2000), true);
$number = fn(string $key, int $max): int => max(0, min($max, (int)($body[$key] ?? 0)));
$bytes = $number('bytes', 200 * 1024 * 1024);
$cellular = min($bytes, $number('cellular', 200 * 1024 * 1024));
$loads = $number('loads', 100);
$requests = $number('requests', 10000);
if ($bytes || $loads || $requests) {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    // iPadOS 13+ se presenta como Mac: consumo.js avisa si la pantalla es táctil.
    $touch = !empty($body['touch']);
    $device = match (true) {
        (bool)preg_match('/iPhone/', $ua) => 'iPhone', (bool)preg_match('/iPad/', $ua) => 'iPad',
        (bool)preg_match('/Android/', $ua) => 'Android', (bool)preg_match('/Windows/', $ua) => 'Windows',
        (bool)preg_match('/Macintosh/', $ua) => $touch ? 'iPad' : 'Mac', default => 'Otro',
    } . ' · ' . match (true) {
        (bool)preg_match('/Edg(A|iOS)?\//', $ua) => 'Edge', (bool)preg_match('/OPR\/|OPiOS|Opera/', $ua) => 'Opera',
        (bool)preg_match('/SamsungBrowser\//', $ua) => 'Samsung Internet', (bool)preg_match('/CriOS|Chrome\//', $ua) => 'Chrome',
        (bool)preg_match('/FxiOS|Firefox\//', $ua) => 'Firefox', (bool)preg_match('/Safari\//', $ua) => 'Safari', default => 'Navegador',
    };
    try {
        $db = database();
        // Como en el resto de la intranet: una cuenta desactivada deja de registrarse aunque su sesión siga viva.
        $user = intranet_usuario($db);
        if (!$user || (int)$user['activo'] !== 1) {
            http_response_code(403);
            exit('{"ok":false}');
        }
        $db->prepare('INSERT INTO consumo_datos (usuario_id, fecha, bytes, bytes_celular, aperturas, solicitudes, dispositivo) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE bytes = bytes + VALUES(bytes), bytes_celular = bytes_celular + VALUES(bytes_celular),
            aperturas = aperturas + VALUES(aperturas), solicitudes = solicitudes + VALUES(solicitudes), dispositivo = VALUES(dispositivo)')
            ->execute([$userId, date('Y-m-d'), $bytes, $cellular, $loads, $requests, $device]);
    } catch (Throwable $e) {
        // Sin la tabla (falta instalar/migraciones.php) no se mide, pero nada más falla.
        error_log('Consumo de datos: ' . $e->getMessage());
    }
}
echo '{"ok":true}';
