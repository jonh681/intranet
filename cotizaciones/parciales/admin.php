<?php
/**
 * Administración de una lista (productos, lista de precios o clientes): buscar, filtrar, agregar, editar y eliminar.
 * Espera $db, $user, $entidad (clave de cotEntidades()), $pagina, $titulo y $descripcion. La lógica está en js/admin.js
 * y api.php (admin_lista, admin_guardar, admin_eliminar).
 */
$e = cotEntidades()[$entidad];
$config = ['entidad' => $entidad, 'singular' => $e['singular'], 'filtro' => $e['filtro'][1], 'columnas' => $e['columnas'],
    'dinero' => array_keys(array_filter($e['campos'], fn($c) => str_starts_with($c[1], 'dinero')))];
// Sugerencias para el campo de marca: las que ya existen.
$colMarca = isset($e['campos']['fabricante']) ? 'fabricante' : (isset($e['campos']['marca']) ? 'marca' : null);
$marcas = $colMarca ? $db->query("SELECT DISTINCT $colMarca FROM {$e['tabla']} WHERE $colMarca <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) : [];

echo cotHeader($db, $user, $pagina, $titulo);
?>
<main class="container-xxl py-4" data-admin="<?= h(json_encode($config, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
      <h1 class="pagina-titulo mb-1"><?= h($titulo) ?></h1>
      <p class="text-secondary mb-0"><?= h($descripcion) ?></p>
    </div>
    <button class="btn btn-primary" type="button" data-accion="nuevo"><i class="bi bi-plus-lg"></i> Agregar <?= h($e['singular']) ?></button>
  </div>

  <div class="card p-3 mb-3">
    <div class="row g-2 align-items-center">
      <div class="col-md">
        <div class="input-group buscador">
          <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
          <input type="search" class="form-control" data-ref="buscar" placeholder="Buscar…" aria-label="Buscar">
        </div>
      </div>
      <div class="col-md-4">
        <select class="form-select" data-ref="filtro" aria-label="<?= h($e['filtro'][1]) ?>">
          <option value=""><?= h($e['filtro'][1]) ?>: todas</option>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 tabla-datos">
        <thead>
          <tr>
            <?php foreach ($e['columnas'] as $col => $label): ?>
              <th class="<?= in_array($col, $config['dinero'], true) ? 'text-end' : '' ?>"><?= h($label) ?></th>
            <?php endforeach; ?>
            <th class="text-end"><span class="visually-hidden">Acciones</span></th>
          </tr>
        </thead>
        <tbody data-ref="filas"><tr><td colspan="<?= count($e['columnas']) + 1 ?>" class="text-center text-secondary py-5">Cargando…</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top small">
      <span class="text-secondary" data-ref="resumen"></span>
      <div class="btn-group">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-accion="anterior">Anterior</button>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-accion="siguiente">Siguiente</button>
      </div>
    </div>
  </div>
</main>

<div class="modal fade" id="editor" tabindex="-1" data-bs-backdrop="static" aria-labelledby="editor-titulo" aria-hidden="true">
  <div class="modal-dialog <?= count($e['campos']) > 6 ? 'modal-lg' : '' ?> modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content border-0" novalidate data-ref="form">
      <div class="modal-header border-0 pb-0">
        <h1 class="modal-title fs-5" id="editor-titulo">Agregar</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="row g-3">
          <?php foreach ($e['campos'] as $col => [$label, $tipo, $max, $requerido]): ?>
            <?php $esDinero = str_starts_with($tipo, 'dinero'); ?>
            <div class="<?= $esDinero || $tipo === 'entero' ? 'col-sm-6 col-lg-4' : 'col-12' ?>">
              <label class="form-label small fw-semibold" for="c-<?= $col ?>"><?= h($label) ?><?= $requerido ? ' <span class="text-danger">*</span>' : '' ?></label>
              <?php if ($tipo === 'lista'): ?>
                <select class="form-select" id="c-<?= $col ?>" name="<?= $col ?>" <?= $requerido ? 'required' : '' ?>>
                  <option value="">Selecciona…</option>
                  <?php foreach ($e['campos'][$col][4] as $op): ?><option><?= h($op) ?></option><?php endforeach; ?>
                </select>
              <?php elseif ($esDinero): ?>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input class="form-control text-end" id="c-<?= $col ?>" name="<?= $col ?>" inputmode="decimal" autocomplete="off" <?= $requerido ? 'required' : '' ?>>
                </div>
              <?php else: ?>
                <input class="form-control" id="c-<?= $col ?>" name="<?= $col ?>" autocomplete="off" <?= $tipo === 'entero' ? 'inputmode="numeric"' : 'maxlength="' . $max . '"' ?>
                  <?= in_array($col, ['fabricante', 'marca'], true) ? 'list="marcas"' : '' ?> <?= $requerido ? 'required' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <datalist id="marcas"><?php foreach ($marcas as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist>
        <?php if ($entidad === 'productos'): ?>
          <div class="d-flex flex-wrap align-items-center gap-2 mt-3 small text-secondary">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-accion="calcular"><i class="bi bi-calculator"></i> Calcular listas desde el precio base</button>
            <span>Llena las listas 15% a 40% con el descuento sobre el precio base. Revisa antes de guardar.</span>
          </div>
        <?php elseif ($entidad === 'lista'): ?>
          <p class="small text-secondary mt-3 mb-0">Al escribir el precio sin IVA se calculan el precio con IVA y el de 25% de descuento; puedes corregirlos. Deja vacío un precio que no aplique.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
      </div>
    </form>
  </div>
</div>
<?= cotFooter(['js/comun.js', 'js/admin.js']) ?>
