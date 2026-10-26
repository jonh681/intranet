<?php
// Incidencias de mi equipo (gerentes): lo que espera su autorización (primer paso de la validación), quién falta
// esta semana y las incidencias de las personas a su cargo.
require __DIR__ . '/lib.php';
$user = incRequire($db, 'equipo.php');

$items = incItems($db, $user['equipo']); // personas de las que es jefe directo
$current = array_filter($items, fn($i) => in_array($i['status'], ['pendiente', 'autorizada', 'aprobada'], true));
$toAuthorize = array_reverse(array_values(array_filter($items, fn($i) => $i['status'] === 'pendiente')));
$monday = new DateTimeImmutable('monday this week');
$holidays = incHolidays();
$week = [];
for ($k = 0; $k < 5; $k++) {
    $day = $monday->modify("+$k days")->format('Y-m-d');
    $week[$day] = array_values(array_filter($current, fn($i) => in_array($day, $i['dates'], true)));
}
$look = ['vacaciones' => 'bg-sky-50 text-sky-700', 'permiso' => 'bg-violet-50 text-violet-700', 'incapacidad' => 'bg-rose-50 text-rose-700'];
$status = ['pendiente' => 'bg-amber-50 text-amber-700 ring-amber-600/20', 'autorizada' => 'bg-sky-50 text-sky-700 ring-sky-600/20', 'aprobada' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'rechazada' => 'bg-red-50 text-red-700 ring-red-600/20', 'cancelada' => 'bg-slate-100 text-slate-600 ring-slate-500/20'];
$weekdays = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie'];

echo incHeader($db, $user, 'equipo.php', 'Incidencias de mi equipo');
?>
<main class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
    <?= incTitle('Jefe directo', 'Incidencias de mi equipo', 'Autoriza lo que pide tu equipo; después lo confirma Administración. Aquí ves también quién falta esta semana.') ?>
    <div id="notice" class="mt-6 rounded-xl border px-4 py-3 text-sm" role="status" hidden></div>

    <section class="mt-8" aria-labelledby="authorize-title">
        <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900" id="authorize-title">Por autorizar <span class="rounded-full bg-amber-100 px-2 text-sm text-amber-700"><?= count($toAuthorize) ?></span></h2>
        <div class="mt-4 space-y-3" data-review-list data-paginar>
            <?php foreach ($toAuthorize as $it) echo incRequestCard($it, [['autorizar', 'autorizar', 'Autorizar', true], ['autorizar', 'rechazar', 'Rechazar', false]]); ?>
        </div>
        <p class="mt-4 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500" data-review-empty <?= $toAuthorize ? 'hidden' : '' ?>>Tu equipo no tiene solicitudes por autorizar.</p>
    </section>

    <section class="mt-10" aria-labelledby="week-title">
        <h2 class="text-lg font-semibold text-slate-900" id="week-title">Esta semana</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-5">
        <?php $i = 0; foreach ($week as $day => $out): ?>
            <div class="rounded-xl border <?= $day === date('Y-m-d') ? 'border-slate-900' : 'border-slate-200' ?> bg-white p-3">
                <div class="text-xs font-semibold text-slate-500"><?= $weekdays[$i++] ?> <?= (int)substr($day, 8) ?></div>
                <div class="mt-2 space-y-1">
                    <?php if (isset($holidays[$day])): ?><span class="text-xs text-slate-400"><?= esc($holidays[$day]) ?></span><?php endif; ?>
                    <?php foreach ($out as $o): ?>
                        <span class="block truncate rounded-md px-2 py-0.5 text-xs font-medium <?= $look[$o['type']] ?> <?= $o['status'] !== 'aprobada' ? 'border border-dashed border-current' : '' ?>"
                            title="<?= esc(INC_TYPES[$o['type']] . ' · ' . INC_STATUS[$o['status']]) ?>"><?= esc($o['user']['name']) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$out && !isset($holidays[$day])): ?><span class="text-xs text-slate-400">Todos disponibles</span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-10" aria-labelledby="team-title">
        <h2 class="text-lg font-semibold text-slate-900" id="team-title">Incidencias</h2>
        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-4 py-3 font-semibold">Persona</th><th class="px-4 py-3 font-semibold">Tipo</th><th class="px-4 py-3 font-semibold">Fechas</th>
                    <th class="px-4 py-3 font-semibold">Días</th><th class="px-4 py-3 font-semibold">Estado</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100" data-paginar>
                <?php foreach ($items as $it): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><?= incPerson($it['user']['name'], (string)$it['user']['area']) ?></td>
                        <td class="px-4 py-3"><span class="rounded-md px-2 py-0.5 text-xs font-semibold <?= $look[$it['type']] ?>"><?= esc(INC_TYPES[$it['type']]) ?></span></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc(incRange($it['dates'], $it['type'] === 'permiso')) ?></td>
                        <td class="px-4 py-3 tabular-nums"><?= (int)$it['days'] ?></td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset <?= $status[$it['status']] ?>"><?= esc(INC_STATUS[$it['status']]) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$items): ?><p class="px-4 py-10 text-center text-sm text-slate-500">Tu equipo no tiene incidencias registradas.</p><?php endif; ?>
        </div>
    </section>
</main>
<?= incRejectModal() ?>
</body>
</html>
