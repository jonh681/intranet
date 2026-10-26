<?php
// Consumo de datos (administración): lo que Fedele 360 descarga y envía en el dispositivo de cada persona, en todas
// las apps (tickets, asistencia e incidencias). Basado en asistencia v3. Datos: comun/consumo.js → consumo_datos.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ui.php';
$user = requireAdminPage($db);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc($_SESSION['csrf']) ?>">
    <title>Consumo de datos · Asistencia | Fedele 360</title>
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('admin-common.js') ?>" defer></script>
    <script src="<?= asset('consumo-reporte.js') ?>" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans antialiased flex flex-col" data-role="admin">
<?= adminHeader($user, 'consumo') ?>

<main class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 flex-1 flex flex-col gap-5">
    <section class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-xs font-semibold text-primary">Administración</div>
            <h1 class="text-2xl sm:text-3xl font-bold mt-1">Consumo de datos</h1>
            <p class="text-sm text-base-content/60 mt-1 max-w-2xl">Datos que Fedele 360 (tickets, asistencia e incidencias) descarga y envía en el dispositivo de cada persona, medidos por su navegador. El contador del celular puede marcar un poco más por la propia conexión.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="join" role="group" aria-label="Periodo">
                <button class="btn btn-sm join-item" type="button" data-range="0">Hoy</button>
                <button class="btn btn-sm join-item" type="button" data-range="6">7 días</button>
                <button class="btn btn-sm join-item" type="button" data-range="29">30 días</button>
                <button class="btn btn-sm join-item" type="button" data-range="month">Este mes</button>
            </div>
            <label class="input input-sm w-auto"><?= icon('calendar', 'size-4 opacity-50') ?><input type="date" id="from" aria-label="Desde"></label>
            <label class="input input-sm w-auto"><input type="date" id="to" aria-label="Hasta"></label>
        </div>
    </section>

    <div id="error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>

    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3" aria-label="Resumen">
<?php foreach ([
    ['total', 'Total del periodo', 'download'],
    ['average', 'Promedio por persona al día', 'users'],
    ['people', 'Personas que usaron la app', 'check'],
    ['top', 'Mayor consumo', 'chart'],
] as [$key, $label, $iconName]): ?>
        <div class="rounded-box bg-base-100 border border-base-300 shadow-sm p-4 min-w-0">
            <div class="flex items-center gap-2 text-xs text-base-content/60"><?= icon($iconName, 'size-4 text-base-content/40') ?><?= $label ?></div>
            <div class="text-2xl sm:text-3xl font-semibold tabular-nums mt-2 truncate" data-kpi="<?= $key ?>">—</div>
            <div class="text-xs text-base-content/50 mt-0.5 truncate" data-note="<?= $key ?>">&nbsp;</div>
        </div>
<?php endforeach; ?>
    </section>

    <!-- Consumo total por día -->
    <section class="card bg-base-100 border border-base-300 shadow-sm" aria-labelledby="daily-title">
        <div class="card-body p-5 gap-3">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="font-semibold" id="daily-title">Consumo total por día</h2>
                <span class="text-xs text-base-content/50" id="daily-max"></span>
            </div>
            <div class="relative" id="daily">
                <div class="flex items-end gap-0.5 h-40 border-b border-base-300" id="daily-bars"></div>
                <div class="flex justify-between text-[11px] text-base-content/50 mt-1.5 tabular-nums" id="daily-axis"></div>
                <div class="pointer-events-none absolute z-10 rounded-field bg-neutral text-neutral-content text-xs px-2.5 py-1.5 shadow-lg whitespace-nowrap" id="daily-tip" hidden></div>
            </div>
        </div>
    </section>

    <!-- Por persona (10 por página: comun/acciones.js) -->
    <section class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden" aria-labelledby="people-title">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 pb-3">
            <h2 class="font-semibold flex items-center gap-2" id="people-title"><?= icon('users', 'size-5 text-base-content/45') ?>Por persona</h2>
            <label class="input input-sm w-full sm:w-64"><?= icon('search', 'size-4 opacity-50') ?><input type="search" id="search" placeholder="Buscar persona o área" autocomplete="off"></label>
        </div>
        <div class="hidden md:block">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr class="text-base-content/55">
                        <th>Persona</th><th>Dispositivo</th><th class="w-56">Total</th><th class="text-right">Por día con uso</th><th class="text-right">Aperturas</th><th class="text-right">Con datos celulares</th>
                    </tr></thead>
                    <tbody id="table" data-paginar></tbody>
                </table>
            </div>
        </div>
        <div class="md:hidden"><ul class="divide-y divide-base-300 border-t border-base-300" id="cards" data-paginar></ul></div>
        <p class="px-5 py-8 text-center text-sm text-base-content/50" id="empty">Cargando…</p>
        <p class="px-5 pb-5 text-xs text-base-content/50">"Con datos celulares" solo lo informan Chrome y otros navegadores en Android; en iPhone se ve el total sin distinguir Wi-Fi o datos.</p>
    </section>
</main>
</body>
</html>
