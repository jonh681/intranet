'use strict';
// Calendario para elegir un rango de días (primer y último día): vacaciones, permisos e incapacidades.
// Lo usan Mis vacaciones (empleado), Vacaciones y Asistencia (administrador).
// Solo deja elegir dentro de la ventana [start, end]; cuenta días hábiles (lunes a viernes sin festivos);
// bloquea fines de semana, festivos y días ocupados, y marca como problema pasar del saldo (si hay saldo).
class VacationPicker {
    constructor({calendar, summary, onChange = () => {}}) {
        Object.assign(this, {calendarEl: calendar, summaryEl: summary, onChange});
        this.month = '';
        this.start = null;
        this.end = null;
        this.ctx = null;
    }

    // ctx: {start, end (ventana), available y entitled (saldo; null = sin saldo), maxDays (opcional),
    //       busy: Set de fechas ocupadas, holidays: {fecha: nombre}, today}
    set(ctx) {
        this.ctx = ctx;
        if (!this.month || this.month < ctx.start.slice(0, 7) || this.month > ctx.end.slice(0, 7)) {
            this.month = (ctx.today >= ctx.start ? ctx.today : ctx.start).slice(0, 7);
        }
        this.render();
    }

    clear() {
        this.start = null;
        this.end = null;
        if (this.ctx) this.render();
    }

    get range() {
        if (!this.start) return [];
        const out = [];
        for (let d = this.start; d <= (this.end || this.start); d = VacationPicker.addDays(d, 1)) out.push(d);
        return out;
    }

    get workdays() { return this.range.filter(d => !VacationPicker.weekend(d) && !this.ctx.holidays[d]); }

    get problem() {
        const range = this.range;
        if (!range.length) return '';
        const workdays = this.workdays;
        const clash = workdays.filter(d => this.ctx.busy.has(d));
        if (!workdays.length) return 'Ese rango no tiene días hábiles.';
        if (clash.length) return `Ya hay vacaciones o incidencia el ${clash.map(VacationPicker.short).join(', ')}.`;
        if (this.ctx.available != null && workdays.length > this.ctx.available) return `Solo hay ${VacationPicker.plural(this.ctx.available, 'día disponible', 'días disponibles')}.`;
        if (this.ctx.maxDays && workdays.length > this.ctx.maxDays) return `Máximo ${this.ctx.maxDays} días hábiles por solicitud.`;
        return '';
    }

    get ready() { return this.range.length > 0 && !this.problem; }

    // Primer clic = inicio; segundo = fin (si es anterior, se invierten); el tercero empieza de nuevo.
    choose(d) {
        if (!this.start || this.end) { this.start = d; this.end = null; }
        else [this.start, this.end] = d < this.start ? [d, this.start] : [this.start, d];
        this.render();
    }

    render() {
        const {start, end, busy, holidays, today} = this.ctx;
        const [y, m] = this.month.split('-').map(Number);
        const first = `${this.month}-01`;
        const offset = (new Date(`${first}T12:00:00Z`).getUTCDay() + 6) % 7;
        const total = new Date(Date.UTC(y, m, 0)).getUTCDate();
        const range = new Set(this.range);
        const el = VacationPicker.el;
        const nav = (dir, text, title) => {
            const target = VacationPicker.addDays(`${this.month}-15`, dir * 30).slice(0, 7);
            const b = el('button', 'btn btn-ghost btn-sm btn-square', text);
            b.type = 'button';
            b.title = title;
            b.disabled = dir < 0 ? target < start.slice(0, 7) : target > end.slice(0, 7);
            b.addEventListener('click', () => { this.month = target; this.render(); });
            return b;
        };
        const cell = (d) => {
            const inPeriod = d >= start && d <= end;
            const holiday = holidays[d];
            const edge = d === this.start || d === (this.end || this.start);
            const off = VacationPicker.weekend(d) || holiday;
            const tone = !inPeriod ? 'bg-base-200/60 text-base-content/25 cursor-not-allowed'
                : edge ? 'bg-primary text-primary-content font-bold'
                : range.has(d) ? (off ? 'bg-primary/10 text-base-content/40' : 'bg-primary/20 text-primary font-semibold')
                : busy.has(d) ? 'bg-info/15 text-info ring-1 ring-info/40 cursor-not-allowed'
                : holiday ? 'bg-warning/20 ring-1 ring-warning/50 cursor-not-allowed'
                : off ? 'text-base-content/30 cursor-not-allowed' : 'hover:bg-base-200';
            const b = el('button', `h-9 rounded-field text-sm tabular-nums transition ${tone} ${d === today ? 'underline underline-offset-4' : ''}`, String(Number(d.slice(8))));
            b.type = 'button';
            // No se puede empezar ni terminar en un día bloqueado; un rango sí puede cruzar fines de semana y festivos.
            b.disabled = !inPeriod || off || busy.has(d);
            b.title = holiday ? `Festivo: ${holiday}` : busy.has(d) ? 'Ya tiene vacaciones, permiso o incapacidad' : !inPeriod ? 'Fuera del periodo permitido' : off ? 'Fin de semana' : '';
            b.addEventListener('click', () => this.choose(d));
            return b;
        };
        const label = new Date(`${first}T12:00:00Z`).toLocaleDateString('es-MX', {timeZone: 'UTC', month: 'long', year: 'numeric'});
        const cells = [...Array(offset)].map(() => el('span'));
        for (let d = 1; d <= total; d++) cells.push(cell(`${this.month}-${String(d).padStart(2, '0')}`));
        this.calendarEl.replaceChildren(
            el('div', 'flex items-center justify-between mb-2', null, nav(-1, '‹', 'Mes anterior'), el('span', 'font-semibold', label.charAt(0).toUpperCase() + label.slice(1)), nav(1, '›', 'Mes siguiente')),
            el('div', 'grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-base-content/45 mb-1', null, ...['L', 'M', 'M', 'J', 'V', 'S', 'D'].map(w => el('span', '', w))),
            el('div', 'grid grid-cols-7 gap-1', null, ...cells));
        this.renderSummary();
        this.onChange(this);
    }

    renderSummary() {
        const range = this.range;
        const workdays = this.workdays;
        const problem = this.problem;
        const fmt = (d) => VacationPicker.short(d, true);
        let text = this.ctx.available != null ? 'Elige el primer día de vacaciones.' : 'Elige el primer día.';
        if (range.length) {
            const skipped = range.filter(d => this.ctx.holidays[d] && !VacationPicker.weekend(d)).map(d => this.ctx.holidays[d]);
            text = `${range.length === 1 ? fmt(range[0]) : `Del ${fmt(range[0])} al ${fmt(range.at(-1))}`} · ${VacationPicker.plural(workdays.length, 'día hábil', 'días hábiles')}`;
            if (skipped.length) text += ` (no cuenta ${skipped.join(', ')})`;
            if (!this.end) text += ' · elige el último día o deja solo este';
            if (problem) text += ` · ${problem}`;
            else if (this.ctx.available != null) text += ` · quedarán ${this.ctx.available - workdays.length} de ${this.ctx.entitled}`;
        }
        this.summaryEl.textContent = text;
        this.summaryEl.className = `text-sm font-medium min-h-5 ${problem ? 'text-error' : range.length ? 'text-primary' : 'text-base-content/55'}`;
    }

    static el(tag, className = '', text = null, ...children) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== null) node.textContent = text;
        node.append(...children);
        return node;
    }
    static addDays(day, n) { const d = new Date(`${day}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
    static weekend(day) { return [0, 6].includes(new Date(`${day}T12:00:00Z`).getUTCDay()); }
    static plural(n, one, many) { return `${n} ${n === 1 ? one : many}`; }
    static short(day, weekday = false) {
        const text = new Date(`${day}T12:00:00Z`).toLocaleDateString('es-MX', {timeZone: 'UTC', ...(weekday ? {weekday: 'short'} : {}), day: 'numeric', month: 'short'}).replace(/ de /g, ' ');
        return text.charAt(0).toUpperCase() + text.slice(1);
    }
}
