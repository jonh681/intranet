'use strict';
// "Mi equipo" del gerente: faltantes, asistencia y visitas de un día. Solo lectura. Requiere admin-common.js.
let day = isoDay(new Date());
let loading = false;

const timeCell = (value) => value ? el('span', 'tabular-nums font-medium', formatTime(value)) : el('span', 'text-base-content/30', '—');
const showError = (message) => { $('#error').textContent = message; $('#error').hidden = !message; };

function personCell(user) {
    return el('div', 'flex items-center gap-3 min-w-0', null,
        el('span', 'grid place-items-center size-9 shrink-0 rounded-full bg-secondary text-secondary-content text-xs font-semibold', initials(user.name)),
        el('div', 'min-w-0', null, el('div', 'font-medium truncate', user.name), el('div', 'text-xs text-base-content/50 truncate', user.area || user.email)));
}

// Quién del equipo no registró entrada ese día (o tiene vacaciones, permiso o incapacidad).
function renderAbsent(rows) {
    const entered = new Set(rows.filter(r => r.punches.entrada).map(r => r.user.id));
    const future = day > data.today;
    const people = data.users
        .filter(u => !u.deleted && (!u.created_on || u.created_on <= day) && !entered.has(u.id))
        .map(u => ({user: u, incident: incidentOn(u.id, day)}))
        .filter(p => !future || p.incident)
        .sort((a, b) => (a.incident ? 1 : 0) - (b.incident ? 1 : 0) || a.user.name.localeCompare(b.user.name, 'es'));
    const missing = people.filter(p => !p.incident).length;
    $('#absent-count').textContent = missing;
    $('#absent-count').className = `badge badge-sm ${missing ? 'badge-error' : 'badge-success badge-soft'}`;
    $('#absent-list').replaceChildren(...people.map(({user, incident}) => {
        const t = incident && INCIDENT_TYPES[incident.type];
        return el('li', `flex items-center justify-between gap-3 rounded-field border px-3 py-2.5 ${incident ? 'border-base-300 bg-base-200/40' : 'border-error/25 bg-error/5'}`, null,
            el('div', 'min-w-0', null, el('div', 'text-sm font-semibold truncate', user.name),
                el('div', 'text-xs text-base-content/55 truncate', incident?.description || user.area || user.email)),
            el('span', `badge badge-soft badge-sm shrink-0 ${incident ? t.badge : 'badge-error'}`, incident ? t.label : 'Sin registro'));
    }));
    if (!people.length) {
        $('#absent-list').append(el('li', 'text-sm text-success sm:col-span-2 lg:col-span-3', future ? 'Nadie de tu equipo tiene vacaciones, permiso o incapacidad este día.' : 'Todo tu equipo registró su entrada.'));
    }
}

function renderAttendance(rows) {
    $('#attendance-count').textContent = rows.length;
    $('#attendance-empty').hidden = rows.length > 0 || loading;
    $('#attendance-table').replaceChildren(...rows.map(r => el('tr', 'hover:bg-base-200/60', null,
        el('td', 'max-w-64', null, personCell(r.user)),
        el('td', '', null, timeCell(r.punches.entrada?.recorded_at)),
        el('td', '', null, timeCell(r.punches.salida_comida?.recorded_at)),
        el('td', '', null, timeCell(r.punches.regreso_comida?.recorded_at)),
        el('td', '', null, timeCell(r.punches.salida?.recorded_at)),
        el('td', 'text-right tabular-nums font-semibold whitespace-nowrap', formatMinutes(r.worked)),
        el('td', '', null, el('span', `badge badge-soft badge-sm whitespace-nowrap ${r.status[1]}`, r.status[0])))));
    $('#attendance-cards').replaceChildren(...rows.map(r => el('li', 'px-4 py-4 flex flex-col gap-3', null,
        el('div', 'flex items-center justify-between gap-3', null, personCell(r.user), el('span', `badge badge-soft badge-sm shrink-0 ${r.status[1]}`, r.status[0])),
        el('div', 'grid grid-cols-4 gap-2 text-xs', null,
            el('div', '', null, el('div', 'text-base-content/50', 'Entrada'), timeCell(r.punches.entrada?.recorded_at)),
            el('div', '', null, el('div', 'text-base-content/50', 'Comida'), el('span', 'font-medium', r.lunch != null ? `${Math.round(r.lunch)} min` : '—')),
            el('div', '', null, el('div', 'text-base-content/50', 'Salida'), timeCell(r.punches.salida?.recorded_at)),
            el('div', 'text-right', null, el('div', 'text-base-content/50', 'Horas'), el('span', 'font-semibold tabular-nums', formatMinutes(r.worked)))))));
}

function renderVisits() {
    const visits = buildVisits().filter(v => v.day === day);
    // Solo se muestra si el equipo tiene representantes médicos o hubo visitas.
    $('#visits-card').hidden = !visits.length && !data.users.some(u => u.rep && !u.deleted);
    $('#visits-count').textContent = visits.length;
    $('#visits-empty').hidden = visits.length > 0 || loading;
    const duration = (v) => v.ended_at ? formatMinutes(v.minutes) : el('span', 'badge badge-soft badge-info badge-sm', 'En curso');
    $('#visits-table').replaceChildren(...visits.map(v => el('tr', 'hover:bg-base-200/60', null,
        el('td', 'max-w-56', null, personCell(v.user)),
        el('td', 'font-medium', v.doctor),
        el('td', '', null, timeCell(v.started_at)),
        el('td', '', null, timeCell(v.ended_at)),
        el('td', 'text-right tabular-nums font-semibold whitespace-nowrap', null, duration(v)),
        el('td', 'text-sm text-base-content/70 max-w-72', v.comments || '—'))));
    $('#visits-cards').replaceChildren(...visits.map(v => el('li', 'px-4 py-4 flex flex-col gap-2', null,
        el('div', 'flex items-center justify-between gap-3', null, personCell(v.user), el('span', 'text-sm font-semibold tabular-nums shrink-0', null, duration(v))),
        el('div', 'text-sm', null, el('span', 'font-semibold', v.doctor), el('span', 'text-base-content/55', ` · ${formatTime(v.started_at)} – ${v.ended_at ? formatTime(v.ended_at) : '…'}`)),
        v.comments ? el('p', 'text-xs text-base-content/60', v.comments) : '')));
}

function render() {
    const rows = buildDays().filter(r => r.day === day);
    $('#day-title').textContent = day === data.today ? `Hoy, ${formatDay(day, {weekday: 'long', day: 'numeric', month: 'long'}).toLowerCase()}`
        : formatDay(day, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
    renderAbsent(rows);
    renderAttendance(rows);
    renderVisits();
}

async function load() {
    $('#day').value = day;
    loading = true;
    showError('');
    try {
        data = await api('admin_data', {from: day, to: day});
    } catch (error) {
        showError(error.message);
    } finally {
        loading = false;
        render();
    }
}

const goTo = (next) => { if (next) { day = next; load(); } };
$('#prev-day').addEventListener('click', () => goTo(addDays(day, -1)));
$('#next-day').addEventListener('click', () => goTo(addDays(day, 1)));
$('#today').addEventListener('click', () => goTo(isoDay(new Date())));
$('#day').addEventListener('change', () => goTo($('#day').value));
setupLogout(showError);
load();
// Se actualiza solo mientras se ve el día de hoy.
setInterval(() => { if (!document.hidden && !loading && day === data.today) load(); }, 60000);
