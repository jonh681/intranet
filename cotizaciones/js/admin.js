'use strict';
// Administración de productos, lista de precios y clientes (parciales/admin.php). Requiere js/comun.js.

(() => {
  const main = document.querySelector('[data-admin]');
  const cfg = JSON.parse(main.dataset.admin);
  const ref = name => document.querySelector(`[data-ref="${name}"]`);
  const editorEl = document.getElementById('editor');
  const editor = new bootstrap.Modal(editorEl);
  const form = ref('form');
  const columnas = Object.keys(cfg.columnas);
  let estado = { pagina: 1, paginas: 1, q: '', filtro: '' };
  let filas = [];
  let peticion = 0;

  const celda = (col, valor) => {
    if (cfg.dinero.includes(col)) return `<td class="num">${valor === null || valor === '' ? '<span class="text-secondary">—</span>' : moneda(valor)}</td>`;
    if (col === 'descripcion' || col === 'nombre') return `<td class="fw-medium">${escapar(valor)}</td>`;
    return `<td>${valor === null || valor === '' ? '<span class="text-secondary">—</span>' : escapar(valor)}</td>`;
  };

  async function cargar() {
    const id = ++peticion;
    try {
      const r = await apiGet('admin_lista', { entidad: cfg.entidad, q: estado.q, filtro: estado.filtro, pagina: estado.pagina });
      if (id !== peticion) return; // llegó una respuesta vieja
      filas = r.filas;
      Object.assign(estado, { pagina: r.pagina, paginas: r.paginas });
      ref('filas').innerHTML = filas.length
        ? filas.map(f => `<tr data-id="${f.id}">${columnas.map(c => celda(c, f[c])).join('')}
            <td class="text-end text-nowrap">
              <button class="btn btn-sm btn-outline-secondary btn-icon" type="button" data-accion="editar" title="Editar"><i class="bi bi-pencil"></i></button>
              <button class="btn btn-sm btn-outline-danger btn-icon" type="button" data-accion="eliminar" title="Eliminar"><i class="bi bi-trash3"></i></button>
            </td></tr>`).join('')
        : `<tr><td colspan="${columnas.length + 1}" class="text-center text-secondary py-5">${estado.q || estado.filtro ? 'Sin resultados con esta búsqueda.' : 'Aún no hay registros.'}</td></tr>`;
      ref('resumen').textContent = `${r.total} ${r.total === 1 ? 'registro' : 'registros'} · página ${r.pagina} de ${r.paginas}`;
      document.querySelector('[data-accion="anterior"]').disabled = r.pagina <= 1;
      document.querySelector('[data-accion="siguiente"]').disabled = r.pagina >= r.paginas;
      // Opciones del filtro (marcas o listas de precios), conservando la elegida.
      const sel = ref('filtro');
      const elegida = sel.value;
      sel.innerHTML = `<option value="">${escapar(cfg.filtro)}: todas</option>` + r.opciones.map(o => `<option>${escapar(o)}</option>`).join('');
      sel.value = elegida;
    } catch (e) {
      UI.error(e.message, 'No se pudo cargar la lista');
    }
  }

  // ---------- Formulario ----------
  function abrir(fila) {
    form.reset();
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    form.querySelectorAll('[name]').forEach(el => {
      const v = fila ? fila[el.name] : '';
      el.value = v === null || v === undefined || v === '' ? '' : (cfg.dinero.includes(el.name) ? Number(v).toFixed(2) : v);
      delete el.dataset.auto;
    });
    editorEl.querySelector('.modal-title').textContent = (fila ? 'Editar ' : 'Agregar ') + cfg.singular;
    editor.show();
    setTimeout(() => form.querySelector('input:not([type=hidden]), select')?.focus(), 300);
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const faltan = [...form.querySelectorAll('[required]')].filter(el => !el.value.trim());
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    if (faltan.length) {
      faltan.forEach(el => el.classList.add('is-invalid'));
      faltan[0].focus();
      UI.toast.fire({ icon: 'error', title: 'Llena los campos marcados' });
      return;
    }
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    try {
      const fd = new FormData(form);
      fd.append('entidad', cfg.entidad);
      const r = await apiPost('admin_guardar', fd);
      editor.hide();
      UI.toast.fire({ icon: 'success', title: r.message });
      cargar();
    } catch (err) {
      UI.error(err.message, 'No se pudo guardar');
    } finally {
      btn.disabled = false;
    }
  });
  form.addEventListener('input', e => {
    e.target.classList.remove('is-invalid');
    e.target.dataset.manual = '1';
    calcularLista(e.target);
  });

  // Lista de precios: con IVA y 25% de descuento se calculan desde el precio sin IVA mientras no se escriban a mano.
  const redondear = v => (Math.round(v * 100) / 100).toFixed(2);
  const numero = el => Number(String(el?.value || '').replace(/[$,\s]/g, ''));
  function calcularLista(origen) {
    if (cfg.entidad !== 'lista') return;
    const f = name => form.elements[name];
    const poner = (name, valor) => {
      const el = f(name);
      if (el && (!el.value || el.dataset.auto) && el !== origen) { el.value = valor; el.dataset.auto = '1'; delete el.dataset.manual; }
    };
    if (origen.name === 'publico' && numero(origen) > 0) {
      poner('publico_iva', redondear(numero(origen) * 1.16));
      poner('descuento', redondear(numero(origen) * 0.75));
      poner('descuento_iva', redondear(numero(origen) * 0.75 * 1.16));
    }
    if (origen.name === 'descuento' && numero(origen) > 0) poner('descuento_iva', redondear(numero(origen) * 1.16));
    if (origen.dataset.manual) delete origen.dataset.auto;
  }

  // Productos: listas 15% a 40% desde el precio base.
  function calcularListas() {
    const base = numero(form.elements.precio_base);
    if (!(base > 0)) {
      UI.aviso('Escribe primero el precio base.');
      return;
    }
    [15, 20, 25, 30, 33, 35, 40].forEach(p => { form.elements[`precio_${p}`].value = redondear(base * (1 - p / 100)); });
    UI.toast.fire({ icon: 'info', title: 'Listas calculadas: revísalas antes de guardar' });
  }

  async function eliminar(fila) {
    const nombre = fila.descripcion || fila.nombre || '';
    if (!await UI.confirmar(`¿Eliminar este ${cfg.singular}?`, `${nombre}\n\nEsta acción no se puede deshacer. Los pedidos ya enviados conservan sus datos.`, 'Sí, eliminar')) return;
    try {
      const r = await apiPost('admin_eliminar', { entidad: cfg.entidad, id: fila.id });
      UI.toast.fire({ icon: 'success', title: r.message });
      cargar();
    } catch (err) {
      UI.error(err.message, 'No se pudo eliminar');
    }
  }

  // ---------- Eventos ----------
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const fila = filas.find(f => String(f.id) === btn.closest('tr')?.dataset.id);
    const accion = btn.dataset.accion;
    if (accion === 'nuevo') abrir(null);
    if (accion === 'editar') abrir(fila);
    if (accion === 'eliminar') eliminar(fila);
    if (accion === 'calcular') calcularListas();
    if (accion === 'anterior' && estado.pagina > 1) { estado.pagina--; cargar(); }
    if (accion === 'siguiente' && estado.pagina < estado.paginas) { estado.pagina++; cargar(); }
  });
  let espera;
  ref('buscar').addEventListener('input', e => {
    clearTimeout(espera);
    espera = setTimeout(() => { Object.assign(estado, { q: e.target.value.trim(), pagina: 1 }); cargar(); }, 250);
  });
  ref('filtro').addEventListener('change', e => { Object.assign(estado, { filtro: e.target.value, pagina: 1 }); cargar(); });

  cargar();
})();
