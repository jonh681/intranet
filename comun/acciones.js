'use strict';
// Retroalimentación común de toda la intranet (se carga con el encabezado, comun/barra.php):
//   1. Ventanas como las de asistencia: window.intranetAviso(texto, 'ok' | 'error', título) al terminar una acción y
//      window.intranetConfirmar({...}) antes de una importante. Los mensajes que manda el servidor después de guardar
//      (tickets: .alert-success / .alert-error) también se muestran en la ventana.
//   2. Nada se hace dos veces: un formulario que se está enviando no se puede volver a enviar, y el botón que
//      dispara un guardado por fetch (asistencia, incidencias) queda bloqueado hasta que el servidor responde.
//   3. Paginación de 10 en 10 de toda tabla o lista con data-paginar.
(() => {
    // ---------- 1. Avisos (las mismas ventanas que asistencia: resultado y confirmación) ----------
    const svg = (paths) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
    const ICON = {
        ok: '<path d="M20 6 9 17l-5-5"/>',
        error: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        warning: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
    };
    const make = (html) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'ia-modal';
        dialog.innerHTML = html;
        // Clic fuera de la ventana = cerrar (como el fondo de las ventanas de asistencia).
        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close('cancel'); });
        document.body.append(dialog);
        return dialog;
    };

    // Resultado de una acción: verde si se guardó (se cierra sola a los 6 s), rojo con el motivo si no (espera a que la cierren).
    let result = null;
    let resultTimer = null;
    window.intranetAviso = (text, type = 'ok', title = '') => {
        if (!text) return;
        const ok = type !== 'error';
        result ??= make(`<div class="ia-box"><div class="ia-body"><span class="ia-circle"></span><h3></h3><p></p></div>
            <div class="ia-actions"><button type="button" class="ia-btn" data-close></button><div class="ia-timer"><div></div></div></div></div>`);
        result.querySelector('[data-close]').onclick = () => result.close();
        clearTimeout(resultTimer);
        result.querySelector('.ia-box').className = `ia-box ${ok ? 'ia-ok' : 'ia-error'}`;
        result.querySelector('.ia-circle').innerHTML = svg(ok ? ICON.ok : ICON.error);
        result.querySelector('h3').textContent = title || (ok ? '¡Listo!' : 'No se pudo completar');
        result.querySelector('p').textContent = text;
        const button = result.querySelector('.ia-btn');
        button.textContent = ok ? 'Entendido' : 'Cerrar';
        const timer = result.querySelector('.ia-timer');
        const bar = timer.firstElementChild;
        timer.hidden = !ok;
        bar.style.transition = 'none';
        bar.style.transform = 'scaleX(1)';
        if (!result.open) result.showModal();
        button.focus();
        if (ok) {
            requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.transition = 'transform 6s linear'; bar.style.transform = 'scaleX(0)'; }));
            resultTimer = setTimeout(() => { if (result.open) result.close(); }, 6000);
        }
    };

    // Confirmación antes de una acción importante. Devuelve una promesa: true (sí) o false (no).
    // window.intranetConfirmar({title, text, ok: 'Sí, …', cancel: 'No, …'})
    let ask = null;
    window.intranetConfirmar = ({ title = '¿Continuar?', text = '', ok = 'Sí, continuar', cancel = 'No, regresar' } = {}) => new Promise((resolve) => {
        ask ??= make(`<div class="ia-box ia-warning"><div class="ia-body"><span class="ia-circle ia-pulse">${svg(ICON.warning)}</span><h3></h3><p></p></div>
            <div class="ia-actions ia-two"><button type="button" class="ia-btn ia-ghost" data-no></button><button type="button" class="ia-btn ia-danger" data-yes></button></div></div>`);
        ask.querySelector('h3').textContent = title;
        ask.querySelector('p').textContent = text;
        ask.querySelector('p').hidden = !text;
        ask.querySelector('[data-yes]').textContent = ok;
        ask.querySelector('[data-no]').textContent = cancel;
        ask.querySelector('[data-yes]').onclick = () => ask.close('yes');
        ask.querySelector('[data-no]').onclick = () => ask.close('cancel');
        ask.onclose = () => resolve(ask.returnValue === 'yes');
        ask.returnValue = '';
        ask.showModal();
        ask.querySelector('[data-no]').focus();
    });
    const serverMessages = () => {
        // Mensajes que el servidor muestra al volver de guardar (estilo de tickets; los de asistencia usan .alert de daisyUI).
        for (const box of document.querySelectorAll('.alert-success:not(.alert), .alert-error:not(.alert)')) {
            if (box.hidden || box.closest('dialog, template, [hidden]') || !box.textContent.trim()) continue;
            window.intranetAviso(box.textContent.trim(), box.classList.contains('alert-error') ? 'error' : 'ok');
            break;
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', serverMessages); else serverMessages();

    // ---------- 2. Una sola vez ----------
    const busy = (button) => {
        if (!button || button.hasAttribute('aria-busy')) return;
        button.dataset.iaWasDisabled = button.disabled ? '1' : '';
        button.setAttribute('aria-busy', 'true');
        button.classList.add('ia-busy');
        button.disabled = true;
    };
    const idle = (button) => {
        if (!button || !button.hasAttribute('aria-busy')) return;
        button.removeAttribute('aria-busy');
        button.classList.remove('ia-busy');
        if (!button.dataset.iaWasDisabled) button.disabled = false;
    };

    // Formularios que se envían al servidor (no los que maneja JavaScript, que cancelan el envío normal).
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.dataset.iaSending) { event.preventDefault(); return; }
        form.dataset.iaSending = '1';
        const submitter = event.submitter;
        // Se bloquean un instante después: si no, el valor del botón presionado no viajaría con el formulario.
        setTimeout(() => {
            for (const button of form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')) {
                if (button === submitter) busy(button); else button.disabled = true;
            }
        }, 0);
    });
    // Al volver con «Atrás», los formularios se pueden usar otra vez.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        for (const form of document.querySelectorAll('form[data-ia-sending]')) {
            delete form.dataset.iaSending;
            for (const button of form.querySelectorAll('button, input[type="submit"]')) { idle(button); button.disabled = false; }
        }
    });

    // Guardados por fetch: el último botón presionado queda bloqueado hasta que llega la respuesta.
    let lastButton = null;
    let lastAt = 0;
    document.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (button && !button.closest('form[method="dialog"]')) { lastButton = button; lastAt = Date.now(); }
    }, true);
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        const method = String(init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const button = method !== 'GET' && lastButton && Date.now() - lastAt < 1500 && lastButton.isConnected && !lastButton.disabled ? lastButton : null;
        lastButton = null;
        if (button) busy(button);
        const request = fetchOriginal(input, init);
        if (button) request.then(() => idle(button), () => idle(button));
        return request;
    };

    // ---------- 3. Paginación: 10 registros por página en toda la intranet ----------
    // Cualquier <tbody> o lista con data-paginar (o data-paginar="20" para otro tamaño). Cuenta solo las filas que dejan
    // los filtros (los filtros ocultan con el atributo hidden) y vuelve a la página 1 cuando cambian las filas o los
    // filtros, así sirve igual para tablas que arma PHP y para las que arma JavaScript. Una fila con data-sin-paginar
    // (p. ej. «No hay registros») siempre se muestra.
    const paginate = (box) => {
        const size = Math.max(1, Number(box.dataset.paginar) || 10);
        const nav = document.createElement('nav');
        nav.className = 'ia-pag';
        nav.setAttribute('aria-label', 'Paginación');
        const anchor = box.tagName === 'TBODY' ? (box.closest('table').closest('.overflow-x-auto, [data-paginar-caja]') || box.closest('table')) : box;
        anchor.after(nav);
        let page = 1;
        let lastCount = -1;
        const render = () => {
            const rows = [...box.children].filter((row) => row.tagName !== 'TEMPLATE' && !('sinPaginar' in row.dataset));
            const shown = rows.filter((row) => !row.hidden);
            const pages = Math.max(1, Math.ceil(shown.length / size));
            page = Math.min(page, pages);
            rows.forEach((row) => row.classList.remove('ia-pag-oculto'));
            shown.forEach((row, i) => row.classList.toggle('ia-pag-oculto', i < (page - 1) * size || i >= page * size));
            lastCount = shown.length;
            nav.hidden = shown.length <= size;
            if (nav.hidden) { nav.replaceChildren(); return; }
            // Números: primera, última y las cercanas a la actual (con «…» en medio).
            const nums = [...new Set([1, page - 1, page, page + 1, pages].filter((n) => n >= 1 && n <= pages))].sort((a, b) => a - b);
            const buttons = [];
            nums.forEach((n, i) => {
                if (i && n - nums[i - 1] > 1) buttons.push('<span class="ia-pag-gap">…</span>');
                buttons.push(`<button type="button" data-p="${n}"${n === page ? ' aria-current="page"' : ''}>${n}</button>`);
            });
            nav.innerHTML = `<span class="ia-pag-info">Mostrando ${(page - 1) * size + 1}–${Math.min(page * size, shown.length)} de ${shown.length}</span>`
                + `<span class="ia-pag-btns"><button type="button" data-p="${page - 1}" aria-label="Página anterior"${page === 1 ? ' disabled' : ''}>‹</button>`
                + buttons.join('') + `<button type="button" data-p="${page + 1}" aria-label="Página siguiente"${page === pages ? ' disabled' : ''}>›</button></span>`;
        };
        nav.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-p]');
            if (!button || button.disabled) return;
            page = Number(button.dataset.p);
            render();
            // Si el inicio de la tabla quedó arriba de la pantalla, se regresa a él.
            const top = anchor.getBoundingClientRect().top;
            if (top < 0) window.scrollBy({ top: top - 90, behavior: 'smooth' });
        });
        // Filas nuevas (otra búsqueda, otro mes) o filtros (hidden) = de vuelta a la página 1.
        new MutationObserver(() => { page = 1; render(); }).observe(box, { childList: true, attributes: true, subtree: true, attributeFilter: ['hidden'] });
        render();
    };
    const start = () => document.querySelectorAll('[data-paginar]').forEach(paginate);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
