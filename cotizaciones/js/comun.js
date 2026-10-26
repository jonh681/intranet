'use strict';
// Utilidades de Cotizaciones: formato, llamadas a api.php (con el token de la sesión) y alertas (SweetAlert).

const CSRF = document.body.dataset.csrf;
const moneda = v => Number(v || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
const escapar = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const normalizar = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
const correoValido = s => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s);
const fechaHora = s => new Date(String(s).replace(' ', 'T')).toLocaleString('es-MX', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/** GET a api.php con los parámetros dados. */
function apiGet(action, params = {}) {
  return fetchJSON('api.php?' + new URLSearchParams({ action, ...params }));
}

/** POST a api.php: datos como objeto o FormData. */
function apiPost(action, datos) {
  const fd = datos instanceof FormData ? datos : Object.entries(datos).reduce((f, [k, v]) => (f.append(k, v ?? ''), f), new FormData());
  fd.append('action', action);
  return fetchJSON('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
}

async function fetchJSON(url, options) {
  let res;
  try {
    res = await fetch(url, options);
  } catch (_) {
    throw new Error('No hay conexión con el servidor. Revisa tu internet e intenta de nuevo.');
  }
  let data;
  try {
    data = await res.json();
  } catch (_) {
    throw new Error(`El servidor respondió con un error inesperado (código ${res.status}).`);
  }
  if (res.status === 401) location.href = '../';
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || data.message || `Error ${res.status}`);
  }
  return data;
}

// ================= Alertas =================
const UI = {
  toast: Swal.mixin({
    toast: true, position: 'top-end', showConfirmButton: false,
    timer: 2600, timerProgressBar: true,
  }),
  aviso(text, title = 'Falta información') {
    return Swal.fire({ icon: 'warning', title, text, confirmButtonText: 'Entendido' });
  },
  error(text, title = 'Algo salió mal') {
    return Swal.fire({ icon: 'error', title, text, confirmButtonText: 'Cerrar' });
  },
  async confirmar(title, text, confirmButtonText = 'Sí, continuar') {
    const r = await Swal.fire({
      icon: 'question', title, text, confirmButtonText,
      showCancelButton: true, cancelButtonText: 'Cancelar', reverseButtons: true, focusCancel: true,
    });
    return r.isConfirmed;
  },
  cargando(title) {
    Swal.fire({
      title, html: 'Esto puede tardar unos segundos…',
      allowOutsideClick: false, allowEscapeKey: false, didOpen: () => Swal.showLoading(),
    });
  },
};
