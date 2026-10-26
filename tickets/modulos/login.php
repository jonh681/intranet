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
<body class="grid lg:grid-cols-[1.15fr_1fr]">
    <aside class="hidden bg-[linear-gradient(160deg,#eef3ff_0%,#f7f9fc_55%,#e6eeff_100%)] text-ink lg:flex lg:flex-col lg:justify-between lg:border-r lg:border-slate-200 lg:p-14 xl:p-20">
        <p class="flex items-center gap-3"><img src="../img/logo-chico.png" alt="Fedele" width="160" height="109" class="h-11 w-auto">
            <span class="border-l border-slate-300 pl-3 text-xl leading-none font-bold tracking-tight text-ink">Fedele <b class="bg-[linear-gradient(135deg,#1f52c4_10%,#0ea5e9_90%)] bg-clip-text font-extrabold text-transparent">360</b></span></p>
        <div class="max-w-xl">
            <h1 class="text-5xl leading-[1.05] font-semibold tracking-tight xl:text-6xl">Todo tu trabajo, en un solo lugar.</h1>
            <dl class="mt-12 text-[15px]">
                <?php foreach (['Soporte' => 'Reporta un problema y sigue su atención', 'Asistencia' => 'Registra tu jornada y tus visitas', 'Incidencias' => 'Vacaciones, permisos e incapacidades', 'Cotizaciones' => 'Precios, cotizaciones y pedidos'] as $app => $what): ?>
                <div class="grid grid-cols-[8rem_1fr] gap-4 border-b border-ink/10 py-3.5"><dt class="font-semibold text-brand-700"><?= $app ?></dt><dd class="text-slate-600"><?= $what ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </div>
        <p class="text-sm text-slate-500">© <?= date('Y') ?> Fedele S.A. de C.V. Desarrollado por Ing. Jonathan Bautista.</p>
    </aside>
    <main class="flex min-h-screen items-center justify-center bg-white px-6 py-12">
        <section class="w-full max-w-sm" aria-labelledby="login-title">
            <img src="../img/logo-mediano.png" alt="Fedele S.A. de C.V." width="320" height="219" class="mb-10 w-32 lg:hidden">
            <h2 id="login-title" class="text-4xl font-semibold tracking-tight text-ink">Inicia sesión</h2>
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
                <button type="submit" class="btn btn-primary w-full py-3">Iniciar sesión</button>
            </form>
            <p class="mt-10 border-t border-slate-200 pt-6 text-xs text-slate-500">¿Olvidaste tu contraseña o necesitas acceso? Pide al administrador de sistemas que te envíe un enlace de recuperación a tu correo.</p>
        </section>
    </main>
</body>
</html>
