'use strict';
// Cotizador (cotización general y venta mostrador), catálogo de precios y encabezado fijo. Requiere js/comun.js.
// El representante es quien tiene la sesión (cfg.representante): ya no se elige ni se pide PIN.

const IVA = 0.16;

function fechaLarga() {
  const t = new Date().toLocaleDateString('es-MX', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  return t.charAt(0).toUpperCase() + t.slice(1);
}

function cargarImagen(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = src;
  });
}

// ================= Cotizador (general y mostrador) =================
function Cotizador(root) {
  const cfg = JSON.parse(root.dataset.config);
  const ref = name => root.querySelector(`[data-ref="${name}"]`);
  const campo = name => form.querySelector(`[data-field="${name}"]`);

  const modalEl = ref('modal');
  document.body.appendChild(modalEl); // fuera de las pestañas para evitar problemas de apilamiento
  const modal = new bootstrap.Modal(modalEl);
  const form = modalEl.querySelector('[data-ref="form"]');

  let items = [];
  let secuencia = 0;
  let modo = 'pedido';
  let peticionProductos = 0;
  let origen = null; // cotización que se está convirtiendo en pedido (folio)

  // ---------- Selects con búsqueda ----------
  const tsCliente = cfg.mostrador ? null : new TomSelect(ref('cliente'), {
    maxOptions: 60, allowEmptyOption: false, searchField: ['text', 'codigo'],
    render: {
      option: (d, esc) => `<div>${esc(d.text)}${d.codigo ? ` <span class="text-secondary small ms-1">${esc(d.codigo)}</span>` : ''}</div>`,
      no_results: () => '<div class="no-results">Ese cliente no está en la lista</div>',
    },
  });
  const tsMarca = new TomSelect(ref('marca'), { allowEmptyOption: false });
  const tsProducto = new TomSelect(ref('producto'), {
    maxOptions: 300, searchField: ['text', 'value'],
    render: {
      option: (d, esc) => `<div>${esc(d.text)} <span class="text-secondary small ms-1">${esc(d.value)}</span></div>`,
      no_results: () => '<div class="no-results">Sin coincidencias</div>',
    },
  });
  function placeholderProducto(texto) {
    tsProducto.settings.placeholder = texto;
    tsProducto.control_input.placeholder = texto;
  }
  tsProducto.disable();

  // Cliente elegido: id (para el servidor) y nombre (para la pantalla, el PDF y el descuento por volumen).
  const clienteId = () => (tsCliente ? tsCliente.getValue() : '');
  const clienteNombre = () => (cfg.mostrador ? 'Venta mostrador' : (tsCliente.options[clienteId()]?.text || ''));
  const hayCliente = () => cfg.mostrador || !!clienteId();

  tsCliente?.on('change', () => render());

  tsMarca.on('change', async marca => {
    const id = ++peticionProductos;
    tsProducto.clear(true);
    tsProducto.clearOptions();
    tsProducto.disable();
    if (!marca) {
      placeholderProducto('Primero elige una marca');
      return;
    }
    placeholderProducto('Cargando productos…');
    try {
      const { productos } = await apiGet('productos', { marca });
      if (id !== peticionProductos) return; // llegó una respuesta vieja
      tsProducto.addOptions(productos.map(p => ({ value: p.numero_articulo, text: p.descripcion })));
      tsProducto.enable();
      placeholderProducto(productos.length ? 'Busca o selecciona un producto' : 'Sin productos para esta marca');
      if (!productos.length) UI.toast.fire({ icon: 'info', title: 'Esta marca no tiene productos registrados' });
    } catch (e) {
      placeholderProducto('Error al cargar productos');
      UI.error(e.message, 'No se pudieron cargar los productos');
    }
  });

  // ---------- Productos ----------
  async function agregar(btn) {
    if (!hayCliente()) {
      await UI.aviso('Selecciona un cliente de la lista antes de agregar productos.');
      tsCliente?.focus();
      return;
    }
    const articulo = tsProducto.getValue();
    if (!articulo) {
      UI.aviso(tsMarca.getValue() ? 'Selecciona un producto.' : 'Selecciona una marca y después un producto.');
      return;
    }

    const existente = items.find(i => !i.bonificacionDe && i.articulo === articulo);
    if (existente) {
      existente.cantidad += 1;
      render();
      tsProducto.clear();
      UI.toast.fire({ icon: 'info', title: 'Ya estaba en la lista', text: `Cantidad actualizada a ${existente.cantidad}` });
      return;
    }

    const html = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Agregando';
    try {
      const d = await apiGet('precio', cfg.mostrador ? { mostrador: 1, articulo } : { cliente: clienteId(), articulo });
      const precio = Number(d.precio) || 0;
      items.push({
        id: ++secuencia, articulo, descripcion: d.descripcion, codigo: d.codigo_barras || '',
        publico: Number(d.publico) || 0, precio, cantidad: 1, bonificacionDe: null,
      });
      bloquearCliente(true);
      render();
      tsProducto.clear();
      if (precio <= 0) {
        UI.aviso('Este producto no tiene precio registrado para el cliente. Revisa la lista de precios.', 'Producto sin precio');
      } else {
        UI.toast.fire({ icon: 'success', title: 'Producto agregado' });
      }
    } catch (e) {
      UI.error(e.message, 'No se pudo agregar el producto');
    } finally {
      btn.disabled = false;
      btn.innerHTML = html;
    }
  }

  function bonificar(item) {
    const pos = items.indexOf(item);
    items.splice(pos + 1, 0, {
      ...item, id: ++secuencia, publico: 0, precio: 0, cantidad: 1, bonificacionDe: item.id,
    });
    render();
    UI.toast.fire({ icon: 'success', title: 'Bonificación agregada' });
  }

  function eliminar(item) {
    items = items.filter(i => i.id !== item.id && i.bonificacionDe !== item.id);
    render();
    UI.toast.fire({ icon: 'info', title: item.bonificacionDe ? 'Bonificación eliminada' : 'Producto eliminado' });
  }

  function bloquearCliente(bloquear) {
    if (!tsCliente) return;
    bloquear ? tsCliente.lock() : tsCliente.unlock();
    ref('cliente-bloqueado').classList.toggle('d-none', !bloquear);
  }

  // ---------- Tabla y totales ----------
  function filaHTML(it) {
    const bonif = !!it.bonificacionDe;
    const tieneBonif = items.some(i => i.bonificacionDe === it.id);
    return `
      <tr data-id="${it.id}" class="${bonif ? 'fila-bonificacion' : ''}">
        <td>
          <div class="fw-medium">${escapar(it.descripcion)}</div>
          <div class="small text-secondary">
            ${bonif ? '<span class="badge text-bg-success me-1">Bonificación</span>' : ''}
            ${it.codigo ? 'CB ' + escapar(it.codigo) : ''}
          </div>
        </td>
        <td class="text-center">
          <input type="number" min="1" step="1" inputmode="numeric" value="${it.cantidad}"
                 class="form-control form-control-sm input-cantidad" aria-label="Cantidad">
        </td>
        <td class="text-end text-secondary">${moneda(it.publico)}</td>
        <td class="text-end">${moneda(it.precio)}</td>
        <td class="text-end fw-semibold" data-total>${moneda(it.precio * it.cantidad)}</td>
        <td class="text-end text-nowrap">
          ${bonif ? '' : `<button type="button" class="btn btn-sm btn-outline-success btn-icon" data-action="bonificar"
              title="${tieneBonif ? 'Ya tiene bonificación' : 'Agregar bonificación'}" ${tieneBonif ? 'disabled' : ''}>
              <i class="bi bi-gift"></i></button>`}
          <button type="button" class="btn btn-sm btn-outline-danger btn-icon" data-action="eliminar" title="Eliminar">
            <i class="bi bi-trash3"></i></button>
        </td>
      </tr>`;
  }

  function render() {
    ref('vacio').classList.toggle('d-none', items.length > 0);
    ref('tabla').classList.toggle('d-none', items.length === 0);
    ref('tbody').innerHTML = items.map(filaHTML).join('');
    actualizarTotales();
  }

  // Igual que cotTotales() en lib.php: el servidor vuelve a calcular todo al enviar.
  function calcular() {
    const suma = items.reduce((s, i) => s + i.precio * i.cantidad, 0);
    const iva = suma * IVA;
    const conIva = suma + iva;
    let descuento = 0;
    const d = cfg.descuento;
    if (d && conIva >= d.minimo) {
      const cliente = normalizar(clienteNombre());
      if (d.clientes.some(n => cliente.includes(normalizar(n)))) descuento = conIva * d.porcentaje;
    }
    return { suma, iva, conIva, descuento, total: conIva - descuento };
  }

  function lineasTotales(t) {
    const lineas = t.descuento
      ? [['Suma', t.suma], ['IVA (16%)', t.iva], ['Subtotal', t.conIva], [`Descuento (${cfg.descuento.porcentaje * 100}%)`, -t.descuento]]
      : [['Subtotal', t.suma], ['IVA (16%)', t.iva]];
    return [...lineas, ['Total', t.total]];
  }

  function actualizarTotales() {
    const t = calcular();
    const piezas = items.reduce((s, i) => s + i.cantidad, 0);
    ref('contador').textContent = `${items.length} ${items.length === 1 ? 'producto' : 'productos'} · ${piezas} pzas`;
    ref('lbl-subtotal').textContent = t.descuento ? 'Suma' : 'Subtotal';
    ref('subtotal').textContent = moneda(t.suma);
    ref('iva').textContent = moneda(t.iva);
    ref('con-iva').textContent = moneda(t.conIva);
    ref('descuento').textContent = '−' + moneda(t.descuento);
    ref('row-con-iva').classList.toggle('d-none', !t.descuento);
    ref('row-descuento').classList.toggle('d-none', !t.descuento);
    ref('total').textContent = moneda(t.total);

    if (!cfg.mostrador) {
      const c = clienteNombre();
      ref('resumen-cliente').innerHTML = c ? escapar(c) : '<span class="text-secondary fw-normal">Sin seleccionar</span>';
      ref('resumen-cliente').title = c;
    }

    const listo = items.length > 0 && t.suma > 0 && hayCliente();
    root.querySelectorAll('[data-action="abrir"]').forEach(b => { b.disabled = !listo; });
    ref('ayuda-envio').classList.toggle('d-none', listo);
    ref('ayuda-envio').textContent = !items.length
      ? 'Agrega productos para habilitar el envío.'
      : 'El total debe ser mayor a $0.00 para poder enviar.';
  }

  // Cantidades: se permite borrar mientras se escribe; al salir del campo, mínimo 1
  ref('tbody').addEventListener('input', e => {
    if (!e.target.matches('.input-cantidad')) return;
    const tr = e.target.closest('tr');
    const it = items.find(i => i.id === Number(tr.dataset.id));
    const n = Math.floor(Number(e.target.value));
    it.cantidad = Number.isFinite(n) && n > 0 ? n : 0;
    tr.querySelector('[data-total]').textContent = moneda(it.precio * it.cantidad);
    actualizarTotales();
  });
  ref('tbody').addEventListener('focusout', e => {
    if (!e.target.matches('.input-cantidad')) return;
    const it = items.find(i => i.id === Number(e.target.closest('tr').dataset.id));
    if (it && it.cantidad < 1) {
      it.cantidad = 1;
      e.target.value = 1;
      e.target.closest('tr').querySelector('[data-total]').textContent = moneda(it.precio);
      actualizarTotales();
    }
  });
  ref('tbody').addEventListener('click', e => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const it = items.find(i => i.id === Number(btn.closest('tr').dataset.id));
    if (btn.dataset.action === 'bonificar') bonificar(it);
    if (btn.dataset.action === 'eliminar') eliminar(it);
  });

  // ---------- Nuevo pedido ----------
  async function nuevo(preguntar = true) {
    if (preguntar && items.length &&
        !await UI.confirmar('¿Empezar un nuevo pedido?', 'Se borrarán los productos agregados y el cliente seleccionado.', 'Sí, empezar de nuevo')) {
      return;
    }
    items = [];
    origen = null;
    mostrarOrigen();
    bloquearCliente(false);
    tsCliente?.clear(true);
    tsMarca.clear();
    form.reset();
    limpiarValidacion();
    render();
  }

  // ---------- Convertir una cotización en pedido ----------
  function mostrarOrigen() {
    const aviso = ref('aviso-origen');
    aviso.classList.toggle('d-none', !origen);
    aviso.classList.toggle('d-flex', !!origen);
    if (origen) ref('aviso-origen-titulo').textContent = `Convirtiendo la cotización #${origen} en pedido`;
  }

  // Carga cliente, productos (con los precios de hoy), bonificaciones y datos de contacto de la cotización.
  async function precargar(p) {
    if (tsCliente) {
      if (!tsCliente.options[p.cliente_id]) {
        UI.aviso('El cliente de esta cotización ya no está en la lista. Elige otro cliente y agrega los productos.', 'Cliente no encontrado');
        return;
      }
      tsCliente.setValue(String(p.cliente_id), true);
    }
    UI.cargando('Cargando la cotización…');
    const faltan = [];
    let cambiaron = 0;
    for (const l of p.partidas) {
      const cantidad = Number(l.cantidad);
      if (Number(l.bonificacion)) {
        const padre = items.findLast(i => !i.bonificacionDe && i.articulo === l.numero_articulo);
        if (padre) items.push({ ...padre, id: ++secuencia, publico: 0, precio: 0, cantidad, bonificacionDe: padre.id });
        continue;
      }
      try {
        const d = await apiGet('precio', cfg.mostrador ? { mostrador: 1, articulo: l.numero_articulo } : { cliente: p.cliente_id, articulo: l.numero_articulo });
        if (Math.abs(Number(d.precio) - Number(l.precio)) >= 0.01) cambiaron++;
        items.push({
          id: ++secuencia, articulo: l.numero_articulo, descripcion: d.descripcion, codigo: d.codigo_barras || '',
          publico: Number(d.publico) || 0, precio: Number(d.precio) || 0, cantidad, bonificacionDe: null,
        });
      } catch (_) {
        faltan.push(l.numero_articulo);
      }
    }
    ['nombre', 'correo', 'telefono', 'direccion'].forEach(k => { if (campo(k) && p[k]) campo(k).value = p[k]; });
    origen = p.id;
    mostrarOrigen();
    bloquearCliente(true);
    render();
    Swal.close();
    const avisos = [];
    if (faltan.length) avisos.push(`Estos productos ya no están en la lista y no se agregaron: ${faltan.join(', ')}.`);
    if (cambiaron) avisos.push(`${cambiaron} ${cambiaron === 1 ? 'producto cambió' : 'productos cambiaron'} de precio desde la cotización: se usa el precio de hoy.`);
    if (avisos.length) UI.aviso(avisos.join(' '), 'Revisa el pedido');
  }

  // ---------- Modal de envío ----------
  const TEXTOS = {
    pedido: {
      titulo: 'Enviar pedido', boton: '<i class="bi bi-send-check"></i> Enviar pedido',
      subtitulo: 'Se enviará a Compras con PDF y Excel, con copia a tu correo. Queda en «Mis pedidos».',
    },
    cotizacion: {
      titulo: 'Enviar cotización', boton: '<i class="bi bi-file-earmark-pdf"></i> Enviar cotización',
      subtitulo: 'El cliente recibirá la cotización en PDF por correo. Queda en «Mis pedidos».',
    },
  };

  function abrirModal(nuevoModo) {
    modo = nuevoModo;
    modalEl.querySelector('[data-ref="modal-titulo"]').textContent = TEXTOS[modo].titulo;
    modalEl.querySelector('[data-ref="modal-subtitulo"]').textContent = TEXTOS[modo].subtitulo;
    modalEl.querySelector('[data-ref="btn-enviar"]').innerHTML = TEXTOS[modo].boton;
    const requeridos = cfg.requeridos[modo];
    form.querySelectorAll('[data-solo-pedido]').forEach(el => el.classList.toggle('d-none', modo !== 'pedido'));
    form.querySelectorAll('[data-field]').forEach(el => {
      const label = form.querySelector(`label[for="${el.id}"]`);
      const req = requeridos.includes(el.dataset.field);
      if (label) {
        label.dataset.base ??= label.innerHTML;
        label.innerHTML = label.dataset.base + (req ? ' <span class="text-danger">*</span>' : ' <span class="text-secondary small">(opcional)</span>');
      }
    });
    limpiarValidacion();
    modal.show();
  }

  function limpiarValidacion() {
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
  }

  form.addEventListener('input', e => e.target.classList.remove('is-invalid'));
  form.addEventListener('change', e => e.target.classList.remove('is-invalid'));

  function leerFormulario() {
    limpiarValidacion();
    const val = {};
    form.querySelectorAll('[data-field]').forEach(el => { val[el.dataset.field] = el.value.trim(); });
    const invalidos = cfg.requeridos[modo].filter(k => !val[k]);
    if (val.correo && !correoValido(val.correo) && !invalidos.includes('correo')) invalidos.push('correo');

    if (invalidos.length) {
      invalidos.forEach(k => campo(k)?.classList.add('is-invalid'));
      campo(invalidos[0])?.focus();
      UI.toast.fire({ icon: 'error', title: 'Revisa los campos marcados' });
      return null;
    }
    return { ...val, repNombre: cfg.representante, cliente: cfg.mostrador ? val.nombre : clienteNombre() };
  }

  function filasDocumento() {
    return items.map(i => [
      i.descripcion + (i.bonificacionDe ? ' (bonificación)' : ''),
      i.cantidad, i.codigo, i.publico, i.precio, i.precio * i.cantidad,
    ]);
  }
  const ENCABEZADOS = ['Artículo', 'Cantidad', 'Código de barras', 'Precio público', 'Precio con descuento', 'Precio total'];

  async function generarPDF(datos, t) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'a4');
    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    const left = 14;
    const maxW = pageW - left * 2;

    try { doc.addImage(await cargarImagen('img/fedele.png'), 'PNG', pageW - left - 40, 6, 40, 20); } catch (_) { /* sin logo */ }

    doc.setFont('helvetica', 'bold').setFontSize(18).setTextColor(30);
    doc.text(modo === 'pedido' ? 'Pedido de productos Fedele' : 'Cotización de productos Fedele', left, 18);
    doc.setFont('helvetica', 'normal').setFontSize(10).setTextColor(110);
    doc.text(fechaLarga(), left, 25);

    let y = 36;
    doc.setFontSize(10.5).setTextColor(40);
    const bloque = (etiqueta, valor) => {
      if (!valor) return;
      const lineas = doc.splitTextToSize(`${etiqueta}: ${valor}`, maxW);
      doc.text(lineas, left, y);
      y += lineas.length * 5 + 1.5;
    };
    bloque('Representante médico', datos.repNombre);
    bloque('Cliente', datos.cliente);
    bloque('Dirección de envío', datos.direccion);
    if (modo === 'pedido') {
      if (cfg.notaPedido) bloque('Tipo', cfg.notaPedido);
      bloque('Comentarios', datos.comentarios);
    }

    doc.autoTable({
      startY: y + 3,
      head: [ENCABEZADOS],
      body: filasDocumento().map(f => [f[0], f[1], f[2], moneda(f[3]), moneda(f[4]), moneda(f[5])]),
      theme: 'striped',
      styles: { fontSize: 9, cellPadding: 2.5 },
      headStyles: { fillColor: [10, 35, 66], textColor: 255, fontStyle: 'bold' },
      columnStyles: { 1: { halign: 'center' }, 3: { halign: 'right' }, 4: { halign: 'right' }, 5: { halign: 'right' } },
    });

    y = doc.lastAutoTable.finalY + 10;
    const lineas = lineasTotales(t);
    if (y + lineas.length * 7 > pageH - 15) { doc.addPage(); y = 20; }
    lineas.forEach(([etiqueta, valor], idx) => {
      const esTotal = idx === lineas.length - 1;
      doc.setFont('helvetica', esTotal ? 'bold' : 'normal').setFontSize(esTotal ? 12 : 10.5).setTextColor(esTotal ? 0 : 60);
      doc.text(`${etiqueta}:`, pageW - left - 55, y);
      doc.text(valor < 0 ? '-' + moneda(-valor) : moneda(valor), pageW - left, y, { align: 'right' });
      y += 7;
    });
    return doc;
  }

  function generarExcel() {
    const ws = XLSX.utils.aoa_to_sheet([ENCABEZADOS, ...filasDocumento()]);
    const rango = XLSX.utils.decode_range(ws['!ref']);
    for (let r = 1; r <= rango.e.r; r++) {
      [3, 4, 5].forEach(c => {
        const cel = ws[XLSX.utils.encode_cell({ r, c })];
        if (cel) cel.z = '"$"#,##0.00';
      });
    }
    ws['!cols'] = [{ wch: 55 }, { wch: 10 }, { wch: 16 }, { wch: 14 }, { wch: 18 }, { wch: 14 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Pedido');
    const buf = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
    return new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }

  const nombreArchivo = s => String(s || 'cliente').replace(/[\\/:*?"<>|]+/g, '').trim().slice(0, 60);

  async function enviar() {
    const datos = leerFormulario();
    if (!datos) return;
    const t = calcular();
    if (t.suma <= 0) {
      modal.hide();
      UI.aviso('El total es $0.00. Agrega productos con precio antes de enviar.');
      return;
    }

    modal.hide();
    UI.cargando(modo === 'pedido' ? 'Enviando pedido…' : 'Enviando cotización…');

    let pdf = null;
    try {
      pdf = await generarPDF(datos, t);
      const fd = new FormData();
      fd.append('tipo', modo);
      fd.append('modalidad', cfg.modalidad);
      fd.append('cliente', clienteId());
      if (modo === 'pedido' && origen) fd.append('origen', origen);
      ['nombre', 'correo', 'telefono', 'direccion', 'comentarios'].forEach(k => fd.append(k, datos[k] || ''));
      // Solo artículo, cantidad y si es bonificación: los precios los pone el servidor.
      fd.append('partidas', JSON.stringify(items.map(i => ({ articulo: i.articulo, cantidad: i.cantidad, bonificacion: !!i.bonificacionDe }))));
      const base = nombreArchivo(datos.cliente);
      fd.append('pdf', pdf.output('blob'), `${modo}_${base}.pdf`);
      if (modo === 'pedido') fd.append('excel', generarExcel(), `pedido_${base}.xlsx`);

      const r = await apiPost('enviar', fd);
      if (modo === 'cotizacion') pdf.save(`cotizacion_${base}.pdf`);
      if (modo === 'pedido' && origen) { origen = null; mostrarOrigen(); } // ya quedó convertida

      const res = await Swal.fire({
        icon: 'success',
        title: modo === 'pedido' ? '¡Pedido enviado!' : '¡Cotización enviada!',
        html: escapar(r.message) + (modo === 'cotizacion' ? '<br><small class="text-secondary">También se descargó una copia del PDF.</small>' : ''),
        showCancelButton: true,
        confirmButtonText: 'Empezar nuevo pedido',
        cancelButtonText: 'Seguir aquí',
        reverseButtons: true,
      });
      if (res.isConfirmed) nuevo(false);
    } catch (e) {
      if (pdf && modo === 'cotizacion') pdf.save(`cotizacion_${nombreArchivo(datos.cliente)}.pdf`);
      const res = await Swal.fire({
        icon: 'error',
        title: modo === 'pedido' ? 'No se pudo enviar el pedido' : 'No se pudo enviar la cotización',
        html: escapar(e.message) + (pdf && modo === 'cotizacion' ? '<br><small class="text-secondary">Se descargó el PDF para que puedas enviarlo manualmente.</small>' : ''),
        showCancelButton: true,
        confirmButtonText: 'Volver al formulario',
        cancelButtonText: 'Cerrar',
        reverseButtons: true,
      });
      if (res.isConfirmed) modal.show();
    }
  }

  form.addEventListener('submit', e => { e.preventDefault(); enviar(); });

  // ---------- Botones ----------
  root.addEventListener('click', e => {
    const btn = e.target.closest('[data-action]');
    if (!btn || btn.closest('tbody')) return;
    if (btn.dataset.action === 'agregar') agregar(btn);
    if (btn.dataset.action === 'nuevo') nuevo();
    if (btn.dataset.action === 'abrir') abrirModal(btn.dataset.modo);
  });

  render();
  if (cfg.precarga) precargar(cfg.precarga);
}

// ================= Catálogo de precios =================
function Catalogo(root) {
  const buscar = root.querySelector('[data-ref="buscar"]');
  const chips = root.querySelectorAll('[data-marca]:is(button)');
  const grupos = root.querySelectorAll('tbody[data-marca]');
  const resultado = root.querySelector('[data-ref="resultado"]');
  const sinResultados = root.querySelector('[data-ref="sin-resultados"]');
  const tabla = root.querySelector('.tabla-precios-wrap');
  const inicial = root.querySelector('[data-ref="inicial"]');
  const listado = root.querySelector('[data-ref="listado"]');
  const barra = root.querySelector('[data-ref="barra-resultado"]');
  let marca = null; // no se muestra nada hasta elegir una marca

  function aplicar() {
    inicial.classList.toggle('d-none', !!marca);
    listado.classList.toggle('d-none', !marca);
    barra.classList.toggle('d-none', !marca);
    if (!marca) return;

    const q = normalizar(buscar.value);
    let total = 0;
    grupos.forEach(g => {
      let visibles = 0;
      if (g.dataset.marca === marca) {
        g.querySelectorAll('tr[data-buscar]').forEach(tr => {
          const ok = !q || tr.dataset.buscar.includes(q);
          tr.classList.toggle('d-none', !ok);
          if (ok) visibles++;
        });
      }
      g.classList.toggle('d-none', visibles === 0);
      total += visibles;
    });
    resultado.textContent = `${total} ${total === 1 ? 'producto' : 'productos'}${q ? ` para “${buscar.value.trim()}”` : ''}`;
    sinResultados.classList.toggle('d-none', total > 0);
    tabla.classList.toggle('d-none', total === 0);
  }

  chips.forEach(chip => chip.addEventListener('click', () => {
    chips.forEach(c => c.classList.toggle('active', c === chip));
    marca = chip.dataset.marca;
    buscar.disabled = false;
    buscar.value = '';
    buscar.placeholder = `Buscar en ${chip.textContent.trim()} por nombre, SAP o código de barras…`;
    tabla.scrollTop = 0;
    aplicar();
  }));
  buscar.addEventListener('input', aplicar);
  aplicar();
}

// ================= Encabezado y bloques fijos =================
function Fijos() {
  const barra = document.querySelector('.ib-header');
  const header = document.querySelector('.app-header');
  const raiz = document.documentElement;
  // El encabezado del cotizador se fija debajo del de la intranet; --header-h es la altura de los dos juntos.
  const alturas = new ResizeObserver(() => {
    const alto = barra ? barra.offsetHeight : 0;
    raiz.style.setProperty('--ib-h', alto + 'px');
    raiz.style.setProperty('--header-h', alto + header.offsetHeight + 'px');
  });
  alturas.observe(header);
  if (barra) alturas.observe(barra);
  document.querySelectorAll('[data-sticky-var]').forEach(el =>
    new ResizeObserver(() => el.closest('section').style.setProperty(el.dataset.stickyVar, el.offsetHeight + 'px')).observe(el));

  // Compactar al bajar (con margen para que no parpadee cerca del límite)
  let compacto = false;
  addEventListener('scroll', () => {
    if (!compacto && scrollY > 80) compacto = true;
    else if (compacto && scrollY < 10) compacto = false;
    else return;
    header.classList.toggle('is-compact', compacto);
  }, { passive: true });

  // Al cambiar de pestaña, volver arriba para ver filtros y contenido desde el inicio
  document.querySelectorAll('.app-tabs [data-bs-toggle="tab"]').forEach(t =>
    t.addEventListener('shown.bs.tab', () => scrollTo({ top: 0 })));
}

// ================= Inicio =================
Fijos();
document.querySelectorAll('[data-ref="catalogo"]').forEach(Catalogo);
document.querySelectorAll('.cotizador').forEach(Cotizador);
