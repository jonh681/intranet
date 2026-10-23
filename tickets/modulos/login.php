<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
if (isset($_SESSION['user'])) {
    header('Location: ../../', true, 303); // ya tiene sesión: a su primera app
    exit;
}
// Modo desarrollo de la intranet: en lugar del login se elige el tipo de usuario (dev.php).
require_once __DIR__ . '/../../comun/acceso.php';
if (intranet_dev()) {
    header('Location: ../../dev.php');
    exit;
}
$error = $_SESSION['error'] ?? '';
$email = $_SESSION['email'] ?? '';
$notice = $_SESSION['login_notice'] ?? '';
unset($_SESSION['error'], $_SESSION['email'], $_SESSION['login_notice']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
</head>
<body class="grid lg:grid-cols-[1.1fr_1fr]">
    <aside class="relative hidden overflow-hidden bg-brand-800 lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_10%,var(--color-brand-500),transparent_55%),radial-gradient(circle_at_90%_95%,var(--color-brand-950),transparent_60%)]" aria-hidden="true"></div>
        <svg class="absolute -right-24 -bottom-24 size-[28rem] text-white/5" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="18" aria-hidden="true"><ellipse cx="100" cy="100" rx="88" ry="62" transform="rotate(-18 100 100)"/></svg>
        <p class="relative text-2xl font-bold tracking-tight text-white">Fedele <span class="text-sky-300">360</span></p>
        <div class="relative max-w-md">
            <h1 class="text-4xl leading-tight font-bold tracking-tight text-white xl:text-5xl">Todo tu trabajo, en un solo lugar.</h1>
            <p class="mt-5 text-lg text-brand-100">Soporte, asistencia e incidencias del equipo Fedele, en una sola cuenta.</p>
            <ul class="mt-10 space-y-4 text-brand-50">
                <?php foreach (['Reporta problemas y sigue cada ticket', 'Registra tu jornada y tus visitas', 'Solicita vacaciones, permisos e incapacidades'] as $feature): ?>
                <li class="flex items-center gap-3"><span class="grid size-7 place-items-center rounded-full bg-white/10 ring-1 ring-white/20"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><path d="m5 10.5 3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?= $feature ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <p class="relative text-sm text-brand-200">© <?= date('Y') ?> Fedele S.A. de C.V. <span class="text-brand-300/70">· Desarrollado por Ing. Jonathan Bautista</span></p>
    </aside>
    <main class="flex min-h-screen items-center justify-center bg-white px-6 py-12">
        <section class="w-full max-w-sm" aria-labelledby="login-title">
            <img src="../img/logo-mediano.png" alt="Fedele S.A. de C.V." width="320" height="219" class="mx-auto mb-8 w-36 mix-blend-multiply">
            <p class="eyebrow">Bienvenido de nuevo</p>
            <h2 id="login-title" class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Inicia sesión</h2>
            <p class="mt-2 text-sm text-slate-500">Ingresa tus datos para entrar a Fedele 360.</p>
            <?php if ($error): ?>
                <div class="alert-error mt-6" role="alert"><?= escape($error) ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="alert-success mt-6" role="status"><?= escape($notice) ?></div>
            <?php endif; ?>
            <form action="validacion.php" method="post" class="mt-8 space-y-5">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <div>
                    <label for="email" class="field-label">Correo electrónico</label>
                    <input id="email" name="email" type="email" class="input py-3" placeholder="nombre@fedele.com.mx" autocomplete="username" maxlength="254" value="<?= escape($email) ?>" required autofocus>
                </div>
                <div>
                    <label for="password" class="field-label">Contraseña</label>
                    <input id="password" name="password" type="password" class="input py-3" placeholder="Ingresa tu contraseña" autocomplete="current-password" maxlength="1024" aria-describedby="password-help">
                    <p class="help-text" id="password-help">¿Primera vez? Escribe solo tu correo y da Enter: te enviaremos un enlace para crear tu contraseña.</p>
                </div>
                <button type="submit" class="btn btn-primary w-full py-3">Iniciar sesión <span aria-hidden="true">→</span></button>
            </form>
            <p class="mt-10 border-t border-slate-100 pt-6 text-center text-xs text-slate-500">¿Olvidaste tu contraseña o necesitas acceso? Pide al administrador de sistemas que te envíe un enlace de recuperación a tu correo.</p>
        </section>
    </main>
</body>
</html>
