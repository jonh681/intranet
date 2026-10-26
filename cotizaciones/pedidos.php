<?php
// Historial de pedidos y cotizaciones: el representante ve lo que él envió; el superusuario ve el de todos y filtra por persona.
require __DIR__ . '/lib.php';
$user = cotRequire($db, 'pedidos.php');
$admin = $user['rol'] === 'admin';

$f = [
    'tipo' => in_array($_GET['tipo'] ?? '', ['pedido', 'cotizacion'], true) ? $_GET['tipo'] : '',
    'desde' => validDate((string)($_GET['desde'] ?? ''), '2020-01-01', '2100-12-31') ? $_GET['desde'] : '',
    'hasta' => validDate((string)($_GET['hasta'] ?? ''), '2020-01-01', '2100-12-31') ? $_GET['hasta'] : '',
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'rep' => $admin ? (int)($_GET['rep'] ?? 0) : $user['id'],
];
$where = [];
$params = [];
if ($f['rep']) { $where[] = 'p.usuario_id = ?'; $params[] = $f['rep']; }
if ($f['tipo']) { $where[] = 'p.tipo = ?'; $params[] = $f['tipo']; }
if ($f['desde']) { $where[] = 'p.creado_en >= ?'; $params[] = $f['desde'] . ' 00:00:00'; }
if ($f['hasta']) { $where[] = 'p.creado_en <= ?'; $params[] = $f['hasta'] . ' 23:59:59'; }
if ($f['q'] !== '') { $where[] = '(p.cliente LIKE ? OR CAST(p.id AS CHAR) = ?)'; array_push($params, '%' . $f['q'] . '%', ltrim($f['q'], '#')); }
$sql = ' FROM cot_pedidos p JOIN usuarios u ON u.id = p.usuario_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

$query = $db->prepare("SELECT COUNT(*) total, SUM(p.tipo = 'pedido') pedidos, SUM(p.tipo = 'cotizacion') cotizaciones,
    COALESCE(SUM(IF(p.tipo = 'pedido' AND p.enviado = 1, p.total, 0)), 0) vendido" . $sql);
$query->execute($params);
$resumen = $query->fetch();
$porPagina = 20;
$paginas = max(1, (int)ceil($resumen['total'] / $porPagina));
$pagina = max(1, min($paginas, (int)($_GET['pagina'] ?? 1)));
$query = $db->prepare('SELECT p.*, u.nombre_usuario representante' . $sql . ' ORDER BY p.creado_en DESC, p.id DESC LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));
$query->execute($params);
$pedidos = $query->fetchAll();
// Cotizaciones de esta página que ya se convirtieron en pedido: folio de la cotización => folio del pedido enviado.
$ids = array_map('intval', array_column($pedidos, 'id'));
$convertidas = $ids ? $db->query("SELECT origen_id, MIN(id) FROM cot_pedidos WHERE tipo = 'pedido' AND enviado = 1 AND origen_id IN (" . implode(',', $ids) . ')
    GROUP BY origen_id')->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$representantes = $admin ? $db->query('SELECT DISTINCT u.id, u.nombre_usuario FROM cot_pedidos p JOIN usuarios u ON u.id = p.usuario_id ORDER BY u.nombre_usuario')->fetchAll() : [];
$filtrado = $f['tipo'] || $f['desde'] || $f['hasta'] || $f['q'] !== '' || ($admin && $f['rep']);
$link = fn(array $cambios) => 'pedidos.php?' . h(http_build_query(array_filter($cambios + ['rep' => $admin ? $f['rep'] : 0] + $f + ['pagina' => $pagina], fn($v) => $v !== '' && $v !== 0)));
$titulo = $admin ? 'Pedidos' : 'Mis pedidos';

echo cotHeader($db, $user, 'pedidos.php', $titulo);
?>
<main class="container-xxl py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
      <h1 class="pagina-titulo mb-1"><?= h($titulo) ?></h1>
      <p class="text-secondary mb-0"><?= $admin ? 'Pedidos y cotizaciones que enviaron los representantes médicos.' : 'Los pedidos que enviaste a Compras y las cotizaciones que mandaste a tus clientes.' ?></p>
    </div>
    <a class="btn btn-primary" href="index.php"><i class="bi bi-plus-lg"></i> Nueva cotización</a>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 <?= $admin ? 'col-lg-3' : 'col-lg-4' ?>"><div class="card p-3 h-100"><div class="small text-secondary">Pedidos</div><div class="kpi"><?= (int)$resumen['pedidos'] ?></div></div></div>
    <div class="col-6 <?= $admin ? 'col-lg-3' : 'col-lg-4' ?>"><div class="card p-3 h-100"><div class="small text-secondary">Cotizaciones</div><div class="kpi"><?= (int)$resumen['cotizaciones'] ?></div></div></div>
    <?php if ($admin): // El total generado solo lo ve el superusuario. ?>
      <div class="col-12 col-lg-6"><div class="card p-3 h-100"><div class="small text-secondary">Total de pedidos enviados (con IVA)</div><div class="kpi">$<?= number_format((float)$resumen['vendido'], 2) ?></div></div></div>
    <?php endif; ?>
  </div>

  <form class="card p-3 mb-3" method="get">
    <div class="row g-2 align-items-end">
      <?php if ($admin): ?>
        <div class="col-md-3">
          <label class="form-label small fw-semibold" for="f-rep">Representante</label>
          <select class="form-select" id="f-rep" name="rep">
            <option value="">Todos</option>
            <?php foreach ($representantes as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= $f['rep'] === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['nombre_usuario']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-2">
        <label class="form-label small fw-semibold" for="f-tipo">Tipo</label>
        <select class="form-select" id="f-tipo" name="tipo">
          <option value="">Todos</option>
          <option value="pedido" <?= $f['tipo'] === 'pedido' ? 'selected' : '' ?>>Pedidos</option>
          <option value="cotizacion" <?= $f['tipo'] === 'cotizacion' ? 'selected' : '' ?>>Cotizaciones</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold" for="f-desde">Desde</label>
        <input class="form-control" type="date" id="f-desde" name="desde" value="<?= h($f['desde']) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold" for="f-hasta">Hasta</label>
        <input class="form-control" type="date" id="f-hasta" name="hasta" value="<?= h($f['hasta']) ?>">
      </div>
      <div class="col-md">
        <label class="form-label small fw-semibold" for="f-q">Cliente o folio</label>
        <input class="form-control" type="search" id="f-q" name="q" value="<?= h($f['q']) ?>" placeholder="Nombre del cliente o #folio">
      </div>
      <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn btn-light" href="pedidos.php">Limpiar</a>
      </div>
    </div>
  </form>

  <div class="card">
    <?php if (!$pedidos): ?>
      <div class="empty-state text-center py-5">
        <div class="empty-icon mb-3"><i class="bi bi-inbox"></i></div>
        <p class="fw-semibold mb-1"><?= $filtrado ? 'Sin resultados con estos filtros' : 'Aún no hay pedidos' ?></p>
        <p class="text-secondary small mb-0">Lo que envíes desde el cotizador aparecerá aquí.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 tabla-datos">
          <thead>
            <tr>
              <th>Folio</th>
              <th>Fecha</th>
              <?php if ($admin): ?><th>Representante</th><?php endif; ?>
              <th>Tipo</th>
              <th>Cliente</th>
              <th class="text-end">Piezas</th>
              <th class="text-end">Total</th>
              <th>Correo</th>
              <th><span class="visually-hidden">Ver</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pedidos as $p): ?>
              <tr>
                <td class="text-secondary">#<?= (int)$p['id'] ?></td>
                <td class="text-nowrap"><?= h(date('d/m/Y H:i', strtotime($p['creado_en']))) ?></td>
                <?php if ($admin): ?><td><?= h($p['representante']) ?></td><?php endif; ?>
                <td>
                  <span class="badge <?= $p['tipo'] === 'pedido' ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= $p['tipo'] === 'pedido' ? 'Pedido' : 'Cotización' ?></span>
                  <?php if ($p['modalidad'] === 'mostrador'): ?><span class="badge text-bg-light border">Mostrador</span><?php endif; ?>
                  <?php if (isset($convertidas[$p['id']])): ?>
                    <div class="small text-success mt-1"><i class="bi bi-arrow-right-circle"></i> Convertida en pedido #<?= (int)$convertidas[$p['id']] ?></div>
                  <?php elseif ($p['origen_id']): ?>
                    <div class="small text-secondary mt-1">De la cotización #<?= (int)$p['origen_id'] ?></div>
                  <?php endif; ?>
                </td>
                <td class="fw-medium"><?= h($p['cliente']) ?></td>
                <td class="num"><?= (int)$p['piezas'] ?></td>
                <td class="num fw-semibold">$<?= number_format((float)$p['total'], 2) ?></td>
                <td><?= $p['enviado'] ? '<span class="text-success small"><i class="bi bi-check-circle-fill"></i> Enviado</span>' : '<span class="text-danger small"><i class="bi bi-exclamation-circle-fill"></i> No enviado</span>' ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($p['tipo'] === 'cotizacion' && (int)$p['usuario_id'] === $user['id'] && !isset($convertidas[$p['id']])): ?>
                    <a class="btn btn-sm btn-primary" href="index.php?cotizacion=<?= (int)$p['id'] ?>"><i class="bi bi-arrow-repeat"></i> Convertir en pedido</a>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-ver="<?= (int)$p['id'] ?>"><i class="bi bi-eye"></i> Ver</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($paginas > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top small">
          <span class="text-secondary">Página <?= $pagina ?> de <?= $paginas ?> · <?= (int)$resumen['total'] ?> registros</span>
          <div class="btn-group">
            <a class="btn btn-sm btn-outline-secondary <?= $pagina <= 1 ? 'disabled' : '' ?>" href="<?= $link(['pagina' => $pagina - 1]) ?>">Anterior</a>
            <a class="btn btn-sm btn-outline-secondary <?= $pagina >= $paginas ? 'disabled' : '' ?>" href="<?= $link(['pagina' => $pagina + 1]) ?>">Siguiente</a>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>

<div class="modal fade" id="detalle" tabindex="-1" aria-labelledby="detalle-titulo" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0">
      <div class="modal-header border-0 pb-0">
        <div>
          <h1 class="modal-title fs-5" id="detalle-titulo">Pedido</h1>
          <p class="small text-secondary mb-0" data-ref="subtitulo"></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" data-ref="cuerpo"></div>
    </div>
  </div>
</div>
<?= cotFooter(['js/comun.js', 'js/pedidos.js']) ?>
