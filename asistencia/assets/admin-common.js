'use strict';
// Utilidades compartidas por las páginas de administración (admin.js y visitas.js).
const $ = (selector) => document.querySelector(selector);
const token = $('meta[name="csrf-token"]').content;
const zone = 'America/Mexico_City';
const KINDS = ['entrada', 'salida_comida', 'regreso_comida', 'salida'];
const NAMES = {entrada: 'Entrada', salida_comida: 'Salida a comida', regreso_comida: 'Regreso de comida', salida: 'Salida'};
const COLORS = {entrada: '#13315c', salida_comida: '#5b8bd9', regreso_comida: '#5b8bd9', salida: '#2f9e6e'};
const VISIT_COLOR = '#d9a032';
// Gerente: ve solo a su equipo y en modo lectura (el servidor también lo impone).
const isManager = document.body.dataset.role === 'gerente';

async function api(action, values = {}) {
    const response = await fetch('api.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token}, body: JSON.stringify({action, ...values})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'No se pudo completar la solicitud.');
    return data;
}

// Igual que api(), pero con FormData para adjuntar archivos (el navegador pone el Content-Type multipart).
async function apiForm(action, form) {
    form.set('action', action);
    const response = await fetch('api.php', {method: 'POST', headers: {'X-CSRF-Token': token}, body: form});
    const data = await response.json().catch(() => ({error: response.status === 413 ? 'El archivo es demasiado grande.' : null}));
    if (!response.ok) throw new Error(data.error || 'No se pudo completar la solicitud.');
    return data;
}

const INCIDENT_TYPES = {
    vacaciones: {label: 'Vacaciones', badge: 'badge-info'},
    permiso: {label: 'Permiso', badge: 'badge-warning'},
    incapacidad: {label: 'Incapacidad', badge: 'badge-error'},
};
// Incidencia que cubre a un empleado en un día (o null).
const incidentOn = (userId, day) => data.incidents.find(i => i.user_id === userId && i.dates.includes(day)) || null;

// Crea un elemento con clases y texto (nunca HTML, para no inyectar datos de usuarios).
function el(tag, className = '', text = null, ...children) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== null) node.textContent = text;
    node.append(...children);
    return node;
}

// ---- Fechas (siempre en hora de Ciudad de México) ----
const isoDay = (date) => date.toLocaleDateString('en-CA', {timeZone: zone});
const addDays = (day, n) => { const d = new Date(`${day}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const formatTime = (value) => new Date(value).toLocaleTimeString('es-MX', {timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false});
const formatDay = (day, opts = {weekday: 'short', day: 'numeric', month: 'short'}) => {
    const text = new Date(`${day}T12:00:00Z`).toLocaleDateString('es-MX', {timeZone: 'UTC', ...opts}).replace(/ de /g, opts.month === 'short' ? ' ' : ' de ');
    return text.charAt(0).toUpperCase() + text.slice(1);
};
const formatMinutes = (minutes) => {
    if (minutes == null) return '—';
    const total = Math.round(minutes);
    return `${Math.floor(total / 60)} h ${String(total % 60).padStart(2, '0')} m`;
};
const initials = (name) => name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');

function presetRange(preset) {
    const today = isoDay(new Date());
    if (preset === 'yesterday') return [addDays(today, -1), addDays(today, -1)];
    if (preset === 'week') {
        const weekday = new Date(`${today}T12:00:00Z`).getUTCDay();
        return [addDays(today, -((weekday + 6) % 7)), today];
    }
    if (preset === 'month') return [`${today.slice(0, 8)}01`, today];
    return [today, today];
}

// Solo se marca el primer periodo que coincide (el día 1, "Hoy" y "Mes" son el mismo rango).
function markPresets(from, to) {
    let marked = false;
    for (const b of document.querySelectorAll('[data-preset]')) {
        const match = !marked && presetRange(b.dataset.preset).join() === [from, to].join();
        b.classList.toggle('btn-primary', match);
        marked ||= match;
    }
}

function describeRange() {
    const [from, to] = [$('#from').value, $('#to').value];
    const long = {weekday: 'long', day: 'numeric', month: 'long'};
    if (from === to) return from === data.today ? `Hoy, ${formatDay(from, long).toLowerCase()}` : formatDay(from, {...long, year: 'numeric'});
    return `${formatDay(from, {day: 'numeric', month: 'short'})} – ${formatDay(to, {day: 'numeric', month: 'short', year: 'numeric'})}`;
}

// ---- Datos del periodo (admin_data) ----
let data = {today: isoDay(new Date()), users: [], punches: [], visits: [], incidents: []};

// Distancia en metros entre dos puntos (fórmula de haversine).
function meters(a, b) {
    const rad = (x) => x * Math.PI / 180;
    const h = Math.sin(rad(b.latitude - a.latitude) / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(rad(b.longitude - a.longitude) / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.sqrt(h));
}
const formatDistance = (m) => m == null ? '—' : m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`;

function accuracyInfo(value) {
    if (value <= 30) return ['Buena', 'bg-success', 'badge-success'];
    if (value <= 100) return ['Regular', 'bg-warning', 'badge-warning'];
    return ['Baja', 'bg-error', 'badge-error'];
}

// Visitas con su representante, duración y estado.
function buildVisits() {
    const users = new Map(data.users.map(u => [u.id, u]));
    const now = Date.now();
    return data.visits.filter(v => users.has(v.user_id)).map(v => {
        const minutes = ((v.ended_at ? new Date(v.ended_at) : now) - new Date(v.started_at)) / 60000;
        const status = v.ended_at ? ['Completa', 'badge-success'] : v.day === data.today ? ['En curso', 'badge-info'] : ['Sin cerrar', 'badge-error'];
        const accuracy = Math.max(v.start?.accuracy ?? 0, v.end?.accuracy ?? 0);
        return {...v, user: users.get(v.user_id), minutes, status, accuracy, distance: v.start && v.end ? meters(v.start, v.end) : null};
    }).sort((a, b) => b.started_at.localeCompare(a.started_at));
}

// Agrupa los registros por empleado y día, con horas trabajadas, estado y visitas (de `src`, por defecto el periodo).
function buildDays(src = data) {
    const users = new Map(src.users.map(u => [u.id, u]));
    const groups = new Map();
    for (const punch of src.punches) {
        const key = `${punch.user_id}|${punch.day}`;
        if (!groups.has(key)) groups.set(key, {user: users.get(punch.user_id), day: punch.day, punches: {}});
        groups.get(key).punches[punch.kind] = punch;
    }
    const now = Date.now();
    return [...groups.values()].filter(g => g.user).map(g => {
        const p = g.punches;
        const t = (kind) => p[kind] ? new Date(p[kind].recorded_at).getTime() : null;
        const isToday = g.day === src.today;
        const open = !p.salida && isToday;
        let worked = null;
        let lunch = null;
        const lunchEnd = t('regreso_comida') ?? (open ? now : null);
        if (p.salida_comida && lunchEnd != null) lunch = (lunchEnd - t('salida_comida')) / 60000;
        if (p.entrada && (p.salida || open)) worked = ((t('salida') ?? now) - t('entrada')) / 60000 - (lunch ?? 0);
        let status = ['Completa', 'badge-success'];
        if (!p.salida) status = !isToday ? ['Sin salida', 'badge-error'] : p.salida_comida && !p.regreso_comida ? ['En comida', 'badge-info'] : ['En curso', 'badge-warning'];
        const visits = src.visits.filter(v => v.user_id === g.user.id && v.day === g.day);
        if (!p.salida && isToday && visits.some(v => !v.ended_at)) status = ['En visita', 'badge-info'];
        const visitMinutes = visits.reduce((sum, v) => sum + ((v.ended_at ? new Date(v.ended_at) : now) - new Date(v.started_at)) / 60000, 0);
        const accuracy = Math.max(...Object.values(p).map(x => x.accuracy));
        const distance = p.entrada && p.salida ? meters(p.entrada, p.salida) : null;
        return {...g, worked, lunch, status, accuracy, distance, visits, visitMinutes, complete: !!p.salida};
    }).sort((a, b) => b.day.localeCompare(a.day) || a.user.name.localeCompare(b.user.name, 'es'));
}

// Días de vacaciones, permiso o incapacidad sin jornada registrada, como filas del reporte de asistencia.
function justifiedRows(match) {
    const users = new Map(data.users.map(u => [u.id, u]));
    const worked = new Set(data.punches.filter(p => p.kind === 'entrada').map(p => `${p.user_id}|${p.day}`));
    return data.incidents.flatMap(i => i.dates.map(day => ({i, day, user: users.get(i.user_id)})))
        .filter(({i, day, user}) => user && match(user, i.description ?? '') && !worked.has(`${user.id}|${day}`))
        .map(({i, day, user}) => ({user, day, punches: {}, status: [INCIDENT_TYPES[i.type].label], lunch: null, worked: null, visits: [], visitMinutes: 0}));
}

// Filtros de la página (búsqueda, empleado, área). La búsqueda también revisa el médico en las visitas.
function userFilter() {
    const term = $('#search').value.trim().toLowerCase();
    const employee = Number($('#employee').value) || null;
    const area = $('#area').value;
    return (user, extra = '') => (!employee || user.id === employee) && (!area || user.area === area)
        && (!term || user.name.toLowerCase().includes(term) || user.email.toLowerCase().includes(term) || extra.toLowerCase().includes(term));
}

// Llena los selectores de empleado y área conservando la selección.
function fillFilters(users, allLabel) {
    const selected = $('#employee').value;
    const all = el('option', '', allLabel); all.value = '';
    $('#employee').replaceChildren(all, ...users.map(u => { const o = el('option', '', u.deleted ? `${u.name} (eliminado)` : u.name); o.value = u.id; return o; }));
    $('#employee').value = users.some(u => String(u.id) === selected) ? selected : '';
    const selectedArea = $('#area').value;
    const areaList = [...new Set(data.users.map(u => u.area).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const allAreas = el('option', '', 'Todas las áreas'); allAreas.value = '';
    $('#area').replaceChildren(allAreas, ...areaList.map(a => { const o = el('option', '', a); o.value = a; return o; }));
    $('#area').value = areaList.includes(selectedArea) ? selectedArea : '';
}

function employeeCell(user) {
    return el('div', 'flex items-center gap-3 min-w-0', null,
        el('span', 'grid place-items-center size-9 shrink-0 rounded-full bg-secondary text-secondary-content text-xs font-semibold', initials(user.name)),
        el('div', 'min-w-0', null,
            el('div', 'font-medium truncate flex items-center gap-1.5', null, el('span', 'truncate', user.name), user.deleted ? el('span', 'badge badge-xs badge-ghost shrink-0', 'Eliminado') : ''),
            el('div', 'text-xs text-base-content/50 truncate', user.area ? `${user.area} · ${user.email}` : user.email)));
}

function mapsLink(point) {
    const link = el('a', 'link link-primary link-hover text-xs', 'Google Maps ↗');
    link.href = `https://www.google.com/maps?q=${encodeURIComponent(point.latitude + ',' + point.longitude)}`;
    link.target = '_blank'; link.rel = 'noopener noreferrer';
    link.addEventListener('click', (e) => e.stopPropagation());
    return link;
}

// Mapa base: OpenStreetMap exige que cada mosaico lleve el origen del sitio; la página usa no-referrer, así que se habilita solo aquí.
function createMap(id) {
    const map = L.map(id, {zoomControl: false}).setView([19.4326, -99.1332], 12);
    L.control.zoom({position: 'bottomright'}).addTo(map);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin',
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);
    return map;
}

// ---- Reporte en Excel: asistencia, visitas o ambas (una hoja cada una) ----
function attendanceSheet(rows) {
    const header = ['Empleado', 'Correo', 'Área', 'Fecha', 'Estado', ...KINDS.flatMap(k => [NAMES[k], `${NAMES[k]} latitud`, `${NAMES[k]} longitud`, `${NAMES[k]} precisión (m)`]),
        'Minutos comida', 'Horas trabajadas', 'Visitas', 'Minutos en visitas'];
    return [header, ...rows.map(r => [r.user.deleted ? `${r.user.name} (eliminado)` : r.user.name, r.user.email, r.user.area ?? '', r.day, r.status[0],
        ...KINDS.flatMap(k => { const p = r.punches[k]; return p ? [formatTime(p.recorded_at), p.latitude, p.longitude, Math.ceil(p.accuracy)] : ['', '', '', '']; }),
        r.lunch != null ? Math.round(r.lunch) : '', r.worked != null ? Number((r.worked / 60).toFixed(2)) : '', r.visits.length, r.visits.length ? Math.round(r.visitMinutes) : ''])];
}

function visitsSheet(rows) {
    const header = ['Representante', 'Correo', 'Área', 'Fecha', 'Médico', 'Inicio', 'Fin', 'Duración (min)', 'Estado',
        'Inicio latitud', 'Inicio longitud', 'Inicio precisión (m)', 'Fin latitud', 'Fin longitud', 'Fin precisión (m)', 'Distancia inicio-fin (m)', 'Comentarios'];
    return [header, ...rows.map(v => [v.user.deleted ? `${v.user.name} (eliminado)` : v.user.name, v.user.email, v.user.area ?? '', v.day, v.doctor,
        formatTime(v.started_at), v.ended_at ? formatTime(v.ended_at) : '', v.ended_at ? Math.round(v.minutes) : '', v.status[0],
        v.start.latitude, v.start.longitude, Math.ceil(v.start.accuracy),
        v.end ? v.end.latitude : '', v.end ? v.end.longitude : '', v.end ? Math.ceil(v.end.accuracy) : '', v.distance != null ? Math.round(v.distance) : '', v.comments ?? ''])];
}

// SheetJS (assets/vendor/xlsx) solo se descarga la primera vez que se pide un reporte.
let xlsxLoading = null;
function loadXlsx() {
    xlsxLoading ??= new Promise((resolve, reject) => {
        const script = el('script');
        script.src = 'assets/vendor/xlsx/xlsx.full.min.js';
        script.onload = () => resolve(window.XLSX);
        script.onerror = () => { xlsxLoading = null; reject(new Error('No se pudo cargar el generador de Excel.')); };
        document.head.append(script);
    });
    return xlsxLoading;
}

async function downloadReport(which, attendanceRows, visitRows) {
    const XLSX = await loadXlsx();
    const book = XLSX.utils.book_new();
    const add = (name, aoa) => {
        const sheet = XLSX.utils.aoa_to_sheet(aoa);
        sheet['!cols'] = aoa[0].map((h, i) => ({wch: Math.min(40, Math.max(h.length, ...aoa.slice(1).map(r => String(r[i] ?? '').length)) + 2)}));
        sheet['!autofilter'] = {ref: XLSX.utils.encode_range({s: {r: 0, c: 0}, e: {r: aoa.length - 1, c: aoa[0].length - 1}})};
        XLSX.utils.book_append_sheet(book, sheet, name);
    };
    if (which !== 'visits') add('Asistencia', attendanceSheet(attendanceRows));
    if (which !== 'attendance') add('Visitas', visitsSheet(visitRows));
    const name = {attendance: 'asistencia', visits: 'visitas', both: 'asistencia_y_visitas'}[which];
    XLSX.writeFile(book, `${name}_${$('#from').value}_a_${$('#to').value}.xlsx`);
}

// Menú "Descargar reporte": getRows() devuelve {attendance, visits} ya filtrados.
function setupReportMenu(getRows, onError) {
    document.querySelectorAll('[data-report]').forEach(item => item.addEventListener('click', async () => {
        $('#report-menu').removeAttribute('open');
        const button = $('#report-menu summary');
        button.classList.add('btn-disabled');
        try {
            const {attendance, visits} = getRows();
            await downloadReport(item.dataset.report, attendance, visits);
        } catch (error) {
            onError(error.message);
        } finally {
            button.classList.remove('btn-disabled');
        }
    }));
    // Cierra el menú al hacer clic fuera.
    document.addEventListener('click', (e) => { if (!$('#report-menu').contains(e.target)) $('#report-menu').removeAttribute('open'); });
}

function setupLogout(onError) {
    $('#logout')?.addEventListener('click', async () => {
        try { await api('logout'); location.href = './'; } catch (error) { onError(error.message); }
    });
}
