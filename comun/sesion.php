<?php
declare(strict_types=1);

/*
 * Sesión única de la intranet: tickets y asistencia comparten la misma cookie y los mismos datos de sesión.
 * La identidad la pone siempre el inicio de sesión de tickets ($_SESSION['user'] y $_SESSION['mfa']).
 * $actividad = false: peticiones automáticas (comun/registrar_consumo.php) que no cuentan como actividad
 * y no deben impedir que la sesión expire por inactividad.
 */
function intranet_session_start(bool $actividad = true): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Nombre propio para no mezclarse con las apps sueltas (/tickets, /asistencia) en el mismo servidor.
    // Con HTTPS, el prefijo __Host- obliga al navegador a usar la cookie solo en este dominio, por HTTPS y en "/".
    session_name($https ? '__Host-intranet' : 'intranet_sid');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => '/']);
    session_start();

    $now = time();
    // Expira por 30 min sin actividad o 10 h desde el inicio de sesión, lo que ocurra primero.
    if ((isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > 1800)
        || (isset($_SESSION['created']) && $now - $_SESSION['created'] > 36000)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    // Un inicio de sesión a medias (esperando el código) solo dura 10 minutos.
    if (isset($_SESSION['pending_login']) && $now - $_SESSION['pending_login']['started'] > 600) {
        unset($_SESSION['pending_login']);
    }
    if ($actividad || !isset($_SESSION['last_activity'])) $_SESSION['last_activity'] = $now;
    $_SESSION['created'] = $_SESSION['created'] ?? $now;
    $_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
}
