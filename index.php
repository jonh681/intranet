<?php
// Entrada de la intranet: con sesión va a la primera app que puede ver (Tickets si la tiene; si no, Asistencia o
// Incidencias, ver comun/acceso.php); sin sesión, al inicio de sesión (o al selector de usuario en modo desarrollo).
require_once __DIR__ . '/tickets/php/bootstrap.php';
require_once __DIR__ . '/tickets/php/soporte_helpers.php';
require_once __DIR__ . '/comun/acceso.php';
require_once __DIR__ . '/asistencia/db.php'; // feature()
if (isset($_SESSION['user'])) {
    $u = intranet_usuario(database());
    $start = $u ? intranet_inicio($u, feature('vacaciones')) : null;
    if ($start === 'tickets/') $start = 'tickets/modulos/' . user_home();
    if ($start !== null) {
        header('Location: ' . $start, true, 303);
        exit;
    }
    // Sin ninguna app (cuenta desactivada): se cierra la sesión.
    $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'error' => 'Tu cuenta no tiene acceso a la intranet. Contacta al área de sistemas.'];
    session_regenerate_id(true);
}
header('Location: ' . (intranet_dev() ? 'dev.php' : 'tickets/modulos/login.php'), true, 303);
exit;
