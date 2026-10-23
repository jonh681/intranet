<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/seguridad.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Solicitud no válida.');
}
// En iPad/celular la pestaña puede quedar abierta con un token viejo (sesión expirada o cookie
// descartada por Safari). Si la sesión ya no existe, solo se vuelve al login; si el token no
// coincide, se acepta cuando la petición viene de este mismo sitio (encabezado Origin/Referer).
if (!isset($_SESSION['user'])) {
    header('Location: login.php', true, 303);
    exit;
}
if (!valid_csrf() && !same_origin_request()) {
    http_response_code(403);
    exit('Solicitud no válida.');
}
audit('logout');
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000, 'path' => $params['path'],
    'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
]);
session_destroy();
header('Location: login.php', true, 303);
exit;
