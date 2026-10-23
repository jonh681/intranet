'use strict';
// Mide los datos que usa Fedele 360 en este dispositivo (tickets, asistencia e incidencias) para el reporte de consumo
// (asistencia/consumo.php). Basado en asistencia v3. Lo carga el encabezado (comun/barra.php) con data-url y data-csrf.
// Descargas: Resource Timing del navegador (transferSize = bytes que viajaron por la red; lo que ya estaba guardado en
// el celular cuenta 0). Envíos: tamaño de lo que se manda al servidor (registros, formularios, archivos).
// Se reporta al ocultar la página (cambiar de app, bloquear el celular, salir) y cada 10 minutos si sigue abierta.
// El reporte no cuenta como actividad: no evita que la sesión expire por inactividad (registrar_consumo.php).
(() => {
    const script = document.currentScript;
    const token = script?.dataset.csrf;
    if (!token || !script?.dataset.url || !window.performance?.getEntriesByType || !window.fetch) return;
    // Dirección completa: data-url es relativa ('../comun/...') y Resource Timing da URLs absolutas.
    const REPORT = new URL(script.dataset.url, location.href).href;
    const isReport = (url) => {
        try { return new URL(url, location.href).href.split('?')[0] === REPORT; } catch { return false; }
    };
    const HEADERS = 400; // encabezados de cada petición y respuesta, aproximados
    // Recursos de otros sitios (mosaicos del mapa de OpenStreetMap) sin Timing-Allow-Origin: el navegador oculta su
    // tamaño (transferSize 0). Se estima con el tamaño típico de un mosaico; los que tardan menos venían del caché.
    const EXTERNAL = 20 * 1024;
    const EXTERNAL_FROM_CACHE_MS = 30;
    const counted = { bytes: 0, cellular: 0, loads: 0, requests: 0 };
    const isCellular = () => navigator.connection?.type === 'cellular';
    const add = (bytes) => {
        counted.bytes += bytes;
        if (isCellular()) counted.cellular += bytes;
    };
    const isHidden = (entry) => entry.transferSize === 0 && entry.encodedBodySize === 0 && entry.responseStart === 0
        && !entry.name.startsWith(location.origin) && /^https?:/.test(entry.name);
    // Bytes recibidos de un recurso: transferSize si el navegador lo informa; si no, el cuerpo + encabezados.
    const received = (entry) => entry.transferSize > 0 ? entry.transferSize
        : entry.transferSize === undefined && entry.encodedBodySize > 0 ? entry.encodedBodySize + HEADERS
        : isHidden(entry) && entry.duration > EXTERNAL_FROM_CACHE_MS ? EXTERNAL : 0;
    // Llamadas al servidor: APIs y descargas de archivos.
    const isRequest = (url) => /\/(api|archivo|formato|evidencia)\.php/.test(url);

    try { performance.setResourceTimingBufferSize?.(2000); } catch { /* sin cambio */ }
    const nav = performance.getEntriesByType('navigation')[0];
    counted.loads = 1;
    add(nav ? received(nav) : 0);
    const onEntries = (entries) => {
        for (const entry of entries) {
            if (isReport(entry.name)) continue;
            add(received(entry));
            if (isRequest(entry.name)) counted.requests++;
        }
    };
    if (window.PerformanceObserver) {
        try { new PerformanceObserver((list) => onEntries(list.getEntries())).observe({ type: 'resource', buffered: true }); }
        catch { onEntries(performance.getEntriesByType('resource')); }
    }

    // Lo que se envía no aparece en Resource Timing: se mide al mandar cada petición o formulario.
    const sentSize = (body) => {
        if (!body) return 0;
        if (typeof body === 'string') return new Blob([body]).size;
        if (body instanceof FormData) {
            let size = 0;
            for (const [key, value] of body.entries()) size += key.length + 60 + (typeof value === 'string' ? new Blob([value]).size : value.size);
            return size;
        }
        if (body instanceof URLSearchParams) return new Blob([body.toString()]).size;
        return body.size || body.byteLength || 0;
    };
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        if (!isReport(String(input?.url || input))) add(sentSize(init.body) + HEADERS);
        return fetchOriginal(input, init);
    };
    // Formularios de tickets y Usuarios (se envían sin fetch): lo que suben cuenta al salir de la página.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        try { add(sentSize(new FormData(form)) + HEADERS); } catch { /* formulario sin datos */ }
    });

    let lastSent = 0;
    const report = () => {
        const payload = { bytes: Math.round(counted.bytes), cellular: Math.round(counted.cellular), loads: counted.loads, requests: counted.requests };
        if (!payload.bytes && !payload.loads && !payload.requests) return;
        Object.assign(counted, { bytes: 0, cellular: 0, loads: 0, requests: 0 });
        lastSent = Date.now();
        // touch: el servidor distingue un iPad (iPadOS se presenta como Mac) de una Mac.
        const body = JSON.stringify({ ...payload, touch: navigator.maxTouchPoints > 1 });
        fetchOriginal(REPORT, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body }).catch(() => {
            // Sin conexión: se vuelve a intentar en el siguiente reporte.
            counted.bytes += payload.bytes; counted.cellular += payload.cellular; counted.loads += payload.loads; counted.requests += payload.requests;
        });
    };
    document.addEventListener('visibilitychange', () => { if (document.hidden) report(); });
    window.addEventListener('pagehide', report);
    setInterval(() => { if (!document.hidden && Date.now() - lastSent > 9 * 60000) report(); }, 10 * 60000);
})();
