<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
if (isset($_SESSION['user'])) {
    header('Location: ../../', true, 303); // la entrada de la intranet lleva a cada quien a su primera app
    exit;
}
if (!isset($_SESSION['pending_login'])) {
    $_SESSION['error'] = 'Tu inicio de sesión venció. Ingresa de nuevo tu correo y contraseña.';
    header('Location: login.php', true, 303);
    exit;
}
$pending = $_SESSION['pending_login'];
$error = $_SESSION['code_error'] ?? '';
$notice = '';
unset($_SESSION['code_error']);

function back_to_login(string $message): void
{
    unset($_SESSION['pending_login']);
    $_SESSION['error'] = $message;
    header('Location: login.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf()) back_to_login('El formulario venció. Inicia sesión de nuevo.');
    $action = $_POST['accion'] ?? '';
    try {
        $db = database();
        if ($action === 'cancelar') {
            back_to_login('Se canceló el inicio de sesión.');
        } elseif ($action === 'reenviar') {
            $wait = ACCESS_CODE_RESEND - (time() - ($pending['sent_at'] ?? 0));
            if ($wait > 0) {
                $error = 'Espera ' . $wait . ' segundos para pedir otro código.';
            } else {
                if (access_code_issue($db)) $notice = 'Te enviamos un código nuevo. El anterior ya no funciona.';
                else $error = 'No se pudo enviar el código. Intenta de nuevo en un minuto.';
            }
        } else {
            access_code_check(is_string($_POST['codigo'] ?? null) ? $_POST['codigo'] : '');
            $user = $pending['user'];
            throttle_clear($db, $user['email']);
            login_complete($user, true);
            audit('login_ok', 'Con código de acceso');
            header('Location: ../../', true, 303); // la entrada de la intranet lleva a cada quien a su primera app
            exit;
        }
    } catch (LengthException $exception) {
        // Demasiados códigos incorrectos: cuenta como intento fallido de la cuenta y obliga a empezar de nuevo.
        throttle_hit(database(), $pending['user']['email']);
        audit('codigo_fallido', 'Se agotaron los intentos', $pending['user']);
        back_to_login('Demasiados códigos incorrectos. Inicia sesión de nuevo para recibir otro código.');
    } catch (CodeThrottleException $exception) {
        // Se enviaron demasiados códigos: hay que esperar y empezar de nuevo.
        back_to_login($exception->getMessage());
    } catch (InvalidArgumentException $exception) {
        if ($action !== 'reenviar') audit('codigo_fallido', '', $pending['user']);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Código de acceso: ' . $exception->getMessage());
        $error = 'No se pudo completar la verificación. Intenta de nuevo.';
    }
    $pending = $_SESSION['pending_login'] ?? $pending;
}
// Muestra el correo parcialmente oculto: jo****@fedele.com.mx
[$local, $domain] = explode('@', $pending['user']['email'], 2) + [1 => ''];
$masked = mb_substr($local, 0, 2) . str_repeat('•', max(2, mb_strlen($local) - 2)) . '@' . $domain;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Código de acceso | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
    <main class="card w-full max-w-md p-8">
        <img src="../img/logo-mediano.png" alt="Fedele S.A. de C.V." width="320" height="219" class="mx-auto mb-6 w-24 mix-blend-multiply">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>
        </span>
        <h1 class="mt-4 text-center text-2xl font-bold tracking-tight text-slate-900">Verifica tu identidad</h1>
        <p class="mt-2 text-center text-sm text-slate-500">Enviamos un código de 6 dígitos a <strong class="text-slate-700"><?= escape($masked) ?></strong>. Vence en 10 minutos.</p>
        <?php if ($error): ?><div class="alert-error mt-6" role="alert"><?= escape($error) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert-success mt-6" role="status"><?= escape($notice) ?></div><?php endif; ?>
        <form method="post" class="mt-6 space-y-4">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <label for="codigo" class="field-label">Código de acceso</label>
            <input id="codigo" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus
                class="input py-3 text-center text-2xl font-semibold tracking-[0.5em] tabular-nums" placeholder="000000">
            <button type="submit" name="accion" value="verificar" class="btn btn-primary w-full py-3">Verificar y entrar</button>
        </form>
        <div class="mt-6 flex items-center justify-between gap-3 border-t border-slate-100 pt-5 text-sm">
            <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><button name="accion" value="reenviar" class="cursor-pointer font-medium text-brand-700 hover:text-brand-900">Reenviar código</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><button name="accion" value="cancelar" class="cursor-pointer text-slate-500 hover:text-slate-800" formnovalidate>Cancelar</button></form>
        </div>
        <p class="mt-5 text-center text-xs text-slate-400">Por seguridad, soporte y supervisión confirman su identidad con un código en cada inicio de sesión.</p>
    </main>
</body>
</html>
