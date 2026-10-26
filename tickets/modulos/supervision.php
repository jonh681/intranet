<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/reporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
require_ticket_user();
if (!is_supervisor()) { http_response_code(403); exit('Acceso exclusivo de supervisión.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
$filters = array_fill_keys(report_filter_keys(), '');
$rows = $history = $images = $areas = $employees = $areaNames = [];
$summary = ['total' => 0, 'resueltos' => 0, 'atencion' => 0, 'pendientes' => 0];
$detail = null; $error = ''; $total = 0; $pages = 1;
$page = max(1, (int) filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT));
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$catalog = ticket_problems();
try {
    $db = database();
    // Solo para el filtro «Empleado»; la administración de usuarios es exclusiva del superusuario (seguridad.php).
    $employees = $db->query('SELECT id, nombre_usuario, correo_usuario FROM usuarios ORDER BY nombre_usuario')->fetchAll();
    $areaNames = $db->query('SELECT DISTINCT COALESCE(NULLIF(area, \'\'), \'Sin área\') area FROM usuarios ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
    $filters = report_filters();
    // The dashboard ignores the status filter so it always shows the full status breakdown.
    [$dashFrom, $dashParams] = report_query(array_merge($filters, ['estado' => '']));
    $query = $db->prepare('SELECT COALESCE(NULLIF(u.area, \'\'), \'Sin área\') area, COUNT(*) total,
        SUM(t.estatus_ticket = 1) resueltos, SUM(t.estatus_ticket = 2) atencion, SUM(t.estatus_ticket = 0) pendientes'
        . $dashFrom . ' GROUP BY 1 ORDER BY total DESC, area');
    $query->execute($dashParams); $areas = $query->fetchAll();
    foreach ($areas as $area) {
        foreach ($summary as $key => $value) $summary[$key] = $value + (int)$area[$key];
    }
    [$from,$params] = report_query($filters);
    $query = $db->prepare('SELECT COUNT(*)' . $from); $query->execute($params);
    $total = (int)$query->fetchColumn(); $pages = max(1,(int)ceil($total/10)); $page = min($page,$pages);
    $query = $db->prepare(report_columns() . $from . ' ORDER BY t.id DESC LIMIT 10 OFFSET ' . (($page-1)*10));
    $query->execute($params); $rows = $query->fetchAll();
    if ($id) {
        [$detailFrom] = report_query(array_fill_keys(array_keys($filters), ''));
        $query = $db->prepare(report_columns() . $detailFrom . ' WHERE t.id = ?');
        $query->execute([$id]); $detail = $query->fetch();
        if (!$detail) { http_response_code(404); $error = 'La solicitud no existe.'; }
        else {
            audit('ver_ticket', 'Ticket #' . $id);
            $query=$db->prepare('SELECT s.*,u.nombre_usuario FROM ticket_seguimiento s JOIN usuarios u ON u.id=s.usuario_id WHERE s.ticket_id=? ORDER BY s.id');
            $query->execute([$id]); $history=$query->fetchAll();
            $query=$db->prepare('SELECT id,nombre FROM ticket_evidencias WHERE ticket_id=? ORDER BY id');
            $query->execute([$id]); $images=$query->fetchAll();
        }
    }
} catch (InvalidArgumentException $exception) { http_response_code(400); $error=$exception->getMessage(); }
catch (Throwable $exception) { error_log('Supervision: '.$exception->getMessage()); $error='No se pudieron cargar las solicitudes. Intenta de nuevo.'; }
function review_url(array $params): string { return 'supervision.php?' . escape(http_build_query($params)); }
function percent(int $part, int $whole): string { return $whole ? rtrim(rtrim(number_format($part * 100 / $whole, 1), '0'), '.') : '0'; }
$maxArea = max(array_merge([1], array_map(static fn($a) => (int)$a['total'], $areas)));
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reportes de soporte | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
    <script src="../script/supervision.js?v=3" defer></script></head>
    <body>
        <?php app_header('supervision.php'); ?>
        <main class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="eyebrow">Reportes</p>
                    <h1 class="page-title mt-1">Seguimiento de solicitudes</h1>
                    <p class="mt-2 max-w-2xl text-slate-500">Consulta qué áreas generan más tickets, el avance de su atención y el detalle de cada solicitud.</p>
                </div>
            </div>

            <form method="get" class="card space-y-4 p-4" id="filters" aria-label="Filtros">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto] xl:items-end">
                    <label class="relative block">
                        <span class="field-label">Buscar</span>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" class="pointer-events-none absolute bottom-3 left-3 size-4 text-slate-400" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4" stroke-linecap="round"/></svg>
                        <input type="search" name="q" value="<?= escape($filters['q']) ?>" placeholder="Folio, nombre o correo" class="input pl-9">
                    </label>
                    <label class="block">
                        <span class="field-label">Empleado</span>
                        <select name="empleado" class="input"><option value="">Todos</option><?php foreach ($employees as $employee): ?>
                            <option value="<?= (int)$employee['id'] ?>" <?= (string)$employee['id']===$filters['empleado']?'selected':'' ?>><?= escape($employee['nombre_usuario'] ?: $employee['correo_usuario']) ?></option>
                        <?php endforeach; ?></select>
                    </label>
                    <label class="block">
                        <span class="field-label">Área</span>
                        <select name="area" class="input"><option value="">Todas</option><?php foreach ($areaNames as $name): ?>
                            <option value="<?= escape($name) ?>" <?= $name===$filters['area']?'selected':'' ?>><?= escape($name) ?></option>
                        <?php endforeach; ?></select>
                    </label>
                    <div>
                        <span class="field-label" id="dates-label">Fecha de creación</span>
                        <div class="flex items-center gap-2" role="group" aria-labelledby="dates-label">
                            <input type="date" name="desde" value="<?= escape($filters['desde']) ?>" aria-label="Desde" class="input">
                            <span class="text-slate-400" aria-hidden="true">→</span>
                            <input type="date" name="hasta" value="<?= escape($filters['hasta']) ?>" aria-label="Hasta" class="input">
                        </div>
                    </div>
                    <button class="btn btn-primary">Aplicar</button>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                    <fieldset class="inline-flex rounded-lg bg-slate-100 p-1">
                        <legend class="sr-only">Estatus</legend>
                        <?php foreach ([''=>'Todos','0'=>'Pendientes','2'=>'En proceso','1'=>'Resueltos'] as $key=>$label): ?>
                        <label class="cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-800 has-checked:bg-white has-checked:text-slate-900 has-checked:shadow-sm has-focus-visible:outline-2 has-focus-visible:outline-brand-500"><input type="radio" name="estado" value="<?= escape((string)$key) ?>" class="sr-only" <?= (string)$key===$filters['estado']?'checked':'' ?>><?= $label ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php
                    $chips = [];
                    if ($filters['q'] !== '') $chips['q'] = 'Búsqueda: ' . $filters['q'];
                    if ($filters['empleado'] !== '') {
                        foreach ($employees as $employee) if ((string)$employee['id'] === $filters['empleado']) $chips['empleado'] = $employee['nombre_usuario'] ?: $employee['correo_usuario'];
                    }
                    if ($filters['area'] !== '') $chips['area'] = 'Área: ' . $filters['area'];
                    if ($filters['desde'] !== '') $chips['desde'] = 'Desde ' . date('d/m/Y', strtotime($filters['desde']));
                    if ($filters['hasta'] !== '') $chips['hasta'] = 'Hasta ' . date('d/m/Y', strtotime($filters['hasta']));
                    ?>
                    <?php if ($chips || $filters['estado'] !== ''): ?>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php foreach ($chips as $key => $label): ?>
                        <a class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 py-1 pr-2 pl-3 text-xs font-medium text-brand-700 hover:bg-brand-100" href="<?= review_url(array_merge($filters, [$key => ''])) ?>" aria-label="Quitar filtro <?= escape($label) ?>"><?= escape($label) ?> <span aria-hidden="true" class="text-sm leading-none">×</span></a>
                        <?php endforeach; ?>
                        <a class="text-xs font-medium text-slate-500 underline-offset-2 hover:text-slate-800 hover:underline" href="supervision.php">Limpiar todo</a>
                    </div>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($error): ?>
            <div class="alert-error" role="alert"><?= escape($error) ?></div>
            <?php else: ?>
            <section class="space-y-6" aria-label="Resumen de tickets">
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <?php foreach ([['', 'Total de tickets', 'total', 'bg-slate-400'], ['1', 'Resueltos', 'resueltos', 'bg-emerald-500'], ['2', 'En proceso', 'atencion', 'bg-brand-500'], ['0', 'Pendientes', 'pendientes', 'bg-amber-400']] as [$state, $label, $key, $dot]): ?>
                    <a class="card block p-5 transition hover:shadow-md aria-[current=true]:ring-2 aria-[current=true]:ring-brand-500" href="<?= review_url(array_merge($filters, ['estado' => $state])) ?>#registros" <?= $filters['estado'] === $state ? 'aria-current="true"' : '' ?>>
                        <span class="flex items-center gap-2 text-sm font-medium text-slate-500"><span class="size-2 rounded-full <?= $dot ?>" aria-hidden="true"></span><?= $label ?></span>
                        <strong class="mt-2 block text-3xl font-bold tracking-tight text-slate-900"><?= $summary[$key] ?></strong>
                        <small class="mt-1 block text-xs text-slate-400"><?= $key === 'total' ? 'Según los filtros aplicados' : percent($summary[$key], $summary['total']) . '% del total' ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
                <div class="card p-5 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-semibold text-slate-900">Tickets por área</h2>
                        <ul class="flex flex-wrap gap-4 text-xs text-slate-500" aria-label="Leyenda">
                            <li class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-emerald-500"></i>Resueltos</li><li class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-brand-500"></i>En proceso</li><li class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-amber-400"></i>Pendientes</li>
                        </ul>
                    </div>
                    <?php if (!$areas): ?><p class="mt-4 text-sm text-slate-400">No hay tickets con estos filtros.</p><?php endif; ?>
                    <div class="mt-4 space-y-1">
                    <?php foreach ($areas as $area): $x = 0; ?>
                    <a class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)_2.5rem] items-center gap-x-4 gap-y-1 rounded-lg px-2 py-2 hover:bg-slate-50 aria-[current=true]:bg-brand-50 sm:grid-cols-[minmax(0,11rem)_minmax(0,1fr)_2.5rem]" href="<?= review_url(array_merge($filters, ['area' => $area['area']])) ?>#registros" <?= $filters['area'] === $area['area'] ? 'aria-current="true"' : '' ?> title="<?= (int)$area['resueltos'] ?> resueltos · <?= (int)$area['atencion'] ?> en proceso · <?= (int)$area['pendientes'] ?> pendientes">
                        <span class="truncate text-sm font-medium text-slate-700"><?= escape($area['area']) ?></span>
                        <svg class="h-3 w-full overflow-hidden rounded-full bg-slate-100" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                            <?php foreach (['resueltos' => 'fill-emerald-500', 'atencion' => 'fill-brand-500', 'pendientes' => 'fill-amber-400'] as $key => $fill): $w = (int)$area[$key] * 100 / $maxArea; ?>
                            <?php if ($w > 0): ?><rect class="<?= $fill ?>" x="<?= $x ?>" y="0" width="<?= $w ?>" height="10"/><?php endif; $x += $w; ?>
                            <?php endforeach; ?>
                        </svg>
                        <strong class="text-right text-sm font-semibold text-slate-900 tabular-nums"><?= (int)$area['total'] ?></strong>
                        <small class="col-start-2 col-end-4 text-xs text-slate-400"><?= (int)$area['resueltos'] ?> resueltos · <?= (int)$area['atencion'] ?> en proceso · <?= (int)$area['pendientes'] ?> pendientes</small>
                    </a>
                    <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="card scroll-mt-24 overflow-hidden" id="registros" aria-labelledby="records-title">
                <div class="flex flex-col gap-4 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 id="records-title" class="flex items-center gap-2 font-semibold text-slate-900">Registros <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= $total ?></span></h2>
                        <p class="mt-1 text-sm text-slate-500">El CSV incluye todas las solicitudes filtradas, no solo esta página. Los tiempos son transcurridos, no horas laborables.</p>
                    </div>
                    <a class="btn btn-secondary aria-disabled:pointer-events-none aria-disabled:opacity-50" href="reporte.php?<?= escape(http_build_query($filters)) ?>" <?= $total ? '' : 'aria-disabled="true"' ?>>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" class="size-4" aria-hidden="true"><path d="M10 3v10M5.5 8.5 10 13l4.5-4.5M4 16.5h12" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Descargar CSV
                    </a>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-left text-sm">
                    <caption class="sr-only"><?= $total ?> solicitudes encontradas</caption>
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Folio / creación</th>
                            <th class="px-3 py-3">Solicitante</th><th class="px-3 py-3">Área</th><th class="px-3 py-3">Estado</th>
                            <th class="px-3 py-3">Validó</th><th class="px-3 py-3">Resolvió</th>
                            <th class="px-3 py-3">Tiempo a resolución</th><th class="px-5 py-3"><span class="sr-only">Detalle</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 [&_small]:block [&_small]:text-xs [&_small]:text-slate-400">
                        <?php foreach ($rows as $row): ?>
                        <tr class="align-top hover:bg-slate-50/70 <?= $id === (int)$row['id'] ? 'bg-brand-50/50' : '' ?>">
                            <td class="px-5 py-3"><strong class="font-semibold text-slate-900">#<?= (int)$row['id'] ?></strong><small><?= escape($row['fecha_ticket'] ?? '') ?></small></td>
                            <td class="px-3 py-3 text-slate-800"><?= escape($row['nombre_usuario'] ?: ($row['creado_por'] ?? '')) ?><small><?= escape($row['creado_por'] ?? '') ?></small></td>
                            <td class="px-3 py-3 text-slate-600"><?= escape($row['area']) ?></td>
                            <td class="px-3 py-3"><?= status_badge((int)$row['estatus_ticket']) ?></td>
                            <td class="px-3 py-3 text-slate-600"><?= escape($row['validador'] ?? 'Sin registro') ?><small><?= escape($row['validado_en'] ?? '') ?></small></td>
                            <td class="px-3 py-3 text-slate-600"><?= escape($row['resuelto_por'] ?: 'Sin registro') ?><small><?= escape($row['resuelto_en'] ?: ($row['fecha_solucion'] ?? '')) ?></small></td>
                            <td class="px-3 py-3 text-slate-600 tabular-nums"><?= escape(report_duration($row['minutos_resolucion'])) ?></td>
                            <td class="px-5 py-3 text-right"><a class="font-medium whitespace-nowrap text-brand-700 hover:text-brand-900" href="<?= review_url(array_merge($filters,['id'=>$row['id'],'pagina'=>$page])) ?>#detalle">Ver solicitud →</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                        <tr>
                            <td colspan="8" class="px-5 py-12 text-center text-slate-400">No hay solicitudes con estos filtros.</td>
                        </tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                <nav class="pagination" aria-label="Páginas"><?php if ($page>1): ?>
                    <a href="<?= review_url(array_merge($filters,['pagina'=>$page-1])) ?>#registros">← Anterior</a>
                    <?php else: ?><span></span><?php endif; ?><span>Página <?= $page ?> de <?= $pages ?></span><?php if ($page<$pages): ?>
                    <a href="<?= review_url(array_merge($filters,['pagina'=>$page+1])) ?>#registros">Siguiente →</a>
                    <?php else: ?><span></span><?php endif; ?>
                </nav>
            </section>
            <?php endif; ?>

            <?php if ($detail): ?>
            <section class="card scroll-mt-24 overflow-hidden" id="detalle">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <h2 class="text-xl font-bold text-slate-900">Solicitud #<?= (int)$detail['id'] ?></h2>
                    <?= status_badge((int)$detail['estatus_ticket']) ?>
                </div>
                <div class="grid gap-8 p-6 lg:grid-cols-2">
                    <div class="space-y-5">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Problemas reportados</h3>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <?php foreach(json_decode($detail['problemas'] ?? '[]',true) ?: [] as $problem): ?>
                                <span class="tag"><?= escape($catalog[$problem] ?? $problem) ?></span><?php endforeach; ?>
                            </div>
                        </div>
                        <p class="text-sm whitespace-pre-wrap text-slate-700 [overflow-wrap:anywhere]"><?= escape($detail['descripcion'] ?? '') ?></p>
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Evidencias</h3>
                            <?php if ($images): ?>
                            <div class="evidence-grid mt-3"><?php foreach($images as $image): ?>
                                <a href="evidencia.php?id=<?= (int)$image['id'] ?>" target="_blank" rel="noopener" class="block"><img src="evidencia.php?id=<?= (int)$image['id'] ?>" alt="<?= escape($image['nombre']) ?>" loading="lazy" class="transition hover:ring-brand-400"></a>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="mt-2 text-sm text-slate-400">Sin imágenes adjuntas.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="space-y-5">
                        <dl class="grid gap-4 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs text-slate-500">Solicitante</dt><dd class="mt-0.5 font-medium text-slate-900"><?= escape($detail['nombre_usuario'] ?: ($detail['creado_por'] ?? '')) ?></dd></div>
                            <div><dt class="text-xs text-slate-500">Área</dt><dd class="mt-0.5 font-medium text-slate-900"><?= escape($detail['area']) ?></dd></div>
                            <div><dt class="text-xs text-slate-500">Tiempo a validación</dt><dd class="mt-0.5 font-medium text-slate-900"><?= escape(report_duration($detail['minutos_validacion'])) ?></dd></div>
                            <div><dt class="text-xs text-slate-500">Tiempo a resolución</dt><dd class="mt-0.5 font-medium text-slate-900"><?= escape(report_duration($detail['minutos_resolucion'])) ?></dd></div>
                        </dl>
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Historial de atención</h3>
                            <?php if (!$history): ?>
                                <p class="mt-2 text-sm text-slate-400">Sin movimientos registrados.</p>
                            <?php endif; ?>
                            <ol class="mt-4 space-y-5 border-l-2 border-slate-100 pl-5">
                            <?php foreach($history as $event): $validated = $event['accion']==='validar'; ?>
                                <li class="relative">
                                    <span class="absolute top-1 -left-[1.6rem] size-3 rounded-full ring-4 ring-white <?= $validated ? 'bg-brand-500' : 'bg-emerald-500' ?>" aria-hidden="true"></span>
                                    <p class="text-sm font-medium text-slate-900"><?= $validated ? 'Validación' : 'Resolución' ?></p>
                                    <p class="text-xs text-slate-500"><?= escape($event['nombre_usuario']) ?> · <?= escape($event['fecha']) ?></p>
                                    <?php if (trim((string)$event['comentario']) !== ''): ?><p class="mt-1.5 text-sm whitespace-pre-wrap text-slate-600"><?= escape((string)$event['comentario']) ?></p><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>
                </div>
            </section><?php endif; ?>
        </main>
    </body>
</html>
