<?php
// Cotizador: lista de precios, cotización general (el cliente define la lista de precios) y venta mostrador.
// El representante es quien tiene la sesión: los pedidos y las cotizaciones salen a su nombre y quedan en su historial.
require __DIR__ . '/lib.php';
$user = cotRequire($db, 'index.php');

$fabricantes = $db->query("SELECT DISTINCT fabricante FROM cot_productos WHERE fabricante <> '' ORDER BY fabricante")->fetchAll(PDO::FETCH_COLUMN);
$clientes = $db->query('SELECT id, codigo, nombre FROM cot_clientes ORDER BY nombre')->fetchAll();
$mostrador = cotClienteMostrador($db);
$lista = $db->query('SELECT * FROM cot_lista_precios ORDER BY marca, orden, id')->fetchAll();

// Convertir una cotización en pedido (botón de «Mis pedidos»): se abre su pestaña con cliente, productos y datos de contacto.
$precarga = null;
$avisoPrecarga = '';
if (isset($_GET['cotizacion'])) {
    $c = cotCotizacion($db, $user['id'], (int)$_GET['cotizacion']);
    if (!$c) $avisoPrecarga = 'Esa cotización no existe o no la enviaste tú.';
    elseif ($c['convertida']) $avisoPrecarga = 'La cotización #' . $c['id'] . ' ya se convirtió en el pedido #' . $c['convertida'] . '.';
    else $precarga = ['id' => (int)$c['id'], 'modalidad' => $c['modalidad'], 'cliente_id' => (int)$c['cliente_id'], 'nombre' => $c['cliente'],
        'correo' => $c['correo'], 'telefono' => $c['telefono'], 'direccion' => $c['direccion'], 'partidas' => $c['partidas']];
}
$pestana = $precarga['modalidad'] ?? 'precios';
$pestanas = ['precios' => ['bi-tags', 'Precios'], 'general' => ['bi-briefcase', 'Cotización general'], 'mostrador' => ['bi-shop', 'Venta mostrador']];


echo cotHeader($db, $user, 'index.php', 'Cotizador', true);
?>
  <header class="app-header">
    <div class="container-xxl pt-2">
      <ul class="nav app-tabs" id="tabs" role="tablist">
        <?php foreach ($pestanas as $clave => [$icono, $nombre]): ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= $clave === $pestana ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $clave ?>" type="button" role="tab"
                    aria-controls="tab-<?= $clave ?>" aria-selected="<?= $clave === $pestana ? 'true' : 'false' ?>">
              <i class="bi <?= $icono ?>"></i><span><?= $nombre ?></span>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </header>

  <main class="container-xxl py-4">
    <?php if ($avisoPrecarga): ?>
      <div class="alert alert-warning d-flex gap-2 align-items-center shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle"></i><span><?= h($avisoPrecarga) ?> <a href="pedidos.php">Ver mis pedidos</a></span>
      </div>
    <?php endif; ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $pestana === 'precios' ? 'show active' : '' ?>" id="tab-precios" role="tabpanel" tabindex="0">
        <?php include __DIR__ . '/parciales/precios.php'; ?>
      </div>
      <div class="tab-pane fade <?= $pestana === 'general' ? 'show active' : '' ?>" id="tab-general" role="tabpanel" tabindex="0">
        <?php $cot = ['id' => 'general', 'mostrador' => false, 'nota' => null]; include __DIR__ . '/parciales/cotizador.php'; ?>
      </div>
      <div class="tab-pane fade <?= $pestana === 'mostrador' ? 'show active' : '' ?>" id="tab-mostrador" role="tabpanel" tabindex="0">
        <?php $cot = ['id' => 'mostrador', 'mostrador' => true, 'nota' => 'Pedido venta mostrador']; include __DIR__ . '/parciales/cotizador.php'; ?>
      </div>
    </div>
  </main>
<?= cotFooter([
    'https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
    'js/comun.js',
    'js/app.js',
]) ?>
