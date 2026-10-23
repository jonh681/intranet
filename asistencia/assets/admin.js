'use strict';
// Panel de asistencia. Requiere admin-common.js.
// Funciones que pueden estar apagadas en este servidor (config.php > features).
const CALENDAR = document.body.dataset.calendar === '1';
const INCIDENTS = document.body.dataset.incidents === '1';
let days = [];
let loading = false;

function filteredDays() {
    const match = userFilter();
    return days.filter(d => match(d.user, d.visits.map(v => v.doctor).join(' ')));
}

// ---- Render ----
function renderKpis(rows) {
    const complete = rows.filter(r => r.complete);
    const totalMinutes = rows.reduce((sum, r) => sum + (r.worked ?? 0), 0);
    const averageBase = complete.filter(r => r.worked != null);
    const people = new Set(rows.map(r => r.user.id)).size;
    $('[data-kpi="days"]').textContent = rows.length;
    $('#kpi-days-note').textContent = `${people} ${people === 1 ? 'empleado' : 'empleados'}`;
    $('[data-kpi="complete"]').textContent = complete.length;
    $('#kpi-complete-note').textContent = rows.length ? `${Math.round(complete.length / rows.length * 100)}% del total` : ' ';
    $('[data-kpi="hours"]').textContent = formatMinutes(totalMinutes);
    $('#kpi-hours-note').textContent = rows.some(r => !r.complete && r.worked != null) ? 'Incluye jornadas en curso' : ' ';
    $('[data-kpi="average"]').textContent = averageBase.length ? formatMinutes(averageBase.reduce((s, r) => s + r.worked, 0) / averageBase.length) : '—';
    $('#kpi-average-note').textContent = averageBase.length ? 'Solo jornadas completas' : ' ';
}

function renderLive(rows) {
    const [from, to] = [$('#from').value, $('#to').value];
    const includesToday = from <= data.today && data.today <= to;
    $('#live').hidden = !includesToday;
    const single = from === to;
    if (includesToday) {
        const todayRows = days.filter(r => r.day === data.today);
        $('[data-live="working"]').textContent = todayRows.filter(r => r.status[0] === 'En curso').length;
        $('[data-live="lunch"]').textContent = todayRows.filter(r => r.status[0] === 'En comida').length;
        $('[data-live="done"]').textContent = todayRows.filter(r => r.complete).length;
        const present = new Set(todayRows.map(r => r.user.id));
        const onVacation = data.users.filter(u => !u.deleted && !present.has(u.id) && incidentOn(u.id, data.today)?.type === 'vacaciones');
        $('[data-live="absent"]').textContent = employees().filter(u => !present.has(u.id) && !incidentOn(u.id, data.today)).length;
        $('[data-live="vacation"]').textContent = onVacation.length;
        $('[data-live="vacation"]').closest('.badge').hidden = !onVacation.length;
    }
    renderAbsent(single ? from : null);
    renderAreas(single ? from : null);
}

const employees = () => data.users.filter(u => u.rol === 'empleado' && !u.deleted);
const areaOf = (u) => u.area || 'Sin área';

// Franja destacada con los empleados que no registraron entrada ese día (sin administradores).
// Sin faltantes ni justificados el resumen no se despliega.
$('#absent-summary').addEventListener('click', (e) => { if ($('#absent-toggle').hidden) e.preventDefault(); });

// Tarjeta de una persona en la franja de faltantes; al hacer clic abre sus incidencias.
function personButton(u, day, incident) {
    if (!INCIDENTS) {
        const item = el('li', 'flex items-center gap-3 rounded-field bg-base-100 border border-error/20 px-3 py-2.5', null,
            el('span', 'grid place-items-center size-9 shrink-0 rounded-full bg-error/10 text-error text-xs font-bold', initials(u.name)),
            el('div', 'min-w-0', null, el('div', 'text-sm font-semibold truncate', u.name), el('div', 'text-xs text-base-content/55 truncate', areaOf(u))));
        item.title = u.email;
        return item;
    }
    const tone = incident ? 'border-base-300 hover:border-primary/40' : 'border-error/20 hover:border-error/50';
    const button = el('button', `w-full text-left flex items-center gap-3 rounded-field bg-base-100 border px-3 py-2.5 transition hover:shadow-sm ${tone}`, null,
        el('span', `grid place-items-center size-9 shrink-0 rounded-full text-xs font-bold ${incident ? 'bg-secondary text-secondary-content' : 'bg-error/10 text-error'}`, initials(u.name)),
        el('div', 'min-w-0 flex-1', null, el('div', 'text-sm font-semibold truncate', u.name), el('div', 'text-xs text-base-content/55 truncate', areaOf(u))),
        incident ? el('span', `badge badge-soft badge-sm shrink-0 ${INCIDENT_TYPES[incident.type].badge}`, INCIDENT_TYPES[incident.type].label)
            : el('span', 'text-xs font-medium text-primary shrink-0', isManager ? 'Ver' : 'Justificar'));
    button.type = 'button';
    button.title = u.email;
    button.addEventListener('click', () => openIncidents(u, day));
    return el('li', '', null, button);
}

function renderAbsent(day) {
    $('#absent-card').hidden = !day;
    if (!day) return;
    const present = new Set(days.filter(r => r.day === day && r.punches.entrada).map(r => r.user.id));
    const missing = employees().filter(u => !present.has(u.id))
        .sort((a, b) => areaOf(a).localeCompare(areaOf(b), 'es') || a.name.localeCompare(b.name, 'es'));
    const others = data.users.filter(u => u.rol !== 'empleado' && !u.deleted && !present.has(u.id) && incidentOn(u.id, day)?.type === 'vacaciones');
    const justified = [...missing.filter(u => incidentOn(u.id, day)), ...others];
    const absent = missing.filter(u => !incidentOn(u.id, day));
    const ok = absent.length === 0;
    // Se conserva si está abierto o cerrado entre actualizaciones; sin personas que mostrar no se despliega.
    const empty = missing.length === 0;
    $('#absent-card').className = `group rounded-box border-2 animate-pop-in ${ok ? 'border-success/30 bg-success/5' : 'border-error/35 bg-error/5'}`;
    if (empty) $('#absent-card').open = false;
    $('#absent-summary').classList.toggle('cursor-pointer', !empty);
    $('#absent-summary').classList.toggle('cursor-default', empty);
    $('#absent-toggle').hidden = empty;
    $('#absent-toggle').classList.toggle('text-error', !ok);
    $('#absent-toggle').classList.toggle('text-success', ok);
    $('#absent-icon').className = `grid place-items-center size-11 shrink-0 rounded-full ${ok ? 'bg-success text-success-content' : 'bg-error text-error-content'}`;
    $('#absent-icon [data-icon="ok"]').hidden = !ok;
    $('#absent-icon [data-icon="missing"]').hidden = ok;
    $('#absent-summary').title = empty ? '' : 'Clic para ver u ocultar la lista';
    $('#absent-title').textContent = !ok ? `${absent.length} ${absent.length === 1 ? 'empleado sin' : 'empleados sin'} registrar entrada`
        : justified.length ? 'Sin faltas sin justificar' : 'Todos los empleados registraron su entrada';
    const when = day === data.today ? 'Hoy' : formatDay(day, {weekday: 'long', day: 'numeric', month: 'long'});
    const extra = justified.length ? ` · ${justified.length} ${justified.length === 1 ? 'justificado' : 'justificados'}` : '';
    $('#absent-subtitle').textContent = `${when} · ${present.size} de ${employees().length} empleados presentes${extra} · no incluye administradores`;
    $('#absent-hint').hidden = ok || !INCIDENTS;
    $('#absent-list').hidden = ok;
    $('#absent-list').replaceChildren(...absent.map(u => personButton(u, day, null)));
    $('#justified-block').hidden = !justified.length || !INCIDENTS;
    $('#justified-title').textContent = `JUSTIFICADOS (${justified.length})`;
    $('#justified-list').replaceChildren(...justified.map(u => personButton(u, day, incidentOn(u.id, day))));
}

// Indicador por área. Un día: empleados con entrada / total. Rango: jornadas y horas. Sin administradores.
function renderAreas(day) {
    const staff = employees();
    $('#areas-block').hidden = staff.length === 0;
    if (!staff.length) return;
    const groups = new Map();
    for (const u of staff) {
        if (!groups.has(areaOf(u))) groups.set(areaOf(u), {name: areaOf(u), total: 0, present: 0, justified: 0, days: 0, minutes: 0});
        groups.get(areaOf(u)).total++;
    }
    const isStaff = new Map(staff.map(u => [u.id, u]));
    for (const row of days) {
        const u = isStaff.get(row.user.id);
        if (!u) continue;
        const g = groups.get(areaOf(u));
        if (day ? row.day === day && row.punches.entrada : true) { g.present++; g.days++; g.minutes += row.worked ?? 0; }
    }
    if (day) {
        const present = new Set(days.filter(r => r.day === day && r.punches.entrada).map(r => r.user.id));
        for (const u of staff) if (!present.has(u.id) && incidentOn(u.id, day)) groups.get(areaOf(u)).justified++;
    }
    const list = [...groups.values()].sort((a, b) => a.name === 'Sin área' ? 1 : b.name === 'Sin área' ? -1 : a.name.localeCompare(b.name, 'es'));
    const maxMinutes = Math.max(1, ...list.map(g => g.minutes));
    $('#areas-caption').textContent = day ? 'Empleados con entrada registrada · clic para filtrar' : 'Jornadas y horas del periodo · clic para filtrar';
    const selected = $('#area').value;
    $('#areas-grid').replaceChildren(...list.map(g => {
        const pct = day ? Math.round(g.present / g.total * 100) : Math.round(g.minutes / maxMinutes * 100);
        const missing = g.total - g.present - g.justified;
        const tone = !day ? 'bg-accent' : missing === 0 ? 'bg-success' : g.present === 0 ? 'bg-error' : 'bg-warning';
        const bar = el('div', `h-full rounded-full ${tone} transition-all`);
        bar.style.width = `${pct}%`;
        const card = el('button', `text-left rounded-box border p-3 transition hover:bg-white/10 ${selected === g.name ? 'bg-white/15 border-white/40' : 'bg-white/[.06] border-white/10'}`, null,
            el('div', 'flex items-center justify-between gap-2', null,
                el('span', 'text-sm font-semibold truncate', g.name),
                day && missing > 0 ? el('span', 'badge badge-xs badge-error shrink-0', `${missing} ${missing === 1 ? 'falta' : 'faltan'}`) : el('span')),
            el('div', 'text-xl font-semibold tabular-nums mt-1', day ? `${g.present}/${g.total}` : formatMinutes(g.minutes)),
            el('div', 'text-[11px] text-neutral-content/55', day ? `${pct}% presentes${g.justified ? ` · ${g.justified} justif.` : ''}` : `${g.days} ${g.days === 1 ? 'jornada' : 'jornadas'} · ${g.total} ${g.total === 1 ? 'empleado' : 'empleados'}`),
            el('div', 'h-1.5 rounded-full bg-white/10 mt-2 overflow-hidden', null, bar));
        card.type = 'button';
        card.addEventListener('click', () => {
            // "Sin área" no es un valor del filtro; ese grupo solo se resalta.
            $('#area').value = selected === g.name || g.name === 'Sin área' ? '' : g.name;
            render();
        });
        return card;
    }));
}

function timeCell(punch) {
    return punch ? el('span', 'tabular-nums font-medium', formatTime(punch.recorded_at)) : el('span', 'text-base-content/30', '—');
}

function lunchText(row) {
    const out = row.punches.salida_comida;
    if (!out) return null;
    const back = row.punches.regreso_comida;
    return `${formatTime(out.recorded_at)} – ${back ? formatTime(back.recorded_at) : '…'}${row.lunch != null ? ` · ${Math.round(row.lunch)} min` : ''}`;
}

// Tabla de jornadas del periodo (solo cuando el calendario está apagado).
function renderTable(rows) {
    $('#days-count').textContent = rows.length;
    $('#days-empty').hidden = rows.length > 0 || loading;
    $('#days-empty').textContent = loading ? 'Cargando…' : 'No hay registros en este periodo.';
    const status = (row) => el('div', 'flex flex-col items-start gap-1', null,
        el('span', `badge badge-soft badge-sm whitespace-nowrap ${row.status[1]}`, row.status[0]),
        row.visits.length ? el('span', 'text-xs text-base-content/60 whitespace-nowrap', `${row.visits.length} ${row.visits.length === 1 ? 'visita' : 'visitas'} · ${formatMinutes(row.visitMinutes)}`) : '');
    $('#days-table').replaceChildren(...rows.map(row => el('tr', 'hover:bg-base-200/60', null,
        el('td', 'max-w-64', null, employeeCell(row.user)),
        el('td', 'whitespace-nowrap text-base-content/70', formatDay(row.day)),
        el('td', '', null, timeCell(row.punches.entrada)),
        el('td', 'whitespace-nowrap text-xs', lunchText(row) ?? '—'),
        el('td', '', null, timeCell(row.punches.salida)),
        el('td', 'text-right tabular-nums font-semibold whitespace-nowrap', formatMinutes(row.worked)),
        el('td', '', null, status(row)),
        el('td', 'text-right', null, locationButton(row, 'btn-ghost border-base-300')))));
    $('#days-cards').replaceChildren(...rows.map(row => {
        const lunch = lunchText(row);
        return el('li', 'px-4 py-4 flex flex-col gap-3', null,
            el('div', 'flex items-center justify-between gap-3', null, employeeCell(row.user), el('span', `badge badge-soft badge-sm shrink-0 ${row.status[1]}`, row.status[0])),
            el('div', 'grid grid-cols-3 gap-2 text-xs', null,
                el('div', '', null, el('div', 'text-base-content/50', 'Entrada'), timeCell(row.punches.entrada)),
                el('div', '', null, el('div', 'text-base-content/50', 'Salida'), timeCell(row.punches.salida)),
                el('div', 'text-right', null, el('div', 'text-base-content/50', 'Horas'), el('span', 'font-semibold tabular-nums', formatMinutes(row.worked)))),
            el('div', 'flex justify-between text-xs text-base-content/50', null, el('span', '', formatDay(row.day)),
                el('span', '', [lunch ? `Comida ${lunch}` : 'Sin comida registrada', row.visits.length ? `${row.visits.length} ${row.visits.length === 1 ? 'visita' : 'visitas'}` : ''].filter(Boolean).join(' · '))),
            locationButton(row, 'btn-outline border-base-300 w-full'));
    }));
}

function locationButton(row, className) {
    const count = KINDS.filter(k => row.punches[k]).length;
    const [label, dot] = accuracyInfo(row.accuracy);
    const button = el('button', `btn btn-sm gap-2 ${className}`, null,
        el('span', `size-2 rounded-full ${dot}`),
        el('span', '', 'Ubicaciones'),
        el('span', 'badge badge-xs badge-ghost', String(count)));
    button.type = 'button';
    button.title = `Precisión GPS ${label.toLowerCase()} (peor: ±${Math.ceil(row.accuracy)} m)`;
    button.addEventListener('click', () => openDetail(row));
    return button;
}

// ---- Ventana de ubicaciones ----
let detailMap, detailLayer;
const markers = {};
function initMap() {
    detailMap = createMap('detail-map');
    detailLayer = L.featureGroup().addTo(detailMap);
}
function fitAll() {
    if (detailLayer.getLayers().length) detailMap.fitBounds(detailLayer.getBounds(), {padding: [50, 50], maxZoom: 17});
}
function focus(key) {
    const target = markers[key];
    if (!target) return;
    for (const [k, m] of Object.entries(markers)) for (const marker of m.all) marker.getElement()?.classList.toggle('pin-active', k === key);
    document.querySelectorAll('#detail-list [data-kind]').forEach(b => b.classList.toggle('ring-2', b.dataset.kind === key));
    if (target.all.length > 1) detailMap.flyToBounds(L.latLngBounds(target.all.map(m => m.getLatLng())), {padding: [60, 60], maxZoom: 18, duration: .6});
    else detailMap.flyTo(target.marker.getLatLng(), 18, {duration: .6});
    target.marker.openTooltip();
}

// Tarjeta de una visita: médico, duración y puntos de inicio y fin.
function visitItem(v, n) {
    const minutes = ((v.ended_at ? new Date(v.ended_at) : new Date()) - new Date(v.started_at)) / 60000;
    const point = (label, time, p) => {
        if (!p) return el('div', 'flex items-center justify-between gap-2 text-xs text-base-content/50', null, el('span', '', label), el('span', 'badge badge-info badge-soft badge-xs', 'En curso'));
        const [acc, , badge] = accuracyInfo(p.accuracy);
        return el('div', 'flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5 text-xs', null,
            el('span', 'text-base-content/60', null, el('span', 'font-semibold text-base-content tabular-nums', `${label} ${formatTime(time)}`), ` · ${p.latitude.toFixed(5)}, ${p.longitude.toFixed(5)}`),
            el('span', 'flex items-center gap-2', null, el('span', `badge badge-soft badge-xs ${badge}`, `${acc} ±${Math.ceil(p.accuracy)} m`), mapsLink(p)));
    };
    const item = el('li', 'rounded-box border border-base-300 bg-base-100 p-3 cursor-pointer hover:border-warning/50 hover:bg-base-200/50 transition ring-warning/50', null,
        el('div', 'flex items-center gap-3', null,
            el('span', 'grid place-items-center size-8 shrink-0 rounded-full text-white text-xs font-bold pin-visita pin-bg', `V${n}`),
            el('div', 'min-w-0 flex-1 flex items-baseline justify-between gap-2', null,
                el('span', 'text-sm font-semibold truncate', v.doctor),
                el('span', 'tabular-nums font-bold shrink-0', v.ended_at ? formatMinutes(minutes) : 'En curso'))),
        el('div', 'mt-2 pl-11 flex flex-col gap-1', null, point('Inicio', v.started_at, v.start), point('Fin', v.ended_at, v.end)));
    item.dataset.kind = `v${v.id}`;
    item.tabIndex = 0;
    item.addEventListener('click', () => focus(`v${v.id}`));
    item.addEventListener('keydown', (e) => { if (e.key === 'Enter') focus(`v${v.id}`); });
    return item;
}

function openDetail(row) {
    $('#detail-initials').textContent = initials(row.user.name);
    $('#detail-name').textContent = row.user.name;
    $('#detail-meta').textContent = `${row.user.email} · ${formatDay(row.day, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'})}`;
    const stat = (label, value) => el('span', 'badge badge-ghost gap-1.5 h-auto py-1', null, el('span', 'opacity-60', label), el('span', 'font-semibold tabular-nums', value));
    $('#detail-stats').replaceChildren(
        stat('Trabajado', formatMinutes(row.worked)),
        stat('Comida', row.lunch != null ? `${Math.round(row.lunch)} min` : '—'),
        stat('Entrada ↔ salida', row.distance != null ? (row.distance >= 1000 ? `${(row.distance / 1000).toFixed(1)} km` : `${Math.round(row.distance)} m`) : '—'),
        el('span', `badge badge-sm ${row.status[1]} self-center`, row.status[0]));

    $('#detail-list').replaceChildren(...KINDS.map((kind, i) => {
        const punch = row.punches[kind];
        const number = el('span', `grid place-items-center size-8 shrink-0 rounded-full text-white text-sm font-bold ${punch ? `pin-${kind} pin-bg` : 'bg-base-300'}`, String(i + 1));
        if (!punch) {
            return el('li', 'flex items-center gap-3 rounded-box border border-dashed border-base-300 p-3 text-base-content/40', null,
                number, el('span', 'text-sm font-medium', NAMES[kind]), el('span', 'ml-auto text-sm', 'Sin registro'));
        }
        const [label, , badge] = accuracyInfo(punch.accuracy);
        const link = mapsLink(punch);
        const item = el('li', 'rounded-box border border-base-300 bg-base-100 p-3 cursor-pointer hover:border-primary/40 hover:bg-base-200/50 transition ring-primary/40', null,
            el('div', 'flex items-center gap-3', null,
                number,
                el('div', 'min-w-0 flex-1', null,
                    el('div', 'flex items-baseline justify-between gap-2', null, el('span', 'text-sm font-semibold', NAMES[kind]), el('span', 'tabular-nums font-bold', formatTime(punch.recorded_at))),
                    el('div', 'flex items-center justify-between gap-2 mt-0.5', null, el('span', `badge badge-soft badge-xs ${badge}`, `${label} ±${Math.ceil(punch.accuracy)} m`), link))),
            el('div', 'mt-1 pl-11 text-xs text-base-content/50 tabular-nums', `${punch.latitude.toFixed(6)}, ${punch.longitude.toFixed(6)}`));
        item.dataset.kind = kind;
        item.tabIndex = 0;
        item.addEventListener('click', () => focus(kind));
        item.addEventListener('keydown', (e) => { if (e.key === 'Enter') focus(kind); });
        return item;
    }));
    if (row.visits.length) {
        $('#detail-list').append(
            el('li', 'text-[11px] font-semibold tracking-widest text-base-content/50 px-1 pt-3', `VISITAS (${row.visits.length}) · ${formatMinutes(row.visitMinutes)}`),
            ...row.visits.map((v, i) => visitItem(v, i + 1)));
    }

    $('#detail').showModal();
    // Leaflet necesita medir el contenedor cuando ya es visible.
    requestAnimationFrame(() => {
        detailMap.invalidateSize();
        detailLayer.clearLayers();
        for (const k of Object.keys(markers)) delete markers[k];
        const pin = (at, cls, text, tip, key) => L.marker(at, {
            icon: L.divIcon({className: cls, html: `<div class="pin"><span>${text}</span></div>`, iconSize: [34, 34], iconAnchor: [17, 40]}),
            riseOnHover: true,
        }).bindTooltip(tip, {direction: 'top', offset: [0, -40], className: 'pin-tip'}).on('click', () => focus(key)).addTo(detailLayer);
        row.visits.forEach((v, i) => {
            const all = [];
            for (const [p, time, label] of [[v.start, v.started_at, 'inicio'], [v.end, v.ended_at, 'fin']]) {
                if (!p) continue;
                L.circle([p.latitude, p.longitude], {radius: p.accuracy, color: '#d9a032', weight: 1.5, opacity: .6, fillOpacity: .08, dashArray: '4 4'}).addTo(detailLayer);
                // El nombre del médico va como texto del tooltip, nunca como HTML.
                const tip = el('span', '', `Visita ${i + 1} (${label}) · ${v.doctor} · ${formatTime(time)}`);
                all.push(pin([p.latitude, p.longitude], 'pin-visita', `V${i + 1}`, tip, `v${v.id}`));
            }
            if (all.length) markers[`v${v.id}`] = {marker: all[0], all};
        });
        KINDS.forEach((kind, i) => {
            const punch = row.punches[kind];
            if (!punch) return;
            const at = [punch.latitude, punch.longitude];
            L.circle(at, {radius: punch.accuracy, color: COLORS[kind], weight: 1.5, opacity: .6, fillOpacity: .08, dashArray: '4 4'}).addTo(detailLayer);
            const marker = L.marker(at, {
                icon: L.divIcon({className: `pin-${kind}`, html: `<div class="pin"><span>${i + 1}</span></div>`, iconSize: [34, 34], iconAnchor: [17, 40]}),
                riseOnHover: true,
            }).bindTooltip(`${NAMES[kind]} · ${formatTime(punch.recorded_at)}`, {direction: 'top', offset: [0, -40], className: 'pin-tip'})
                .on('click', () => focus(kind)).addTo(detailLayer);
            markers[kind] = {marker, all: [marker]};
        });
        fitAll();
    });
}

// ---- Calendario: el mismo de vacaciones (vacation-picker.js); bloquea fines de semana, festivos y días ocupados ----
const picker = new VacationPicker({calendar: $('#calendar'), summary: $('#calendar-summary')});
let pickerContext = {busy: new Set(), holidays: {}};
const calendarDates = () => picker.ctx ? picker.workdays : [];

function resetPicker(day) {
    picker.month = day.slice(0, 7);
    picker.start = picker.end = null;
    // Se puede registrar hasta un año atrás o adelante.
    picker.set({start: addDays(data.today, -366), end: addDays(data.today, 366), available: null, maxDays: 120, today: data.today, ...pickerContext});
}

// ---- Ventana de incidencias ----
async function loadPickerContext() {
    const {busy, holidays} = await api('admin_user_incidents', {user_id: incidentUser.id});
    pickerContext = {busy: new Set(busy || []), holidays: holidays || {}};
    picker.set({...picker.ctx, ...pickerContext});
}

let incidentUser = null;
let incidentDay = null;

function renderIncidentList(incidents) {
    $('#incident-existing').hidden = !incidents.length;
    $('#incident-list').replaceChildren(...incidents.map(i => {
        const t = INCIDENT_TYPES[i.type];
        const range = i.dates.length === 1 ? formatDay(i.dates[0], {day: 'numeric', month: 'short', year: 'numeric'})
            : i.source === 'vacaciones' ? `${formatDay(i.dates[0], {day: 'numeric', month: 'short'})} – ${formatDay(i.dates.at(-1), {day: 'numeric', month: 'short', year: 'numeric'})} · ${i.dates.length} días hábiles`
            : i.type === 'permiso' ? i.dates.map(d => formatDay(d, {day: 'numeric', month: 'short'})).join(', ')
            : `${formatDay(i.dates[0], {day: 'numeric', month: 'short'})} – ${formatDay(i.dates.at(-1), {day: 'numeric', month: 'short', year: 'numeric'})}`;
        // Las vacaciones se administran en Incidencias (pestaña de la intranet); aquí solo se consultan.
        const fromVacations = i.source === 'vacaciones';
        const remove = isManager || fromVacations ? el('span', 'text-[11px] text-base-content/45', fromVacations ? 'Se administra en Incidencias' : '') : el('button', 'btn btn-ghost btn-xs text-error', 'Eliminar');
        remove.type = 'button';
        if (remove.tagName === 'BUTTON') remove.addEventListener('click', async () => {
            if (!(await window.intranetConfirmar({ title: `¿Eliminar este registro de ${t.label.toLowerCase()}?`, text: 'Los días volverán a contar como faltas.', ok: 'Sí, eliminar', cancel: 'No, conservarlo' }))) return;
            try {
                renderIncidentList((await api('admin_delete_incident', {id: i.id})).incidents);
                window.intranetAviso?.(`Se eliminó el registro de ${t.label.toLowerCase()}.`);
                await loadPickerContext();
                await load();
            } catch (error) { showIncidentError(error.message); }
        });
        const file = i.file ? el('a', 'link link-primary link-hover text-xs inline-flex items-center gap-1', `📎 ${i.file.name}`) : null;
        if (file) { file.href = `archivo.php?id=${i.id}`; file.rel = 'noopener'; }
        return el('li', 'rounded-box border border-base-300 p-3', null,
            el('div', 'flex items-center justify-between gap-2', null,
                el('div', 'flex items-center gap-2 min-w-0', null, el('span', `badge badge-soft badge-sm shrink-0 ${t.badge}`, t.label), el('span', 'text-sm font-medium truncate', range)),
                remove),
            i.description ? el('p', 'text-sm text-base-content/70 mt-1.5 whitespace-pre-line', i.description) : '',
            file ? el('div', 'mt-1.5', null, file) : '');
    }));
}

function showIncidentError(message) {
    $('#incident-error').textContent = message;
    $('#incident-error').hidden = !message;
}

function setIncidentType(type) {
    $('#incident-fields').hidden = false;
    $('#calendar-label').textContent = 'Toca el primer día y luego el último (o solo uno). Solo cuentan los días hábiles.';
    $('#description-field').hidden = type === 'vacaciones';
    $('#file-field').hidden = type === 'vacaciones';
    $('#file-label').textContent = type === 'incapacidad' ? 'Documentación (obligatoria)' : 'Evidencia (opcional)';
    $('#incident-description').placeholder = type === 'incapacidad' ? 'Diagnóstico, folio o institución que la emite' : 'Motivo del permiso';
    $('#incident-save').textContent = `Guardar ${INCIDENT_TYPES[type].label.toLowerCase()}`;
    showIncidentError('');
}

async function openIncidents(user, day) {
    incidentUser = user;
    incidentDay = day;
    $('#incident-form').reset();
    $('#incident-fields').hidden = true;
    showIncidentError('');
    pickerContext = {busy: new Set(), holidays: {}};
    resetPicker(day);
    $('#incident-initials').textContent = initials(user.name);
    $('#incident-name').textContent = user.name;
    $('#incident-meta').textContent = `${areaOf(user)} · sin registro el ${formatDay(day, {weekday: 'long', day: 'numeric', month: 'long'}).toLowerCase()}`;
    $('#incident-existing').hidden = true;
    // El gerente solo consulta: sin formulario para registrar.
    $('#incident-form').hidden = isManager;
    $('#incident-readonly').hidden = !isManager;
    $('#incident-modal').showModal();
    try {
        const {incidents, busy, holidays} = await api('admin_user_incidents', {user_id: user.id});
        renderIncidentList(incidents);
        pickerContext = {busy: new Set(busy || []), holidays: holidays || {}};
        resetPicker(day);
        // El día sin registro que se abrió queda elegido si se puede.
        if (!picker.ctx.busy.has(day) && !VacationPicker.weekend(day) && !picker.ctx.holidays[day] && day >= picker.ctx.start) picker.choose(day);
        if (isManager) $('#incident-readonly').textContent = incidents.length ? 'Solo lectura: las incidencias las registra un administrador.' : 'No tiene vacaciones, permisos ni incapacidades registradas. Las incidencias las registra un administrador.';
    } catch (error) { showIncidentError(error.message); }
}

document.querySelectorAll('#incident-form [name="type"]').forEach(r => r.addEventListener('change', () => setIncidentType(r.value)));
$('#calendar-clear').addEventListener('click', () => picker.clear());
$('#incident-cancel').addEventListener('click', () => $('#incident-modal').close());
$('#incident-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const type = $('#incident-form [name="type"]:checked')?.value;
    const dates = calendarDates();
    const description = $('#incident-description').value.trim();
    const file = $('#incident-file').files[0];
    const problem = !type ? 'Elige el tipo de incidencia.'
        : !dates.length || picker.problem ? picker.problem || 'Selecciona los días en el calendario.'
        : type !== 'vacaciones' && !description ? 'Escribe una descripción.'
        : type === 'incapacidad' && !file ? 'Adjunta la documentación de la incapacidad.'
        : file && file.size > 10 * 1024 * 1024 ? 'El archivo supera los 10 MB.' : '';
    if (problem) return showIncidentError(problem);
    const form = new FormData();
    form.set('user_id', incidentUser.id);
    form.set('type', type);
    form.set('dates', JSON.stringify(dates));
    if (type !== 'vacaciones') {
        form.set('description', description);
        if (file) form.set('file', file);
    }
    $('#incident-save').disabled = true;
    try {
        await apiForm('admin_save_incident', form);
        $('#incident-modal').close();
        const what = {vacaciones: 'Se registraron vacaciones', permiso: 'Se registró un permiso', incapacidad: 'Se registró una incapacidad'}[type];
        $('#notice').textContent = `${what} para ${incidentUser.name} (${dates.length} ${dates.length === 1 ? 'día' : 'días'}).`;
        $('#notice').hidden = false;
        window.intranetAviso?.($('#notice').textContent); // aviso flotante de la intranet (comun/acciones.js)
        setTimeout(() => { $('#notice').hidden = true; }, 6000);
        await load();
    } catch (error) {
        showIncidentError(error.message);
    } finally {
        $('#incident-save').disabled = false;
    }
});

// ---- Vista mensual de jornadas ----
const MONTH_KINDS = [
    ['done', 'completas', 'completa'], ['open', 'en curso', 'en curso'], ['late', 'sin salida', 'sin salida'], ['absent', 'faltas', 'falta'],
    ['vacation', 'de vacaciones', 'de vacaciones'], ['justified', 'justificados', 'justificado'],
];
const kindLabel = (k, n) => { const [, many, one] = MONTH_KINDS.find(([key]) => key === k); return n === 1 ? one : many; };
let month = isoDay(new Date()).slice(0, 7);
let monthData = null;
let monthLoading = false;

// Semanas completas (lunes a domingo) que cubren el mes, para mostrar también los días vecinos.
function monthGrid() {
    const first = `${month}-01`;
    const start = addDays(first, -((new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7));
    return Array.from({length: 42}, (_, i) => addDays(start, i));
}

async function loadMonth() {
    if (!CALENDAR || monthLoading) return;
    const grid = monthGrid();
    monthLoading = true;
    $('#month-loading').hidden = false;
    renderMonth();
    const requested = month;
    let failed = false;
    try {
        monthData = await api('admin_data', {from: grid[0], to: grid.at(-1)});
        monthData.forMonth = requested;
    } catch (error) {
        failed = true;
        showError(error.message);
    } finally {
        monthLoading = false;
        $('#month-loading').hidden = true;
        renderMonth();
        renderDayModal();
        // Si se cambió de mes mientras cargaba, se carga el mes pedido.
        if (!failed && month !== requested) loadMonth();
    }
}

// Conteos de un día con los filtros de la pantalla. Las faltas solo cuentan si alguien registró entrada ese día
// (así los fines de semana y días festivos no aparecen llenos de faltas) y si la persona ya existía.
function dayCounts(day, source, match) {
    const users = new Map(source.users.map(u => [u.id, u]));
    const rows = new Map();
    for (const punch of source.punches) {
        if (punch.day !== day) continue;
        const u = users.get(punch.user_id);
        if (!u || !match(u)) continue;
        (rows.get(u.id) ?? rows.set(u.id, {}).get(u.id))[punch.kind] = true;
    }
    const counts = {done: 0, open: 0, late: 0, absent: 0, vacation: 0, justified: 0};
    for (const r of rows.values()) {
        if (!r.entrada) continue;
        if (r.salida) counts.done++;
        else day === source.today ? counts.open++ : counts.late++;
    }
    const people = dayPeople(day, source, match, rows);
    counts.absent = people.absent.length;
    counts.justified = people.justified.length;
    counts.vacation = people.vacation.length;
    return counts;
}

// Empleados sin entrada ese día. Justificados: siempre (vacaciones futuras incluidas).
// Faltas: solo si el día ya llegó y alguien registró entrada (así fines de semana y festivos no cuentan).
function dayPeople(day, source, match, rows = null) {
    const entered = rows ? new Set([...rows].filter(([, r]) => r.entrada).map(([id]) => id))
        : new Set(source.punches.filter(x => x.day === day && x.kind === 'entrada').map(x => x.user_id));
    const workday = day <= source.today && source.punches.some(x => x.day === day && x.kind === 'entrada');
    const absent = [];
    const justified = [];
    const vacation = [];
    for (const u of source.users) {
        if (u.deleted || (u.created_on && u.created_on > day) || !match(u) || entered.has(u.id)) continue;
        const incident = source.incidents.find(i => i.user_id === u.id && i.dates.includes(day));
        // Las vacaciones se muestran para todos (también gerentes y administradores); las faltas solo cuentan empleados.
        if (incident?.type === 'vacaciones') vacation.push({user: u, incident});
        else if (u.rol !== 'empleado') continue;
        else if (incident) justified.push({user: u, incident});
        else if (workday) absent.push(u);
    }
    const byName = (a, b) => (a.user ?? a).name.localeCompare((b.user ?? b).name, 'es');
    return {absent: absent.sort(byName), justified: justified.sort(byName), vacation: vacation.sort(byName)};
}

function renderMonth() {
    const title = new Date(`${month}-15T12:00:00Z`).toLocaleDateString('es-MX', {timeZone: 'UTC', month: 'long', year: 'numeric'}).replace(' de ', ' ');
    $('#month-title').textContent = title.charAt(0).toUpperCase() + title.slice(1);
    const ready = monthData && monthData.forMonth === month;
    const match = userFilter();
    const icons = $('#month-icons').content;
    const selected = $('#from').value === $('#to').value ? $('#from').value : null;
    $('#month-grid').replaceChildren(...monthGrid().map(day => {
        const inMonth = day.startsWith(month);
        const isToday = day === data.today;
        const counts = ready ? dayCounts(day, monthData, (u) => match(u)) : null;
        const chips = counts ? MONTH_KINDS.filter(([k]) => counts[k]).map(([k]) => {
            const chip = el('span', 'inline-flex items-center gap-0.5 text-[11px] sm:text-xs font-semibold tabular-nums', null, icons.querySelector(`[data-k="${k}"]`).cloneNode(true), String(counts[k]));
            chip.classList.add(icons.querySelector(`[data-k="${k}"]`).className);
            return chip;
        }) : [];
        const cell = el('button', `group/day relative flex flex-col items-start text-left rounded-field border p-1.5 sm:p-2 min-h-14 sm:min-h-24 transition
            ${inMonth ? 'bg-base-100 border-base-300 hover:border-primary/50 hover:shadow-sm' : 'bg-base-200/60 border-transparent text-base-content/35 hover:border-base-300'}
            ${day === selected ? 'border-primary! bg-primary/5!' : ''} ${isToday ? 'ring-2 ring-primary/60' : ''}`, null,
            el('span', `text-xs sm:text-sm tabular-nums ${isToday ? 'font-bold text-primary' : 'font-semibold'}`, String(Number(day.slice(8)))),
            el('span', 'flex flex-wrap gap-x-1.5 sm:gap-x-2.5 gap-y-0.5 mt-1', null, ...chips));
        cell.type = 'button';
        cell.title = counts && chips.length
            ? `${formatDay(day, {weekday: 'long', day: 'numeric', month: 'long'})}: ` + MONTH_KINDS.filter(([k]) => counts[k]).map(([k]) => `${counts[k]} ${kindLabel(k, counts[k])}`).join(', ') + '. Clic para ver los registros.'
            : `${formatDay(day, {weekday: 'long', day: 'numeric', month: 'long'})}. Clic para ver los registros.`;
        cell.addEventListener('click', () => openDay(day));
        return cell;
    }));
}

// ---- Ventana con los registros de un día del calendario ----
let dayOpen = null;

let dayTab = 'all';
const DAY_TABS = [['all', 'Todos'], ['absent', 'Faltas'], ['justified', 'Justificados'], ['present', 'Presentes']];

// Periodo legible de una incidencia (días seguidos como rango, días sueltos como lista).
function incidentPeriod(dates, source = null) {
    const short = (d) => formatDay(d, {day: 'numeric', month: 'short'});
    if (dates.length === 1) return short(dates[0]);
    // Vacaciones: solo días hábiles, así que se muestran como rango aunque salten fines de semana.
    if (source === 'vacaciones') return `${short(dates[0])} – ${short(dates.at(-1))} · ${dates.length} días hábiles`;
    const consecutive = dates.every((d, i) => i === 0 || addDays(dates[i - 1], 1) === d);
    return consecutive ? `${short(dates[0])} – ${short(dates.at(-1))} · ${dates.length} días` : `${dates.length} días: ${dates.map(short).join(', ')}`;
}

// Personas del día en un solo formato: presentes (con su jornada), faltas y justificados (con su incidencia).
function dayEntries(day) {
    const match = userFilter();
    const present = buildDays(monthData).filter(r => r.day === day && match(r.user, r.visits.map(v => v.doctor).join(' ')))
        .map(row => ({kind: 'present', user: row.user, row}));
    const {absent, justified, vacation} = dayPeople(day, monthData, (u) => match(u));
    return [
        ...vacation.map(({user, incident}) => ({kind: 'justified', user, incident})),
        ...absent.map(user => ({kind: 'absent', user})),
        ...justified.map(({user, incident}) => ({kind: 'justified', user, incident})),
        ...present,
    ];
}

function justifyButton(entry, day, className = '') {
    const justify = entry.kind === 'absent' && !isManager;
    const b = el('button', `btn btn-sm ${justify ? 'btn-primary' : 'btn-ghost border-base-300'} ${className}`, justify ? 'Justificar' : 'Ver');
    b.type = 'button';
    b.addEventListener('click', () => openIncidents(entry.user, day));
    return b;
}

// Justificación (tipo, periodo, descripción y archivo) en lugar de los horarios.
function justificationCell(incident) {
    const t = INCIDENT_TYPES[incident.type];
    const file = incident.file ? el('a', 'link link-primary link-hover inline-flex items-center gap-1 shrink-0', `📎 ${incident.file.name}`) : null;
    if (file) { file.href = `archivo.php?id=${incident.id}`; file.rel = 'noopener'; file.addEventListener('click', (e) => e.stopPropagation()); }
    return el('div', 'flex flex-col gap-1 min-w-0', null,
        el('div', 'flex flex-wrap items-center gap-2', null,
            el('span', `badge badge-soft badge-sm ${t.badge}`, t.label),
            el('span', 'text-xs font-medium text-base-content/70 tabular-nums', incidentPeriod(incident.dates, incident.source))),
        incident.description || file ? el('div', 'flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-base-content/60', null,
            incident.description ? el('span', 'line-clamp-2', incident.description) : '', file || '') : '');
}

function renderDayModal() {
    const day = dayOpen;
    if (!day || !monthData) return;
    const entries = dayEntries(day);
    const totals = {all: entries.length, absent: 0, justified: 0, present: 0};
    for (const e of entries) totals[e.kind]++;
    if (!totals[dayTab]) dayTab = 'all';
    const counts = dayCounts(day, monthData, userFilter());
    const isFuture = day > monthData.today;

    $('#day-kicker').textContent = day === monthData.today ? 'HOY' : isFuture ? 'DÍA FUTURO' : 'DETALLE DEL DÍA';
    $('#day-title').textContent = formatDay(day, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
    const icons = $('#month-icons').content;
    // En celular solo icono y número (la leyenda del calendario explica los iconos).
    $('#day-stats').replaceChildren(...MONTH_KINDS.map(([k]) => {
        const card = el('div', `rounded-box border border-white/10 px-2 sm:px-3 py-2 ${counts[k] ? 'bg-white/[.08]' : 'bg-white/[.03] opacity-55'}`, null,
            el('div', 'flex items-center gap-1.5 text-[11px] text-neutral-content/70', null, icons.querySelector(`[data-k="${k}"]`).cloneNode(true), el('span', 'hidden sm:inline', kindLabel(k, counts[k]))),
            el('div', 'text-lg sm:text-xl font-semibold tabular-nums leading-tight mt-0.5', String(counts[k])));
        card.title = `${counts[k]} ${kindLabel(k, counts[k])}`;
        return card;
    }));

    $('#day-tabs').replaceChildren(...DAY_TABS.map(([key, label]) => {
        const tab = el('button', `btn btn-sm join-item gap-1 sm:gap-1.5 flex-1 sm:flex-none px-2 sm:px-3 ${dayTab === key ? 'btn-primary' : ''}`, null, label,
            el('span', `badge badge-xs ${dayTab === key ? 'bg-white/20 border-0 text-primary-content' : 'badge-ghost'}`, String(totals[key])));
        tab.type = 'button';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', String(dayTab === key));
        tab.disabled = key !== 'all' && !totals[key];
        tab.addEventListener('click', () => { dayTab = key; renderDayModal(); });
        return tab;
    }));
    $('#day-subtitle').textContent = isFuture ? 'Solo se muestran incidencias programadas.' : `${totals.present} ${totals.present === 1 ? 'jornada registrada' : 'jornadas registradas'}`;

    const shown = entries.filter(e => dayTab === 'all' || e.kind === dayTab);
    $('#day-empty').hidden = shown.length > 0;
    $('#day-empty').textContent = isFuture ? 'Nadie tiene vacaciones, permiso o incapacidad este día.' : 'Nadie registró entrada este día.';
    const dash = () => el('span', 'text-base-content/30', '—');

    $('#day-table').replaceChildren(...shown.map(e => {
        const tone = e.kind === 'absent' ? 'bg-error/[.04]' : e.kind === 'justified' ? 'bg-base-200/40' : '';
        const name = el('td', 'max-w-64', null, employeeCell(e.user));
        if (e.kind === 'present') {
            const r = e.row;
            return el('tr', `hover:bg-base-200/60 ${tone}`, null, name,
                el('td', '', null, timeCell(r.punches.entrada)),
                el('td', 'whitespace-nowrap text-xs', lunchText(r) ?? '—'),
                el('td', '', null, timeCell(r.punches.salida)),
                el('td', 'text-right tabular-nums font-semibold whitespace-nowrap', formatMinutes(r.worked)),
                el('td', '', null, el('div', 'flex flex-col items-start gap-1', null,
                    el('span', `badge badge-soft badge-sm whitespace-nowrap ${r.status[1]}`, r.status[0]),
                    r.visits.length ? el('span', 'text-xs text-base-content/60 whitespace-nowrap', `${r.visits.length} ${r.visits.length === 1 ? 'visita' : 'visitas'}`) : '')),
                el('td', 'text-right', null, locationButton(r, 'btn-ghost border-base-300')));
        }
        if (e.kind === 'justified') {
            const cell = el('td', '', null, justificationCell(e.incident));
            cell.colSpan = 4;
            return el('tr', `hover:bg-base-200/60 ${tone}`, null, name, cell,
                el('td', '', null, el('span', 'badge badge-soft badge-sm badge-info whitespace-nowrap', 'Justificado')),
                el('td', 'text-right', null, justifyButton(e, day)));
        }
        return el('tr', `hover:bg-base-200/60 ${tone}`, null, name,
            el('td', '', null, dash()), el('td', '', null, dash()), el('td', '', null, dash()), el('td', 'text-right', null, dash()),
            el('td', '', null, el('span', 'badge badge-soft badge-sm badge-error whitespace-nowrap', 'Falta')),
            el('td', 'text-right', null, justifyButton(e, day)));
    }));

    $('#day-cards').replaceChildren(...shown.map(e => {
        const head = el('div', 'flex items-center justify-between gap-3', null, employeeCell(e.user),
            e.kind === 'present' ? el('span', `badge badge-soft badge-sm shrink-0 ${e.row.status[1]}`, e.row.status[0])
                : el('span', `badge badge-soft badge-sm shrink-0 ${e.kind === 'absent' ? 'badge-error' : 'badge-info'}`, e.kind === 'absent' ? 'Falta' : 'Justificado'));
        if (e.kind === 'present') {
            const r = e.row;
            return el('li', 'px-4 py-4 flex flex-col gap-3', null, head,
                el('div', 'grid grid-cols-4 gap-2 text-xs', null,
                    el('div', '', null, el('div', 'text-base-content/50', 'Entrada'), timeCell(r.punches.entrada)),
                    el('div', '', null, el('div', 'text-base-content/50', 'Comida'), el('span', 'font-medium', r.lunch != null ? `${Math.round(r.lunch)} min` : '—')),
                    el('div', '', null, el('div', 'text-base-content/50', 'Salida'), timeCell(r.punches.salida)),
                    el('div', 'text-right', null, el('div', 'text-base-content/50', 'Horas'), el('span', 'font-semibold tabular-nums', formatMinutes(r.worked)))),
                locationButton(r, 'btn-outline border-base-300 w-full'));
        }
        return el('li', `px-4 py-4 flex flex-col gap-3 ${e.kind === 'absent' ? 'bg-error/[.04]' : 'bg-base-200/40'}`, null, head,
            e.kind === 'justified' ? justificationCell(e.incident) : '',
            justifyButton(e, day, 'w-full'));
    }));
}

function openDay(day) {
    dayOpen = day;
    dayTab = 'all';
    renderDayModal();
    $('#day-modal').showModal();
}

$('#day-modal').addEventListener('close', () => { dayOpen = null; });
$('#day-open-panel').addEventListener('click', () => {
    const day = dayOpen;
    $('#day-modal').close();
    setRange(day, day);
    $('main').scrollIntoView({behavior: 'smooth', block: 'start'});
});

const shiftMonth = (n) => { month = addDays(`${month}-15`, n * 30).slice(0, 7); loadMonth(); };
if (CALENDAR) {
    $('#month-prev').addEventListener('click', () => shiftMonth(-1));
    $('#month-next').addEventListener('click', () => shiftMonth(1));
    $('#month-today').addEventListener('click', () => { month = isoDay(new Date()).slice(0, 7); loadMonth(); });
}

// ---- Carga y eventos ----
function render() {
    const rows = filteredDays();
    renderKpis(rows);
    renderLive(rows);
    CALENDAR ? renderMonth() : renderTable(rows);
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
        fillFilters(data.users, 'Todos los empleados');
        days = buildDays();
    } catch (error) {
        $('#error').textContent = error.message;
        $('#error').hidden = false;
    } finally {
        loading = false;
        $('#refresh svg').classList.remove('animate-spin');
        $('#range-title').textContent = describeRange();
        render();
        loadMonth();
    }
}

function setRange(from, to) {
    month = from.slice(0, 7);
    $('#from').value = from;
    $('#to').value = to;
    load();
}

document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => setRange(...presetRange(b.dataset.preset))));
for (const input of [$('#from'), $('#to')]) input.addEventListener('change', () => {
    if ($('#from').value > $('#to').value) (input.id === 'from' ? $('#to') : $('#from')).value = input.value;
    load();
});
$('#search').addEventListener('input', render);
$('#employee').addEventListener('change', render);
$('#area').addEventListener('change', render);
$('#refresh').addEventListener('click', load);
const showError = (message) => { $('#error').textContent = message; $('#error').hidden = false; };
setupLogout(showError);
// El reporte usa los mismos filtros de la pantalla; las visitas son las de esas mismas personas.
setupReportMenu(() => {
    const match = userFilter();
    return {attendance: [...filteredDays(), ...justifiedRows(match)].sort((a, b) => b.day.localeCompare(a.day) || a.user.name.localeCompare(b.user.name, 'es')),
        visits: buildVisits().filter(v => match(v.user, v.doctor))};
}, showError);
$('#detail-fit').addEventListener('click', () => {
    for (const m of Object.values(markers)) for (const marker of m.all) marker.getElement()?.classList.remove('pin-active');
    document.querySelectorAll('#detail-list [data-kind]').forEach(b => b.classList.remove('ring-2'));
    fitAll();
});
initMap();
setRange(...presetRange('today'));
// Mantiene el panel al día mientras el periodo incluye hoy.
setInterval(() => { if (!document.hidden && !loading && $('#to').value >= data.today) load(); }, 60000);
