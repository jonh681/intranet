'use strict';
const $ = (selector) => document.querySelector(selector);
const token = $('meta[name="csrf-token"]').content;
async function api(action, values = {}) {
    const response = await fetch('api.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token}, body: JSON.stringify({action, ...values})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'No se pudo completar la solicitud.');
    return data;
}
function message(element, text, error = false) {
    element.textContent = text;
    element.classList.toggle('alert-error', error);
    element.classList.toggle('alert-success', !error);
    element.hidden = false;
}
const zone = 'America/Mexico_City';

// Aviso grande al centro después de cada registro: verde si se guardó, rojo con el motivo si no.
// Las confirmaciones se cierran solas a los 6 segundos; los errores esperan a que se cierren.
let resultTimer = null;
function notify(ok, title, text, time = '') {
    const modal = document.querySelector('#result-modal');
    if (!modal) return;
    clearTimeout(resultTimer);
    document.querySelector('#result-box').className = `modal-box max-w-sm p-0 overflow-hidden text-center border-t-8 ${ok ? 'border-success' : 'border-error'}`;
    document.querySelector('#result-icon').className = `grid place-items-center size-24 rounded-full ring-8 animate-pop-in ${ok ? 'bg-success text-success-content ring-success/15' : 'bg-error text-error-content ring-error/15'}`;
    document.querySelector('#result-ok-icon').hidden = !ok;
    document.querySelector('#result-error-icon').hidden = ok;
    document.querySelector('#result-title').textContent = title;
    document.querySelector('#result-title').classList.toggle('text-error', !ok);
    document.querySelector('#result-time').textContent = time;
    document.querySelector('#result-time').hidden = !time;
    document.querySelector('#result-text').textContent = text;
    document.querySelector('#result-close').className = `btn btn-lg w-full ${ok ? 'btn-success' : 'btn-error'}`;
    document.querySelector('#result-close').textContent = ok ? 'Entendido' : 'Cerrar';
    const bar = document.querySelector('#result-timer-bar');
    document.querySelector('#result-timer').hidden = !ok;
    bar.style.transitionDuration = '0s';
    bar.style.transform = 'scaleX(1)';
    if (!modal.open) modal.showModal();
    if (ok) {
        requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.transitionDuration = '6s'; bar.style.transform = 'scaleX(0)'; }));
        resultTimer = setTimeout(() => modal.close(), 6000);
    }
}
const formatTime = (value) => new Date(value).toLocaleTimeString('es-MX', {timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false});
if ($('#auth-form')) {
    $('#auth-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        $('#auth-submit').disabled = true;
        $('#auth-error').hidden = true;
        try {
            await api('login', Object.fromEntries(new FormData(event.target)));
            location.reload();
        } catch (error) { message($('#auth-error'), error.message, true); }
        finally { $('#auth-submit').disabled = false; }
    });
}
if (document.body.dataset.authenticated === '1') {
    let punches = [];
    let visits = [];
    let busy = false;
    const isRep = document.body.dataset.rep === '1';
    const openVisit = () => visits.find(v => !v.ended_at);
    let loaded = false;
    const buttons = [...document.querySelectorAll('[data-punch]')];
    const names = {entrada: 'Entrada', salida_comida: 'Salida a comida', regreso_comida: 'Regreso de comida', salida: 'Salida'};
    const find = (kind) => punches.find(p => p.kind === kind);
    // Mismas reglas que punchError() en bootstrap.php.
    function allowed(kind) {
        if (find(kind)) return false;
        // Con una visita abierta no se puede salir a comer ni terminar la jornada (igual que en api.php).
        if (openVisit() && (kind === 'salida_comida' || kind === 'salida')) return false;
        if (kind === 'entrada') return true;
        if (!find('entrada') || find('salida')) return false;
        if (kind === 'salida_comida') return !find('regreso_comida');
        if (kind === 'regreso_comida') return !!find('salida_comida');
        return !find('salida_comida') || !!find('regreso_comida');
    }
    function worked(now) {
        const start = find('entrada');
        if (!start) return '—';
        const end = find('salida');
        const lunchOut = find('salida_comida');
        const lunchIn = find('regreso_comida');
        let ms = (end ? new Date(end.recorded_at) : now) - new Date(start.recorded_at);
        if (lunchOut) ms -= (lunchIn ? new Date(lunchIn.recorded_at) : end ? new Date(end.recorded_at) : now) - new Date(lunchOut.recorded_at);
        const minutes = Math.max(0, Math.floor(ms / 60000));
        return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
    }
    function clock() {
        const now = new Date();
        $('#clock').textContent = now.toLocaleTimeString('es-MX', {timeZone: zone, hour12: false});
        $('#today-label').textContent = now.toLocaleDateString('es-MX', {timeZone: zone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}).replace(/^./, c => c.toUpperCase());
        $('#worked').textContent = worked(now);
        if (isRep) tickVisit(now);
    }
    clock();
    setInterval(clock, 1000);
    function render() {
        const step = !loaded ? 'loading' : find('salida') ? 'done' : find('regreso_comida') ? 'back' : find('salida_comida') ? 'lunch' : find('entrada') ? 'in' : 'none';
        // En celular solo se muestran las acciones del paso actual (ver data-show en index.php).
        $('#attendance').dataset.step = step;
        for (const button of buttons) {
            const kind = button.dataset.punch;
            const punch = find(kind);
            button.disabled = !loaded || busy || !allowed(kind);
            button.querySelector('.js-text').textContent = punch ? `${names[kind]} registrada ✓` : `Registrar ${names[kind].toLowerCase()}`;
            $(`[data-time="${kind}"]`).textContent = punch ? formatTime(punch.recorded_at) : '--:--';
        }
        $('#worked').textContent = worked(new Date());
        if (isRep) renderVisits(step);
        const [label, dot] = {
            loading: ['Consultando…', 'status-neutral'],
            none: ['Sin iniciar', 'status-warning'],
            in: ['Jornada en curso', 'status-success animate-pulse'],
            lunch: ['En comida', 'status-info animate-pulse'],
            back: ['Jornada en curso', 'status-success animate-pulse'],
            done: ['Jornada finalizada', 'status-neutral'],
        }[step];
        $('#day-status .status').className = `status ${dot}`;
        $('#day-status .js-text').textContent = label;
        $('#record-count').textContent = `${punches.length} ${punches.length === 1 ? 'registro' : 'registros'}`;
        const list = $('#records-list');
        list.replaceChildren();
        if (!punches.length) {
            const empty = document.createElement('li');
            empty.className = 'py-6 text-center text-base-content/50 text-sm';
            empty.textContent = 'Aún no hay registros hoy.';
            list.append(empty);
        }
        punches.forEach((punch, index) => {
            const last = index === punches.length - 1;
            const lunch = punch.kind.endsWith('comida');
            const row = document.createElement('li'); row.className = `relative pl-8 animate-pop-in ${last ? '' : 'pb-6'}`;
            const line = document.createElement('span'); line.className = `absolute left-[7px] top-5 bottom-0 w-0.5 bg-base-300 ${last ? 'hidden' : ''}`;
            const marker = document.createElement('span'); marker.className = `absolute left-0 top-1 size-4 rounded-full ring-4 ${lunch ? 'bg-accent ring-accent/20' : 'bg-primary ring-primary/15'}`;
            const head = document.createElement('div'); head.className = 'flex items-baseline justify-between gap-3';
            const kind = document.createElement('span'); kind.className = 'font-semibold'; kind.textContent = names[punch.kind];
            const time = document.createElement('span'); time.className = 'text-lg font-semibold tabular-nums'; time.textContent = formatTime(punch.recorded_at);
            head.append(kind, time);
            const brief = document.createElement('p'); brief.className = 'text-sm text-base-content/60 mt-0.5';
            brief.textContent = `Registraste tu ${names[punch.kind].toLowerCase()} a las ${formatTime(punch.recorded_at)}.`;
            row.append(line, marker, head, brief); list.append(row);
        });
    }
    async function refresh() {
        try { const data = await api('status'); punches = data.punches; visits = data.visits; loaded = true; render(); }
        catch (error) { loaded = false; render(); message($('#punch-message'), error.message, true); }
    }
    // Cuando la ubicación está bloqueada, el navegador no vuelve a preguntar: hay que indicar dónde activarla.
    function deniedHelp() {
        const ua = navigator.userAgent;
        const ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
        if (/FBAN|FBAV|Instagram|Line\/|TikTok|Snapchat/.test(ua)) return 'Este navegador interno no permite registrar. Abre la página en Safari o Chrome (menú ··· > Abrir en el navegador).';
        if (ios && /CriOS/.test(ua)) return 'Para registrar, permite la ubicación: Ajustes > Privacidad y seguridad > Localización (activada) y Ajustes > Apps > Chrome > Ubicación > "Mientras se usa la app". Luego recarga la página.';
        if (ios) return 'Para registrar, permite la ubicación: Ajustes > Privacidad y seguridad > Localización (activada) > Sitios web de Safari > "Mientras se usa la app". Luego en Ajustes > Apps > Safari > Ubicación elige "Preguntar" y recarga la página.';
        if (/Android/.test(ua)) return 'Para registrar, permite la ubicación: toca el candado junto a la dirección > Permisos > Ubicación > Permitir, y revisa que la ubicación del teléfono esté activada.';
        return 'Para registrar, permite la ubicación: haz clic en el candado junto a la dirección > Ubicación > Permitir, y recarga la página.';
    }
    function locate() {
        return new Promise((resolve, reject) => {
            if (!window.isSecureContext) return reject(new Error('Abre la página con https:// para poder registrar.'));
            if (!navigator.geolocation) return reject(new Error('Este navegador no permite registrar asistencia. Usa Safari o Chrome actualizados.'));
            navigator.geolocation.getCurrentPosition(resolve, error => reject(new Error({1: deniedHelp(), 2: 'No se pudo determinar tu ubicación. Activa la ubicación del dispositivo y vuelve a intentar.', 3: 'La ubicación tardó demasiado. Intenta nuevamente.'}[error.code] || 'No se pudo obtener tu ubicación.')), {enableHighAccuracy: true, timeout: 25000, maximumAge: 0});
        });
    }
    async function punch(kind) {
        if (busy) return;
        busy = true; render();
        $('#punch-message').hidden = true;
        $('#punch-progress').hidden = false;
        try {
            const position = await locate();
            const {latitude, longitude, accuracy} = position.coords;
            const data = await api('punch', {kind, latitude, longitude, accuracy});
            punches = data.punches;
            const saved = punches.find(p => p.kind === kind);
            const at = formatTime(saved ? saved.recorded_at : new Date());
            message($('#punch-message'), `${names[kind]} registrada a las ${at}.`);
            notify(true, `¡${names[kind]} registrada!`, {
                entrada: 'Tu entrada quedó guardada. ¡Que tengas una excelente jornada!',
                salida_comida: 'Buen provecho. No olvides registrar tu regreso de comida.',
                regreso_comida: 'Tu regreso de comida quedó guardado.',
                salida: `Jornada terminada: trabajaste ${worked(new Date())}. ¡Descansa!`,
            }[kind], at);
        } catch (error) {
            message($('#punch-message'), error.message, true);
            notify(false, `No se pudo registrar tu ${names[kind].toLowerCase()}`, error.message);
            await refresh();
        }
        finally { busy = false; $('#punch-progress').hidden = true; render(); }
    }
    // La salida termina la jornada: se pide confirmación antes de registrarla.
    function confirmExit() {
        const now = new Date();
        $('#exit-time').textContent = now.toLocaleTimeString('es-MX', {timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false});
        $('#exit-worked').textContent = worked(now);
        $('#exit-confirm').showModal();
        $('#exit-cancel').focus();
    }
    $('#exit-ok').addEventListener('click', () => { $('#exit-confirm').close(); punch('salida'); });
    for (const button of buttons) button.addEventListener('click', () => button.dataset.punch === 'salida' ? confirmExit() : punch(button.dataset.punch));

    // ---- Visitas (representantes médicos) ----
    let finished = null; // última visita cerrada desde la ventana, para mostrar su resumen
    let commentsFor = null; // visita a la que pertenecen los comentarios escritos en la ventana
    const pad = (n) => String(n).padStart(2, '0');
    const stopwatch = (ms) => { const s = Math.max(0, Math.floor(ms / 1000)); return `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s / 60) % 60)}:${pad(s % 60)}`; };
    const duration = (ms) => { const m = Math.max(0, Math.round(ms / 60000)); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${pad(m % 60)} min`; };
    const elapsed = (v, now = new Date()) => (v.ended_at ? new Date(v.ended_at) : now) - new Date(v.started_at);
    function tickVisit(now) {
        const open = openVisit();
        if (open) $('#visit-live-timer').textContent = stopwatch(elapsed(open, now));
        if (!$('#visit-modal').open) return;
        const shown = open || finished;
        $('#visit-timer').textContent = shown ? stopwatch(elapsed(shown, now)) : '00:00:00';
    }
    function renderVisits(step) {
        const open = openVisit();
        const canStart = loaded && !open && (step === 'in' || step === 'back');
        $('#visit-bar').hidden = !(open || canStart);
        $('#visit-open').hidden = !!open;
        $('#visit-live').hidden = !open;
        if (open) $('#visit-live-doctor').textContent = open.doctor;
        $('#visit-count').textContent = `${visits.length} ${visits.length === 1 ? 'visita' : 'visitas'}`;
        const list = $('#visits-list');
        list.replaceChildren(...visits.map(v => {
            const item = document.createElement('li');
            item.className = `rounded-field border px-3 py-2.5 ${v.ended_at ? 'border-base-300' : 'border-info/40 bg-info/5'}`;
            const head = document.createElement('div'); head.className = 'flex items-baseline justify-between gap-3';
            const doctor = document.createElement('span'); doctor.className = 'font-semibold truncate'; doctor.textContent = v.doctor;
            const total = document.createElement('span'); total.className = 'text-sm font-semibold tabular-nums shrink-0'; total.textContent = v.ended_at ? duration(elapsed(v)) : 'En curso';
            head.append(doctor, total);
            const times = document.createElement('p'); times.className = 'text-xs text-base-content/55 mt-0.5 tabular-nums';
            times.textContent = v.ended_at ? `${formatTime(v.started_at)} – ${formatTime(v.ended_at)}` : `Desde las ${formatTime(v.started_at)}`;
            item.append(head, times);
            return item;
        }));
        if (!visits.length) {
            const empty = document.createElement('li'); empty.className = 'py-4 text-center text-base-content/50 text-sm';
            empty.textContent = step === 'none' || step === 'loading' ? 'Registra tu entrada para iniciar visitas.' : 'Aún no registras visitas hoy.';
            list.append(empty);
        }
        renderVisitModal(canStart);
    }
    function renderVisitModal(canStart = !openVisit()) {
        const open = openVisit();
        const shown = open || finished;
        $('#visit-doctor').disabled = !!open || busy;
        $('#visit-comments').disabled = busy;
        if (open) $('#visit-doctor').value = open.doctor;
        $('#visit-start').disabled = busy || !!open || !canStart;
        $('#visit-end').disabled = busy || !open;
        $('#visit-state').textContent = open ? 'Visita en curso' : finished ? 'Visita finalizada' : 'Listo para iniciar';
        $('#visit-times').textContent = !shown ? '' : open ? `Inicio ${formatTime(open.started_at)}`
            : `${shown.doctor} · ${formatTime(shown.started_at)} – ${formatTime(shown.ended_at)} · ${duration(elapsed(shown))}`;
        $('#visit-start').textContent = finished && !open ? 'Nueva visita' : 'Inicio de visita';
        tickVisit(new Date());
    }
    async function visitAction(action) {
        if (busy) return;
        const doctor = $('#visit-doctor').value.trim();
        const comments = $('#visit-comments').value.trim();
        if (action === 'visit_start' && !doctor) {
            message($('#visit-error'), 'Escribe el nombre del médico.', true);
            $('#visit-doctor').focus();
            return;
        }
        busy = true; render();
        $('#visit-error').hidden = true;
        $('#visit-progress').hidden = false;
        try {
            const position = await locate();
            const {latitude, longitude, accuracy} = position.coords;
            const closing = openVisit();
            visits = (await api(action, {doctor, comments, latitude, longitude, accuracy})).visits;
            const now = new Date().toLocaleTimeString('es-MX', {timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false});
            if (action === 'visit_end') notify(true, '¡Visita finalizada!', `Tu visita con ${closing.doctor} quedó registrada.`, now);
            else notify(true, '¡Visita iniciada!', `Con ${doctor}. Al terminar, registra el fin de la visita.`, now);
            if (action === 'visit_end') {
                finished = visits.find(v => v.id === closing.id) || null;
                $('#visit-doctor').value = '';
                $('#visit-comments').value = '';
                commentsFor = null;
            } else {
                finished = null;
                commentsFor = openVisit()?.id ?? null;
            }
        } catch (error) {
            message($('#visit-error'), error.message, true);
            notify(false, action === 'visit_end' ? 'No se pudo finalizar la visita' : 'No se pudo iniciar la visita', error.message);
            await refresh();
        }
        finally { busy = false; $('#visit-progress').hidden = true; render(); }
    }
    if (isRep) {
        const openModal = () => {
            const open = openVisit();
            if (!open) { finished = null; $('#visit-doctor').value = ''; $('#visit-comments').value = ''; commentsFor = null; }
            // Comentarios de la visita abierta, sin pisar lo que se haya escrito antes de cerrar la ventana.
            else if (commentsFor !== open.id) { $('#visit-comments').value = open.comments || ''; commentsFor = open.id; }
            $('#visit-error').hidden = true;
            renderVisitModal();
            render();
            $('#visit-modal').showModal();
            if (!openVisit()) $('#visit-doctor').focus();
        };
        $('#visit-open').addEventListener('click', openModal);
        $('#visit-live-end').addEventListener('click', openModal);
        $('#visit-start').addEventListener('click', () => visitAction('visit_start'));
        // Finalizar la visita pide confirmación (muestra médico, hora y duración).
        $('#visit-end').addEventListener('click', () => {
            const open = openVisit();
            if (!open) return visitAction('visit_end');
            const now = new Date();
            $('#visit-end-doctor').textContent = open.doctor;
            $('#visit-end-time').textContent = now.toLocaleTimeString('es-MX', {timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false});
            $('#visit-end-duration').textContent = duration(elapsed(open, now));
            $('#visit-end-confirm').showModal();
            $('#visit-end-cancel').focus();
        });
        $('#visit-end-ok').addEventListener('click', () => { $('#visit-end-confirm').close(); visitAction('visit_end'); });
    }
    $('#logout')?.addEventListener('click', async () => {
        if (busy) return;
        try { await api('logout'); location.reload(); } catch (error) { message($('#punch-message'), error.message, true); }
    });
    refresh();
    // Cada minuto y solo con la pantalla visible; al volver a la app se actualiza enseguida (menos datos, asistencia v3).
    setInterval(() => { if (!busy && !document.hidden) refresh(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !busy) refresh(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !busy) refresh(); });
}
