'use strict';
// Reporte de consumo de datos por persona (consumo.php), de asistencia v3. Requiere admin-common.js.
// Mide comun/consumo.js en toda la intranet; la tabla y las tarjetas se paginan de 10 en 10 (comun/acciones.js).
let report = {users: [], daily: [], from: '', to: ''};

const showError = (text) => { $('#error').textContent = text; $('#error').hidden = !text; };
function bytes(n) {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
    if (n < 1024 ** 3) return `${(n / 1024 / 1024).toFixed(n < 10 * 1024 * 1024 ? 2 : 1)} MB`;
    return `${(n / 1024 ** 3).toFixed(2)} GB`;
}
const shortDay = (d) => formatDay(d, {day: 'numeric', month: 'short'});

function renderKpis() {
    const used = report.users.filter(u => u.bytes > 0);
    const total = used.reduce((s, u) => s + u.bytes, 0);
    const personDays = used.reduce((s, u) => s + u.days, 0);
    const top = used[0];
    $('[data-kpi="total"]').textContent = bytes(total);
    $('[data-note="total"]').textContent = !report.from ? ' ' : report.from === report.to ? shortDay(report.from) : `${shortDay(report.from)} – ${shortDay(report.to)}`;
    $('[data-kpi="average"]').textContent = personDays ? bytes(Math.round(total / personDays)) : '—';
    $('[data-note="average"]').textContent = personDays ? 'Solo días en que la usaron' : ' ';
    $('[data-kpi="people"]').textContent = `${used.length} de ${report.users.length}`;
    $('[data-note="people"]').textContent = 'Usuarios activos';
    $('[data-kpi="top"]').textContent = top ? bytes(top.bytes) : '—';
    $('[data-note="top"]').textContent = top ? top.name : ' ';
}

// Barras por día (una sola serie: no lleva leyenda; el título la nombra). Detalle al pasar el cursor o tocar.
function renderDaily() {
    if (!report.from || !report.to) {
        // Sin periodo (falló la consulta): gráfica vacía.
        $('#daily-max').textContent = '';
        $('#daily-tip').hidden = true;
        $('#daily-bars').replaceChildren();
        $('#daily-axis').replaceChildren();
        return;
    }
    const values = new Map(report.daily.map(d => [d.day, d]));
    const days = [];
    for (let d = report.from; d <= report.to; d = addDays(d, 1)) days.push(values.get(d) || {day: d, bytes: 0, people: 0});
    const max = Math.max(1, ...days.map(d => d.bytes));
    $('#daily-max').textContent = `Máximo: ${bytes(max === 1 ? 0 : max)}`;
    const tip = $('#daily-tip');
    const show = (column, d) => {
        tip.textContent = `${formatDay(d.day, {weekday: 'short', day: 'numeric', month: 'short'})} · ${bytes(d.bytes)} · ${d.people} ${d.people === 1 ? 'persona' : 'personas'}`;
        tip.hidden = false;
        const box = $('#daily').getBoundingClientRect();
        const col = column.getBoundingClientRect();
        const left = Math.min(Math.max(0, col.left - box.left + col.width / 2 - tip.offsetWidth / 2), box.width - tip.offsetWidth);
        tip.style.left = `${left}px`;
        tip.style.top = '-8px';
    };
    $('#daily-bars').replaceChildren(...days.map(d => {
        const bar = el('div', `w-full rounded-t-[4px] ${d.bytes ? 'bg-accent' : ''}`);
        bar.style.height = d.bytes ? `${Math.max(2, d.bytes / max * 100)}%` : '0';
        const column = el('div', 'flex-1 h-full flex items-end cursor-default hover:bg-base-200/70 rounded-t-[4px]', null, bar);
        column.setAttribute('aria-label', `${d.day}: ${bytes(d.bytes)}`);
        column.addEventListener('pointerenter', () => show(column, d));
        column.addEventListener('pointerdown', () => show(column, d));
        column.addEventListener('pointerleave', () => { tip.hidden = true; });
        return column;
    }));
    const labels = days.length <= 8 ? days : [days[0], days[Math.floor(days.length / 2)], days.at(-1)];
    $('#daily-axis').replaceChildren(...labels.map(d => el('span', '', shortDay(d.day))));
}

function renderPeople() {
    const term = $('#search').value.trim().toLowerCase();
    const list = report.users.filter(u => !term || u.name.toLowerCase().includes(term) || (u.area || '').toLowerCase().includes(term));
    const max = Math.max(1, ...report.users.map(u => u.bytes));
    $('#empty').hidden = list.length > 0;
    $('#empty').textContent = report.users.length ? 'Nadie coincide con la búsqueda.' : 'Sin datos.';
    const meter = (u) => {
        const fill = el('div', 'h-full rounded-full bg-accent');
        fill.style.width = `${u.bytes ? Math.max(2, u.bytes / max * 100) : 0}%`;
        return el('div', 'flex flex-col gap-1', null,
            el('span', `font-semibold tabular-nums ${u.bytes ? '' : 'text-base-content/35'}`, u.bytes ? bytes(u.bytes) : 'Sin uso'),
            el('div', 'h-1.5 rounded-full bg-base-200 overflow-hidden', null, fill));
    };
    const perDay = (u) => u.days ? bytes(Math.round(u.bytes / u.days)) : '—';
    const cellular = (u) => u.cellular ? bytes(u.cellular) : '—';
    const role = (u) => [u.area, u.puesto, u.role].filter(Boolean).join(' · ') || u.email;
    $('#table').replaceChildren(...list.map(u => el('tr', 'hover:bg-base-200/60', null,
        el('td', 'max-w-64', null, employeeCell({...u, area: role(u)})),
        el('td', 'text-sm text-base-content/70 whitespace-nowrap', u.device || '—'),
        el('td', '', null, meter(u)),
        el('td', 'text-right tabular-nums', perDay(u)),
        el('td', 'text-right tabular-nums', String(u.loads)),
        el('td', 'text-right tabular-nums text-base-content/70', cellular(u)))));
    $('#cards').replaceChildren(...list.map(u => el('li', 'px-4 py-4 flex flex-col gap-3', null,
        employeeCell({...u, area: role(u)}),
        meter(u),
        el('div', 'grid grid-cols-3 gap-2 text-xs', null,
            el('div', '', null, el('div', 'text-base-content/50', 'Por día'), el('div', 'font-medium tabular-nums', perDay(u))),
            el('div', '', null, el('div', 'text-base-content/50', 'Aperturas'), el('div', 'font-medium tabular-nums', String(u.loads))),
            el('div', '', null, el('div', 'text-base-content/50', 'Dispositivo'), el('div', 'font-medium truncate', u.device || '—'))))));
}

function markRange() {
    const today = report.today || isoDay(new Date());
    document.querySelectorAll('[data-range]').forEach(b => {
        const r = b.dataset.range;
        const from = r === 'month' ? `${today.slice(0, 8)}01` : addDays(today, -Number(r));
        const on = $('#to').value === today && $('#from').value === from;
        b.classList.toggle('btn-primary', on);
    });
}

async function load() {
    showError('');
    try {
        report = await api('admin_usage', {from: $('#from').value, to: $('#to').value});
        // El servidor puede recortar el periodo a su fecha de hoy.
        $('#from').value = report.from;
        $('#to').value = report.to;
    } catch (error) {
        showError(error.message);
        // No dejar las cifras del periodo anterior bajo las fechas nuevas.
        report = {users: [], daily: [], from: '', to: '', today: report.today};
    }
    renderKpis();
    renderDaily();
    renderPeople();
    markRange();
}

function setRange(r) {
    // La fecha del servidor, si ya se conoce: el reloj del dispositivo puede ir adelantado.
    const today = report.today || isoDay(new Date());
    $('#to').value = today;
    $('#from').value = r === 'month' ? `${today.slice(0, 8)}01` : addDays(today, -Number(r));
    load();
}
document.querySelectorAll('[data-range]').forEach(b => b.addEventListener('click', () => setRange(b.dataset.range)));
['#from', '#to'].forEach(id => $(id).addEventListener('change', () => { if ($('#from').value && $('#to').value) load(); }));
$('#search').addEventListener('input', renderPeople);
setRange('6');
