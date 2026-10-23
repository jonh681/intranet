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
    <title>Administración · Asistencia | Fedele 360</title>
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.min.css">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('vendor/leaflet/leaflet.min.js') ?>" defer></script>
    <script src="<?= asset('admin-common.js') ?>" defer></script>
    <script src="<?= asset('vacation-picker.js') ?>" defer></script>
    <script src="<?= asset('admin.js') ?>" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans antialiased flex flex-col" data-role="<?= esc($user['rol']) ?>" data-calendar="<?= feature('calendario') ? '1' : '0' ?>" data-incidents="<?= feature('incidencias') ? '1' : '0' ?>">
<?= adminHeader($user, 'asistencia') ?>

<main class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 flex-1 flex flex-col gap-5">
    <!-- Encabezado e indicadores -->
    <section class="relative overflow-hidden rounded-box bg-neutral text-neutral-content shadow-xl shadow-neutral/20 p-5 sm:p-7 animate-pop-in">
        <div class="pointer-events-none absolute -right-20 -top-28 size-80 rounded-full bg-accent/25 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="text-[11px] font-semibold tracking-widest text-neutral-content/60">PANEL DE ASISTENCIA</div>
                <h1 class="text-2xl sm:text-3xl font-bold mt-1" id="range-title">Hoy</h1>
            </div>
            <div class="flex flex-wrap gap-2" id="live" hidden>
                <span class="badge badge-ghost gap-1.5"><span class="status status-success animate-pulse"></span><span data-live="working">0</span> trabajando</span>
                <span class="badge badge-ghost gap-1.5"><span class="status status-info"></span><span data-live="lunch">0</span> en comida</span>
                <span class="badge badge-ghost gap-1.5"><span class="status status-neutral"></span><span data-live="done">0</span> terminaron</span>
                <span class="badge badge-ghost gap-1.5"><span class="status status-warning"></span><span data-live="absent">0</span> sin registro</span>
                <span class="badge badge-ghost gap-1.5" hidden><?= icon('plane', 'size-3.5 text-info') ?><span data-live="vacation">0</span> de vacaciones</span>
            </div>
        </div>
        <div class="relative mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
<?php foreach ([
    ['days', 'Jornadas', 'users', 'kpi-days-note'],
    ['complete', 'Completas', 'check', 'kpi-complete-note'],
    ['hours', 'Horas trabajadas', 'timer', 'kpi-hours-note'],
    ['average', 'Promedio por jornada', 'chart', 'kpi-average-note'],
] as [$key, $label, $iconName, $note]): ?>
            <div class="rounded-box bg-white/[.06] border border-white/10 p-4">
                <div class="flex items-center gap-2 text-xs text-neutral-content/65"><?= icon($iconName, 'size-4 text-accent') ?><?= $label ?></div>
                <div class="text-2xl sm:text-3xl font-semibold tabular-nums mt-2" data-kpi="<?= $key ?>">—</div>
                <div class="text-xs text-neutral-content/50 mt-0.5 truncate" id="<?= $note ?>">&nbsp;</div>
            </div>
<?php endforeach; ?>
        </div>
        <div class="relative mt-5" id="areas-block" hidden>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-[11px] font-semibold tracking-widest text-neutral-content/60">POR ÁREA</span>
                <span class="text-xs text-neutral-content/45" id="areas-caption"></span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 mt-2" id="areas-grid"></div>
        </div>
    </section>

    <!-- Empleados sin entrada (solo cuando el periodo es un día; no cuenta administradores). Desplegable: cerrado muestra el total. -->
    <details class="group rounded-box border-2 animate-pop-in" id="absent-card" hidden>
        <summary class="flex items-center gap-3 p-4 sm:p-5 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden" id="absent-summary">
            <span class="grid place-items-center size-11 shrink-0 rounded-full" id="absent-icon"><span data-icon="missing"><?= icon('user-x', 'size-5') ?></span><span data-icon="ok" hidden><?= icon('check', 'size-5') ?></span></span>
            <span class="min-w-0 flex-1">
                <span class="block text-lg font-bold leading-tight" id="absent-title"></span>
                <span class="block text-sm text-base-content/60" id="absent-subtitle"></span>
            </span>
            <span class="flex items-center gap-1.5 shrink-0 text-sm font-medium text-error" id="absent-toggle">
                <span class="hidden sm:inline sm:group-open:hidden">Ver lista</span>
                <span class="hidden sm:group-open:inline">Ocultar</span>
                <?= icon('chevron-down', 'size-5 transition-transform group-open:rotate-180') ?>
            </span>
        </summary>
        <div class="px-4 pb-4 sm:px-5 sm:pb-5 flex flex-col gap-3">
            <p class="text-xs text-base-content/55" id="absent-hint">Haz clic en un empleado para registrar vacaciones, permiso o incapacidad.</p>
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2" id="absent-list"></ul>
            <div id="justified-block" hidden>
                <div class="text-[11px] font-semibold tracking-widest text-base-content/50 mb-2" id="justified-title">JUSTIFICADOS</div>
                <ul class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2" id="justified-list"></ul>
            </div>
        </div>
    </details>
    <div id="notice" class="alert alert-success alert-soft text-sm animate-pop-in" role="status" aria-live="polite" hidden></div>

    <!-- Filtros -->
    <?= adminFilters('Buscar por nombre, correo o médico', 'Todos los empleados') ?>

    <div id="error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>


    <section class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden" aria-labelledby="days-title">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 pb-3">
            <h2 class="font-semibold flex items-center gap-2" id="days-title"><?= icon(feature('calendario') ? 'calendar' : 'list', 'size-5 text-base-content/45') ?>Jornadas<?php if (!feature('calendario')): ?> <span class="badge badge-ghost badge-sm" id="days-count">0</span><?php endif; ?></h2>
        </div>
<?php if (feature('calendario')): ?>

        <!-- Vista mensual: conteos por día; clic en un día = ver su detalle -->
        <div class="px-3 sm:px-5 pb-5" id="month-view">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-1.5">
                    <button class="btn btn-sm btn-square btn-ghost border border-base-300" type="button" id="month-prev" aria-label="Mes anterior"><?= icon('chevron-left', 'size-4') ?></button>
                    <button class="btn btn-sm btn-square btn-ghost border border-base-300" type="button" id="month-next" aria-label="Mes siguiente"><?= icon('chevron-right', 'size-4') ?></button>
                    <h3 class="text-lg sm:text-xl font-bold ml-2" id="month-title"></h3>
                    <span class="loading loading-dots loading-xs text-primary ml-1" id="month-loading" hidden></span>
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-base-content/60">
                    <span class="flex items-center gap-1"><?= icon('check', 'size-3.5 text-success') ?>Completas</span>
                    <span class="flex items-center gap-1"><?= icon('clock', 'size-3.5 text-info') ?>En curso</span>
                    <span class="flex items-center gap-1"><?= icon('circle-alert', 'size-3.5 text-warning') ?>Sin salida</span>
                    <span class="flex items-center gap-1"><?= icon('user-x', 'size-3.5 text-error') ?>Faltas</span>
                    <span class="flex items-center gap-1"><?= icon('plane', 'size-3.5 text-info') ?>Vacaciones</span>
                    <span class="flex items-center gap-1"><?= icon('calendar-check', 'size-3.5 text-accent') ?>Justificados</span>
                    <button class="btn btn-sm btn-ghost border border-base-300 ml-1" type="button" id="month-today">Hoy</button>
                </div>
            </div>
            <div class="grid grid-cols-7 gap-1 sm:gap-2 text-center text-[11px] sm:text-xs font-semibold text-base-content/50 mb-1.5">
                <span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>
            </div>
            <div class="grid grid-cols-7 gap-1 sm:gap-2" id="month-grid"></div>
            <p class="text-xs text-base-content/50 mt-3">Haz clic en un día para ver sus registros. Las faltas solo se cuentan en días en que alguien registró entrada; los justificados se muestran siempre, también los días futuros.</p>
            <template id="month-icons"><span data-k="done" class="text-success"><?= icon('check', 'size-3 sm:size-3.5') ?></span><span data-k="open" class="text-info"><?= icon('clock', 'size-3 sm:size-3.5') ?></span><span data-k="late" class="text-warning"><?= icon('circle-alert', 'size-3 sm:size-3.5') ?></span><span data-k="absent" class="text-error"><?= icon('user-x', 'size-3 sm:size-3.5') ?></span><span data-k="vacation" class="text-info"><?= icon('plane', 'size-3 sm:size-3.5') ?></span><span data-k="justified" class="text-accent"><?= icon('calendar-check', 'size-3 sm:size-3.5') ?></span></template>
        </div>
<?php else: ?>

        <!-- Tabla de jornadas (cuando el calendario está apagado en config.php) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="table">
                <thead><tr class="text-base-content/55">
                    <th>Empleado</th><th>Fecha</th><th>Entrada</th><th>Comida</th><th>Salida</th><th class="text-right">Horas</th><th>Estado</th><th class="text-right">Ubicación</th>
                </tr></thead>
                <tbody id="days-table" data-paginar></tbody>
            </table>
        </div>
        <ul class="md:hidden divide-y divide-base-300 border-t border-base-300" id="days-cards"></ul>
        <p class="px-5 py-10 text-center text-sm text-base-content/50" id="days-empty">Cargando…</p>
<?php endif; ?>
    </section>
</main>

<!-- Ubicaciones de una jornada -->
<dialog id="detail" class="modal modal-bottom sm:modal-middle">
    <div class="modal-box w-full sm:max-w-5xl p-0 overflow-hidden flex flex-col max-h-[92vh]">
        <div class="bg-neutral text-neutral-content px-5 sm:px-6 pt-5 pb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="grid place-items-center size-11 shrink-0 rounded-full bg-white/10 font-semibold" id="detail-initials"></span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-bold truncate" id="detail-name"></h3>
                        <p class="text-sm text-neutral-content/60 truncate" id="detail-meta"></p>
                    </div>
                </div>
                <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-neutral-content" aria-label="Cerrar"><?= icon('x', 'size-4') ?></button></form>
            </div>
            <div class="flex flex-wrap gap-2 mt-4" id="detail-stats"></div>
        </div>
        <div class="grid md:grid-cols-[minmax(0,1fr)_20rem] min-h-0 flex-1 overflow-y-auto md:overflow-hidden">
            <div class="relative">
                <div id="detail-map" class="h-64 sm:h-80 md:h-[30rem] bg-base-200 z-0" role="region" aria-label="Mapa de la jornada"></div>
                <button class="btn btn-sm bg-base-100 shadow-md absolute right-3 top-3 z-[500] gap-1.5" id="detail-fit" type="button"><?= icon('map-pin', 'size-4') ?>Ver todos</button>
            </div>
            <div class="border-t md:border-t-0 md:border-l border-base-300 p-4 flex flex-col gap-2 md:overflow-y-auto md:h-[30rem]">
                <div class="text-[11px] font-semibold tracking-widest text-base-content/50 px-1 pb-1">REGISTROS DEL DÍA</div>
                <ol class="flex flex-col gap-2" id="detail-list"></ol>
            </div>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
</dialog>

<!-- Detalle de un día del calendario -->
<dialog id="day-modal" class="modal modal-bottom sm:modal-middle">
    <div class="modal-box w-full sm:max-w-5xl p-0 overflow-hidden flex flex-col max-h-[92vh]">
        <div class="relative shrink-0 overflow-hidden bg-neutral text-neutral-content px-5 sm:px-7 pt-5 pb-5">
            <div class="pointer-events-none absolute -right-16 -top-24 size-64 rounded-full bg-accent/25 blur-3xl" aria-hidden="true"></div>
            <div class="relative flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] font-semibold tracking-widest text-neutral-content/60" id="day-kicker">DETALLE DEL DÍA</div>
                    <h3 class="text-xl sm:text-2xl font-bold mt-0.5 sm:truncate" id="day-title"></h3>
                </div>
                <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-neutral-content" aria-label="Cerrar"><?= icon('x', 'size-4') ?></button></form>
            </div>
            <div class="relative grid grid-cols-6 gap-1.5 sm:gap-2 mt-4" id="day-stats"></div>
        </div>
        <div class="shrink-0 flex flex-wrap items-center justify-between gap-2 px-4 sm:px-6 py-3 border-b border-base-300 bg-base-100">
            <div class="join w-full sm:w-auto" role="tablist" aria-label="Filtrar personas" id="day-tabs"></div>
            <span class="text-xs text-base-content/50" id="day-subtitle"></span>
        </div>
        <div class="overflow-y-auto flex-1 min-h-0">
            <div class="hidden md:block">
                <table class="table">
                    <thead class="sticky top-0 bg-base-100 z-10"><tr class="text-base-content/55">
                        <th>Empleado</th><th>Entrada</th><th>Comida</th><th>Salida</th><th class="text-right">Horas</th><th>Estado</th><th class="text-right">Acción</th>
                    </tr></thead>
                    <tbody id="day-table" data-paginar></tbody>
                </table>
            </div>
            <ul class="md:hidden divide-y divide-base-300" id="day-cards"></ul>
            <p class="px-5 py-12 text-center text-sm text-base-content/50" id="day-empty" hidden></p>
        </div>
        <div class="shrink-0 border-t border-base-300 px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-2 bg-base-200/50">
            <span class="text-xs text-base-content/50">Las faltas no cuentan a administradores ni días sin actividad.</span>
            <button class="btn btn-sm btn-ghost gap-2" type="button" id="day-open-panel"><?= icon('layout', 'size-4') ?>Ver este día en el panel</button>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>Cerrar</button></form>
</dialog>

<!-- Incidencias de un empleado: vacaciones, permiso o incapacidad -->
<dialog id="incident-modal" class="modal modal-bottom sm:modal-middle">
    <div class="modal-box w-full sm:max-w-2xl p-0 overflow-hidden flex flex-col max-h-[94vh]">
        <div class="flex items-start justify-between gap-3 bg-neutral text-neutral-content px-5 sm:px-6 py-5">
            <div class="flex items-center gap-3 min-w-0">
                <span class="grid place-items-center size-11 shrink-0 rounded-full bg-white/10 font-semibold" id="incident-initials"></span>
                <div class="min-w-0">
                    <h3 class="text-lg font-bold truncate" id="incident-name"></h3>
                    <p class="text-sm text-neutral-content/60 truncate" id="incident-meta"></p>
                </div>
            </div>
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost text-neutral-content" aria-label="Cerrar"><?= icon('x', 'size-4') ?></button></form>
        </div>
        <div class="overflow-y-auto p-5 sm:p-6 flex flex-col gap-5">
            <section id="incident-existing" hidden>
                <div class="text-[11px] font-semibold tracking-widest text-base-content/50 mb-2">INCIDENCIAS REGISTRADAS</div>
                <ul class="flex flex-col gap-2" id="incident-list"></ul>
            </section>
            <p class="alert alert-info alert-soft text-sm" id="incident-readonly" hidden></p>
            <form id="incident-form" class="flex flex-col gap-4" novalidate>
                <fieldset>
                    <legend class="text-sm font-semibold mb-2">Registrar incidencia</legend>
<?php
// Con la pestaña Vacaciones encendida, las vacaciones se registran allá (con saldo y días hábiles).
$incidentTypes = array_filter([
    ['vacaciones', 'Vacaciones', 'plane', 'Inicio y fin'],
    ['permiso', 'Permiso', 'clipboard', 'Inicio y fin'],
    ['incapacidad', 'Incapacidad', 'heart-pulse', 'Con documentación'],
], fn($t) => $t[0] !== 'vacaciones' || !feature('vacaciones'));
?>
                    <div class="grid grid-cols-<?= count($incidentTypes) ?> gap-2">
<?php foreach ($incidentTypes as [$value, $label, $iconName, $hint]): ?>
                        <label class="cursor-pointer rounded-box border-2 border-base-300 p-3 text-center transition hover:border-primary/40 has-checked:border-primary has-checked:bg-primary/5">
                            <input type="radio" name="type" value="<?= $value ?>" class="sr-only">
                            <span class="grid place-items-center size-10 mx-auto rounded-full bg-secondary text-secondary-content"><?= icon($iconName, 'size-5') ?></span>
                            <span class="block text-sm font-semibold mt-2"><?= $label ?></span>
                            <span class="block text-[11px] text-base-content/55 leading-tight mt-0.5"><?= $hint ?></span>
                        </label>
<?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="flex flex-col gap-4" id="incident-fields" hidden>
                    <div>
                        <div class="flex items-end justify-between gap-2 mb-2">
                            <span class="text-sm font-medium" id="calendar-label">Días</span>
                            <button class="btn btn-ghost btn-xs" type="button" id="calendar-clear">Limpiar</button>
                        </div>
                        <div class="rounded-box border border-base-300 p-3" id="calendar"></div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-base-content/60 mt-2">
                            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-primary"></span>Seleccionado</span>
                            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-warning/30 ring-1 ring-warning/60"></span>Festivo</span>
                            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-info/20 ring-1 ring-info/50"></span>Ya tiene vacaciones o incidencia</span>
                        </div>
                        <p class="text-sm mt-2 font-medium min-h-5" id="calendar-summary"></p>
                    </div>
                    <label class="w-full" id="description-field">
                        <span class="text-sm font-medium block mb-1.5">Descripción</span>
                        <textarea class="textarea w-full" name="description" id="incident-description" rows="3" maxlength="2000" placeholder="Motivo o detalles"></textarea>
                    </label>
                    <label class="w-full" id="file-field">
                        <span class="text-sm font-medium flex items-center gap-1.5 mb-1.5"><?= icon('paperclip', 'size-4') ?><span id="file-label">Evidencia</span></span>
                        <input type="file" class="file-input w-full" name="file" id="incident-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/*">
                        <span class="text-xs text-base-content/50 block mt-1.5">PDF o imagen (JPG, PNG, WEBP, HEIC), hasta 10 MB.</span>
                    </label>
                    <div id="incident-error" class="alert alert-error alert-soft text-sm" role="alert" hidden></div>
                    <div class="flex justify-end gap-2">
                        <button class="btn btn-ghost" type="button" id="incident-cancel">Cancelar</button>
                        <button class="btn btn-primary" type="submit" id="incident-save">Guardar incidencia</button>
                    </div>
                </div>
            </form>
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
