<?php
/*
 * Inicio de sesión.
 *  - Con contraseña: la valida, aplica límites de intentos y, si el rol lo requiere, pide un código por correo.
 *  - Sin contraseña (el empleado solo escribe su correo y da Enter): si la cuenta aún no tiene contraseña,
 *    se le envía un enlace de un solo uso para crearla. El mensaje es el mismo exista o no el correo,
 *    para que nadie pueda averiguar qué cuentas existen.
 */
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}
function login_error(string $message): void
{
    $_SESSION['error'] = $message;
    header('Location: login.php', true, 303);
    exit;
}
function login_notice(string $message): void
{
    $_SESSION['login_notice'] = $message;
    header('Location: login.php', true, 303);
    exit;
}
if (!valid_csrf()) {
    login_error('La sesión del formulario venció. Intenta de nuevo.');
}
$email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$_SESSION['email'] = substr($email, 0, 254);
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 1024) {
    login_error('Ingresa un correo válido.');
}
try {
    $db = database();
    $ipKey = 'ip:' . client_ip();
    // Dos frenos: 5 fallos por correo (adivinar la contraseña de una persona) y 20 por IP (probar muchas cuentas).
    if (throttle_blocked($db, $email, 5) || throttle_blocked($db, $ipKey, 20)) {
        audit('login_bloqueado', $email, null);
        login_error('Demasiados intentos. Espera 15 minutos antes de intentar de nuevo.');
    }
    $query = $db->prepare('SELECT id, nombre_usuario AS name, correo_usuario AS email, contrasena_usuario AS password, activo, rol FROM usuarios WHERE correo_usuario = ? LIMIT 2');
    $query->execute([$email]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    // Reject ambiguous accounts when duplicate emails exist.
    if ($query->fetch(PDO::FETCH_ASSOC)) {
        $user = false;
    }

    // ---- Solo correo (sin contraseña): primera vez → enlace para crear la contraseña ----
    if ($password === '') {
        $generic = 'Si tu correo está registrado y todavía no tienes contraseña, te enviamos un enlace para crearla (vale 24 horas). '
            . 'Revisa tu bandeja de entrada y la carpeta de spam. Si ya tienes contraseña, escríbela para entrar.';
        // Máximo 3 enlaces por correo y 20 solicitudes por IP cada hora (evita llenar buzones o abusar del correo).
        if (throttle_blocked($db, 'enlace:' . $email, 3, 3600) || throttle_blocked($db, 'enlace-ip:' . client_ip(), 20, 3600)) {
            login_notice($generic);
        }
        throttle_hit($db, 'enlace:' . $email, 3600);
        throttle_hit($db, 'enlace-ip:' . client_ip(), 3600);
        if ($user && (int)$user['activo'] === 1 && (string)$user['password'] === '') {
            try {
                $sent = password_link_send($db, ['id' => (int)$user['id'], 'name' => (string)$user['name'], 'email' => $user['email']], 'crear');
                audit($sent ? 'enlace_crear_enviado' : 'enlace_no_enviado', $email, null);
            } catch (Throwable $exception) {
                error_log('Enlace para crear contraseña: ' . $exception->getMessage());
            }
        } else {
            audit('enlace_solicitado', $email . ($user ? ' (ya tiene contraseña o está desactivada)' : ' (no registrado)'), null);
        }
        login_notice($generic);
    }

    $hash = $user ? (string)$user['password'] : '';
    // Cuentas sin contraseña: se compara contra un hash ficticio para tardar lo mismo y no revelar nada.
    $compareHash = $hash !== '' ? $hash : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $legacy = $user && $hash !== '' && !preg_match('/^\$(?:2[aby]\$|argon2)/', $hash);
    $verified = password_verify($password, $compareHash) && $hash !== '';
    if ($legacy) {
        $verified = hash_equals($hash, $password);
    }
    if (!$verified || !$user) {
        throttle_hit($db, $email);
        throttle_hit($db, $ipKey);
        audit('login_fallido', $email, null);
        login_error('El correo o la contraseña son incorrectos. Si es tu primera vez, deja la contraseña vacía y da Enter.');
    }
    // Cuenta desactivada por el superusuario: no entra (el aviso solo se da con la contraseña correcta).
    if ((int)$user['activo'] !== 1) {
        audit('login_desactivado', $email, null);
        login_error('Tu cuenta está desactivada. Contacta al área de sistemas.');
    }
    if ($legacy || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $query = $db->prepare('UPDATE usuarios SET contrasena_usuario = ? WHERE id = ? AND BINARY contrasena_usuario = ?');
        $query->execute([password_hash($password, PASSWORD_DEFAULT), $user['id'], $hash]);
        if ($query->rowCount() !== 1) {
            login_error('Los datos de acceso cambiaron. Intenta de nuevo.');
        }
    }
    $account = ['id' => (int)$user['id'], 'name' => (string) $user['name'], 'email' => $user['email'], 'rol' => (string)$user['rol']];

    // Soporte, supervisión y superusuario ven información de todos: además de la contraseña, se pide un código a su correo.
    if (role_requires_code($account['rol']) && access_code_enabled()) {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'last_activity' => time(), 'created' => time(),
            'pending_login' => ['user' => $account, 'started' => time()]];
        audit('contrasena_ok', 'Falta el código de acceso', $account);
        try {
            $sent = access_code_issue($db);
        } catch (InvalidArgumentException $exception) {
            unset($_SESSION['pending_login']);
            login_error($exception->getMessage());
        }
        if (!$sent) $_SESSION['code_error'] = 'No se pudo enviar el código. Espera un minuto y pulsa «Reenviar código».';
        header('Location: codigo.php', true, 303);
        exit;
    }

    throttle_clear($db, $email);
    login_complete($account);
    audit('login_ok');
    header('Location: ../../', true, 303); // la entrada de la intranet lleva a cada quien a su primera app
    exit;
} catch (Throwable $exception) {
    error_log('Tickets login: ' . $exception->getMessage());
    login_error('No se pudo iniciar sesión. Contacta al administrador.');
}
