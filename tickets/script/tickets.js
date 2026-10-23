'use strict';

const modal = document.getElementById('ticket-modal');
const form = document.getElementById('ticket-form');
const evidence = document.getElementById('evidence');
const preview = document.getElementById('evidence-preview');
const clear = document.getElementById('clear-evidence');
const error = document.getElementById('evidence-error');
const description = document.getElementById('description');
const submit = document.getElementById('submit-ticket');
let previewUrls = [];
const problems = Array.from(form.querySelectorAll('input[name="problemas[]"]'));
function validateProblems() {
    problems[0].setCustomValidity(problems.some(input => input.checked) ? '' : 'Selecciona al menos un problema.');
}
problems.forEach(input => input.addEventListener('change', validateProblems));
validateProblems();

document.getElementById('open-ticket').addEventListener('click', () => modal.showModal());
for (const id of ['close-ticket', 'cancel-ticket']) {
    document.getElementById(id).addEventListener('click', () => modal.close());
}
// Close by clicking the backdrop (the dialog itself, outside its content).
modal.addEventListener('click', event => { if (event.target === modal) modal.close(); });
if (modal.dataset.reopen === 'true') modal.showModal();

function updateCount() {
    document.getElementById('description-count').textContent = Array.from(description.value).length;
}
description.addEventListener('input', updateCount);
updateCount();

const MAX_BYTES = 1024 * 1024;
const MAX_SIDE = 1920;
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

// Reduced copies of the selected photos (null = use the input's files as they are). They are sent with
// fetch on submit, because older Safari (iPad) cannot replace the files inside the <input>.
let optimizedFiles = null;

// notice: shown after discarding the selection (the field is optional, so it does not block sending).
function updateEvidence(notice = '') {
    previewUrls.forEach(url => URL.revokeObjectURL(url));
    previewUrls = [];
    preview.replaceChildren();
    if (notice) {
        evidence.value = '';
        optimizedFiles = null;
        evidence.setCustomValidity('');
        error.textContent = notice;
        clear.hidden = true;
        return;
    }
    const files = optimizedFiles || Array.from(evidence.files);
    clear.hidden = files.length === 0;
    let message = '';
    if (files.length > 3) message = 'Selecciona como máximo 3 imágenes.';
    else if (files.some(file => !ACCEPTED.includes(file.type))) {
        message = 'Solo se admiten imágenes JPG, PNG y WebP.';
    } else if (files.some(file => file.size === 0 || file.size > MAX_BYTES)) {
        message = 'Cada imagen debe pesar entre 1 byte y 1 MB.';
    }
    evidence.setCustomValidity(message);
    error.textContent = message;
    if (message) return;
    for (const file of files) {
        const figure = document.createElement('figure');
        const image = document.createElement('img');
        const caption = document.createElement('figcaption');
        const url = URL.createObjectURL(file);
        previewUrls.push(url);
        image.src = url;
        image.alt = 'Vista previa de ' + file.name;
        caption.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
        figure.append(image, caption);
        preview.append(figure);
    }
}

function loadImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
        image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen.')); };
        image.src = url;
    });
}

// Phone photos and tablet screenshots weigh several MB: shrink them to MAX_SIDE and re-encode as JPEG
// so they fit the 1 MB server limit. Small JPG/PNG/WebP files are sent untouched.
async function optimizeImage(file) {
    if (ACCEPTED.includes(file.type) && file.size > 0 && file.size <= MAX_BYTES) return file;
    const image = await loadImage(file);
    let scale = Math.min(1, MAX_SIDE / Math.max(image.naturalWidth, image.naturalHeight));
    for (let attempt = 0; attempt < 5; attempt++) {
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff'; // transparent PNG areas become white instead of black
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        if (blob && blob.size <= MAX_BYTES) {
            // Some browsers ignore the JPEG request and return PNG; name the file after what was produced.
            const type = ACCEPTED.includes(blob.type) ? blob.type : 'image/jpeg';
            const extension = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/webp': '.webp' }[type];
            return new File([blob], file.name.replace(/\.[^.]*$/, '') + extension, { type, lastModified: Date.now() });
        }
        scale *= 0.75;
    }
    throw new Error('La imagen sigue siendo demasiado grande.');
}

async function handleEvidence() {
    optimizedFiles = null;
    const files = Array.from(evidence.files);
    if (files.length === 0 || files.length > 3 || typeof fetch === 'undefined' || typeof FormData === 'undefined') {
        updateEvidence();
        return;
    }
    submit.disabled = true;
    submit.textContent = 'Optimizando imágenes…';
    try {
        const optimized = [];
        for (const file of files) optimized.push(await optimizeImage(file)); // one by one to save memory on phones
        optimizedFiles = optimized;
        updateEvidence();
    } catch (exception) {
        console.warn('Evidencias:', exception);
        updateEvidence('No se pudo procesar una de las imágenes. Intenta con otra foto o captura en formato JPG o PNG.');
    } finally {
        submit.disabled = false;
        submit.textContent = 'Enviar solicitud →';
    }
}
evidence.addEventListener('change', handleEvidence);
clear.addEventListener('click', () => { evidence.value = ''; optimizedFiles = null; updateEvidence(); });
form.addEventListener('submit', event => {
    submit.disabled = true;
    submit.textContent = 'Guardando ticket…';
    if (!optimizedFiles) return; // no photos: normal form submission
    event.preventDefault();
    const data = new FormData(form);
    data.delete('evidencias[]');
    optimizedFiles.forEach(file => data.append('evidencias[]', file, file.name));
    fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(response => (response.headers.get('Content-Type') || '').includes('application/json')
            ? response.json().then(result => result.redirect)
            : response.url) // e.g. the session expired and the server redirected to the login page
        .then(url => { window.location.href = url || 'tickets.php'; })
        .catch(() => {
            submit.disabled = false;
            submit.textContent = 'Enviar solicitud →';
            error.textContent = 'No se pudo enviar el ticket. Revisa tu conexión e intenta de nuevo.';
        });
});
window.addEventListener('pageshow', () => {
    submit.disabled = false;
    submit.textContent = 'Enviar solicitud →';
    validateProblems();
});
