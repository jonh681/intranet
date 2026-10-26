<?php
// Saldos de vacaciones (administración): días por antigüedad (Ley Federal del Trabajo), usados, en revisión y disponibles.
require __DIR__ . '/lib.php';
$user = incRequire($db, 'saldos.php');
$people = vacationSummaries($db);

echo incHeader($db, $user, 'saldos.php', 'Saldos de vacaciones');
?>
<main class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
    <?= incTitle('Administración', 'Saldos de vacaciones', 'Días del año de vacaciones en curso de cada persona (de un aniversario de ingreso al siguiente). Los ajustes sirven para registrar días tomados antes del sistema o correcciones.') ?>
    <div id="notice" class="mt-6 rounded-xl border px-4 py-3 text-sm" role="status" hidden></div>
    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
        <input type="search" id="s-search" placeholder="Buscar persona o área…" autocomplete="off" class="block w-full rounded-lg px-3 py-2 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500 sm:max-w-sm">
    </div>
    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                <th class="px-4 py-3 font-semibold">Persona</th><th class="px-4 py-3 font-semibold">Ingreso</th><th class="px-4 py-3 font-semibold">Antigüedad</th>
                <th class="px-4 py-3 font-semibold">Corresponden</th><th class="px-4 py-3 font-semibold">Usados</th><th class="px-4 py-3 font-semibold">En revisión</th>
                <th class="px-4 py-3 font-semibold">Disponibles</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100" id="balance-list" data-paginar>
            <?php foreach ($people as $p): $c = $p['current']; ?>
                <tr class="hover:bg-slate-50" data-search="<?= esc(mb_strtolower($p['name'] . ' ' . $p['area'])) ?>">
                    <td class="px-4 py-3"><?= incPerson($p['name'], (string)$p['area']) ?></td>
                    <?php if (!$p['hire_date']): ?>
                        <td class="px-4 py-3 text-slate-500" colspan="6">Falta la fecha de ingreso. <?= $user['rol'] === 'admin' && ($_SESSION['user']['rol'] ?? '') === 'superusuario' ? '<a class="font-medium text-brand-700 hover:underline" href="../tickets/modulos/seguridad.php#usuarios">Regístrala en Seguridad</a>' : 'La registra el superusuario en Seguridad.' ?></td>
                        <td class="px-4 py-3"></td>
                    <?php else: ?>
                        <td class="whitespace-nowrap px-4 py-3"><?= esc(incDate($p['hire_date'])) ?></td>
                        <td class="whitespace-nowrap px-4 py-3"><?= (int)$p['years'] ?> <?= (int)$p['years'] === 1 ? 'año' : 'años' ?></td>
                        <?php if ($c): ?>
                            <td class="px-4 py-3 tabular-nums"><?= (int)$c['entitled'] ?></td>
                            <td class="px-4 py-3 tabular-nums"><?= (int)$c['used'] ?></td>
                            <td class="px-4 py-3 tabular-nums"><?= (int)$c['pending'] ?: '—' ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <strong class="w-6 tabular-nums <?= (int)$c['available'] === 0 ? 'text-red-600' : 'text-slate-900' ?>"><?= (int)$c['available'] ?></strong>
                                    <span class="block h-2 w-28 rounded-full bg-slate-100"><span class="block h-2 rounded-full bg-sky-500" data-w="<?= $c['entitled'] ? round($c['available'] / $c['entitled'] * 100, 2) : 0 ?>"></span></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-slate-300 hover:bg-brand-50" data-adjust="<?= $p['id'] ?>" data-name="<?= esc($p['name']) ?>"
                                    data-period="<?= esc(incDate($c['start']) . ' – ' . incDate($c['end'])) ?>">Ajustar</button>
                            </td>
                        <?php else: ?>
                            <td class="px-4 py-3 text-slate-500" colspan="4">Aún no cumple un año: el <?= esc(incDate($p['next']['date'])) ?> recibe <?= (int)$p['next']['days'] ?> días.</td>
                            <td class="px-4 py-3"></td>
                        <?php endif; ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$people): ?><p class="px-4 py-10 text-center text-sm text-slate-500">Aún no hay personas con acceso a asistencia.</p><?php endif; ?>
    </div>
</main>
<dialog id="adjust-modal" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40">
    <form id="adjust-form" class="p-6" novalidate>
        <input type="hidden" name="usuario">
        <h2 class="text-lg font-semibold text-slate-900">Ajustar el saldo de <span data-name></span></h2>
        <p class="mt-1 text-sm text-slate-500">Año de vacaciones <span data-period></span>.</p>
        <label class="mt-4 block"><span class="mb-1.5 block text-sm font-medium">Días</span>
            <input type="number" name="dias" min="-60" max="60" step="1" required class="block w-full rounded-[10px] px-3 py-2.5 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Ej. -5 (ya tomados) o 2 (días extra)"></label>
        <p class="mt-1.5 text-xs text-slate-500">Negativo: días que ya tomó fuera del sistema. Positivo: días extra que se le otorgan.</p>
        <label class="mt-4 block"><span class="mb-1.5 block text-sm font-medium">Motivo</span>
            <input name="motivo" maxlength="255" required class="block w-full rounded-[10px] px-3 py-2.5 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Ej. Saldo inicial: días tomados antes del sistema"></label>
        <p class="mt-3 rounded-[10px] border border-error/30 bg-error/10 px-3 py-2 text-sm text-error" id="adjust-error" role="alert" hidden></p>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="rounded-[10px] px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100" data-close>Cancelar</button>
            <button class="rounded-[10px] bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary/90">Guardar ajuste</button>
        </div>
    </form>
</dialog>
</body>
</html>
