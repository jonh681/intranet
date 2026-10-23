<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ui.php';
$user = currentUser($db);
// Sin sesión (o sin acceso a asistencia): se entra por la intranet, que decide a dónde llevarlo.
if (!$user) {
    header('Location: ../', true, 303);
    exit;
}
// Supervisión y superusuario no registran asistencia: entran directo a la administración.
// Un gerente de oficina tampoco registra jornada: entra a Mi equipo.
if ($user['rol'] === 'admin' || !$user['registra']) {
    header('Location: ' . ($user['rol'] === 'admin' ? 'admin.php' : 'equipo.php'), true, 303);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc($_SESSION['csrf']) ?>">
    <title>Mi jornada · Asistencia | Fedele 360</title>
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('app.js') ?>" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans antialiased flex flex-col" data-authenticated="<?= $user ? '1' : '0' ?>" data-rep="<?= $user && $user['representante'] ? '1' : '0' ?>">
<?= userHeader($user, 'jornada') ?>
<main class="w-full max-w-5xl mx-auto px-4 sm:px-8 py-8 sm:py-12 flex-1">
<?php if (!$user): ?>
    <section class="card bg-base-100 shadow-xl shadow-base-300/40 border border-base-300 max-w-md mx-auto animate-pop-in">
        <div class="card-body p-6 sm:p-9">
            <div class="text-xs font-semibold tracking-widest text-primary">TU ESPACIO DE TRABAJO</div>
            <h1 class="text-3xl font-bold mt-1">Bienvenido de nuevo.</h1>
            <p class="text-base-content/60">Inicia sesión para registrar tu jornada.</p>
            <form id="auth-form" class="mt-5 flex flex-col gap-4">
                <label class="w-full">
                    <span class="text-sm font-medium block mb-1.5">Correo electrónico</span>
                    <input class="input w-full" id="email" name="email" type="email" autocomplete="username" maxlength="254" required placeholder="nombre@empresa.com">
                </label>
                <label class="w-full">
                    <span class="text-sm font-medium block mb-1.5">Contraseña</span>
                    <input class="input w-full" id="password" name="password" type="password" autocomplete="current-password" required maxlength="72">
                </label>
                <div id="auth-error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>
                <button class="btn btn-primary w-full mt-2" id="auth-submit" type="submit"><span class="js-text">Iniciar sesión</span><?= icon('log-in', 'size-4') ?></button>
            </form>
            <div class="divider my-1"></div>
            <p class="flex gap-2 text-xs text-base-content/55 leading-relaxed"><?= icon('shield', 'size-4 shrink-0 mt-0.5') ?>¿No tienes cuenta o olvidaste tu contraseña? Solicítala al área de administración.</p>
        </div>
    </section>
<?php else: ?>
    <div id="attendance" class="group/att grid gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] items-start" data-step="loading">
        <section class="relative overflow-hidden rounded-box bg-neutral text-neutral-content shadow-xl shadow-neutral/20 p-5 sm:p-8 animate-pop-in" aria-labelledby="attendance-title">
            <div class="pointer-events-none absolute -right-24 -top-24 size-72 rounded-full bg-accent/25 blur-3xl" aria-hidden="true"></div>
            <div class="relative flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] font-semibold tracking-widest text-neutral-content/60" id="today-label">HOY</div>
                    <h1 class="text-2xl sm:text-3xl font-bold mt-1 break-words" id="attendance-title">Hola, <?= esc($user['name']) ?>.</h1>
                </div>
                <span class="badge badge-ghost gap-1.5" id="day-status"><span class="status status-neutral"></span><span class="js-text">Consultando…</span></span>
            </div>
            <div class="relative mt-6 sm:mt-8 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <div>
                    <span class="block text-5xl sm:text-6xl font-light tabular-nums tracking-tight leading-none" id="clock">--:--:--</span>
                    <span class="flex items-center gap-1.5 mt-2 text-xs text-neutral-content/55"><?= icon('clock', 'size-3.5') ?>Ciudad de México</span>
                </div>
                <div class="text-right">
                    <span class="block text-[11px] tracking-widest text-neutral-content/55">TIEMPO EN JORNADA</span>
                    <span class="block text-xl font-semibold tabular-nums" id="worked">—</span>
                </div>
            </div>
            <div class="relative mt-6 sm:mt-8 grid md:grid-cols-2 gap-3">
<?php foreach ([
    ['entrada', 'Entrada', 'log-in', 'loading none'],
    ['salida_comida', 'Salida a comida', 'utensils', 'in'],
    ['regreso_comida', 'Regreso de comida', 'utensils', 'lunch'],
    ['salida', 'Salida', 'log-out', 'in back done'],
] as [$kind, $title, $iconName, $show]): ?>
                <div class="rounded-box bg-white/[.06] border border-white/10 p-4 sm:p-5" data-show="<?= $show ?>">
                    <div class="flex items-center justify-between text-xs text-neutral-content/65"><span class="flex items-center gap-2 tracking-wider uppercase"><?= icon($iconName, 'size-4 text-accent') ?><?= $title ?></span><span class="tabular-nums text-base font-semibold text-neutral-content" data-time="<?= $kind ?>">--:--</span></div>
                    <button class="btn btn-lg w-full mt-4 border-0 bg-white text-neutral hover:bg-secondary disabled:bg-white/10! disabled:text-white/55!" data-punch="<?= $kind ?>" type="button" disabled><span class="js-text">Registrar <?= mb_strtolower($title) ?></span><?= icon($iconName, 'size-5') ?></button>
                </div>
<?php endforeach; ?>
            </div>
<?php if ($user['representante']): ?>
            <!-- Representante médico: visitas (visible desde la entrada hasta la salida). -->
            <div class="relative mt-3" id="visit-bar" hidden>
                <button class="btn btn-lg w-full gap-2 border border-white/25 bg-white/[.08] text-neutral-content hover:bg-white/15" id="visit-open" type="button"><?= icon('stethoscope', 'size-5') ?>Registrar visita</button>
                <div class="rounded-box bg-accent/20 border border-accent/40 p-4" id="visit-live" hidden>
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2 text-[11px] font-semibold tracking-widest text-neutral-content/70"><span class="status status-info animate-pulse"></span>VISITA EN CURSO</span>
                        <span class="text-2xl font-light tabular-nums" id="visit-live-timer">00:00:00</span>
                    </div>
                    <div class="flex items-center justify-between gap-3 mt-2">
                        <span class="font-semibold truncate min-w-0" id="visit-live-doctor"></span>
                        <button class="btn btn-sm shrink-0 border-0 bg-white text-neutral hover:bg-secondary" id="visit-live-end" type="button">Finalizar visita</button>
                    </div>
                </div>
            </div>
<?php endif; ?>
            <div class="relative flex items-center gap-2 mt-4 text-xs text-neutral-content/60" id="punch-progress" hidden>
                <span class="loading loading-dots loading-xs"></span><span>Registrando…</span>
            </div>
        </section>

        <section class="card bg-base-100 border border-base-300 shadow-sm animate-pop-in [animation-delay:80ms] max-lg:order-2" aria-labelledby="records-title">
            <div class="card-body p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold flex items-center gap-2" id="records-title"><?= icon('list', 'size-5 text-base-content/45') ?>Registros de hoy</h2>
                    <span class="badge badge-ghost badge-sm" id="record-count">0 registros</span>
                </div>
                <ul class="mt-4" id="records-list" aria-live="polite"><li class="py-6 text-center text-base-content/50 text-sm">Consultando tus registros…</li></ul>
            </div>
        </section>

<?php if ($user['representante']): ?>
        <section class="card bg-base-100 border border-base-300 shadow-sm animate-pop-in [animation-delay:120ms] max-lg:order-3 lg:col-start-2" aria-labelledby="visits-title">
            <div class="card-body p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold flex items-center gap-2" id="visits-title"><?= icon('stethoscope', 'size-5 text-base-content/45') ?>Visitas de hoy</h2>
                    <span class="badge badge-ghost badge-sm" id="visit-count">0 visitas</span>
                </div>
                <ul class="mt-4 flex flex-col gap-2" id="visits-list" aria-live="polite"></ul>
            </div>
        </section>
<?php endif; ?>

        <div id="punch-message" class="alert alert-success alert-soft text-sm animate-pop-in max-lg:order-1 lg:col-span-2" role="status" aria-live="polite" hidden></div>
    </div>

    <!-- Confirmación antes de registrar la salida (fin de la jornada). -->
    <dialog id="exit-confirm" class="modal modal-middle">
        <div class="modal-box max-w-sm p-0 overflow-hidden text-center border-t-8 border-warning">
            <div class="px-6 pt-7 pb-5 flex flex-col items-center gap-3">
                <span class="grid place-items-center size-20 rounded-full bg-warning/20 text-warning ring-8 ring-warning/10 animate-pulse"><?= icon('log-out', 'size-10') ?></span>
                <h3 class="text-2xl font-bold">¿Terminar tu jornada?</h3>
                <p class="text-base-content/70">Vas a registrar tu <b>salida de labores</b> a las <b class="tabular-nums" id="exit-time">--:--</b>.</p>
                <div class="w-full rounded-box bg-base-200 px-4 py-3">
                    <div class="text-[11px] font-semibold tracking-widest text-base-content/50">TIEMPO TRABAJADO HOY</div>
                    <div class="text-2xl font-bold tabular-nums mt-0.5" id="exit-worked">—</div>
                </div>
                <p class="text-sm text-error font-medium flex items-start gap-1.5 text-left"><?= icon('circle-alert', 'size-4 shrink-0 mt-0.5') ?>Después de registrarla ya no podrás registrar más movimientos hoy.</p>
            </div>
            <div class="grid grid-cols-2 gap-2 px-6 pb-6">
                <form method="dialog"><button class="btn btn-ghost border-base-300 w-full" id="exit-cancel">No, sigo trabajando</button></form>
                <button class="btn btn-error w-full" type="button" id="exit-ok">Sí, terminar</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
    </dialog>

    <!-- Resultado de cada registro: confirmación (verde) o error (rojo). -->
    <dialog id="result-modal" class="modal modal-middle">
        <div class="modal-box max-w-sm p-0 overflow-hidden text-center border-t-8" id="result-box">
            <div class="px-6 pt-8 pb-6 flex flex-col items-center gap-3">
                <span class="grid place-items-center size-24 rounded-full ring-8 animate-pop-in" id="result-icon">
                    <span id="result-ok-icon"><?= icon('check', 'size-12') ?></span>
                    <span id="result-error-icon" hidden><?= icon('x', 'size-12') ?></span>
                </span>
                <h3 class="text-2xl font-bold" id="result-title"></h3>
                <div class="text-5xl font-light tabular-nums tracking-tight" id="result-time" hidden></div>
                <p class="text-base-content/70" id="result-text"></p>
            </div>
            <div class="px-6 pb-6">
                <form method="dialog"><button class="btn btn-lg w-full" id="result-close">Entendido</button></form>
                <div class="h-1 rounded-full bg-base-300 overflow-hidden mt-3" id="result-timer" hidden><div class="h-full bg-success w-full origin-left transition-transform ease-linear" id="result-timer-bar"></div></div>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
    </dialog>
<?php if ($user['representante']): ?>
    <dialog id="visit-modal" class="modal modal-bottom sm:modal-middle">
        <div class="modal-box w-full sm:max-w-md p-0 overflow-hidden">
            <div class="flex items-center justify-between gap-3 bg-neutral text-neutral-content px-5 sm:px-6 py-5">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center size-10 rounded-full bg-white/10"><?= icon('stethoscope', 'size-5') ?></span>
                    <h3 class="text-lg font-bold">Registrar visita</h3>
                </div>
                <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-neutral-content" aria-label="Cerrar"><?= icon('x', 'size-4') ?></button></form>
            </div>
            <div class="p-5 sm:p-6 flex flex-col gap-4">
                <label class="w-full">
                    <span class="text-sm font-medium block mb-1.5">Nombre del médico</span>
                    <input class="input input-lg w-full" id="visit-doctor" maxlength="150" autocomplete="off" placeholder="Ej. Dra. Laura Méndez">
                </label>
                <label class="w-full">
                    <span class="text-sm font-medium flex flex-wrap items-baseline justify-between gap-x-2 mb-1.5">Comentarios <span class="text-xs font-normal text-base-content/50">Opcional · puedes completarlos antes de finalizar</span></span>
                    <textarea class="textarea w-full" id="visit-comments" rows="3" maxlength="2000" placeholder="Temas tratados, muestras entregadas, acuerdos…"></textarea>
                </label>
                <div class="rounded-box bg-base-200 p-5 text-center">
                    <div class="text-[11px] font-semibold tracking-widest text-base-content/50" id="visit-state">LISTO PARA INICIAR</div>
                    <div class="text-5xl font-light tabular-nums mt-1" id="visit-timer">00:00:00</div>
                    <div class="text-sm text-base-content/60 mt-1 min-h-5" id="visit-times"></div>
                </div>
                <div id="visit-error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>
                <div class="flex items-center gap-2 text-xs text-base-content/60" id="visit-progress" hidden><span class="loading loading-dots loading-xs"></span><span>Registrando…</span></div>
                <div class="grid grid-cols-2 gap-2">
                    <button class="btn btn-primary btn-lg" id="visit-start" type="button">Inicio de visita</button>
                    <button class="btn btn-lg btn-outline btn-primary" id="visit-end" type="button" disabled>Fin de visita</button>
                </div>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
    </dialog>

    <!-- Confirmación antes de finalizar una visita. -->
    <dialog id="visit-end-confirm" class="modal modal-middle">
        <div class="modal-box max-w-sm p-0 overflow-hidden text-center border-t-8 border-warning">
            <div class="px-6 pt-7 pb-5 flex flex-col items-center gap-3">
                <span class="grid place-items-center size-20 rounded-full bg-warning/20 text-warning ring-8 ring-warning/10 animate-pulse"><?= icon('stethoscope', 'size-10') ?></span>
                <h3 class="text-2xl font-bold">¿Finalizar la visita?</h3>
                <p class="text-base-content/70">Vas a registrar el <b>fin de tu visita</b> con <b id="visit-end-doctor"></b> a las <b class="tabular-nums" id="visit-end-time">--:--</b>.</p>
                <div class="w-full rounded-box bg-base-200 px-4 py-3">
                    <div class="text-[11px] font-semibold tracking-widest text-base-content/50">DURACIÓN DE LA VISITA</div>
                    <div class="text-2xl font-bold tabular-nums mt-0.5" id="visit-end-duration">—</div>
                </div>
                <p class="text-sm text-error font-medium flex items-start gap-1.5 text-left"><?= icon('circle-alert', 'size-4 shrink-0 mt-0.5') ?>Una vez finalizada ya no podrás modificarla.</p>
            </div>
            <div class="grid grid-cols-2 gap-2 px-6 pb-6">
                <form method="dialog"><button class="btn btn-ghost border-base-300 w-full" id="visit-end-cancel">No, sigo en la visita</button></form>
                <button class="btn btn-error w-full" type="button" id="visit-end-ok">Sí, finalizar</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
    </dialog>
<?php endif; ?>
<?php endif; ?>
</main>
<footer class="footer sm:footer-horizontal justify-between items-center px-4 sm:px-[6%] py-5 border-t border-base-300 text-xs text-base-content/50">
    <span class="font-semibold tracking-widest">ASISTENCIA</span>
    <span>Un día a la vez.</span>
</footer>
</body>
</html>
