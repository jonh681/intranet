<?php
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
    <title>Visitas · Asistencia | Fedele 360</title>
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.min.css">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('vendor/leaflet/leaflet.min.js') ?>" defer></script>
    <script src="<?= asset('admin-common.js') ?>" defer></script>
    <script src="<?= asset('visitas.js') ?>" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans antialiased flex flex-col" data-role="<?= esc($user['rol']) ?>">
<?= adminHeader($user, 'visitas') ?>

<main class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 flex-1 flex flex-col gap-5">
    <section class="relative overflow-hidden rounded-box bg-neutral text-neutral-content shadow-xl shadow-neutral/20 p-5 sm:p-7 animate-pop-in">
        <div class="pointer-events-none absolute -right-20 -top-28 size-80 rounded-full bg-warning/20 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="text-[11px] font-semibold tracking-widest text-neutral-content/60">VISITAS MÉDICAS</div>
                <h1 class="text-2xl sm:text-3xl font-bold mt-1" id="range-title">Hoy</h1>
            </div>
            <span class="badge badge-ghost gap-1.5" id="open-badge" hidden><span class="status status-info animate-pulse"></span><span data-kpi="open">0</span> en curso</span>
        </div>
        <div class="relative mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
<?php foreach ([
    ['visits', 'Visitas', 'stethoscope', 'kpi-visits-note'],
    ['doctors', 'Médicos distintos', 'users', 'kpi-doctors-note'],
    ['time', 'Tiempo en visitas', 'timer', 'kpi-time-note'],
    ['average', 'Promedio por visita', 'chart', 'kpi-average-note'],
] as [$key, $label, $iconName, $note]): ?>
            <div class="rounded-box bg-white/[.06] border border-white/10 p-4">
                <div class="flex items-center gap-2 text-xs text-neutral-content/65"><?= icon($iconName, 'size-4 text-warning') ?><?= $label ?></div>
                <div class="text-2xl sm:text-3xl font-semibold tabular-nums mt-2" data-kpi="<?= $key ?>">—</div>
                <div class="text-xs text-neutral-content/50 mt-0.5 truncate" id="<?= $note ?>">&nbsp;</div>
            </div>
<?php endforeach; ?>
        </div>
    </section>

    <?= adminFilters('Buscar por médico o representante', 'Todos los representantes') ?>

    <div id="error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>

    <section class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden" aria-labelledby="visits-title">
        <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
            <h2 class="font-semibold flex items-center gap-2" id="visits-title"><?= icon('stethoscope', 'size-5 text-base-content/45') ?>Visitas</h2>
            <span class="badge badge-ghost badge-sm" id="visits-count">0</span>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="table">
                <thead><tr class="text-base-content/55">
                    <th>Representante</th><th>Fecha</th><th>Médico</th><th>Inicio</th><th>Fin</th><th class="text-right">Duración</th><th>Estado</th><th class="text-right">Ubicación</th>
                </tr></thead>
                <tbody id="visits-table" data-paginar></tbody>
            </table>
        </div>
        <ul class="md:hidden divide-y divide-base-300 border-t border-base-300" id="visits-cards"></ul>
        <p class="px-5 py-10 text-center text-sm text-base-content/50" id="visits-empty">Cargando…</p>
    </section>
</main>

<!-- Ubicaciones de una visita -->
<dialog id="detail" class="modal modal-bottom sm:modal-middle">
    <div class="modal-box w-full sm:max-w-4xl p-0 overflow-hidden flex flex-col max-h-[92vh]">
        <div class="bg-neutral text-neutral-content px-5 sm:px-6 pt-5 pb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="grid place-items-center size-11 shrink-0 rounded-full bg-white/10"><?= icon('stethoscope', 'size-5') ?></span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-bold truncate" id="detail-doctor"></h3>
                        <p class="text-sm text-neutral-content/60 truncate" id="detail-meta"></p>
                    </div>
                </div>
                <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-neutral-content" aria-label="Cerrar"><?= icon('x', 'size-4') ?></button></form>
            </div>
            <div class="flex flex-wrap gap-2 mt-4" id="detail-stats"></div>
        </div>
        <div class="grid md:grid-cols-[minmax(0,1fr)_19rem] min-h-0 flex-1 overflow-y-auto md:overflow-hidden">
            <div id="detail-map" class="h-64 sm:h-80 md:h-[26rem] bg-base-200 z-0" role="region" aria-label="Mapa de la visita"></div>
            <ol class="border-t md:border-t-0 md:border-l border-base-300 p-4 flex flex-col gap-2" id="detail-list"></ol>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
</dialog>

<footer class="flex flex-wrap gap-2 justify-between items-center px-4 sm:px-[4%] py-5 border-t border-base-300 text-xs text-base-content/50">
    <span class="font-semibold tracking-widest">ASISTENCIA</span>
    <span>Panel de administración</span>
</footer>
</body>
</html>
