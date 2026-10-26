<?php
/**
 * Vista del cotizador (cotización general y venta mostrador).
 * Espera: $cot (['id', 'mostrador', 'nota']), $user, $fabricantes, $clientes y $mostrador (de index.php).
 * El representante es quien tiene la sesión: ya no se elige en el formulario de envío.
 */
$id = $cot['id'];
$modalidad = $cot['mostrador'] ? 'mostrador' : 'general';
$config = [
    'modalidad' => $modalidad,
    'mostrador' => $cot['mostrador'],
    'descuento' => $cot['mostrador'] ? null : COT_DESCUENTO_VOLUMEN,
    'requeridos' => COT_REQUERIDOS[$modalidad],
    'notaPedido' => $cot['nota'],
    'representante' => $user['name'],
    // Cotización que se convierte en pedido en esta pestaña (index.php?cotizacion=ID).
    'precarga' => $precarga && $precarga['modalidad'] === $modalidad ? $precarga : null,
];
?>
<?php if ($cot['mostrador'] && !$mostrador): ?>
  <div class="alert alert-warning d-flex gap-3 align-items-start shadow-sm" role="alert">
    <i class="bi bi-exclamation-triangle fs-4"></i>
    <div>
      <div class="fw-semibold">Falta el cliente de venta mostrador</div>
      <div class="small">Los precios de mostrador se calculan con el cliente «<?= h(COT_CLIENTE_MOSTRADOR) ?>». Pide al superusuario que lo agregue en Clientes.</div>
    </div>
  </div>
<?php endif; ?>

<section class="cotizador" data-config="<?= h(json_encode($config, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="alert alert-info d-none align-items-center gap-3 shadow-sm mb-4" role="status" data-ref="aviso-origen">
    <i class="bi bi-arrow-repeat fs-4"></i>
    <div class="flex-grow-1">
      <div class="fw-semibold" data-ref="aviso-origen-titulo"></div>
      <div class="small">Revisa los productos y las cantidades, y presiona <strong>Enviar pedido</strong>. Los precios son los de hoy.</div>
    </div>
  </div>
  <div class="row g-4">
    <!-- ========== Columna principal ========== -->
    <div class="col-xl-8">
      <div class="panel-fijo" data-sticky-var="--agregar-h">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h6 text-secondary fw-semibold mb-0 letter-spaced">
              <i class="bi bi-bag-plus me-1"></i> Agregar productos
            </h2>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-action="nuevo">
              <i class="bi bi-arrow-counterclockwise"></i> Nuevo pedido
            </button>
          </div>

          <div class="row g-3 align-items-end">
            <?php if (!$cot['mostrador']): ?>
              <div class="col-12">
                <label class="form-label small fw-semibold" for="<?= $id ?>-cliente">Cliente</label>
                <select id="<?= $id ?>-cliente" data-ref="cliente" placeholder="Busca por nombre o código de cliente…" autocomplete="off">
                  <option value=""></option>
                  <?php foreach ($clientes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" data-data="<?= h(json_encode(['codigo' => (string)$c['codigo']])) ?>"><?= h($c['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text d-none" data-ref="cliente-bloqueado">
                  <i class="bi bi-lock-fill"></i> Cliente fijado para este pedido. Usa <strong>Nuevo pedido</strong> para cambiarlo.
                </div>
              </div>
            <?php endif; ?>

            <div class="col-md-4">
              <label class="form-label small fw-semibold" for="<?= $id ?>-marca">Marca</label>
              <select id="<?= $id ?>-marca" data-ref="marca" placeholder="Selecciona una marca" autocomplete="off">
                <option value=""></option>
                <?php foreach ($fabricantes as $f): ?>
                  <option value="<?= h($f) ?>"><?= h($f) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md">
              <label class="form-label small fw-semibold" for="<?= $id ?>-producto">Producto</label>
              <select id="<?= $id ?>-producto" data-ref="producto" placeholder="Primero elige una marca" autocomplete="off">
                <option value=""></option>
              </select>
            </div>
            <div class="col-md-auto d-grid">
              <button type="button" class="btn btn-primary" data-action="agregar">
                <i class="bi bi-plus-lg"></i> Agregar
              </button>
            </div>
          </div>
        </div>
      </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
          <h2 class="h6 text-secondary fw-semibold mb-0 letter-spaced">
            <i class="bi bi-list-check me-1"></i> Productos
          </h2>
          <span class="badge rounded-pill text-bg-light border" data-ref="contador">0 productos</span>
        </div>
        <div class="card-body p-4">
          <div class="empty-state text-center py-5" data-ref="vacio">
            <div class="empty-icon mb-3"><i class="bi bi-cart3"></i></div>
            <p class="fw-semibold mb-1">Aún no hay productos</p>
            <p class="text-secondary small mb-0">
              <?= $cot['mostrador'] ? 'Elige una marca y un producto para empezar.' : 'Elige un cliente, una marca y un producto para empezar.' ?>
            </p>
          </div>
          <div class="table-responsive d-none" data-ref="tabla">
            <table class="table align-middle mb-0 tabla-productos">
              <thead>
                <tr>
                  <th>Artículo</th>
                  <th class="text-center">Cantidad</th>
                  <th class="text-end">Precio público</th>
                  <th class="text-end">Precio con desc.</th>
                  <th class="text-end">Total</th>
                  <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
              </thead>
              <tbody data-ref="tbody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== Resumen ========== -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm resumen">
        <div class="card-body p-4">
          <h2 class="h6 text-secondary fw-semibold mb-3 letter-spaced">
            <i class="bi bi-receipt me-1"></i> Resumen
          </h2>
          <div class="small text-secondary mb-1">Cliente</div>
          <div class="fw-semibold mb-3 text-truncate" data-ref="resumen-cliente">
            <?= $cot['mostrador'] ? 'Venta mostrador' : '<span class="text-secondary fw-normal">Sin seleccionar</span>' ?>
          </div>

          <dl class="totales mb-0">
            <div><dt data-ref="lbl-subtotal">Subtotal</dt><dd data-ref="subtotal">$0.00</dd></div>
            <div><dt>IVA (16%)</dt><dd data-ref="iva">$0.00</dd></div>
            <div class="d-none" data-ref="row-con-iva"><dt>Subtotal</dt><dd data-ref="con-iva">$0.00</dd></div>
            <div class="d-none text-success" data-ref="row-descuento"><dt>Descuento (5%)</dt><dd data-ref="descuento">$0.00</dd></div>
            <div class="total"><dt>Total</dt><dd data-ref="total">$0.00</dd></div>
          </dl>

          <div class="d-grid gap-2 mt-4">
            <button type="button" class="btn btn-primary btn-lg" data-action="abrir" data-modo="pedido" disabled>
              <i class="bi bi-send-check"></i> Enviar pedido
            </button>
            <button type="button" class="btn btn-outline-primary" data-action="abrir" data-modo="cotizacion" disabled>
              <i class="bi bi-file-earmark-pdf"></i> Enviar cotización al cliente
            </button>
          </div>
          <p class="small text-secondary mt-3 mb-0" data-ref="ayuda-envio">Agrega productos para habilitar el envío.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ========== Modal de envío ========== -->
  <div class="modal fade" tabindex="-1" data-ref="modal" data-bs-backdrop="static" aria-labelledby="<?= $id ?>-modal-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <form class="modal-content border-0" novalidate data-ref="form">
        <div class="modal-header border-0 pb-0">
          <div>
            <h1 class="modal-title fs-5" id="<?= $id ?>-modal-titulo" data-ref="modal-titulo">Enviar</h1>
            <p class="small text-secondary mb-0" data-ref="modal-subtitulo"></p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="d-flex align-items-center gap-2 rounded-3 bg-body-tertiary px-3 py-2 mb-3 small">
            <i class="bi bi-person-badge text-secondary"></i>
            <span class="text-secondary">Representante médico:</span>
            <strong class="text-truncate"><?= h($user['name']) ?></strong>
          </div>

          <?php if ($cot['mostrador']): ?>
            <div class="form-floating mb-3">
              <input type="text" class="form-control" id="<?= $id ?>-f-nombre" data-field="nombre" placeholder="Nombre" maxlength="150">
              <label for="<?= $id ?>-f-nombre">Nombre del cliente</label>
              <div class="invalid-feedback">Escribe el nombre del cliente.</div>
            </div>
          <?php endif; ?>

          <div class="row g-3 mb-3">
            <div class="col-sm-7">
              <div class="form-floating">
                <input type="email" class="form-control" id="<?= $id ?>-f-correo" data-field="correo" placeholder="correo@ejemplo.com" maxlength="200">
                <label for="<?= $id ?>-f-correo">Correo del cliente</label>
                <div class="invalid-feedback">Escribe un correo válido.</div>
              </div>
            </div>
            <div class="col-sm-5">
              <div class="form-floating">
                <input type="tel" class="form-control" id="<?= $id ?>-f-telefono" data-field="telefono" placeholder="Teléfono" maxlength="40">
                <label for="<?= $id ?>-f-telefono">Teléfono</label>
                <div class="invalid-feedback">Escribe el teléfono.</div>
              </div>
            </div>
          </div>

          <div class="form-floating mb-3">
            <input type="text" class="form-control" id="<?= $id ?>-f-direccion" data-field="direccion" placeholder="Dirección" maxlength="300">
            <label for="<?= $id ?>-f-direccion">Dirección de envío</label>
            <div class="invalid-feedback">Escribe la dirección de envío.</div>
          </div>

          <div class="form-floating" data-solo-pedido>
            <textarea class="form-control campo-comentarios" id="<?= $id ?>-f-comentarios" data-field="comentarios" placeholder="Comentarios" maxlength="2000"></textarea>
            <label for="<?= $id ?>-f-comentarios">Comentarios del pedido</label>
            <div class="invalid-feedback">Agrega comentarios para el pedido.</div>
          </div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" data-ref="btn-enviar">Enviar</button>
        </div>
      </form>
    </div>
  </div>
</section>
