<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ui.php';
$user = requireManagerPage($db);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc($_SESSION['csrf']) ?>">
    <title>Mi equipo · Asistencia | Fedele 360</title>
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('admin-common.js') ?>" defer></script>
    <script src="<?= asset('equipo.js') ?>" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans antialiased flex flex-col" data-role="gerente">
<?= userHeader($user, 'equipo') ?>

<main class="w-full max-w-5xl mx-auto px-4 sm:px-8 py-6 sm:py-10 flex-1 flex flex-col gap-5">
    <section class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="text-xs font-semibold tracking-widest text-primary">MI EQUIPO</div>
            <h1 class="text-2xl sm:text-3xl font-bold mt-1" id="day-title">Hoy</h1>
        </div>
        <div class="flex items-center gap-1.5">
            <button class="btn btn-sm btn-square btn-ghost border border-base-300 bg-base-100" type="button" id="prev-day" aria-label="Día anterior"><?= icon('chevron-left', 'size-4') ?></button>
            <label class="input input-sm w-auto"><?= icon('calendar', 'size-4 opacity-50') ?><input type="date" id="day" aria-label="Día"></label>
            <button class="btn btn-sm btn-square btn-ghost border border-base-300 bg-base-100" type="button" id="next-day" aria-label="Día siguiente"><?= icon('chevron-right', 'size-4') ?></button>
            <button class="btn btn-sm btn-ghost border border-base-300 bg-base-100" type="button" id="today">Hoy</button>
        </div>
    </section>

    <div id="error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>

    <!-- Quién no ha registrado entrada -->
    <section class="card bg-base-100 border border-base-300 shadow-sm" aria-labelledby="absent-title">
        <div class="card-body p-5 gap-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold flex items-center gap-2" id="absent-title"><?= icon('user-x', 'size-5 text-error') ?>Sin registro de entrada</h2>
                <span class="badge badge-sm" id="absent-count">0</span>
            </div>
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2" id="absent-list"></ul>
        </div>
    </section>

    <!-- Asistencia del día -->
    <section class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden" aria-labelledby="attendance-title">
        <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
            <h2 class="font-semibold flex items-center gap-2" id="attendance-title"><?= icon('clock', 'size-5 text-base-content/45') ?>Asistencia</h2>
            <span class="badge badge-ghost badge-sm" id="attendance-count">0</span>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="table">
                <thead><tr class="text-base-content/55"><th>Persona</th><th>Entrada</th><th>Salida a comida</th><th>Regreso</th><th>Salida</th><th class="text-right">Horas</th><th>Estado</th></tr></thead>
                <tbody id="attendance-table" data-paginar></tbody>
            </table>
        </div>
        <ul class="md:hidden divide-y divide-base-300 border-t border-base-300" id="attendance-cards"></ul>
        <p class="px-5 py-8 text-center text-sm text-base-content/50" id="attendance-empty" hidden>Nadie de tu equipo registró entrada este día.</p>
    </section>

    <!-- Visitas del día -->
    <section class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden" aria-labelledby="visits-title" id="visits-card">
        <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
            <h2 class="font-semibold flex items-center gap-2" id="visits-title"><?= icon('stethoscope', 'size-5 text-base-content/45') ?>Visitas</h2>
            <span class="badge badge-ghost badge-sm" id="visits-count">0</span>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="table">
                <thead><tr class="text-base-content/55"><th>Representante</th><th>Médico</th><th>Inicio</th><th>Fin</th><th class="text-right">Duración</th><th>Comentarios</th></tr></thead>
                <tbody id="visits-table" data-paginar></tbody>
            </table>
        </div>
        <ul class="md:hidden divide-y divide-base-300 border-t border-base-300" id="visits-cards"></ul>
        <p class="px-5 py-8 text-center text-sm text-base-content/50" id="visits-empty" hidden>No hay visitas registradas este día.</p>
    </section>
</main>

<footer class="flex flex-wrap gap-2 justify-between items-center px-4 sm:px-[6%] py-5 border-t border-base-300 text-xs text-base-content/50">
    <span class="font-semibold tracking-widest">ASISTENCIA</span>
    <span>Solo lectura</span>
</footer>
</body>
</html>
