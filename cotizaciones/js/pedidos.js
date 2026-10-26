'use strict';
// Historial de pedidos (pedidos.php): detalle de un pedido o cotización en una ventana. Requiere js/comun.js.

const detalleEl = document.getElementById('detalle');
const detalle = new bootstrap.Modal(detalleEl);
const ref = name => detalleEl.querySelector(`[data-ref="${name}"]`);

function htmlDetalle(p, partidas) {
  const dato = (etiqueta, valor) => valor ? `<div class="col-sm-6"><div class="small text-secondary">${etiqueta}</div><div class="fw-medium text-break">${escapar(valor)}</div></div>` : '';
  const filas = partidas.map(i => `
    <tr class="${Number(i.bonificacion) ? 'table-success' : ''}">
      <td><div class="fw-medium">${escapar(i.descripcion)}</div>
        <div class="small text-secondary">${Number(i.bonificacion) ? '<span class="badge text-bg-success me-1">Bonificación</span>' : ''}SAP ${escapar(i.numero_articulo)}${i.codigo_barras ? ' · CB ' + escapar(i.codigo_barras) : ''}</div></td>
      <td class="num">${Number(i.cantidad)}</td>
      <td class="num text-secondary">${moneda(i.precio_publico)}</td>
      <td class="num">${moneda(i.precio)}</td>
      <td class="num fw-semibold">${moneda(i.precio * i.cantidad)}</td>
    </tr>`).join('');
  const total = (etiqueta, valor, clase = '') => `<div class="d-flex justify-content-between ${clase}"><span>${etiqueta}</span><span>${valor}</span></div>`;
  return `
    <div class="row g-3 mb-4">
      ${dato('Representante médico', p.representante)}
      ${dato('Cliente', p.cliente)}
      ${dato('Correo', p.correo)}
      ${dato('Teléfono', p.telefono)}
      ${dato('Dirección de envío', p.direccion)}
      ${dato('Comentarios', p.comentarios)}
      ${p.origen_id ? `<div class="col-12 small text-secondary"><i class="bi bi-arrow-repeat"></i> Pedido a partir de la cotización #${Number(p.origen_id)}</div>` : ''}
      ${p.convertida ? `<div class="col-12 small text-success"><i class="bi bi-arrow-right-circle"></i> Convertida en el pedido #${Number(p.convertida)}</div>` : ''}
    </div>
    <div class="table-responsive">
      <table class="table align-middle tabla-datos">
        <thead><tr><th>Artículo</th><th class="text-end">Cantidad</th><th class="text-end">Precio público</th><th class="text-end">Precio con desc.</th><th class="text-end">Total</th></tr></thead>
        <tbody>${filas}</tbody>
      </table>
    </div>
    <div class="ms-auto small" style="max-width: 18rem">
      ${total('Subtotal', moneda(p.subtotal))}
      ${total('IVA (16%)', moneda(p.iva))}
      ${Number(p.descuento) ? total('Descuento', '−' + moneda(p.descuento), 'text-success') : ''}
      ${total('<strong>Total</strong>', `<strong>${moneda(p.total)}</strong>`, 'border-top pt-2 mt-2 fs-6')}
    </div>
    ${Number(p.enviado) ? '' : '<div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> El correo de este registro no se pudo enviar.</div>'}`;
}

document.addEventListener('click', async e => {
  const btn = e.target.closest('[data-ver]');
  if (!btn) return;
  btn.disabled = true;
  try {
    const { pedido: p, partidas } = await apiGet('pedido', { id: btn.dataset.ver });
    detalleEl.querySelector('.modal-title').textContent = `${p.tipo === 'pedido' ? 'Pedido' : 'Cotización'} #${p.id}`;
    ref('subtitulo').textContent = `${fechaHora(p.creado_en)}${p.modalidad === 'mostrador' ? ' · Venta mostrador' : ''} · ${p.piezas} piezas`;
    ref('cuerpo').innerHTML = htmlDetalle(p, partidas);
    detalle.show();
  } catch (err) {
    UI.error(err.message, 'No se pudo abrir el detalle');
  } finally {
    btn.disabled = false;
  }
});
