<?php
// Mis incidencias: saldo de vacaciones, permisos e incapacidades del año, calendario de colores y mis solicitudes.
require __DIR__ . '/lib.php';
$user = incRequire($db, 'index.php');

$items = incItems($db, [$user['id']]);
$balance = incBalance($db, $user['id']);
$period = $balance['period'];
$year = date('Y');
$thisYear = array_filter($items, fn($i) => in_array($i['status'], ['aprobada', 'pendiente', 'autorizada'], true) && substr($i['dates'][0] ?? '', 0, 4) === $year);
$permits = array_values(array_filter($thisYear, fn($i) => $i['type'] === 'permiso'));
$sick = array_values(array_filter($thisYear, fn($i) => $i['type'] === 'incapacidad'));
$withPay = count(array_filter($permits, fn($i) => $i['goce'] === true));
$pct = fn(int $n) => $period && $period['entitled'] ? round($n / $period['entitled'] * 100, 2) : 0;

echo incHeader($db, $user, 'index.php', 'Mis incidencias');
?>
<main class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
    <?= incTitle('Mis incidencias', 'Incidencias', 'Registra vacaciones, permisos e incapacidades. Administración las revisa y, ya aprobadas, justifican tus días sin registro en asistencia.',
        '<button class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-700" id="open-inc" type="button">'
        . '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke-linecap="round"/></svg>Registrar incidencia</button>') ?>
    <div id="notice" class="mt-6 rounded-xl border px-4 py-3 text-sm" role="status" hidden></div>

    <!-- Resumen por tipo -->
    <div class="mt-8 grid gap-3 sm:grid-cols-3 sm:gap-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex items-center gap-2 text-sm font-medium text-sky-700"><span class="grid size-7 place-items-center rounded-lg bg-sky-100"><?= icon('plane', 'size-4') ?></span>Vacaciones</div>
            <?php if ($period): ?>
                <p class="mt-3 text-3xl font-bold text-slate-900"><?= (int)$period['available'] ?> <span class="text-sm font-medium text-slate-500">de <?= (int)$period['entitled'] ?> días disponibles</span></p>
                <div class="mt-3 flex h-1.5 overflow-hidden rounded-full bg-slate-200" title="<?= (int)$period['used'] ?> usados · <?= (int)$period['pending'] ?> en revisión · <?= (int)$period['available'] ?> disponibles">
                    <span class="bg-slate-500" data-w="<?= $pct((int)$period['used']) ?>"></span><span class="bg-amber-400" data-w="<?= $pct((int)$period['pending']) ?>"></span><span class="bg-sky-500" data-w="<?= $pct((int)$period['available']) ?>"></span>
                </div>
                <p class="mt-2 text-xs text-slate-500">Año <?= date('Y', strtotime($period['start'])) ?>–<?= date('Y', strtotime($period['end'])) ?> · <?= (int)$period['used'] ?> usados · <?= (int)$period['pending'] ?> en revisión</p>
            <?php elseif ($balance['hire_date']): ?>
                <p class="mt-3 text-3xl font-bold text-slate-900">0 <span class="text-sm font-medium text-slate-500">días por ahora</span></p>
                <p class="mt-5 text-xs text-slate-500">Tus primeros días llegan el <?= esc(date('d/m/Y', strtotime($balance['next']['date']))) ?> (<?= (int)$balance['next']['days'] ?> días).</p>
            <?php else: ?>
                <p class="mt-3 text-lg font-semibold text-slate-900">Sin fecha de ingreso</p>
                <p class="mt-2 text-xs text-slate-500">Pide a administración que la registre para calcular tus días.</p>
            <?php endif; ?>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex items-center gap-2 text-sm font-medium text-violet-700"><span class="grid size-7 place-items-center rounded-lg bg-violet-100"><?= icon('clipboard', 'size-4') ?></span>Permisos</div>
            <p class="mt-3 text-3xl font-bold text-slate-900"><?= count($permits) ?> <span class="text-sm font-medium text-slate-500">este año</span></p>
            <p class="mt-5 text-xs text-slate-500"><?= $withPay ?> con goce de sueldo · <?= count($permits) - $withPay ?> sin goce</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex items-center gap-2 text-sm font-medium text-rose-700"><span class="grid size-7 place-items-center rounded-lg bg-rose-100"><?= icon('heart-pulse', 'size-4') ?></span>Incapacidades</div>
            <p class="mt-3 text-3xl font-bold text-slate-900"><?= count($sick) ?> <span class="text-sm font-medium text-slate-500">este año · <?= array_sum(array_column($sick, 'days')) ?> días</span></p>
            <p class="mt-5 text-xs text-slate-500">Con folio IMSS y comprobante adjunto</p>
        </div>
    </div>

    <div class="mt-10 grid items-start gap-5 lg:grid-cols-[1fr_1.2fr]">
        <section class="rounded-2xl border border-slate-200 bg-white p-6" aria-label="Calendario">
            <div class="flex items-center justify-between">
                <button class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" id="cal-prev" type="button" aria-label="Mes anterior">‹</button>
                <h2 class="font-semibold text-slate-900" id="cal-title"></h2>
                <button class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" id="cal-next" type="button" aria-label="Mes siguiente">›</button>
            </div>
            <div class="mt-4 grid grid-cols-7 gap-1 text-center text-xs" id="calendar"></div>
            <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-sky-500"></span>Vacaciones</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-violet-500"></span>Permiso</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-rose-500"></span>Incapacidad</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm border-2 border-dashed border-slate-400"></span>En revisión</span>
            </div>
        </section>
        <section aria-labelledby="list-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold text-slate-900" id="list-title">Mis registros</h2>
                <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium" id="inc-filter">
                    <button type="button" class="rounded-md bg-white px-2.5 py-1 shadow-sm" data-type="">Todas</button>
                    <button type="button" class="rounded-md px-2.5 py-1 text-slate-500" data-type="vacaciones">Vacaciones</button>
                    <button type="button" class="rounded-md px-2.5 py-1 text-slate-500" data-type="permiso">Permisos</button>
                    <button type="button" class="rounded-md px-2.5 py-1 text-slate-500" data-type="incapacidad">Incapacidades</button>
                </div>
            </div>
            <div class="mt-4 space-y-3" id="inc-list" data-paginar></div>
        </section>
    </div>
</main>
<?= incModal() ?>
<?= incJson('inc-data', [
    'today' => date('Y-m-d'), 'holidays' => incHolidays(), 'items' => $items,
    'me' => ['id' => $user['id'], 'busy' => incBusy($db, $user['id']), 'period' => $period, 'hire_date' => $balance['hire_date'], 'weekends' => $balance['weekends']],
]) ?>
</body>
</html>
