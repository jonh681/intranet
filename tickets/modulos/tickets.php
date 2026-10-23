<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_ticket_user();
// Soporte solo ve el Panel de soporte.
if (is_support()) {
    header('Location: soporte.php', true, 303);
    exit;
}
$catalog = ticket_problems();
$error = $_SESSION['ticket_error'] ?? '';
$success = $_SESSION['ticket_success'] ?? '';
$old = ($_SESSION['ticket_old'] ?? []) + ['descripcion' => '', 'problemas' => [], 'actividades_detenidas' => null];
$asksActivity = asks_activity_stop();
unset($_SESSION['ticket_error'], $_SESSION['ticket_success'], $_SESSION['ticket_old']);
$_SESSION['ticket_form_token'] = $_SESSION['ticket_form_token'] ?? bin2hex(random_bytes(32));
$tickets = [];
$evidence = [];
$loadError = false;
try {
    $db = database();
    $query = $db->prepare('SELECT t.id, t.estatus_ticket, d.problemas, d.descripcion, d.actividades_detenidas, d.creado_en
        FROM tickets t JOIN ticket_detalles d ON d.ticket_id = t.id
        WHERE d.usuario_id = ? ORDER BY t.id DESC LIMIT 500');
    $query->execute([$_SESSION['user']['id']]);
    $tickets = $query->fetchAll();
    if ($tickets) {
        $ids = array_column($tickets, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $db->prepare('SELECT id, ticket_id, nombre FROM ticket_evidencias WHERE ticket_id IN (' . $marks . ') ORDER BY id');
        $query->execute($ids);
        foreach ($query->fetchAll() as $file) {
            $evidence[$file['ticket_id']][] = $file;
        }
    }
} catch (Throwable $exception) {
    error_log('Listado tickets: ' . $exception->getMessage());
    $loadError = true;
}
$counts = array_count_values(array_map(static fn($t) => (int) $t['estatus_ticket'], $tickets)) + [0 => 0, 1 => 0, 2 => 0];
$step = 0;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi espacio | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
    <script src="../script/tickets.js?v=<?= (int) filemtime(__DIR__ . '/../script/tickets.js') ?>" defer></script>
</head>
<body>
    <?php app_header('tickets.php'); ?>
    <main class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="eyebrow">Mi espacio</p>
                <h1 class="page-title mt-1">Hola, <?= escape($_SESSION['user']['name']) ?></h1>
                <p class="mt-2 text-slate-500">Cuéntanos qué sucede. Aquí puedes consultar tus solicitudes de soporte de TI.</p>
            </div>
            <button class="btn btn-primary px-5 py-3" id="open-ticket" type="button">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke-linecap="round"/></svg>
                Crear ticket
            </button>
        </div>

        <?php if ($success): ?><div class="alert-success mt-8" role="status"><?= escape($success) ?></div><?php endif; ?>

        <?php if ($tickets): ?>
        <dl class="mt-8 grid grid-cols-3 gap-3 sm:gap-4">
            <?php foreach ([[0, 'Pendientes', 'bg-amber-400'], [2, 'En atención', 'bg-brand-500'], [1, 'Resueltos', 'bg-emerald-500']] as [$state, $label, $dot]): ?>
            <div class="card px-4 py-4 sm:px-5">
                <dt class="flex items-center gap-2 text-xs font-medium text-slate-500 sm:text-sm"><span class="size-2 rounded-full <?= $dot ?>" aria-hidden="true"></span><?= $label ?></dt>
                <dd class="mt-1 text-2xl font-bold text-slate-900 sm:text-3xl"><?= $counts[$state] ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>

        <?php if ($loadError): ?>
            <div class="alert-error mt-8" role="alert">No se pudieron cargar tus tickets. Intenta recargar la página; si continúa, contacta al administrador.</div>
        <?php elseif (!$tickets): ?>
            <section class="card mt-10 px-6 py-16 text-center">
                <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-600" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-7"><path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z" stroke-linejoin="round"/><path d="M14 5v12" stroke-dasharray="2 2"/></svg>
                </span>
                <h2 class="mt-5 text-lg font-semibold text-slate-900">Aún no tienes solicitudes</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">Crea tu primer ticket, selecciona los problemas y agrega los detalles para que podamos ayudarte.</p>
            </section>
        <?php else: ?>
            <div class="mt-10 flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-semibold text-slate-900">Mis tickets</h2>
                <span class="text-sm text-slate-500"><?= count($tickets) ?> <?= count($tickets) === 1 ? 'solicitud' : 'solicitudes' ?></span>
            </div>
            <div class="mt-4 space-y-3" data-paginar>
            <?php foreach ($tickets as $ticket): ?>
                <article class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 pt-5">
                        <h3 class="font-semibold text-slate-900">Ticket #<?= (int) $ticket['id'] ?></h3>
                        <?= status_badge((int) $ticket['estatus_ticket']) ?>
                        <?php if ((int) $ticket['actividades_detenidas'] === 1): ?><span class="badge-stop">Actividades detenidas</span><?php endif; ?>
                        <time class="ml-auto text-xs text-slate-500" datetime="<?= escape(date('c', strtotime($ticket['creado_en']))) ?>"><?= escape(date('d/m/Y H:i', strtotime($ticket['creado_en']))) ?></time>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1.5 px-5">
                    <?php foreach (json_decode($ticket['problemas'], true) ?? [] as $problem): ?>
                        <span class="tag"><?= escape($catalog[$problem] ?? $problem) ?></span>
                    <?php endforeach; ?>
                    </div>
                    <p class="mt-3 line-clamp-2 px-5 text-sm text-slate-600"><?= escape($ticket['descripcion']) ?></p>
                    <details class="group mt-4 border-t border-slate-100">
                        <summary class="flex items-center justify-between px-5 py-3 text-sm font-medium text-brand-700 hover:bg-slate-50">
                            Ver descripción y evidencias
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="size-4 transition-transform group-open:rotate-180" aria-hidden="true"><path d="m5 7.5 5 5 5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="space-y-4 px-5 pb-5">
                            <p class="text-sm whitespace-pre-wrap text-slate-700 [overflow-wrap:anywhere]"><?= escape($ticket['descripcion']) ?></p>
                            <?php if ($ticket['actividades_detenidas'] !== null): ?><p class="text-sm text-slate-500">¿Tus actividades se detienen? <strong class="text-slate-800"><?= (int) $ticket['actividades_detenidas'] === 1 ? 'Sí' : 'No' ?></strong></p><?php endif; ?>
                            <?php if (!empty($evidence[$ticket['id']])): ?>
                                <div class="evidence-grid">
                                <?php foreach ($evidence[$ticket['id']] as $file): ?>
                                    <a href="evidencia.php?id=<?= (int) $file['id'] ?>" target="_blank" rel="noopener" class="group/img block">
                                        <img src="evidencia.php?id=<?= (int) $file['id'] ?>" alt="<?= escape($file['nombre']) ?> del ticket #<?= (int) $ticket['id'] ?>" loading="lazy" class="transition group-hover/img:ring-brand-400">
                                        <span class="mt-1 block truncate text-xs text-slate-500 group-hover/img:text-brand-700"><?= escape($file['nombre']) ?> ↗</span>
                                    </a>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?><p class="text-sm text-slate-400">Sin imágenes adjuntas.</p><?php endif; ?>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <noscript><div class="alert-error mt-6">Activa JavaScript en tu navegador para abrir el formulario de creación de tickets.</div></noscript>
    </main>

    <dialog id="ticket-modal" class="modal max-w-2xl" aria-labelledby="ticket-title" data-reopen="<?= $error ? 'true' : 'false' ?>">
        <div class="modal-header">
            <div class="flex gap-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-600 text-white" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z"/><path d="M9.5 9.2a2.6 2.6 0 0 1 5 .9c0 1.7-2.5 2.2-2.5 3.7M12 17h.01" stroke-linecap="round"/></svg>
                </span>
                <div>
                    <p class="eyebrow">Estamos para ayudarte</p>
                    <h2 id="ticket-title" class="mt-0.5 text-xl font-bold text-slate-900">¿En qué podemos ayudarte?</h2>
                    <p class="mt-1 text-sm text-slate-500">Cuéntanos qué sucede y enviaremos tu solicitud a soporte.</p>
                </div>
            </div>
            <button type="button" class="btn-icon" id="close-ticket" aria-label="Cerrar formulario">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/></svg>
            </button>
        </div>
        <form action="crear_ticket.php" method="post" enctype="multipart/form-data" id="ticket-form" class="flex min-h-0 flex-auto flex-col">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="solicitud" value="<?= escape($_SESSION['ticket_form_token']) ?>">
            <div class="modal-body space-y-7">
            <?php if ($error): ?><div class="alert-error" role="alert"><?= escape($error) ?> Si adjuntaste imágenes, selecciónalas de nuevo.</div><?php endif; ?>
            <fieldset aria-describedby="problems-help">
                <legend class="font-semibold text-slate-900"><span class="flex items-center gap-2.5"><span class="grid size-6 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700"><?= ++$step ?></span> Selecciona los problemas <span class="text-red-600">*</span></span></legend>
                <p class="help-text mb-3 ml-8.5" id="problems-help">Marca todas las opciones que correspondan a tu solicitud.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                <?php foreach ($catalog as $key => $label): ?>
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3.5 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 has-checked:border-brand-500 has-checked:bg-brand-50 has-checked:text-brand-800">
                        <input type="checkbox" name="problemas[]" value="<?= escape($key) ?>" class="size-4 accent-brand-600" <?= in_array($key, $old['problemas'], true) ? 'checked' : '' ?>>
                        <span><?= escape($label) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <div>
                <label for="description" class="flex items-center gap-2.5 font-semibold text-slate-900"><span class="grid size-6 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700"><?= ++$step ?></span> Cuéntanos los detalles <span class="text-red-600">*</span></label>
                <textarea id="description" name="descripcion" rows="4" minlength="10" maxlength="5000" required class="input mt-3 min-h-28 resize-y" placeholder="Ej. Desde esta mañana no puedo conectarme a internet. Ya reinicié mi equipo y el problema continúa…" aria-describedby="description-help"><?= escape($old['descripcion']) ?></textarea>
                <p class="help-text flex justify-between gap-4" id="description-help"><span>Incluye qué sucede y desde cuándo. Mínimo 10 caracteres.</span><span class="tabular-nums"><span id="description-count">0</span>/5,000</span></p>
            </div>
            <?php if ($asksActivity): ?>
            <fieldset>
                <legend class="font-semibold text-slate-900"><span class="flex items-center gap-2.5"><span class="grid size-6 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700"><?= ++$step ?></span> ¿Tus actividades se detienen? <span class="text-red-600">*</span></span></legend>
                <p class="help-text mb-3 ml-8.5">Indica si este problema te impide continuar con tu trabajo.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                <?php foreach (['si' => [1, 'Sí, no puedo trabajar', 'has-checked:border-red-500 has-checked:bg-red-50 has-checked:text-red-800'], 'no' => [0, 'No, puedo continuar', 'has-checked:border-emerald-500 has-checked:bg-emerald-50 has-checked:text-emerald-800']] as $value => [$stored, $label, $checked]): ?>
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3.5 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 <?= $checked ?>">
                        <input type="radio" name="actividades_detenidas" value="<?= $value ?>" class="size-4 accent-brand-600" required <?= $old['actividades_detenidas'] === $stored ? 'checked' : '' ?>>
                        <span><?= $label ?></span>
                    </label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <?php endif; ?>
            <div>
                <label for="evidence" class="flex items-center gap-2.5 font-semibold text-slate-900"><span class="grid size-6 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700"><?= ++$step ?></span> Agrega evidencia <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Opcional</span></label>
                <div class="mt-3 rounded-xl border-2 border-dashed border-slate-200 bg-slate-50/60 p-5">
                    <div class="flex items-start gap-3">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="mt-0.5 size-6 shrink-0 text-brand-500" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 15-5-5L5 20" stroke-linejoin="round"/></svg>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-800">Una imagen nos ayuda a entenderlo mejor</p>
                            <p class="text-xs text-slate-500">Adjunta capturas de pantalla o fotos del problema.</p>
                            <input id="evidence" name="evidencias[]" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="evidence-help evidence-error" class="mt-3 block w-full text-sm text-slate-500 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-brand-600 file:px-3.5 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-700">
                            <p class="help-text" id="evidence-help">Hasta 3 imágenes. Puedes subir fotos o capturas del celular o iPad: se reducen automáticamente antes de enviarse.</p>
                            <button type="button" id="clear-evidence" class="btn btn-secondary mt-3 px-3 py-1.5 text-xs" hidden>Quitar imágenes</button>
                        </div>
                    </div>
                </div>
                <p id="evidence-error" class="mt-2 text-sm text-red-700 empty:hidden" role="alert"></p>
                <div id="evidence-preview" class="evidence-grid mt-3 empty:hidden" aria-live="polite"></div>
            </div>
            </div>
            <div class="modal-footer">
                <span class="mr-auto hidden text-xs text-slate-500 sm:inline"><span class="text-red-600">*</span> Campos obligatorios</span>
                <button type="button" class="btn btn-secondary" id="cancel-ticket">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="submit-ticket">Enviar solicitud →</button>
            </div>
        </form>
    </dialog>
</body>
</html>
