<?php
// Por revisar (administración): solicitudes que esperan a quien valida incidencias.
//   Listas para confirmar  ya las autorizó el jefe directo (o la persona no tiene jefe).
//   Esperando a su jefe    aún no las autoriza su jefe; quien valida también puede decidir sin esperar.
require __DIR__ . '/lib.php';
$user = incRequire($db, 'revisar.php');

$ready = array_reverse(incItems($db, null, ['autorizada'])); // las que empiezan antes, primero
$waiting = array_reverse(incItems($db, null, ['pendiente']));
$balances = [];
foreach (array_unique(array_map(fn($i) => $i['user']['id'], array_filter([...$ready, ...$waiting], fn($i) => $i['type'] === 'vacaciones'))) as $id) {
    $balances[$id] = incBalance($db, $id)['period'];
}
$validators = incValidators($db);
$who = $validators ? implode(' o ', $validators) : 'nadie todavía (se asigna en Usuarios → Editar)';
$card = function (array $i, bool $early) use ($user, $who, $balances): string {
    $buttons = [['revisar', 'aprobar', $early ? 'Aprobar sin esperar' : 'Aprobar', true], ['revisar', 'rechazar', 'Rechazar', false]];
    if ($i['user']['id'] === $user['id']) return incRequestCard($i, [], 'Es tu solicitud: la valida otra persona.', $balances[$i['user']['id']] ?? null);
    if (!$user['valida']) return incRequestCard($i, [], 'La valida ' . $who . '.', $balances[$i['user']['id']] ?? null);
    return incRequestCard($i, $buttons, '', $balances[$i['user']['id']] ?? null);
};

echo incHeader($db, $user, 'revisar.php', 'Por revisar');
?>
<main class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
    <?= incTitle('Administración', 'Por revisar', 'Primero autoriza el jefe directo y después se confirma aquí. Al aprobarlas justifican los días en asistencia y las vacaciones se descuentan del saldo.') ?>
    <?php if (!$user['valida']): ?><p class="mt-6 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">Puedes consultarlas; las valida <?= esc($who) ?>.</p><?php endif; ?>
    <div id="notice" class="mt-6 rounded-xl border px-4 py-3 text-sm" role="status" hidden></div>

    <section class="mt-8" aria-labelledby="ready-title">
        <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900" id="ready-title">Listas para confirmar <span class="rounded-full bg-amber-100 px-2 text-sm text-amber-700"><?= count($ready) ?></span></h2>
        <p class="text-sm text-slate-500">Ya las autorizó su jefe directo, o la persona no tiene jefe asignado.</p>
        <div class="mt-4 space-y-3" data-review-list data-paginar>
            <?php foreach ($ready as $i) echo $card($i, false); ?>
        </div>
        <p class="mt-4 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500" data-review-empty <?= $ready ? 'hidden' : '' ?>>Nada por confirmar. ¡Todo al día!</p>
    </section>

    <section class="mt-12" aria-labelledby="waiting-title">
        <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900" id="waiting-title">Esperando a su jefe <span class="rounded-full bg-slate-100 px-2 text-sm text-slate-600"><?= count($waiting) ?></span></h2>
        <p class="text-sm text-slate-500">Su jefe directo aún no las autoriza. Si es urgente, puedes decidir sin esperar.</p>
        <div class="mt-4 space-y-3" data-review-list data-paginar>
            <?php foreach ($waiting as $i) echo $card($i, true); ?>
        </div>
        <p class="mt-4 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500" data-review-empty <?= $waiting ? 'hidden' : '' ?>>Ningún jefe tiene solicitudes pendientes.</p>
    </section>
</main>
<?= incRejectModal() ?>
</body>
</html>
