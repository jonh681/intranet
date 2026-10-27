<?php
// Todas las incidencias (administración): calendario del mes, listado con filtros y registro a nombre de otra persona.
require __DIR__ . '/lib.php';
$user = incRequire($db, 'todas.php');

$items = incItems($db);
// Personas con acceso a asistencia: su saldo de vacaciones y sus días ocupados, para el calendario de registro.
$busy = [];
foreach ($items as $i) {
    if (in_array($i['status'], ['pendiente', 'autorizada', 'aprobada'], true)) foreach ($i['dates'] as $d) $busy[$i['user']['id']][] = $d;
}
$people = array_map(function (array $s) use ($busy) {
    $c = $s['current'];
    return ['id' => $s['id'], 'name' => $s['name'], 'area' => $s['area'], 'busy' => $busy[$s['id']] ?? [], 'hire_date' => $s['hire_date'], 'weekends' => $s['weekends'],
        'period' => $c ? ['start' => $c['start'], 'end' => $c['end'], 'entitled' => $c['entitled'], 'available' => $c['available']] : null];
}, vacationSummaries($db));
$areas = array_values(array_unique(array_filter(array_column($people, 'area'))));
sort($areas);
$select = 'block w-full rounded-lg px-3 py-2 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500';

echo incHeader($db, $user, 'todas.php', 'Todas las incidencias');
?>
<main class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
    <?= incTitle('Administración', 'Todas las incidencias', 'Historial completo de vacaciones, permisos e incapacidades de todas las personas. El reporte en Excel trae lo que coincide con los filtros.',
        '<button type="button" id="download-report" class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" title="Excel con todas las incidencias que coinciden con los filtros">'
        . icon('file-sheet', 'size-4') . 'Descargar reporte</button>'
        . '<button type="button" id="open-inc" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">+ Registrar para alguien</button>') ?>
    <div id="notice" class="mt-6 rounded-xl border px-4 py-3 text-sm" role="status" hidden></div>

    <div class="mt-8 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5" id="filters">
        <label class="text-xs font-medium text-slate-500 lg:col-span-2">Buscar persona
            <input type="search" id="f-search" class="mt-1 <?= $select ?>" placeholder="Nombre…" autocomplete="off"></label>
        <label class="text-xs font-medium text-slate-500">Tipo
            <select id="f-type" class="mt-1 <?= $select ?>"><option value="">Todos</option><?php foreach (INC_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
        <label class="text-xs font-medium text-slate-500">Estado
            <select id="f-status" class="mt-1 <?= $select ?>"><option value="">Todos</option><?php foreach (INC_STATUS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
        <label class="text-xs font-medium text-slate-500">Área
            <select id="f-area" class="mt-1 <?= $select ?>"><option value="">Todas</option><?php foreach ($areas as $a): ?><option><?= esc($a) ?></option><?php endforeach; ?></select></label>
    </div>

    <!-- Vista mensual: cuántas personas tienen cada tipo de incidencia por día (con los filtros). Clic en un día = solo ese día en el listado. -->
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white px-3 pb-5 pt-5 shadow-sm sm:px-5" aria-label="Calendario del mes">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1.5">
                <button type="button" id="month-prev" class="grid size-8 place-items-center rounded-[10px] border border-slate-200 hover:bg-slate-100" aria-label="Mes anterior"><?= icon('chevron-left', 'size-4') ?></button>
                <button type="button" id="month-next" class="grid size-8 place-items-center rounded-[10px] border border-slate-200 hover:bg-slate-100" aria-label="Mes siguiente"><?= icon('chevron-right', 'size-4') ?></button>
                <h2 class="ml-2 text-lg font-bold sm:text-xl" id="month-title"></h2>
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                <span class="flex items-center gap-1"><span class="text-sky-600"><?= icon('plane', 'size-3.5') ?></span>Vacaciones</span>
                <span class="flex items-center gap-1"><span class="text-violet-600"><?= icon('clipboard', 'size-3.5') ?></span>Permisos</span>
                <span class="flex items-center gap-1"><span class="text-rose-600"><?= icon('heart-pulse', 'size-3.5') ?></span>Incapacidades</span>
                <span class="flex items-center gap-1"><span class="text-amber-600"><?= icon('clock', 'size-3.5') ?></span>En revisión</span>
                <button type="button" id="month-today" class="ml-1 rounded-[10px] border border-slate-200 px-3 py-1 text-sm font-medium hover:bg-slate-100">Hoy</button>
            </div>
        </div>
        <div class="mb-1.5 grid grid-cols-7 gap-1 text-center text-xs font-medium text-slate-400 sm:gap-2 sm:text-xs"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div>
        <div class="grid grid-cols-7 gap-1 sm:gap-2" id="month-grid"></div>
        <p class="mt-3 text-xs text-slate-500">Haz clic en un día para ver quién tiene vacaciones, permiso o incapacidad ese día. Los festivos aparecen tachados.</p>
    </section>

    <div class="mt-10 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900">Listado <span class="text-sm font-normal text-slate-500" id="list-count"></span></h2>
    </div>
    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                <th class="px-4 py-3 font-semibold">Persona</th><th class="px-4 py-3 font-semibold">Tipo</th><th class="px-4 py-3 font-semibold">Fechas</th>
                <th class="px-4 py-3 font-semibold">Días</th><th class="px-4 py-3 font-semibold">Estado</th><th class="px-4 py-3 font-semibold">Detalle</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100" id="all-list" data-paginar></tbody>
        </table>
        <p class="px-4 py-10 text-center text-sm text-slate-500" id="all-empty" hidden>No hay incidencias con esos filtros.</p>
    </div>
</main>
<!-- Detalle de un día del calendario (lo llena js/incidencias.js) -->
<dialog id="day-modal" class="m-auto flex max-h-[92dvh] w-[calc(100%-2rem)] max-w-3xl flex-col overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 [&:not([open])]:hidden">
    <div class="relative shrink-0 overflow-hidden bg-navy px-5 pb-5 pt-5 text-white sm:px-7">
        <div class="pointer-events-none absolute -right-16 -top-24 size-64 rounded-full bg-accent/25 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="text-xs font-medium text-white/60" id="day-kicker">Detalle del día</div>
                <h2 class="mt-0.5 text-xl font-bold sm:text-2xl" id="day-title"></h2>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button" class="grid size-9 place-items-center rounded-full hover:bg-white/10" data-day-nav="-1" aria-label="Día anterior"><?= icon('chevron-left', 'size-5') ?></button>
                <button type="button" class="grid size-9 place-items-center rounded-full hover:bg-white/10" data-day-nav="1" aria-label="Día siguiente"><?= icon('chevron-right', 'size-5') ?></button>
                <button type="button" class="grid size-9 place-items-center rounded-full hover:bg-white/10" data-close aria-label="Cerrar"><?= icon('x', 'size-5') ?></button>
            </div>
        </div>
        <div class="relative mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4" id="day-stats"></div>
    </div>
    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
        <p class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800" id="day-holiday" hidden></p>
        <div class="space-y-3" id="day-list"></div>
        <p class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500" id="day-empty" hidden></p>
    </div>
    <div class="shrink-0 border-t border-slate-200 bg-white px-5 py-3 text-xs text-slate-500 sm:px-7">Se muestran las incidencias aprobadas y las que están en revisión, con los filtros de la página.</div>
</dialog>
<?= incModal($people, $user['valida']) ?>
<?php if ($user['valida']): ?>
<!-- Cancelar unas vacaciones ya aprobadas (solo quien valida; lo maneja js/incidencias.js) -->
<dialog id="cancel-vac-modal" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40">
    <form id="cancel-vac-form" class="p-6" novalidate>
        <h2 class="text-lg font-semibold text-slate-900">Cancelar las vacaciones de <span data-name></span></h2>
        <p class="mt-1 text-sm text-slate-500"><span data-dates></span>. Ya están aprobadas por su jefe y por Administración; al cancelarlas, los días regresan a su saldo y se le avisa por correo.</p>
        <label class="mt-4 block"><span class="mb-1.5 block text-sm font-medium">Motivo</span>
            <textarea name="motivo" rows="3" maxlength="200" required class="block w-full rounded-[10px] px-3 py-2.5 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Ej. Se cambian las fechas por cierre de mes"></textarea></label>
        <p class="mt-3 rounded-[10px] border border-error/30 bg-error/10 px-3 py-2 text-sm text-error" id="cancel-vac-error" role="alert" hidden></p>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="rounded-[10px] px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100" data-close>Volver</button>
            <button class="rounded-[10px] bg-error px-5 py-2.5 text-sm font-semibold text-white hover:bg-error/90">Cancelar vacaciones</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?= incJson('inc-data', ['today' => date('Y-m-d'), 'holidays' => incHolidays(), 'items' => $items, 'people' => $people, 'formato' => $user['valida']]) ?>
</body>
</html>
