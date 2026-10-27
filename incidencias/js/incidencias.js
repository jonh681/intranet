'use strict';
// Incidencias de la intranet. Cada página marca <body data-page="..."> y trae sus datos en <script id="inc-data" type="application/json">.
//   index   Mis incidencias: calendario de colores, mis registros y la ventana para registrar.
//   todas   Administración: vista mensual, listado con filtros y registro a nombre de otra persona.
//   revisar Administración: aprobar o rechazar.
//   saldos  Administración: búsqueda y ajuste de saldo.

const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const page = document.body.dataset.page;
const DATA = $('#inc-data') ? JSON.parse($('#inc-data').textContent) : {};
const TODAY = DATA.today || new Date().toISOString().slice(0, 10);
const HOLIDAYS = DATA.holidays || {};

// ---------- Utilidades ----------
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const addDays = (day, n) => { const d = new Date(`${day}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const weekend = (day) => [0, 6].includes(new Date(`${day}T12:00:00Z`).getUTCDay());
const formatDay = (d, opts) => new Date(`${d}T12:00:00Z`).toLocaleDateString('es-MX', { timeZone: 'UTC', ...opts }).replace(/ de /g, ' ').replace('.', '');
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
const between = (a, b) => { const out = []; for (let d = a; d <= b; d = addDays(d, 1)) out.push(d); return out; };
const workdays = (dates) => dates.filter((d) => !weekend(d) && !HOLIDAYS[d]);
// Días que descuentan unas vacaciones: hábiles o, para quien trabaja fines de semana (dermoconsejeras), todos menos festivos.
const vacationDays = (dates, weekends) => dates.filter((d) => (weekends || !weekend(d)) && !HOLIDAYS[d]);
const vvDate = (d) => formatDay(d, { day: 'numeric', month: 'short', year: 'numeric' });
function period(i) {
    const dates = i.dates;
    if (!dates.length) return '—';
    const a = dates[0], b = dates.at(-1);
    const consecutive = dates.every((d, k) => k === 0 || addDays(dates[k - 1], 1) === d);
    if (i.type === 'permiso' && !consecutive) return `${dates.map((d) => formatDay(d, { day: 'numeric', month: 'short' })).join(', ')} ${b.slice(0, 4)}`;
    if (a === b) return vvDate(a);
    if (a.slice(0, 7) === b.slice(0, 7)) return `${Number(a.slice(8))} – ${vvDate(b)}`;
    return `${formatDay(a, { day: 'numeric', month: 'short' })} – ${vvDate(b)}`;
}
const LOOK = {
    vacaciones:  { label: 'Vacaciones',  icon: '✈️', solid: 'bg-sky-500 text-white',    soft: 'border-sky-500 text-sky-700',       tag: 'bg-sky-50 text-sky-700',       chip: 'text-sky-600' },
    permiso:     { label: 'Permiso',     icon: '📋', solid: 'bg-violet-500 text-white', soft: 'border-violet-500 text-violet-700', tag: 'bg-violet-50 text-violet-700', chip: 'text-violet-600' },
    incapacidad: { label: 'Incapacidad', icon: '🩺', solid: 'bg-rose-500 text-white',   soft: 'border-rose-500 text-rose-700',     tag: 'bg-rose-50 text-rose-700',     chip: 'text-rose-600' },
};
const PILL = {
    pendiente: ['Con su jefe', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
    autorizada: ['Por confirmar', 'bg-sky-50 text-sky-700 ring-sky-600/20'],
    aprobada:  ['Aprobada', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
    rechazada: ['Rechazada', 'bg-red-50 text-red-700 ring-red-600/20'],
    cancelada: ['Cancelada', 'bg-slate-100 text-slate-600 ring-slate-500/20'],
};
// En revisión: esperan al jefe (pendiente) o a quien valida (autorizada). Apartan sus días y se pueden cancelar.
const OPEN = ['pendiente', 'autorizada'];
const ACTIVE = [...OPEN, 'aprobada'];
const pill = (status) => `<span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${PILL[status][1]}">${PILL[status][0]}</span>`;
const typeTag = (type) => `<span class="whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold ${LOOK[type].tag}">${LOOK[type].icon} ${LOOK[type].label}</span>`;
// Texto de detalle: goce del permiso, tipo y folio de la incapacidad, comentario.
function detail(i) {
    const parts = [];
    if (i.type === 'permiso' && i.goce !== null) parts.push(i.goce ? 'Con goce de sueldo' : 'Sin goce de sueldo');
    if (i.type === 'incapacidad') { if (i.subtype) parts.push(i.subtype); if (i.folio) parts.push(`Folio IMSS ${i.folio}`); }
    if (i.note) parts.push(i.note);
    return parts.join(' · ');
}
const daysText = (i) => `${i.days} día${i.days === 1 ? '' : 's'}${i.type === 'vacaciones' ? ` hábil${i.days === 1 ? '' : 'es'}` : ''}`;

async function api(action, data) {
    const isForm = data instanceof FormData;
    if (isForm) data.append('action', action);
    const response = await fetch('api.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').content, ...(isForm ? {} : { 'Content-Type': 'application/json' }) },
        body: isForm ? data : JSON.stringify({ action, ...data }),
    });
    let json = {};
    try { json = await response.json(); } catch { /* respuesta vacía */ }
    if (!response.ok) throw new Error(json.error || 'No se pudo completar la operación.');
    return json;
}

// Aviso arriba de la página; sobrevive a una recarga (sessionStorage, si el navegador lo permite).
function notice(text, error = false) {
    const box = $('#notice');
    if (!box) return;
    box.textContent = text;
    box.className = `mt-6 rounded-xl border px-4 py-3 text-sm ${error ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`;
    box.hidden = false;
    window.intranetAviso?.(text, error ? 'error' : 'ok'); // aviso flotante común (comun/acciones.js)
}
function reloadWith(text) {
    try { sessionStorage.setItem('inc-notice', text); } catch { /* sin almacenamiento */ }
    location.reload();
}
try { const saved = sessionStorage.getItem('inc-notice'); if (saved) { sessionStorage.removeItem('inc-notice'); notice(saved); } } catch { /* sin almacenamiento */ }

// Anchos de barras (la CSP no permite style="" en el HTML).
$$('[data-w]').forEach((el) => { el.style.width = `${Math.max(0, Math.min(100, Number(el.dataset.w) || 0))}%`; });
// Ventanas: se cierran con [data-close] o clic fuera.
$$('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-close]')) d.close(); }));

// ---------- Mis incidencias: lista y calendario de colores ----------
const items = DATA.items || [];
let filter = '';
function renderMine() {
    $('#inc-list').innerHTML = items.filter((i) => !filter || i.type === filter).map((i) => `
        <article class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                ${typeTag(i.type)} ${pill(i.status)}
                <span class="ml-auto text-sm text-slate-500">${daysText(i)}</span>
            </div>
            <h3 class="mt-3 font-semibold text-slate-900">${esc(period(i))}</h3>
            ${detail(i) ? `<p class="mt-1 text-sm text-slate-600">${esc(detail(i))}</p>` : ''}
            ${i.status === 'rechazada' ? `<p class="mt-1 text-sm font-medium text-red-600">Rechazada${i.reviewed_by ? ` por ${esc(i.reviewed_by)}` : ''}${i.reason ? `: ${esc(i.reason)}` : ''}</p>` : ''}
            ${i.authorized_by ? `<p class="mt-1 text-xs text-slate-500">Autorizó ${esc(i.authorized_by)} (jefe directo)</p>` : ''}
            ${i.status === 'pendiente' ? `<p class="mt-1 text-xs text-amber-700">Espera la autorización de ${esc(i.manager || 'tu jefe directo')}; después la confirma Administración.</p>` : ''}
            ${i.status === 'autorizada' ? '<p class="mt-1 text-xs text-sky-700">Falta la confirmación de Administración.</p>' : ''}
            ${i.status === 'aprobada' && i.reviewed_by ? `<p class="mt-1 text-xs text-slate-500">Aprobada por ${esc(i.reviewed_by)}</p>` : ''}
            ${!i.self_created ? `<p class="mt-1 text-xs text-slate-500">Registrada por ${esc(i.created_by)}</p>` : ''}
            ${i.file ? `<a href="archivo.php?id=${i.id}" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">📎 ${esc(i.file.name)}</a>` : ''}
            ${OPEN.includes(i.status) ? `<button type="button" data-cancel="${i.source}:${i.id}" class="mt-3 block text-sm font-medium text-red-600 hover:text-red-800">Cancelar solicitud</button>` : ''}
        </article>`).join('') || '<p class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">Sin registros de este tipo.</p>';
}
let calMonth = TODAY.slice(0, 7);
function renderColorCalendar() {
    const first = `${calMonth}-01`, offset = (new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7;
    const [y, m] = calMonth.split('-').map(Number), total = new Date(Date.UTC(y, m, 0)).getUTCDate();
    $('#cal-title').textContent = cap(new Date(`${first}T12:00:00Z`).toLocaleDateString('es-MX', { timeZone: 'UTC', month: 'long', year: 'numeric' }));
    const byDay = {};
    items.filter((i) => ACTIVE.includes(i.status)).forEach((i) => i.dates.forEach((d) => (byDay[d] = i)));
    let html = ['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((d) => `<span class="py-1 font-semibold text-slate-400">${d}</span>`).join('') + '<span></span>'.repeat(offset);
    for (let n = 1; n <= total; n++) {
        const key = `${calMonth}-${String(n).padStart(2, '0')}`, inc = byDay[key];
        const cls = inc ? (OPEN.includes(inc.status) ? `border-2 border-dashed font-semibold ${LOOK[inc.type].soft}` : `font-semibold ${LOOK[inc.type].solid}`)
            : HOLIDAYS[key] ? 'bg-slate-100 text-slate-400 line-through' : weekend(key) ? 'text-slate-300' : 'text-slate-700';
        const title = inc ? `${LOOK[inc.type].label} · ${PILL[inc.status][0]}` : HOLIDAYS[key] ? `Festivo: ${HOLIDAYS[key]}` : '';
        html += `<span class="grid aspect-square place-items-center rounded-lg ${cls} ${key === TODAY ? 'ring-2 ring-slate-900' : ''}" title="${esc(title)}">${n}</span>`;
    }
    $('#calendar').innerHTML = html;
}
if (page === 'index') {
    renderMine();
    renderColorCalendar();
    $$('#inc-filter button').forEach((b) => b.addEventListener('click', () => {
        filter = b.dataset.type;
        $$('#inc-filter button').forEach((x) => (x.className = `rounded-md px-2.5 py-1 ${x === b ? 'bg-white shadow-sm' : 'text-slate-500'}`));
        renderMine();
    }));
    $('#cal-prev').addEventListener('click', () => { calMonth = addDays(`${calMonth}-15`, -30).slice(0, 7); renderColorCalendar(); });
    $('#cal-next').addEventListener('click', () => { calMonth = addDays(`${calMonth}-15`, 30).slice(0, 7); renderColorCalendar(); });
    $('#inc-list').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-cancel]');
        if (!b || !(await window.intranetConfirmar({ title: '¿Cancelar esta solicitud?', text: 'Esos días quedarán libres y se avisará a quien la estaba revisando.', ok: 'Sí, cancelar', cancel: 'No, conservarla' }))) return;
        const [source, id] = b.dataset.cancel.split(':');
        b.disabled = true;
        try { reloadWith((await api('cancelar', { source, id })).message); } catch (err) { notice(err.message, true); b.disabled = false; }
    });
}

// ---------- Calendario para elegir días (igual al de asistencia: vacation-picker.js y admin.js) ----------
// vacaciones: inicio y fin, solo dentro del año de vacaciones y contando días hábiles contra el saldo.
// incapacidad: inicio y fin.  permiso: uno o varios días sueltos.
const picker = { type: '', month: '', start: null, end: null, picked: new Set(), ctx: null };
function pickerDates() {
    if (picker.type === 'permiso') return [...picker.picked].sort();
    return picker.start ? between(picker.start, picker.end || picker.start) : [];
}
const pickerCounted = (dates) => picker.type === 'vacaciones' ? vacationDays(dates, picker.ctx.weekends) : workdays(dates);
// «día hábil» o, con fines de semana, solo «día».
const countText = (n) => picker.type === 'vacaciones' && picker.ctx.weekends ? plural(n, 'día', 'días') : plural(n, 'día hábil', 'días hábiles');
function pickerProblem() {
    const dates = pickerDates();
    if (!dates.length) return '';
    const { period: p, busy } = picker.ctx;
    const counted = pickerCounted(dates);
    const clash = counted.filter((d) => busy.has(d));
    if (!counted.length) return picker.type === 'permiso' ? 'Elige días hábiles (sin fines de semana ni festivos).' : picker.ctx.weekends && picker.type === 'vacaciones' ? 'Ese rango solo tiene días festivos.' : 'Ese rango no tiene días hábiles.';
    if (clash.length) return `Ya hay vacaciones o incidencia el ${clash.map((d) => formatDay(d, { day: 'numeric', month: 'short' })).join(', ')}.`;
    if (picker.type === 'vacaciones' && counted.length > p.available) return `Solo hay ${plural(p.available, 'día disponible', 'días disponibles')}.`;
    return '';
}
function renderPicker() {
    const { period: p, busy } = picker.ctx;
    const limited = picker.type === 'vacaciones';
    const [y, m] = picker.month.split('-').map(Number);
    const first = `${picker.month}-01`, offset = (new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7, total = new Date(Date.UTC(y, m, 0)).getUTCDate();
    const selected = new Set(pickerDates());
    const cells = Array(offset).fill('<span></span>');
    for (let n = 1; n <= total; n++) {
        const d = `${picker.month}-${String(n).padStart(2, '0')}`;
        // Los días anteriores a hoy no se pueden elegir (también lo revisa api.php).
        const past = d < TODAY;
        const inPeriod = !past && (!limited || (d >= p.start && d <= p.end));
        const holiday = HOLIDAYS[d], off = (weekend(d) && !(limited && picker.ctx.weekends)) || holiday;
        const edge = picker.type === 'permiso' ? selected.has(d) : picker.start !== null && (d === picker.start || d === (picker.end || picker.start));
        const tone = !inPeriod ? 'bg-slate-100/60 text-slate-300 cursor-not-allowed'
            : edge ? 'bg-primary text-white font-bold'
            : selected.has(d) ? (off ? 'bg-primary/10 text-slate-400' : 'bg-primary/20 text-primary font-semibold')
            : busy.has(d) ? 'bg-info/15 text-info ring-1 ring-info/40'
            : holiday ? 'bg-warning/20 ring-1 ring-warning/50'
            : off ? 'text-slate-400 hover:bg-slate-100' : 'hover:bg-slate-100';
        const title = holiday ? `Festivo: ${holiday}` : busy.has(d) ? 'Ya tiene vacaciones o incidencia' : past ? 'Día pasado' : !inPeriod ? 'Fuera del año de vacaciones' : '';
        cells.push(`<button type="button" data-day="${d}" ${inPeriod ? '' : 'disabled'} title="${esc(title)}" class="h-9 rounded-[10px] text-sm tabular-nums transition ${tone} ${d === TODAY ? 'underline underline-offset-4' : ''}">${n}</button>`);
    }
    const label = cap(new Date(`${first}T12:00:00Z`).toLocaleDateString('es-MX', { timeZone: 'UTC', month: 'long', year: 'numeric' }));
    $('#picker').innerHTML = `
        <div class="mb-2 flex items-center justify-between">
            <button type="button" data-nav="-1" ${picker.month <= TODAY.slice(0, 7) ? 'disabled' : ''} class="grid size-8 place-items-center rounded-[10px] hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-30" title="Mes anterior">‹</button>
            <span class="font-semibold">${label}</span>
            <button type="button" data-nav="1" class="grid size-8 place-items-center rounded-[10px] hover:bg-slate-100" title="Mes siguiente">›</button>
        </div>
        <div class="mb-1 grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-slate-400">${['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((w) => `<span>${w}</span>`).join('')}</div>
        <div class="grid grid-cols-7 gap-1">${cells.join('')}</div>`;
    renderPickerSummary();
}
function renderPickerSummary() {
    const dates = pickerDates(), problem = pickerProblem(), p = picker.ctx.period;
    const short = (d) => formatDay(d, { day: 'numeric', month: 'short' });
    const long = (d) => cap(formatDay(d, { weekday: 'short', day: 'numeric', month: 'short' }));
    let text = picker.type === 'vacaciones' ? 'Elige el primer día de vacaciones.' : '';
    if (dates.length && picker.type === 'vacaciones') {
        const counted = pickerCounted(dates), skipped = dates.filter((d) => HOLIDAYS[d] && (picker.ctx.weekends || !weekend(d))).map((d) => HOLIDAYS[d]);
        text = `${dates.length === 1 ? long(dates[0]) : `Del ${long(dates[0])} al ${long(dates.at(-1))}`} · ${countText(counted.length)}`;
        if (picker.ctx.weekends && dates.some(weekend)) text += ' (incluye fin de semana)';
        if (skipped.length) text += ` (no cuenta ${skipped.join(', ')})`;
        if (!picker.end) text += ' · elige el último día o deja solo este';
        if (!problem) text += ` · quedarán ${p.available - counted.length} de ${p.entitled}`;
    } else if (dates.length && picker.type === 'incapacidad') {
        const counted = workdays(dates);
        text = `${dates.length === 1 ? short(dates[0]) : `Del ${short(dates[0])} al ${short(dates.at(-1))}`} · ${plural(counted.length, 'día hábil', 'días hábiles')}${picker.end || dates.length > 1 ? '' : ' (elige el día final)'}`;
    } else if (dates.length) {
        const counted = workdays(dates);
        text = `${plural(counted.length, 'día hábil', 'días hábiles')}: ${counted.map(short).join(', ')}${counted.length < dates.length ? ' (no cuentan fines de semana ni festivos)' : ''}`;
    }
    if (problem) text += ` · ${problem}`;
    $('#picker-summary').textContent = text;
    $('#picker-summary').className = `mt-2 min-h-5 text-sm font-medium ${problem ? 'text-error' : dates.length ? 'text-primary' : 'text-slate-500'}`;
    $('#inc-submit').disabled = !dates.length || Boolean(problem);
}

const modal = $('#inc-modal');
if (modal) {
    const form = $('#inc-form');
    const forOthers = modal.dataset.forOthers === '1';
    // Contexto del calendario: la propia persona o la elegida (administración).
    const context = () => {
        const person = forOthers ? (DATA.people || []).find((x) => String(x.id) === $('#inc-person').value) : DATA.me;
        return person ? { period: person.period, busy: new Set(person.busy), hire: person.hire_date, weekends: Boolean(person.weekends) } : null;
    };
    const chooseType = () => {
        const type = form.querySelector('[name=tipo]:checked')?.value || '';
        const ctx = context();
        $('#inc-error').hidden = true;
        if (!type || !ctx) { $('#inc-fields').hidden = true; $('#inc-submit').disabled = true; return; }
        Object.assign(picker, { type, ctx, month: TODAY.slice(0, 7), start: null, end: null, picked: new Set() });
        $('#inc-fields').hidden = false;
        $$('[data-only]', form).forEach((el) => (el.hidden = el.dataset.only !== type));
        $('#picker-label').textContent = type === 'vacaciones' ? 'Toca el primer día y luego el último. Si es un solo día, tócalo una vez.'
            : type === 'permiso' ? 'Selecciona uno o varios días' : 'Selecciona el día de inicio y el día de fin';
        $('#inc-file-label').textContent = type === 'incapacidad' ? 'Incapacidad del IMSS (obligatoria)' : 'Evidencia (opcional)';
        $('#inc-desc-label').textContent = type === 'permiso' ? 'Motivo del permiso' : type === 'vacaciones' ? 'Comentario para administración (opcional)' : 'Descripción (opcional)';
        $('#inc-submit').textContent = forOthers ? (modal.dataset.approves === '1' ? 'Guardar y aprobar' : 'Guardar (queda en revisión)') : type === 'vacaciones' ? 'Enviar solicitud' : 'Enviar a revisión';
        if (type === 'vacaciones' && !ctx.period) {
            $('#picker').innerHTML = `<p class="p-4 text-center text-sm text-slate-500">${ctx.hire ? 'Aún no cumple un año: todavía no tiene días de vacaciones.' : 'Falta la fecha de ingreso para calcular los días de vacaciones (Seguridad → Empleados).'}</p>`;
            $('#picker-summary').textContent = '';
            $('#inc-submit').disabled = true;
            return;
        }
        if (type === 'vacaciones' && TODAY < ctx.period.start) picker.month = ctx.period.start.slice(0, 7);
        renderPicker();
    };
    form.addEventListener('change', (e) => { if (['tipo', 'usuario'].includes(e.target.name)) chooseType(); });
    $('#picker').addEventListener('click', (e) => {
        const nav = e.target.closest('[data-nav]');
        if (nav) { if (nav.disabled) return; picker.month = addDays(`${picker.month}-15`, Number(nav.dataset.nav) * 30).slice(0, 7); renderPicker(); return; }
        const cell = e.target.closest('[data-day]');
        if (!cell || cell.disabled) return;
        const d = cell.dataset.day;
        if (picker.type === 'permiso') picker.picked.has(d) ? picker.picked.delete(d) : picker.picked.add(d);
        else if (!picker.start || picker.end) { picker.start = d; picker.end = null; }
        else [picker.start, picker.end] = d < picker.start ? [d, picker.start] : [picker.start, d];
        renderPicker();
    });
    $('#picker-clear').addEventListener('click', () => { Object.assign(picker, { start: null, end: null, picked: new Set() }); renderPicker(); });
    $('#open-inc').addEventListener('click', () => { form.reset(); chooseType(); modal.showModal(); });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if ($('#inc-submit').disabled) return;
        const body = new FormData(form);
        // Vacaciones: el rango completo (el servidor cuenta solo los días hábiles); permiso: días sueltos; incapacidad: días seguidos.
        body.set('fechas', JSON.stringify(pickerDates()));
        $('#inc-submit').disabled = true;
        try {
            reloadWith((await api('crear', body)).message);
        } catch (err) {
            $('#inc-error').textContent = err.message;
            $('#inc-error').hidden = false;
            $('#inc-submit').disabled = false;
        }
    });
}

// ---------- Todas las incidencias: filtros, vista mensual y listado ----------
if (page === 'todas') {
    let month = TODAY.slice(0, 7);
    let day = '';
    const matches = (i) => {
        const term = $('#f-search').value.trim().toLowerCase();
        return (!term || i.user.name.toLowerCase().includes(term)) && (!$('#f-type').value || i.type === $('#f-type').value)
            && (!$('#f-status').value || i.status === $('#f-status').value) && (!$('#f-area').value || i.user.area === $('#f-area').value);
    };
    const KINDS = [['vacaciones', '✈️'], ['permiso', '📋'], ['incapacidad', '🩺']];
    function renderMonth() {
        const first = `${month}-01`;
        const start = addDays(first, -((new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7));
        $('#month-title').textContent = cap(new Date(`${month}-15T12:00:00Z`).toLocaleDateString('es-MX', { timeZone: 'UTC', month: 'long', year: 'numeric' }).replace(' de ', ' '));
        const shown = items.filter((i) => matches(i) && ACTIVE.includes(i.status));
        $('#month-grid').innerHTML = Array.from({ length: 42 }, (_, k) => addDays(start, k)).map((d) => {
            const inMonth = d.startsWith(month), today = d === TODAY;
            const on = shown.filter((i) => i.dates.includes(d));
            const count = (type) => on.filter((i) => i.type === type && i.status === 'aprobada').length;
            const pending = on.filter((i) => OPEN.includes(i.status)).length;
            const chips = KINDS.filter(([t]) => count(t)).map(([t, ic]) => `<span class="inline-flex items-center gap-0.5 text-[11px] font-semibold tabular-nums sm:text-xs ${LOOK[t].chip}">${ic}${count(t)}</span>`).join('')
                + (pending ? `<span class="inline-flex items-center gap-0.5 text-[11px] font-semibold tabular-nums text-amber-600 sm:text-xs">⏳${pending}</span>` : '');
            const names = on.map((i) => `${i.user.name}: ${LOOK[i.type].label}${OPEN.includes(i.status) ? ` (${PILL[i.status][0].toLowerCase()})` : ''}`).join('\n');
            return `<button type="button" data-day="${d}" title="${esc(HOLIDAYS[d] ? `Festivo: ${HOLIDAYS[d]}\n` : '')}${esc(names || formatDay(d, { weekday: 'long', day: 'numeric', month: 'long' }))}"
                class="relative flex min-h-14 flex-col items-start rounded-[10px] border p-1.5 text-left transition sm:min-h-24 sm:p-2
                ${inMonth ? 'border-slate-200 bg-white hover:border-primary/50 hover:shadow-sm' : 'border-transparent bg-slate-100/60 text-slate-400 hover:border-slate-200'}
                ${d === day ? 'border-primary! bg-primary/5!' : ''} ${today ? 'ring-2 ring-primary/60' : ''}">
                <span class="text-xs tabular-nums sm:text-sm ${today ? 'font-bold text-primary' : 'font-semibold'} ${HOLIDAYS[d] ? 'line-through' : ''}">${Number(d.slice(8))}</span>
                <span class="mt-1 flex flex-wrap gap-x-1.5 gap-y-0.5 sm:gap-x-2.5">${chips}</span>
            </button>`;
        }).join('');
    }
    // Formato de vacaciones en PDF (formato.php): solo para quien valida y solo de vacaciones ya aprobadas.
    // También puede cancelarlas (ventana #cancel-vac-modal de todas.php).
    const formatLink = (i) => DATA.formato && i.source === 'vacaciones' && i.status === 'aprobada'
        ? `<span class="mt-1 flex flex-wrap gap-1.5"><a href="formato.php?id=${i.id}" class="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-2 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-100" title="Descargar el formato de vacaciones con su historial">📄 Formato PDF</a>
            <button type="button" data-cancel-vac="${i.id}" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100" title="Cancelar estas vacaciones aunque ya estén aprobadas">✕ Cancelar</button></span>` : '';
    const cancelModal = $('#cancel-vac-modal');
    let cancelling = null;
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-cancel-vac]');
        if (!b || !cancelModal) return;
        cancelling = items.find((i) => i.source === 'vacaciones' && String(i.id) === b.dataset.cancelVac);
        if (!cancelling) return;
        $('#cancel-vac-form').reset();
        $('#cancel-vac-error').hidden = true;
        $('[data-name]', cancelModal).textContent = cancelling.user.name;
        $('[data-dates]', cancelModal).textContent = `${cap(period(cancelling))} (${daysText(cancelling)})`;
        cancelModal.showModal();
    });
    $('#cancel-vac-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submit = e.target.querySelector('button:not([type="button"])');
        if (submit.disabled || !cancelling) return;
        if (!e.target.motivo.value.trim()) { $('#cancel-vac-error').textContent = 'Escribe el motivo de la cancelación.'; $('#cancel-vac-error').hidden = false; return; }
        submit.disabled = true;
        submit.classList.add('ia-busy');
        try {
            reloadWith((await api('cancelar_aprobada', { id: cancelling.id, motivo: e.target.motivo.value.trim() })).message);
        } catch (err) {
            $('#cancel-vac-error').textContent = err.message;
            $('#cancel-vac-error').hidden = false;
            submit.disabled = false;
            submit.classList.remove('ia-busy');
        }
    });
    function renderAll() {
        const rows = items.filter(matches);
        $('#list-count').textContent = `· ${plural(rows.length, 'registro', 'registros')}`;
        $('#all-empty').hidden = rows.length > 0;
        $('#all-list').innerHTML = rows.map((i) => `<tr class="hover:bg-slate-50">
            <td class="px-4 py-3"><div class="font-medium text-slate-900">${esc(i.user.name)}</div><div class="text-xs text-slate-500">${esc(i.user.area || '')}</div></td>
            <td class="px-4 py-3">${typeTag(i.type)}</td>
            <td class="whitespace-nowrap px-4 py-3">${esc(period(i))}</td>
            <td class="px-4 py-3 tabular-nums">${i.days}</td>
            <td class="px-4 py-3">${pill(i.status)}</td>
            <td class="max-w-xs px-4 py-3 text-slate-600"><div class="line-clamp-2">${esc(detail(i)) || '—'}</div>
                ${i.file ? `<a href="archivo.php?id=${i.id}" class="text-xs font-medium text-brand-700 hover:underline">📎 ${esc(i.file.name)}</a>` : ''}
                ${i.reason && i.status !== 'aprobada' ? `<div class="text-xs text-red-600">${esc(i.reason)}</div>` : ''}
                ${formatLink(i)}</td>
        </tr>`).join('');
    }
    // Ventana con las incidencias de un día (aprobadas y en revisión, con los filtros de la página).
    const dayModal = $('#day-modal');
    const STAT = [['vacaciones', 'Vacaciones', 'bg-sky-500/20 text-sky-100'], ['permiso', 'Permisos', 'bg-violet-500/20 text-violet-100'],
        ['incapacidad', 'Incapacidades', 'bg-rose-500/20 text-rose-100']];
    function renderDay() {
        const on = items.filter((i) => matches(i) && ACTIVE.includes(i.status) && i.dates.includes(day))
            .sort((a, b) => (OPEN.includes(a.status) - OPEN.includes(b.status)) || a.type.localeCompare(b.type) || a.user.name.localeCompare(b.user.name, 'es'));
        $('#day-kicker').textContent = day === TODAY ? 'Hoy' : 'Detalle del día';
        $('#day-title').textContent = cap(formatDay(day, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
        const approved = (type) => on.filter((i) => i.type === type && i.status === 'aprobada').length;
        const pending = on.filter((i) => OPEN.includes(i.status)).length;
        $('#day-stats').innerHTML = [...STAT.map(([t, label, cls]) => [label, approved(t), cls]), ['En revisión', pending, 'bg-amber-500/20 text-amber-100']]
            .map(([label, n, cls]) => `<div class="rounded-xl px-3 py-2 ${cls}"><div class="text-2xl font-bold tabular-nums leading-none text-white">${n}</div><div class="mt-1 text-xs">${label}</div></div>`).join('');
        $('#day-holiday').hidden = !HOLIDAYS[day] && !weekend(day);
        $('#day-holiday').textContent = HOLIDAYS[day] ? `Día festivo: ${HOLIDAYS[day]}. No se descuenta de las vacaciones.` : 'Fin de semana.';
        $('#day-empty').hidden = on.length > 0;
        $('#day-empty').textContent = 'Nadie tiene vacaciones, permiso ni incapacidad este día.';
        $('#day-list').innerHTML = on.map((i) => `
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <div class="min-w-0 flex-1"><div class="font-semibold text-slate-900">${esc(i.user.name)}</div><div class="text-xs text-slate-500">${esc(i.user.area || 'Sin área')}</div></div>
                    ${typeTag(i.type)} ${pill(i.status)}
                </div>
                <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-sm font-medium text-slate-800">${esc(period(i))}</span>
                    <span class="text-xs text-slate-500">${daysText(i)}</span>
                </div>
                ${detail(i) ? `<p class="mt-1 text-sm text-slate-600">${esc(detail(i))}</p>` : ''}
                ${i.file ? `<a href="archivo.php?id=${i.id}" class="mt-1 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">📎 ${esc(i.file.name)}</a>` : ''}
                ${formatLink(i)}
                <p class="mt-2 text-xs text-slate-500">${esc([i.authorized_by ? `Autorizó ${i.authorized_by}` : '',
                    i.status === 'pendiente' ? `Espera a ${i.manager || 'su jefe directo'}` : i.status === 'autorizada' ? 'Falta la confirmación de Administración' : i.reviewed_by ? `Aprobó ${i.reviewed_by}` : ''].filter(Boolean).join(' · '))}</p>
            </article>`).join('');
    }
    const openDay = (d) => { day = d; renderDay(); renderMonth(); if (!dayModal.open) dayModal.showModal(); };
    dayModal.addEventListener('click', (e) => {
        const nav = e.target.closest('[data-day-nav]');
        if (!nav) return;
        day = addDays(day, Number(nav.dataset.dayNav));
        if (!day.startsWith(month)) month = day.slice(0, 7);
        renderDay();
        renderMonth();
    });
    dayModal.addEventListener('close', () => { day = ''; renderMonth(); });
    // Reporte en Excel de lo que coincide con los filtros (SheetJS de asistencia: solo se descarga al pedir el primer reporte).
    let xlsx = null;
    const loadXlsx = () => (xlsx ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = '../asistencia/assets/vendor/xlsx/xlsx.full.min.js';
        script.onload = () => resolve(window.XLSX);
        script.onerror = () => { xlsx = null; reject(new Error('No se pudo cargar el generador de Excel. Intenta de nuevo.')); };
        document.head.append(script);
    }));
    const stamp = (v) => (v ? v.slice(0, 16).replace('T', ' ') : '');
    $('#download-report').addEventListener('click', async (e) => {
        const button = e.currentTarget, rows = items.filter(matches);
        if (!rows.length) return notice('No hay incidencias con esos filtros para descargar.', true);
        button.disabled = true;
        try {
            const XLSX = await loadXlsx();
            const aoa = [['Persona', 'Área', 'Tipo', 'Detalle del tipo', 'Desde', 'Hasta', 'Fechas', 'Días', 'Estado', 'Jefe directo', 'Autorizó', 'Revisó',
                'Registrada por', 'Registrada el', 'Comentario', 'Motivo de rechazo o cancelación', 'Comprobante'],
                ...rows.map((i) => [i.user.name, i.user.area || '', LOOK[i.type].label,
                    i.type === 'permiso' && i.goce !== null ? (i.goce ? 'Con goce de sueldo' : 'Sin goce de sueldo') : [i.subtype, i.folio && `Folio IMSS ${i.folio}`].filter(Boolean).join(' · '),
                    i.dates[0] || '', i.dates.at(-1) || '', i.dates.join(', '), i.days, PILL[i.status][0], i.manager || '', i.authorized_by || '', i.reviewed_by || '',
                    i.created_by || '', stamp(i.created_at), i.note || '', i.status === 'aprobada' ? '' : i.reason || '', i.file ? i.file.name : ''])];
            const sheet = XLSX.utils.aoa_to_sheet(aoa);
            sheet['!cols'] = aoa[0].map((h, c) => ({ wch: Math.min(45, Math.max(h.length, ...aoa.slice(1).map((r) => String(r[c] ?? '').length)) + 2) }));
            sheet['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: aoa.length - 1, c: aoa[0].length - 1 } }) };
            const book = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(book, sheet, 'Incidencias');
            XLSX.writeFile(book, `incidencias_${TODAY}.xlsx`);
        } catch (err) {
            notice(err.message, true);
        } finally {
            button.disabled = false;
        }
    });
    const refresh = () => { renderMonth(); renderAll(); };
    ['#f-search', '#f-type', '#f-status', '#f-area'].forEach((s) => $(s).addEventListener('input', refresh));
    $('#month-prev').addEventListener('click', () => { month = addDays(`${month}-15`, -30).slice(0, 7); renderMonth(); });
    $('#month-next').addEventListener('click', () => { month = addDays(`${month}-15`, 30).slice(0, 7); renderMonth(); });
    $('#month-today').addEventListener('click', () => { month = TODAY.slice(0, 7); renderMonth(); });
    $('#month-grid').addEventListener('click', (e) => { const b = e.target.closest('[data-day]'); if (b) openDay(b.dataset.day); });
    refresh();
}

// ---------- Por revisar (quien valida) e Incidencias de mi equipo (jefe directo) ----------
// Cada botón lleva data-action ('revisar' o 'autorizar') y data-review (aprobar, autorizar o rechazar).
if (page === 'revisar' || page === 'equipo') {
    const rejectModal = $('#reject-modal');
    let rejecting = null;
    const done = (button, text, pending) => {
        const list = button.closest('[data-review-list]');
        button.closest('[data-item]').remove();
        const empty = list?.parentElement.querySelector('[data-review-empty]');
        if (empty) empty.hidden = list.querySelector('[data-item]') !== null;
        const badge = $(`.ib-section[href="${page}.php"] .ib-badge`);
        if (badge) { badge.textContent = pending; badge.hidden = !pending; }
        notice(text);
    };
    const send = (b, decision, motivo = '') => api(b.dataset.action, { source: b.dataset.source, id: b.dataset.id, decision, motivo });
    // Mientras se decide una solicitud, toda su tarjeta queda bloqueada (aprobar/autorizar y rechazar) y un segundo
    // clic se ignora: la misma solicitud nunca se manda dos veces.
    const working = new Set();
    const lock = (button, on) => {
        const card = button.closest('[data-item]');
        if (on) working.add(card.dataset.item); else working.delete(card.dataset.item);
        for (const other of card.querySelectorAll('[data-review]')) other.disabled = on;
        button.classList.toggle('ia-busy', on);
        if (on) button.setAttribute('aria-busy', 'true'); else button.removeAttribute('aria-busy');
    };
    const busyCard = (button) => working.has(button.closest('[data-item]')?.dataset.item);
    document.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-review]');
        if (!b || b.disabled || busyCard(b)) return;
        if (b.dataset.review === 'rechazar') {
            rejecting = b;
            $('#reject-form').reset();
            $('#reject-error').hidden = true;
            $('[data-name]', rejectModal).textContent = b.dataset.name;
            rejectModal.showModal();
            return;
        }
        lock(b, true);
        try { const r = await send(b, b.dataset.review); done(b, r.message, r.pending); }
        catch (err) { notice(err.message, true); lock(b, false); }
    });
    const rejectSubmit = $('#reject-form button:not([type="button"])');
    $('#reject-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (rejectSubmit.disabled || busyCard(rejecting)) return;
        rejectSubmit.disabled = true;
        rejectSubmit.classList.add('ia-busy');
        lock(rejecting, true);
        try {
            const r = await send(rejecting, 'rechazar', e.target.motivo.value);
            rejectModal.close();
            done(rejecting, r.message, r.pending);
        } catch (err) {
            $('#reject-error').textContent = err.message;
            $('#reject-error').hidden = false;
            lock(rejecting, false);
        } finally {
            rejectSubmit.disabled = false;
            rejectSubmit.classList.remove('ia-busy');
        }
    });
}

// ---------- Saldos de vacaciones ----------
if (page === 'saldos') {
    $('#s-search').addEventListener('input', (e) => {
        const term = e.target.value.trim().toLowerCase();
        $$('#balance-list tr').forEach((tr) => (tr.hidden = term !== '' && !tr.dataset.search.includes(term)));
    });
    const adjustModal = $('#adjust-modal');
    $('#balance-list').addEventListener('click', (e) => {
        const b = e.target.closest('[data-adjust]');
        if (!b) return;
        $('#adjust-form').reset();
        $('#adjust-error').hidden = true;
        $('#adjust-form').usuario.value = b.dataset.adjust;
        $('[data-name]', adjustModal).textContent = b.dataset.name;
        $('[data-period]', adjustModal).textContent = b.dataset.period;
        adjustModal.showModal();
    });
    $('#adjust-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        try { reloadWith((await api('ajustar', { usuario: f.usuario.value, dias: f.dias.value, motivo: f.motivo.value })).message); }
        catch (err) { $('#adjust-error').textContent = err.message; $('#adjust-error').hidden = false; }
    });
}
