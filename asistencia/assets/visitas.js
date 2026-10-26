'use strict';
// Panel de visitas médicas. Requiere admin-common.js.
let visits = [];
let loading = false;

// Representantes: marcados como tales o con visitas en el periodo (aunque ya no lo sean).
const isRepresentative = (user) => user.rep || data.visits.some(v => v.user_id === user.id);

function filteredVisits() {
    const match = userFilter();
    return visits.filter(v => match(v.user, v.doctor));
}

function renderKpis(rows) {
    const closed = rows.filter(v => v.ended_at);
    const total = rows.reduce((sum, v) => sum + v.minutes, 0);
    const doctors = new Set(rows.map(v => v.doctor.trim().toLowerCase()));
    const reps = new Set(rows.map(v => v.user.id));
    $('[data-kpi="visits"]').textContent = rows.length;
    $('#kpi-visits-note').textContent = `${reps.size} ${reps.size === 1 ? 'representante' : 'representantes'}`;
    $('[data-kpi="doctors"]').textContent = doctors.size;
    $('#kpi-doctors-note').textContent = rows.length ? `${(rows.length / Math.max(1, reps.size)).toFixed(1)} visitas por representante` : ' ';
    $('[data-kpi="time"]').textContent = formatMinutes(total);
    $('#kpi-time-note').textContent = rows.some(v => !v.ended_at) ? 'Incluye visitas en curso' : ' ';
    $('[data-kpi="average"]').textContent = closed.length ? formatMinutes(closed.reduce((s, v) => s + v.minutes, 0) / closed.length) : '—';
    $('#kpi-average-note').textContent = closed.length ? 'Solo visitas finalizadas' : ' ';
    const open = rows.filter(v => v.status[0] === 'En curso').length;
    $('#open-badge').hidden = !open;
    $('[data-kpi="open"]').textContent = open;
}

function timeCell(value) {
    return value ? el('span', 'tabular-nums font-medium', formatTime(value)) : el('span', 'text-base-content/30', '—');
}

function locationButton(v, className) {
    const [label, dot] = accuracyInfo(v.accuracy);
    const button = el('button', `btn btn-sm gap-2 ${className}`, null, el('span', `size-2 rounded-full ${dot}`), el('span', '', 'Ubicaciones'));
    button.type = 'button';
    button.title = `Precisión GPS ${label.toLowerCase()} (peor: ±${Math.ceil(v.accuracy)} m)`;
    button.addEventListener('click', () => openDetail(v));
    return button;
}

function renderTable(rows) {
    $('#visits-count').textContent = rows.length;
    $('#visits-empty').hidden = rows.length > 0 || loading;
    if (!rows.length && !loading) $('#visits-empty').textContent = 'No hay visitas en este periodo.';
    $('#visits-table').replaceChildren(...rows.map(v => el('tr', 'hover:bg-base-200/60', null,
        el('td', 'max-w-64', null, employeeCell(v.user)),
        el('td', 'whitespace-nowrap text-base-content/70', formatDay(v.day)),
        el('td', 'max-w-64', null, el('div', 'font-medium truncate', v.doctor), v.comments ? el('div', 'text-xs text-base-content/55 line-clamp-2', v.comments) : ''),
        el('td', '', null, timeCell(v.started_at)),
        el('td', '', null, timeCell(v.ended_at)),
        el('td', 'text-right tabular-nums font-semibold whitespace-nowrap', v.ended_at ? formatMinutes(v.minutes) : '—'),
        el('td', '', null, el('span', `badge badge-soft badge-sm whitespace-nowrap ${v.status[1]}`, v.status[0])),
        el('td', 'text-right', null, locationButton(v, 'btn-ghost border-base-300')))));
    $('#visits-cards').replaceChildren(...rows.map(v => el('li', 'px-4 py-4 flex flex-col gap-3', null,
        el('div', 'flex items-center justify-between gap-3', null, employeeCell(v.user), el('span', `badge badge-soft badge-sm shrink-0 ${v.status[1]}`, v.status[0])),
        el('div', 'flex items-baseline justify-between gap-3', null, el('span', 'font-semibold truncate', v.doctor), el('span', 'text-xs text-base-content/55 shrink-0', formatDay(v.day))),
        v.comments ? el('p', 'text-xs text-base-content/60 -mt-1', v.comments) : '',
        el('div', 'grid grid-cols-3 gap-2 text-xs', null,
            el('div', '', null, el('div', 'text-base-content/50', 'Inicio'), timeCell(v.started_at)),
            el('div', '', null, el('div', 'text-base-content/50', 'Fin'), timeCell(v.ended_at)),
            el('div', 'text-right', null, el('div', 'text-base-content/50', 'Duración'), el('span', 'font-semibold tabular-nums', v.ended_at ? formatMinutes(v.minutes) : '—'))),
        locationButton(v, 'btn-outline border-base-300 w-full'))));
}

// ---- Ventana de ubicaciones de una visita ----
let detailMap, detailLayer;
const points = {};
function focusPoint(key) {
    const target = points[key];
    if (!target) return;
    for (const [k, m] of Object.entries(points)) m.getElement()?.classList.toggle('pin-active', k === key);
    document.querySelectorAll('#detail-list [data-point]').forEach(b => b.classList.toggle('ring-2', b.dataset.point === key));
    detailMap.flyTo(target.getLatLng(), 18, {duration: .6});
    target.openTooltip();
}

function openDetail(v) {
    $('#detail-doctor').textContent = v.doctor;
    $('#detail-meta').textContent = `${v.user.name} · ${formatDay(v.day, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'})}`;
    const stat = (label, value) => el('span', 'badge badge-ghost gap-1.5 h-auto py-1', null, el('span', 'opacity-60', label), el('span', 'font-semibold tabular-nums', value));
    $('#detail-stats').replaceChildren(
        stat('Duración', v.ended_at ? formatMinutes(v.minutes) : 'En curso'),
        stat('Inicio ↔ fin', formatDistance(v.distance)),
        el('span', `badge badge-sm ${v.status[1]} self-center`, v.status[0]));
    const item = (key, label, time, p) => {
        if (!p) {
            return el('li', 'flex items-center gap-3 rounded-box border border-dashed border-base-300 p-3 text-base-content/40', null,
                el('span', 'grid place-items-center size-8 shrink-0 rounded-full bg-base-300 text-white text-sm font-bold', key === 'start' ? 'I' : 'F'),
                el('span', 'text-sm font-medium', label), el('span', 'ml-auto text-sm', 'Sin registrar'));
        }
        const [acc, , badge] = accuracyInfo(p.accuracy);
        const li = el('li', 'rounded-box border border-base-300 bg-base-100 p-3 cursor-pointer hover:border-warning/50 hover:bg-base-200/50 transition ring-warning/50', null,
            el('div', 'flex items-center gap-3', null,
                el('span', 'grid place-items-center size-8 shrink-0 rounded-full text-white text-sm font-bold pin-visita pin-bg', key === 'start' ? 'I' : 'F'),
                el('div', 'min-w-0 flex-1', null,
                    el('div', 'flex items-baseline justify-between gap-2', null, el('span', 'text-sm font-semibold', label), el('span', 'tabular-nums font-bold', formatTime(time))),
                    el('div', 'flex items-center justify-between gap-2 mt-0.5', null, el('span', `badge badge-soft badge-xs ${badge}`, `${acc} ±${Math.ceil(p.accuracy)} m`), mapsLink(p)))),
            el('div', 'mt-1 pl-11 text-xs text-base-content/50 tabular-nums', `${p.latitude.toFixed(6)}, ${p.longitude.toFixed(6)}`));
        li.dataset.point = key;
        li.tabIndex = 0;
        li.addEventListener('click', () => focusPoint(key));
        li.addEventListener('keydown', (e) => { if (e.key === 'Enter') focusPoint(key); });
        return li;
    };
    $('#detail-list').replaceChildren(
        v.comments ? el('li', 'rounded-box bg-base-200 p-3', null, el('div', 'text-xs font-medium text-base-content/50 mb-1', 'Comentarios'), el('p', 'text-sm whitespace-pre-line', v.comments)) : '',
        el('li', 'text-xs font-medium text-base-content/50 px-1 pb-1', 'Ubicaciones de la visita'),
        item('start', 'Inicio de visita', v.started_at, v.start),
        item('end', 'Fin de visita', v.ended_at, v.end));
    $('#detail').showModal();
    // Leaflet necesita medir el contenedor cuando ya es visible.
    requestAnimationFrame(() => {
        detailMap.invalidateSize();
        detailLayer.clearLayers();
        for (const k of Object.keys(points)) delete points[k];
        for (const [key, p, time, label] of [['start', v.start, v.started_at, 'Inicio'], ['end', v.end, v.ended_at, 'Fin']]) {
            if (!p) continue;
            const at = [p.latitude, p.longitude];
            L.circle(at, {radius: p.accuracy, color: VISIT_COLOR, weight: 1.5, opacity: .6, fillOpacity: .08, dashArray: '4 4'}).addTo(detailLayer);
            points[key] = L.marker(at, {
                icon: L.divIcon({className: 'pin-visita', html: `<div class="pin"><span>${key === 'start' ? 'I' : 'F'}</span></div>`, iconSize: [34, 34], iconAnchor: [17, 40]}),
                riseOnHover: true,
            }).bindTooltip(`${label} · ${formatTime(time)}`, {direction: 'top', offset: [0, -40], className: 'pin-tip'})
                .on('click', () => focusPoint(key)).addTo(detailLayer);
        }
        if (detailLayer.getLayers().length) detailMap.fitBounds(detailLayer.getBounds(), {padding: [60, 60], maxZoom: 17});
    });
}

// ---- Carga y eventos ----
function render() {
    const rows = filteredVisits();
    renderKpis(rows);
    renderTable(rows);
}

async function load() {
    const [from, to] = [$('#from').value, $('#to').value];
    if (!from || !to) return;
    markPresets(from, to);
    loading = true;
    $('#refresh svg').classList.add('animate-spin');
    $('#error').hidden = true;
    try {
        data = await api('admin_data', {from, to});
        fillFilters(data.users.filter(isRepresentative), 'Todos los representantes');
        visits = buildVisits();
    } catch (error) {
        showError(error.message);
    } finally {
        loading = false;
        $('#refresh svg').classList.remove('animate-spin');
        $('#range-title').textContent = describeRange();
        render();
    }
}

function setRange(from, to) {
    $('#from').value = from;
    $('#to').value = to;
    load();
}

const showError = (message) => { $('#error').textContent = message; $('#error').hidden = false; };
document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => setRange(...presetRange(b.dataset.preset))));
for (const input of [$('#from'), $('#to')]) input.addEventListener('change', () => {
    if ($('#from').value > $('#to').value) (input.id === 'from' ? $('#to') : $('#from')).value = input.value;
    load();
});
for (const id of ['#search', '#employee', '#area']) $(id).addEventListener(id === '#search' ? 'input' : 'change', render);
$('#refresh').addEventListener('click', load);
setupLogout(showError);
// Desde esta pestaña, la hoja de asistencia incluye solo a los representantes que coinciden con los filtros.
setupReportMenu(() => {
    const match = userFilter();
    return {
        attendance: buildDays().filter(d => isRepresentative(d.user) && match(d.user, d.visits.map(v => v.doctor).join(' '))),
        visits: filteredVisits(),
    };
}, showError);
detailMap = createMap('detail-map');
detailLayer = L.featureGroup().addTo(detailMap);
setRange(...presetRange('today'));
// Mantiene la lista al día mientras el periodo incluye hoy.
setInterval(() => { if (!document.hidden && !loading && $('#to').value >= data.today) load(); }, 60000);
