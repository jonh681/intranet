<?php
// Pestaña "Precios": lista de precios por marca (cot_lista_precios; la edita el superusuario en «Lista de precios»).
// Espera $lista (index.php).
$precio = fn($v) => $v === null ? '<span class="text-secondary">—</span>' : '$' . number_format((float)$v, 2);
$normalizar = fn($s) => strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
$marcas = [];
foreach ($lista as $p) $marcas[$p['marca']][$p['linea']][] = $p;
?>
<section class="catalogo" data-ref="catalogo">
  <div class="filtros-fijos" data-sticky-var="--filtros-h">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3 p-md-4">
      <div class="row g-3 align-items-center">
        <div class="col-lg-5">
          <div class="input-group input-group-lg buscador">
            <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" data-ref="buscar" disabled
                   placeholder="Primero selecciona una marca" aria-label="Buscar productos">
          </div>
        </div>
        <div class="col-lg-7">
          <div class="d-flex gap-2 justify-content-lg-end chips" role="group" aria-label="Filtrar por marca">
            <?php foreach (array_keys($marcas) as $i => $marca): ?>
              <button type="button" class="btn btn-sm btn-chip" data-marca="m<?= $i ?>"><?= h($marca) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center pb-2 px-1 d-none" data-ref="barra-resultado">
    <span class="small text-secondary" data-ref="resultado"></span>
  </div>
  </div>

  <div class="card border-0 shadow-sm" data-ref="inicial">
    <div class="empty-state text-center py-5">
      <div class="empty-icon mb-3"><i class="bi bi-tags"></i></div>
      <?php if ($marcas): ?>
        <p class="fw-semibold mb-1">Selecciona una marca</p>
        <p class="text-secondary small mb-0">Elige una de las marcas de arriba para ver sus precios.</p>
      <?php else: ?>
        <p class="fw-semibold mb-1">Aún no hay precios registrados</p>
        <p class="text-secondary small mb-0">El superusuario los agrega en «Lista de precios».</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm d-none" data-ref="listado">
    <div class="table-responsive tabla-precios-wrap">
      <table class="table table-hover align-middle mb-0 tabla-precios">
        <thead>
          <tr>
            <th>Descripción</th>
            <th>SAP</th>
            <th>Código de barras</th>
            <th class="text-end">Público s/IVA</th>
            <th class="text-end">Público c/IVA</th>
            <th class="text-end">25% dcto.</th>
            <th class="text-end">25% dcto. + IVA</th>
          </tr>
        </thead>
        <?php foreach (array_values($marcas) as $i => $lineas): ?>
          <?php foreach ($lineas as $linea => $productos): ?>
            <tbody data-marca="m<?= $i ?>">
              <tr class="fila-grupo">
                <th colspan="7">
                  <span class="badge text-bg-primary me-2"><?= h($productos[0]['marca']) ?></span>
                  <?= h((string)$linea) ?>
                </th>
              </tr>
              <?php foreach ($productos as $p): ?>
                <tr data-buscar="<?= h($normalizar($p['descripcion'] . ' ' . $p['sap'] . ' ' . $p['codigo_barras'])) ?>">
                  <td class="fw-medium"><?= h($p['descripcion']) ?></td>
                  <td><code><?= h($p['sap']) ?></code></td>
                  <td class="text-secondary small"><?= h($p['codigo_barras']) ?></td>
                  <td class="text-end"><?= $precio($p['publico']) ?></td>
                  <td class="text-end"><?= $precio($p['publico_iva']) ?></td>
                  <td class="text-end"><?= $precio($p['descuento']) ?></td>
                  <td class="text-end fw-semibold"><?= $precio($p['descuento_iva']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="empty-state text-center py-5 d-none" data-ref="sin-resultados">
      <div class="empty-icon mb-3"><i class="bi bi-search"></i></div>
      <p class="fw-semibold mb-1">Sin resultados</p>
      <p class="text-secondary small mb-0">Prueba con otro nombre o código, o cambia de marca.</p>
    </div>
  </div>
</section>
