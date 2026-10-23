<?php
/*
 * Pantalla para CREAR (primera vez) o RESTABLECER la contraseña desde el enlace enviado por correo.
 * El enlace trae un token de un solo uso; en la base solo existe su hash (tabla password_resets).
 * Al guardar: se actualiza la contraseña (bcrypt), se invalida el enlace y se redirige al login.
 */
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/seguridad.php';

$token = is_string($_POST['token'] ?? null) ? $_POST['token'] : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$error = '';
$link = null;
try {
    $db = database();
    $link = password_link_find($db, $token);
    if ($link && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!valid_csrf()) throw new InvalidArgumentException('El formulario venció. Vuelve a intentarlo.');
        $password = is_string($_POST['contrasena'] ?? null) ? $_POST['contrasena'] : '';
        $confirm = is_string($_POST['confirmacion'] ?? null) ? $_POST['confirmacion'] : '';
        if (mb_strlen($password) < PASSWORD_MIN || strlen($password) > PASSWORD_MAX) throw new InvalidArgumentException('La contraseña debe tener entre ' . PASSWORD_MIN . ' y ' . PASSWORD_MAX . ' caracteres.');
        if (!hash_equals($password, $confirm)) throw new InvalidArgumentException('Las dos contraseñas no coinciden.');
        // Solo con un usuario de correo de 4 letras o más: uno corto ('a', 'jl') rechazaría casi cualquier contraseña.
        $local = explode('@', (string)$link['email'], 2)[0];
        if (mb_strlen($local) >= 4 && mb_stripos($password, $local) !== false) throw new InvalidArgumentException('La contraseña no debe contener tu correo.');

        $db->beginTransaction();
        $now = date('Y-m-d H:i:s');
        // Marca el enlace como usado solo si sigue vigente (evita que dos pestañas lo usen a la vez).
        $query = $db->prepare('UPDATE password_resets SET usado_en = ? WHERE id = ? AND usado_en IS NULL');
        $query->execute([$now, $link['reset_id']]);
        if ($query->rowCount() !== 1) {
            $db->rollBack();
            throw new InvalidArgumentException('Este enlace ya se usó. Pide uno nuevo.');
        }
        // La contraseña nueva cierra cualquier otra sesión abierta de la cuenta (intranet_sesion_vigente).
        $db->prepare('UPDATE usuarios SET contrasena_usuario = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $link['id']]);
        $db->prepare('UPDATE password_resets SET usado_en = ? WHERE usuario_id = ? AND usado_en IS NULL')->execute([$now, $link['id']]);
        $db->commit();
        throttle_clear($db, strtolower($link['email']));
        audit($link['tipo'] === 'crear' ? 'contrasena_creada' : 'contrasena_restablecida', '', ['id' => $link['id'], 'email' => $link['email']]);
        // Cualquier sesión de este navegador se reinicia y se envía al login con un aviso.
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'email' => $link['email'],
            'login_notice' => 'Tu contraseña se guardó correctamente. Ya puedes iniciar sesión con ella.'];
        session_regenerate_id(true);
        header('Location: login.php', true, 303);
        exit;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Restablecer contraseña: ' . $exception->getMessage());
    $error = 'No se pudo guardar la contraseña. Intenta de nuevo.';
}
$create = ($link['tipo'] ?? '') === 'crear';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $create ? 'Crear contraseña' : 'Restablecer contraseña' ?> | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
    <main class="card w-full max-w-md p-8">
        <img src="../img/logo-mediano.png" alt="Fedele S.A. de C.V." width="320" height="219" class="mx-auto mb-6 w-24 mix-blend-multiply">
        <?php if (!$link): ?>
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-amber-100 text-amber-700" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01" stroke-linecap="round"/></svg>
            </span>
            <h1 class="mt-4 text-center text-2xl font-bold tracking-tight text-slate-900">Enlace no válido</h1>
            <p class="mt-2 text-center text-sm text-slate-500">El enlace venció, ya se usó o no es correcto. Si es tu primera vez, escribe tu correo en el inicio de sesión y da Enter para recibir uno nuevo. Si olvidaste tu contraseña, pídele al administrador de sistemas un enlace de recuperación.</p>
            <a href="login.php" class="btn btn-primary mt-6 w-full py-3">Ir al inicio de sesión</a>
        <?php else: ?>
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2 2M14 9l2 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h1 class="mt-4 text-center text-2xl font-bold tracking-tight text-slate-900"><?= $create ? 'Crea tu contraseña' : 'Escribe tu nueva contraseña' ?></h1>
            <p class="mt-2 text-center text-sm text-slate-500">Para la cuenta <strong class="text-slate-700"><?= escape($link['email']) ?></strong>.</p>
            <?php if ($error): ?><div class="alert-error mt-6" role="alert"><?= escape($error) ?></div><?php endif; ?>
            <form method="post" class="mt-6 space-y-4" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="token" value="<?= escape($token) ?>">
                <!-- Ayuda a los gestores de contraseñas a guardarla con el correo correcto. -->
                <input type="email" name="usuario" value="<?= escape($link['email']) ?>" autocomplete="username" class="sr-only" tabindex="-1" aria-hidden="true" readonly>
                <div>
                    <label for="contrasena" class="field-label">Nueva contraseña</label>
                    <input id="contrasena" name="contrasena" type="password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" required autofocus autocomplete="new-password" class="input py-3" aria-describedby="reglas">
                    <p id="reglas" class="help-text">Mínimo <?= PASSWORD_MIN ?> caracteres. Usa una frase fácil de recordar para ti, por ejemplo «MiCafe-8am».</p>
                </div>
                <div>
                    <label for="confirmacion" class="field-label">Confirma la contraseña</label>
                    <input id="confirmacion" name="confirmacion" type="password" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" required autocomplete="new-password" class="input py-3">
                </div>
                <button type="submit" class="btn btn-primary w-full py-3"><?= $create ? 'Crear contraseña' : 'Guardar contraseña' ?></button>
            </form>
            <p class="mt-5 text-center text-xs text-slate-400">Al guardarla te llevaremos al inicio de sesión. El enlace solo funciona una vez.</p>
        <?php endif; ?>
    </main>
</body>
</html>
