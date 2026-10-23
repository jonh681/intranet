<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

if (PHP_SAPI !== 'cli') {
    // Nunca mostrar errores internos (rutas, consultas) al navegador; solo al log del servidor.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    header_remove('X-Powered-By');

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // Sesión compartida con asistencia (comun/sesion.php): mismo inicio de sesión para toda la intranet.
    require_once __DIR__ . '/../../comun/sesion.php';
    // INTRANET_SIN_ACTIVIDAD: lo define comun/registrar_consumo.php para no renovar la inactividad.
    intranet_session_start(!defined('INTRANET_SIN_ACTIVIDAD'));

    // Menos datos (asistencia v3): PHP comprime páginas y respuestas de la API. Los archivos (evidencias, PDF) no, porque
    // mandan su tamaño exacto. Las respuestas que mandan correos después (comun/segundo_plano.php) salen sin comprimir.
    if (extension_loaded('zlib') && !headers_sent() && !ini_get('zlib.output_compression') && !in_array('ob_gzhandler', ob_list_handlers(), true)
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['archivo.php', 'evidencia.php', 'formato.php'], true)) {
        ob_start('ob_gzhandler');
    }
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    if ($https) header('Strict-Transport-Security: max-age=31536000');
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Conexión única por petición. Las tablas se crean con instalar/*.sql, no aquí (el usuario de la BD no necesita CREATE). */
function database(): PDO
{
    static $db = null;
    if ($db === null) {
        require_once __DIR__ . '/conexion.php';
        $db = getConexion();
    }
    return $db;
}

function valid_csrf(): bool
{
    return is_string($_POST['csrf'] ?? null)
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/** True when the browser reports that the request comes from this same host (Origin, or Referer as fallback). */
function same_origin_request(): bool
{
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host = is_string($source) ? parse_url($source, PHP_URL_HOST) : null;
    $own = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    return is_string($host) && is_string($own) && $own !== '' && strcasecmp($host, $own) === 0;
}
