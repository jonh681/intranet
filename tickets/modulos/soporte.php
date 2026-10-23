<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/correo.php';
require_once __DIR__ . '/../php/seguridad.php';
require_once __DIR__ . '/../php/reporte_helpers.php';
require_ticket_user();
if (!is_support()) {
    http_response_code(403);
    exit('Esta sección está disponible únicamente para el personal de soporte autorizado.');
}
$catalog = ticket_problems();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$error = '';
$success = $_SESSION['support_success'] ?? '';
unset($_SESSION['support_success']);
$oldNote = '';
$tickets = $history = $images = [];
$detail = null;
$filter = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'pendientes';
$filters = ['pendientes' => '0', 'atencion' => '2', 'resueltos' => '1', 'todos' => ''];
if (!isset($filters[$filter])) $filter = 'pendientes';
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$page = max(1, (int) filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT));
$pages = 1;
$db = null;
try {
    $db = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $oldNote = is_string($_POST['comentario'] ?? null) ? trim($_POST['comentario']) : '';
        $action = $_POST['accion'] ?? '';
        try {
            if (!valid_csrf()) throw new InvalidArgumentException('El formulario venció. Intenta de nuevo.');
            if (!$id || !in_array($action, ['validar', 'resolver'], true)) throw new InvalidArgumentException('La acción no es válida.');
            if (mb_strlen($oldNote) > 5000 || ($action === 'resolver' && mb_strlen($oldNote) < 10)) {
                throw new InvalidArgumentException('Para resolver, describe la solución con entre 10 y 5,000 caracteres.');
            }
            $db->beginTransaction();
            $query = $db->prepare('SELECT estatus_ticket FROM tickets WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $current = $query->fetchColumn();
            if ($current === false) throw new InvalidArgumentException('El ticket no existe.');
            if (($action === 'validar' && (int) $current !== 0) || ($action === 'resolver' && (int) $current !== 2)) {
                throw new InvalidArgumentException('El estado del ticket cambió. Revisa su estado actual antes de continuar.');
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
            if ($action === 'validar') {
                $query = $db->prepare('UPDATE tickets SET estatus_ticket = 2 WHERE id = ?');
                $query->execute([$id]);
            } else {
                $query = $db->prepare('UPDATE tickets SET estatus_ticket = 1, fecha_solucion = ?, resuelto_por = ? WHERE id = ?');
                $query->execute([$now->format('Y-m-d'), $_SESSION['user']['email'], $id]);
            }
            $query = $db->prepare('INSERT INTO ticket_seguimiento (ticket_id, usuario_id, accion, comentario, fecha) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$id, $_SESSION['user']['id'], $action, $oldNote, $now->format('Y-m-d H:i:s')]);
            $db->commit();
            audit($action === 'validar' ? 'ticket_validado' : 'ticket_resuelto', 'Ticket #' . $id);
            $_SESSION['support_success'] = $action === 'validar' ? 'Ticket validado. Ahora está en atención.' : 'Ticket resuelto. Se registró la solución.';
            // El aviso sale después de responder (comun/segundo_plano.php).
            $actorName = $_SESSION['user']['name'];
            intranet_despues(fn() => mail_ticket_status($db, $id, $action, $oldNote, $actorName));
            $_SESSION['support_success'] .= ' Se avisará al solicitante por correo.';
            header('Location: soporte.php?' . http_build_query(['id' => $id, 'estado' => $action === 'validar' ? 'atencion' : 'resueltos']), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $exception->getMessage();
        }
    }
    $where = [];
    $params = [];
    if ($filters[$filter] !== '') { $where[] = 't.estatus_ticket = ?'; $params[] = (int) $filters[$filter]; }
    if ($search !== '') {
        $where[] = '(CAST(t.id AS CHAR) = ? OR t.creado_por LIKE ? OR u.nombre_usuario LIKE ?)';
        array_push($params, $search, '%' . $search . '%', '%' . $search . '%');
    }
    // Mismo solicitante que en Reportes: los tickets sin detalle se asocian por el correo de quien lo creó.
    $from = ' FROM tickets t' . ticket_requester_join();
    $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $query = $db->prepare('SELECT COUNT(*)' . $from . $condition);
    $query->execute($params);
    $pages = max(1, (int) ceil($query->fetchColumn() / 10));
    $page = min($page, $pages);
    $query = $db->prepare('SELECT t.*, u.nombre_usuario, d.problemas, d.descripcion, d.actividades_detenidas' . $from . $condition . ' ORDER BY t.id DESC LIMIT 10 OFFSET ' . (($page - 1) * 10));
    $query->execute($params);
    $tickets = $query->fetchAll();
    if ($id) {
        $query = $db->prepare('SELECT t.*, u.nombre_usuario, d.problemas, d.descripcion, d.actividades_detenidas' . $from . ' WHERE t.id = ?');
        $query->execute([$id]);
        $detail = $query->fetch();
        if (!$detail) { http_response_code(404); $error = 'El ticket solicitado no existe.'; }
        else {
            if ($_SERVER['REQUEST_METHOD'] === 'GET') audit('ver_ticket', 'Ticket #' . $id);
            $query = $db->prepare('SELECT id, nombre FROM ticket_evidencias WHERE ticket_id = ? ORDER BY id');
            $query->execute([$id]); $images = $query->fetchAll();
            $query = $db->prepare('SELECT s.*, u.nombre_usuario FROM ticket_seguimiento s JOIN usuarios u ON u.id = s.usuario_id WHERE s.ticket_id = ? ORDER BY s.id DESC');
            $query->execute([$id]); $history = $query->fetchAll();
        }
    }
} catch (Throwable $exception) {
    if ($db && $db->inTransaction()) $db->rollBack();
    error_log('Soporte: ' . $exception->getMessage());
    $error = 'No se pudo completar la operación. Recarga la página e intenta de nuevo.';
}
function support_link(array $params): string { return 'soporte.php?' . escape(http_build_query($params)); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de soporte | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
</head>
<body>
<?php app_header('soporte.php'); ?>
<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <p class="eyebrow">Fedele 360 · Soporte TI</p>
    <h1 class="page-title mt-1">Centro de soporte</h1>
    <p class="mt-2 text-slate-500">Revisa las solicitudes, valida los problemas y da seguimiento hasta su resolución.</p>
    <?php if ($error): ?><div class="alert-error mt-6" role="alert"><?= escape($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success mt-6" role="status"><?= escape($success) ?></div><?php endif; ?>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
    <section class="card overflow-hidden" aria-label="Bandeja de tickets">
        <div class="space-y-4 border-b border-slate-100 p-4">
            <form method="get" class="flex gap-2">
                <input type="hidden" name="estado" value="<?= escape($filter) ?>">
                <label class="relative flex-1">
                    <span class="sr-only">Buscar por folio, nombre o correo</span>
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4" stroke-linecap="round"/></svg>
                    <input type="search" name="q" value="<?= escape($search) ?>" placeholder="Folio, nombre o correo" class="input pl-9">
                </label>
                <button class="btn btn-soft">Buscar</button>
            </form>
            <nav class="grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1" aria-label="Filtrar por estado">
            <?php foreach (['pendientes'=>'Por validar','atencion'=>'En atención','resueltos'=>'Resueltos','todos'=>'Todos'] as $key=>$label): ?>
                <a href="<?= support_link(['estado'=>$key,'q'=>$search]) ?>" class="rounded-md px-2 py-1.5 text-center text-xs font-medium <?= $filter===$key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>" <?= $filter===$key ? 'aria-current="page"' : '' ?>><?= escape($label) ?></a>
            <?php endforeach; ?>
            </nav>
        </div>
        <?php if (!$tickets): ?>
        <div class="px-6 py-14 text-center"><p class="font-medium text-slate-800">Sin solicitudes en esta vista</p><p class="mt-1 text-sm text-slate-500">Prueba otro estado o modifica tu búsqueda.</p></div>
        <?php endif; ?>
        <ul class="divide-y divide-slate-100">
        <?php foreach ($tickets as $ticket): $selected = $id === (int)$ticket['id']; ?>
            <li>
                <a href="<?= support_link(['id'=>$ticket['id'],'estado'=>$filter,'q'=>$search,'pagina'=>$page]) ?>" class="block border-l-4 px-4 py-4 transition <?= $selected ? 'border-brand-600 bg-brand-50/60' : 'border-transparent hover:bg-slate-50' ?>" <?= $selected ? 'aria-current="true"' : '' ?>>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-slate-900">#<?= (int)$ticket['id'] ?></span>
                        <?= status_badge((int)$ticket['estatus_ticket']) ?>
                        <?php if ((int)($ticket['actividades_detenidas'] ?? 0) === 1): ?><span class="badge-stop">Detenido</span><?php endif; ?>
                        <span class="ml-auto text-xs text-slate-400"><?= escape($ticket['fecha_ticket'] ?? '') ?></span>
                    </div>
                    <p class="mt-1.5 text-sm font-medium text-slate-800"><?= escape($ticket['nombre_usuario'] ?: ($ticket['creado_por'] ?: 'Solicitante')) ?></p>
                    <p class="mt-0.5 line-clamp-2 text-sm text-slate-500"><?= escape(mb_substr($ticket['descripcion'] ?: ($ticket['comentarios'] ?? ''),0,160)) ?></p>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
        <nav class="pagination" aria-label="Páginas">
            <?php if ($page>1): ?><a href="<?= support_link(['estado'=>$filter,'q'=>$search,'pagina'=>$page-1]) ?>">← Anterior</a><?php else: ?><span></span><?php endif; ?>
            <span>Página <?= $page ?> de <?= $pages ?></span>
            <?php if ($page<$pages): ?><a href="<?= support_link(['estado'=>$filter,'q'=>$search,'pagina'=>$page+1]) ?>">Siguiente →</a><?php else: ?><span></span><?php endif; ?>
        </nav>
    </section>

    <section class="card lg:sticky lg:top-24" aria-label="Detalle y atención del ticket">
    <?php if (!$detail): ?>
        <div class="px-6 py-24 text-center">
            <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-100 text-slate-400" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-7"><path d="M4 5h16v14H4zM4 9h16M8 13h5M8 16h8" stroke-linecap="round"/></svg></span>
            <h2 class="mt-5 font-semibold text-slate-900">Selecciona una solicitud</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">Aquí verás los detalles, las evidencias y las opciones para validar o resolver el ticket.</p>
        </div>
    <?php else: $state = (int)$detail['estatus_ticket']; ?>
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-6 py-5">
            <div>
                <p class="eyebrow">Detalle de solicitud</p>
                <h2 class="mt-0.5 text-xl font-bold text-slate-900">Ticket #<?= (int)$detail['id'] ?></h2>
            </div>
            <div class="flex flex-wrap gap-2"><?php if ((int)($detail['actividades_detenidas'] ?? 0) === 1): ?><span class="badge-stop">Actividades detenidas</span><?php endif; ?><?= status_badge($state) ?></div>
        </div>
        <div class="space-y-6 px-6 py-6">
            <dl class="grid gap-4 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-slate-500">Solicitante</dt><dd class="mt-0.5 font-medium text-slate-900"><?= escape($detail['nombre_usuario'] ?: ($detail['creado_por'] ?? '')) ?></dd></div>
                <div><dt class="text-xs text-slate-500">Fecha de creación</dt><dd class="mt-0.5 font-medium text-slate-900"><?= escape($detail['fecha_ticket'] ?? '') ?></dd></div>
                <div class="min-w-0"><dt class="text-xs text-slate-500">Correo / creador</dt><dd class="mt-0.5 truncate font-medium text-slate-900"><?= escape($detail['creado_por'] ?? '') ?></dd></div>
                <?php if ($detail['actividades_detenidas'] !== null): ?><div><dt class="text-xs text-slate-500">¿Actividades detenidas?</dt><dd class="mt-0.5 font-medium <?= (int)$detail['actividades_detenidas'] === 1 ? 'text-red-700' : 'text-slate-900' ?>"><?= escape(activity_stop_label((int)$detail['actividades_detenidas'])) ?></dd></div><?php endif; ?>
            </dl>
            <div class="flex flex-wrap gap-1.5"><?php foreach (json_decode($detail['problemas'] ?? '[]',true) ?: [] as $problem): ?><span class="tag"><?= escape($catalog[$problem] ?? $problem) ?></span><?php endforeach; ?></div>
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Descripción del problema</h3>
                <p class="mt-2 text-sm whitespace-pre-wrap text-slate-700 [overflow-wrap:anywhere]"><?= escape($detail['descripcion'] ?: ($detail['comentarios'] ?? 'Sin descripción.')) ?></p>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Evidencias <span class="font-normal text-slate-400">(<?= count($images) ?>)</span></h3>
                <?php if ($images): ?>
                <div class="evidence-grid mt-3"><?php foreach ($images as $image): ?><a href="evidencia.php?id=<?= (int)$image['id'] ?>" target="_blank" rel="noopener" class="block"><img src="evidencia.php?id=<?= (int)$image['id'] ?>" alt="<?= escape($image['nombre']) ?>" loading="lazy" class="transition hover:ring-brand-400"></a><?php endforeach; ?></div>
                <?php else: ?><p class="mt-2 text-sm text-slate-400">No se adjuntaron imágenes.</p><?php endif; ?>
            </div>
            <?php if (in_array($state,[0,2],true)): ?>
            <form method="post" action="<?= support_link(['id'=>$id,'estado'=>$filter]) ?>" class="rounded-xl border border-brand-100 bg-brand-50/50 p-5">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <h3 class="font-semibold text-slate-900"><?= $state===0 ? 'Validar solicitud' : 'Registrar solución' ?></h3>
                <p class="mt-1 text-sm text-slate-500"><?= $state===0 ? 'Confirma la revisión para pasar este ticket a atención.' : 'Describe cómo se solucionó el problema antes de cerrar el ticket.' ?></p>
                <label for="resolution" class="field-label mt-4"><?= $state===0 ? 'Observaciones (opcional)' : 'Solución aplicada *' ?></label>
                <textarea id="resolution" name="comentario" rows="4" maxlength="5000" class="input resize-y" <?= $state===2 ? 'required minlength="10"' : '' ?> placeholder="Escribe aquí los detalles…"><?= escape($oldNote) ?></textarea>
                <div class="mt-4 flex justify-end">
                    <button name="accion" value="<?= $state===0 ? 'validar' : 'resolver' ?>" class="btn <?= $state===0 ? 'btn-primary' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>"><?= $state===0 ? 'Validar y pasar a atención' : 'Guardar solución y resolver ticket' ?></button>
                </div>
            </form>
            <?php elseif ($state===1): ?><div class="alert-success">Resuelto el <?= escape($detail['fecha_solucion'] ?? '') ?> por <?= escape($detail['resuelto_por'] ?? '') ?>.</div><?php endif; ?>
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Historial de atención</h3>
                <?php if (!$history): ?><p class="mt-2 text-sm text-slate-400">Aún no hay movimientos registrados.</p><?php endif; ?>
                <ol class="mt-4 space-y-5 border-l-2 border-slate-100 pl-5">
                <?php foreach ($history as $event): $validated = $event['accion']==='validar'; ?>
                    <li class="relative">
                        <span class="absolute top-1 -left-[1.6rem] size-3 rounded-full ring-4 ring-white <?= $validated ? 'bg-brand-500' : 'bg-emerald-500' ?>" aria-hidden="true"></span>
                        <p class="text-sm font-medium text-slate-900"><?= $validated ? 'Solicitud validada' : 'Ticket resuelto' ?></p>
                        <p class="text-xs text-slate-500"><?= escape($event['nombre_usuario']) ?> · <?= escape($event['fecha']) ?></p>
                        <?php if (trim((string)$event['comentario']) !== ''): ?><p class="mt-1.5 text-sm whitespace-pre-wrap text-slate-600"><?= escape((string)$event['comentario']) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ol>
            </div>
        </div>
    <?php endif; ?>
    </section>
    </div>
</main>
</body>
</html>
