'use strict';

// Página Seguridad (solo superusuario): búsqueda de empleados y ventanas para agregar, editar y eliminar.
const formModal = document.getElementById('user-form');
const deleteModal = document.getElementById('user-delete');
const wipeModal = document.getElementById('wipe-modal');
const userForm = formModal.querySelector('form');

for (const dialog of [formModal, deleteModal, wipeModal]) {
    dialog.addEventListener('click', event => {
        // Close with the × / Cancelar buttons or by clicking the backdrop.
        if (event.target.closest('[data-close]') || event.target === dialog) dialog.close();
    });
}

// The same dialog creates or edits. The password is optional in both: empty = the person creates it
// with the e-mailed link (new account) or keeps the current one (editing).
function openUserForm(user) {
    const editing = Boolean(user);
    userForm.reset();
    const boss = userForm.elements.correo_jefe;
    boss.querySelector('[data-extra]')?.remove();
    userForm.elements.accion.value = editing ? 'editar' : 'crear';
    userForm.elements.namedItem('id').value = editing ? user.id : '';
    if (editing) {
        for (const [key, value] of Object.entries(user)) {
            if (userForm.elements[key] && key !== 'id') userForm.elements[key].value = value;
        }
        // Un jefe que ya no está en la lista (dejó de ser gerente) se conserva mientras no se cambie.
        const current = String(user.correo_jefe || '').trim().toLowerCase();
        if (current && boss.value !== current) {
            boss.add(new Option(`${user.jefe || current} · ${current} (ya no está en la lista)`, current), 1);
            boss.options[1].dataset.extra = '1';
            boss.value = current;
        }
    }
    // Nadie es su propio jefe.
    for (const option of boss.options) option.disabled = editing && option.value !== '' && option.value === String(user.correo).toLowerCase();
    syncAttendance();
    // Nobody changes their own role, and a new account cannot start as Superusuario (that change needs a code).
    const role = userForm.elements.rol;
    role.disabled = editing && user.yo;
    // Solo el superusuario asigna el rol Superusuario.
    role.querySelector('option[value="superusuario"]').disabled = !editing || !isSuper;
    if (role.disabled) role.title = 'No puedes cambiar tu propio rol'; else role.removeAttribute('title');
    formModal.querySelector('[data-mode-label]').textContent = editing ? 'Editar empleado' : 'Nuevo empleado';
    formModal.querySelector('[data-mode-title]').textContent = editing ? 'Datos del usuario' : 'Agregar empleado';
    formModal.querySelector('[data-mode-submit]').textContent = editing ? 'Guardar cambios' : 'Agregar empleado';
    formModal.querySelector('[data-password-label]').textContent = editing ? 'Nueva contraseña (opcional)' : 'Contraseña inicial (opcional)';
    formModal.querySelector('[data-password-help]').textContent = editing
        ? 'Déjala vacía para no cambiarla. Si la cambias, se le avisará por correo. Para que la persona la elija, usa mejor el botón de enlace.'
        : `Si la dejas vacía, la persona la crea con el enlace que recibe al escribir su correo y dar Enter. Mínimo ${formModal.dataset.passwordMin} caracteres.`;
    formModal.showModal();
}

// Los accesos salen del rol y del puesto (comun/acceso.php); aquí solo se muestran para que se sepa qué verá la persona.
const isSuper = formModal.dataset.super === '1';
function syncAttendance() {
    const role = userForm.elements.rol.value;
    const puesto = userForm.elements.puesto.value;
    const admin = ['superusuario', 'supervision'].includes(role);
    // El puesto solo cuenta para Empleado y Gerente; los demás roles tienen sus accesos fijos.
    userForm.elements.puesto.disabled = !['empleado', 'gerente'].includes(role);
    const apps = admin ? 'Tickets (reportes), Asistencia e Incidencias como administración' + (role === 'supervision' ? ' y Usuarios' : ' y Seguridad')
        : role === 'soporte' ? 'Solo el Panel de soporte de Tickets'
        : { representante: 'Tickets, Asistencia e Incidencias', dermoconsejera: 'Asistencia e Incidencias (sin Tickets)', oficina: 'Tickets e Incidencias (no registra asistencia)' }[puesto]
            + (role !== 'gerente' ? '' : puesto === 'oficina'
                ? ', además de su equipo (quienes lo tienen como jefe directo) en Incidencias'
                : ', además de su equipo (quienes lo tienen como jefe directo) en Asistencia e Incidencias (gerente de ventas)');
    formModal.querySelector('[data-access-preview]').textContent = `Verá: ${apps}.`;
}
userForm.elements.puesto.addEventListener('change', syncAttendance);
userForm.elements.rol.addEventListener('change', syncAttendance);

document.getElementById('add-user').addEventListener('click', () => openUserForm(null));
document.getElementById('wipe-passwords')?.addEventListener('click', () => { wipeModal.querySelector('form').reset(); wipeModal.showModal(); });

document.getElementById('user-list').addEventListener('click', event => {
    const button = event.target.closest('[data-edit], [data-delete]');
    if (!button || button.disabled) return;
    const user = JSON.parse(button.closest('.user-item').dataset.user);
    if (button.hasAttribute('data-edit')) {
        openUserForm(user);
    } else {
        deleteModal.querySelector('input[name="id"]').value = user.id;
        deleteModal.querySelector('[data-name]').textContent = user.nombre || user.correo;
        deleteModal.showModal();
    }
});

// Desactivar / reactivar / enviar enlace: confirmación antes de enviar (ventana de la intranet, comun/acciones.js).
for (const form of document.querySelectorAll('form[data-confirm]')) {
    form.addEventListener('submit', async event => {
        if (form.dataset.confirmed) return;
        event.preventDefault();
        const yes = window.intranetConfirmar
            ? await window.intranetConfirmar({ title: '¿Estás seguro?', text: form.dataset.confirm, ok: 'Sí, continuar', cancel: 'No, regresar' })
            : window.confirm(form.dataset.confirm);
        if (!yes) return;
        form.dataset.confirmed = '1';
        form.requestSubmit();
    });
}

// Evita envíos dobles.
for (const form of [userForm, deleteModal.querySelector('form'), wipeModal.querySelector('form')]) {
    form.addEventListener('submit', () => { form.querySelector('[type="submit"]').disabled = true; });
}

// Filtros de la tabla (búsqueda, área, puesto, rol y estado); la paginación de 10 en 10 la hace comun/acciones.js.
const search = document.getElementById('users-filter');
const selects = { area: 'users-area', puesto: 'users-puesto', rol: 'users-role', active: 'users-status' };
const items = Array.from(document.querySelectorAll('.user-item'));
function filterUsers() {
    const term = search.value.trim().toLowerCase();
    let visible = 0;
    for (const item of items) {
        item.hidden = (term !== '' && !item.dataset.search.includes(term))
            || Object.entries(selects).some(([key, id]) => { const value = document.getElementById(id).value; return value !== '' && item.dataset[key] !== value; });
        if (!item.hidden) visible++;
    }
    document.getElementById('users-empty').hidden = visible > 0;
}
search.addEventListener('input', filterUsers);
Object.values(selects).forEach((id) => document.getElementById(id).addEventListener('change', filterUsers));
