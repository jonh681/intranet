<?php
/*
 * Administración de empleados y roles: el superusuario y Supervisión (pestaña Usuarios).
 * Supervisión no asigna el rol Superusuario, no modifica cuentas de superusuario (protege a quien administra la
 * seguridad: cambiar su correo permitiría robarle la cuenta) y no borra las contraseñas de todos.
 * Acciones (POST con token CSRF): crear, editar (incluye rol y contraseña), desactivar, activar, eliminar,
 * enviar_enlace (recuperación de contraseña), borrar_contrasenas (todos los empleados),
 * y confirmar_rol / reenviar_rol / cancelar_rol para los cambios que involucran al rol Superusuario,
 * que exigen un código extra enviado al correo del superusuario que hace el cambio.
 * Todo queda en la bitácora (tabla auditoria) y vuelve a seguridad.php#usuarios con un mensaje.
 */
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
require_once __DIR__ . '/../php/correo.php';
require_once __DIR__ . '/../../comun/acceso.php'; // puestos y accesos
require_ticket_user();
if (!can_manage_users()) { http_response_code(403); exit('Acceso exclusivo del superusuario.'); }
$super = is_superuser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }

function users_redirect(string $key, string $message): void
{
    $_SESSION['users_' . $key] = $message;
    header('Location: seguridad.php#usuarios', true, 303);
    exit;
}

function post_text(string $key): string
{
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
}

/** Valida los datos del formulario de crear/editar y los devuelve normalizados. */
function user_fields(): array
{
    $data = [
        'name' => post_text('nombre'),
        'email' => strtolower(post_text('correo')),
        'area' => post_text('area'),
        'boss' => '', // se toma del jefe elegido (resolve_boss)
        'bossEmail' => strtolower(post_text('correo_jefe')),
        'role' => post_text('rol'), // vacío = al crear, Empleado; al editar, se conserva el rol actual
        'password' => is_string($_POST['contrasena'] ?? null) ? $_POST['contrasena'] : '',
        // Puesto: define los accesos (comun/acceso.php). Vacío = no se envió (el formulario lo deshabilita para
        // soporte, supervisión y superusuario): al crear, Oficina; al editar, se conserva el puesto actual.
        'puesto' => post_text('puesto'),
        'hire' => post_text('fecha_ingreso'),
    ];
    if ($data['puesto'] !== '' && !isset(intranet_puestos()[$data['puesto']])) users_redirect('error', 'El puesto no es válido.');
    if ($data['hire'] !== '') {
        $hire = DateTimeImmutable::createFromFormat('!Y-m-d', $data['hire']);
        if (!$hire || $hire->format('Y-m-d') !== $data['hire'] || $data['hire'] < '1950-01-01' || $data['hire'] > date('Y-m-d', strtotime('+1 year'))) {
            users_redirect('error', 'La fecha de ingreso no es válida.');
        }
    }
    if ($data['name'] === '' || mb_strlen($data['name']) > 200) users_redirect('error', 'El nombre es obligatorio y admite hasta 200 caracteres.');
    if (strlen($data['email']) > 200 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) users_redirect('error', 'Ingresa un correo válido.');
    if ($data['area'] === '' || mb_strlen($data['area']) > 80) users_redirect('error', 'El área es obligatoria y admite hasta 80 caracteres.');
    if (mb_strlen($data['boss']) > 200) users_redirect('error', 'El nombre del jefe directo admite hasta 200 caracteres.');
    if ($data['bossEmail'] !== '' && (strlen($data['bossEmail']) > 200 || !filter_var($data['bossEmail'], FILTER_VALIDATE_EMAIL))) users_redirect('error', 'El correo del jefe no es válido.');
    if ($data['bossEmail'] !== '' && $data['bossEmail'] === $data['email']) users_redirect('error', 'Una persona no puede ser su propio jefe directo.');
    if ($data['role'] !== '' && !isset(role_labels()[$data['role']])) users_redirect('error', 'El rol no es válido.');
    // Largo mínimo y máximo en php/seguridad.php (PASSWORD_MIN y PASSWORD_MAX).
    if ($data['password'] !== '' && (mb_strlen($data['password']) < PASSWORD_MIN || strlen($data['password']) > PASSWORD_MAX)) {
        users_redirect('error', 'La contraseña debe tener entre ' . PASSWORD_MIN . ' y ' . PASSWORD_MAX . ' caracteres.');
    }
    return $data;
}

/** Cuenta los superusuarios activos distintos de $exceptId (siempre debe quedar al menos uno). */
function other_superusers(PDO $db, int $exceptId): int
{
    $query = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'superusuario' AND activo = 1 AND id <> ?");
    $query->execute([$exceptId]);
    return (int)$query->fetchColumn();
}

/**
 * El jefe directo se elige de la lista (comun/acceso.php > intranet_jefes: Dirección y gerentes); su nombre sale de su cuenta.
 * Al editar, se respeta el jefe que ya tenía aunque ya no esté en la lista, mientras no se cambie.
 */
function resolve_boss(PDO $db, array $data, ?array $current = null): array
{
    if ($data['bossEmail'] === '') return $data;
    foreach (intranet_jefes($db) as $boss) {
        if ($boss['correo'] === $data['bossEmail']) return ['boss' => $boss['nombre']] + $data;
    }
    if ($current && strtolower(trim((string)$current['correo_jefe'])) === $data['bossEmail']) return ['boss' => (string)$current['jefe_directo']] + $data;
    users_redirect('error', 'Elige el jefe directo de la lista (Cesar, Brenda, Angélica o un gerente).');
}

function find_user(PDO $db, $id): array
{
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) users_redirect('error', 'La acción no es válida.');
    $query = $db->prepare('SELECT id, nombre_usuario, correo_usuario, contrasena_usuario, activo, rol, puesto, jefe_directo, correo_jefe FROM usuarios WHERE id = ?');
    $query->execute([$id]);
    $user = $query->fetch();
    if (!$user) users_redirect('error', 'El usuario ya no existe.');
    return $user;
}

if (!valid_csrf()) users_redirect('error', 'El formulario venció. Intenta de nuevo.');
$actions = ['crear', 'editar', 'desactivar', 'activar', 'eliminar', 'enviar_enlace', 'borrar_contrasenas', 'confirmar_rol', 'reenviar_rol', 'cancelar_rol'];
$action = post_text('accion');
if (!in_array($action, $actions, true)) users_redirect('error', 'La acción no es válida.');
$self = (int)$_SESSION['user']['id'];
$actor = (string)$_SESSION['user']['name'];
$labels = role_labels();

try {
    $db = database();

    /* ---------- Cambio de rol pendiente de código (involucra al rol Superusuario) ---------- */
    if (in_array($action, ['confirmar_rol', 'reenviar_rol', 'cancelar_rol', 'borrar_contrasenas'], true) && !$super) {
        users_redirect('error', 'Esta acción es exclusiva del superusuario.');
    }
    if (in_array($action, ['confirmar_rol', 'reenviar_rol', 'cancelar_rol'], true)) {
        $pending = $_SESSION['step_up'] ?? null;
        if (!$pending) users_redirect('error', 'No hay ningún cambio de rol pendiente.');
        if ($action === 'cancelar_rol') {
            unset($_SESSION['step_up']);
            audit('rol_cancelado', $pending['descripcion']);
            users_redirect('success', 'Se canceló el cambio de rol.');
        }
        if ($action === 'reenviar_rol') {
            $wait = ACCESS_CODE_RESEND - (time() - ($pending['sent_at'] ?? 0));
            if ($wait > 0) users_redirect('error', 'Espera ' . $wait . ' segundos para pedir otro código.');
            $sent = step_up_issue($db, ['id' => $pending['id'], 'rol' => $pending['rol']], $pending['descripcion']);
            users_redirect($sent ? 'success' : 'error', $sent ? 'Te enviamos un código nuevo. El anterior ya no funciona.' : 'No se pudo enviar el código. Intenta en un minuto.');
        }
        try {
            code_verify($_SESSION['step_up'], post_text('codigo'));
        } catch (LengthException $exception) {
            unset($_SESSION['step_up']);
            audit('codigo_fallido', 'Cambio de rol cancelado: ' . $pending['descripcion']);
            users_redirect('error', 'Demasiados códigos incorrectos. El cambio de rol se canceló.');
        } catch (InvalidArgumentException $exception) {
            audit('codigo_fallido', 'Cambio de rol: ' . $pending['descripcion']);
            users_redirect('error', $exception->getMessage());
        }
        unset($_SESSION['step_up']);
        // Se vuelven a validar las reglas: pudo cambiar algo mientras se esperaba el código.
        $target = find_user($db, $pending['id']);
        if ((int)$target['id'] === $self) users_redirect('error', 'No puedes cambiar tu propio rol.');
        if ($target['rol'] === 'superusuario' && $pending['rol'] !== 'superusuario' && other_superusers($db, (int)$target['id']) === 0) {
            users_redirect('error', 'Debe quedar al menos un superusuario activo.');
        }
        $db->prepare('UPDATE usuarios SET rol = ? WHERE id = ?')->execute([$pending['rol'], $target['id']]);
        audit('rol_cambiado', $pending['descripcion'] . ' (confirmado con código)');
        users_redirect('success', 'Listo: ' . $pending['descripcion'] . '.');
    }

    /* ---------- Borrar las contraseñas de todos los empleados ---------- */
    if ($action === 'borrar_contrasenas') {
        if (post_text('confirmacion') !== 'BORRAR') users_redirect('error', 'Para borrar las contraseñas escribe BORRAR en mayúsculas.');
        if (system_url() === '') users_redirect('error', 'Primero configura INTRANET_URL en el archivo de configuración: sin ella los empleados no podrían recibir el enlace para crear su contraseña.');
        if (env('TICKETS_CORREO_ACTIVO', '1') === '0') users_redirect('error', 'El envío de correos está apagado (TICKETS_CORREO_ACTIVO=0): los empleados no podrían crear su contraseña.');
        $count = $db->exec("UPDATE usuarios SET contrasena_usuario = NULL WHERE rol = 'empleado' AND contrasena_usuario IS NOT NULL AND contrasena_usuario <> ''");
        audit('contrasenas_borradas', $count . ' empleados');
        users_redirect('success', 'Se borraron las contraseñas de ' . $count . ' empleados. La próxima vez que entren, escribirán su correo, darán Enter y crearán una nueva con el enlace que les llegue.');
    }

    /* ---------- Crear ---------- */
    if ($action === 'crear') {
        $data = resolve_boss($db, user_fields());
        $data['role'] = $data['role'] ?: 'empleado';
        $data['puesto'] = $data['puesto'] ?: 'oficina';
        $query = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE correo_usuario = ?');
        $query->execute([$data['email']]);
        if ((int)$query->fetchColumn() > 0) users_redirect('error', 'Ya existe un usuario con el correo ' . $data['email'] . '.');
        // Una cuenta nueva siempre nace como empleado o con un rol sin superusuario; el superusuario se asigna después, con código.
        if ($data['role'] === 'superusuario') users_redirect('error', 'Crea la cuenta con otro rol y después cámbiala a Superusuario: ese cambio pide un código de confirmación.');
        $db->prepare('INSERT INTO usuarios (nombre_usuario, correo_usuario, contrasena_usuario, area, puesto, jefe_directo, correo_jefe, activo, rol, fecha_ingreso)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
            ->execute([$data['name'], $data['email'], $data['password'] !== '' ? password_hash($data['password'], PASSWORD_DEFAULT) : null, $data['area'], $data['puesto'],
                $data['boss'] !== '' ? $data['boss'] : null, $data['bossEmail'] !== '' ? $data['bossEmail'] : null, $data['role'], $data['hire'] ?: null]);
        audit('usuario_creado', $data['name'] . ' <' . $data['email'] . '> · rol ' . $labels[$data['role']]);
        users_redirect('success', 'Se agregó a ' . $data['name'] . ' como ' . $labels[$data['role']] . '. ' . ($data['password'] !== ''
            ? 'Comparte su contraseña inicial por un medio seguro (en persona o por teléfono).'
            : 'Para entrar escribirá su correo, dará Enter y creará su contraseña con el enlace que le llegue.'));
    }

    $user = find_user($db, $_POST['id'] ?? null);
    if (!$super && $user['rol'] === 'superusuario') users_redirect('error', 'Solo el superusuario modifica las cuentas de superusuario.');
    $id = (int)$user['id'];
    $label = $user['nombre_usuario'] . ' <' . $user['correo_usuario'] . '>';
    $protected = $id === $self || $user['rol'] === 'superusuario';

    /* ---------- Enlace de recuperación ---------- */
    if ($action === 'enviar_enlace') {
        if ((int)$user['activo'] !== 1) users_redirect('error', 'La cuenta está desactivada: reactívala antes de enviar el enlace.');
        if (system_url() === '') users_redirect('error', 'Configura INTRANET_URL en el archivo de configuración para poder enviar enlaces.');
        $type = (string)$user['contrasena_usuario'] === '' ? 'crear' : 'recuperar';
        $sent = password_link_send($db, ['id' => $id, 'name' => (string)$user['nombre_usuario'], 'email' => $user['correo_usuario']], $type, $self);
        audit($sent ? 'enlace_recuperar_enviado' : 'enlace_no_enviado', $label);
        users_redirect($sent ? 'success' : 'error', $sent
            ? 'Se envió a ' . $user['correo_usuario'] . ' un enlace para ' . ($type === 'crear' ? 'crear' : 'restablecer') . ' su contraseña (vale ' . ($type === 'crear' ? '24 horas' : '60 minutos') . ').'
            : 'No se pudo enviar el correo. Revisa la configuración del correo e intenta de nuevo.');
    }

    /* ---------- Desactivar / activar ---------- */
    if ($action === 'desactivar' || $action === 'activar') {
        if ($protected) users_redirect('error', 'No puedes desactivar tu propia cuenta ni la de otro superusuario.');
        $db->prepare('UPDATE usuarios SET activo = ? WHERE id = ?')->execute([$action === 'activar' ? 1 : 0, $id]);
        audit($action === 'activar' ? 'usuario_activado' : 'usuario_desactivado', $label);
        users_redirect('success', $action === 'activar'
            ? $user['nombre_usuario'] . ' puede volver a iniciar sesión.'
            : $user['nombre_usuario'] . ' ya no puede iniciar sesión. Si tenía una sesión abierta, se cerrará en su siguiente clic.');
    }

    /* ---------- Eliminar ---------- */
    if ($action === 'eliminar') {
        if ($protected) users_redirect('error', 'No puedes eliminar tu propia cuenta ni la de otro superusuario.');
        if (!in_array($user['rol'], ['empleado', 'gerente'], true)) users_redirect('error', 'Solo se eliminan cuentas con rol Empleado o Gerente. Cambia el rol o desactiva la cuenta.');
        $query = $db->prepare('SELECT (SELECT COUNT(*) FROM ticket_detalles WHERE usuario_id = ?) + (SELECT COUNT(*) FROM ticket_seguimiento WHERE usuario_id = ?)
            + (SELECT COUNT(*) FROM registros WHERE usuario_id = ?) + (SELECT COUNT(*) FROM visitas WHERE usuario_id = ?)
            + (SELECT COUNT(*) FROM incidencias WHERE usuario_id = ? OR creado_por = ?) + (SELECT COUNT(*) FROM vacaciones_solicitudes WHERE usuario_id = ? OR creado_por = ?)
            + (SELECT COUNT(*) FROM vacaciones_ajustes WHERE usuario_id = ? OR creado_por = ?)');
        $query->execute(array_fill(0, 10, $id));
        if ((int)$query->fetchColumn() > 0) {
            users_redirect('error', $user['nombre_usuario'] . ' tiene tickets, registros de asistencia o incidencias; para conservar el historial, desactívalo en lugar de eliminarlo.');
        }
        $db->prepare('DELETE FROM password_resets WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        audit('usuario_eliminado', $label);
        users_redirect('success', 'Se eliminó a ' . $user['nombre_usuario'] . '.');
    }

    /* ---------- Editar (datos, contraseña y rol) ---------- */
    $data = resolve_boss($db, user_fields(), $user);
    $data['role'] = $data['role'] ?: (string)$user['rol'];
    $data['puesto'] = $data['puesto'] ?: ((string)$user['puesto'] ?: 'oficina');
    $query = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE correo_usuario = ? AND id <> ?');
    $query->execute([$data['email'], $id]);
    if ((int)$query->fetchColumn() > 0) users_redirect('error', 'Ya existe otro usuario con el correo ' . $data['email'] . '.');
    $roleChange = $data['role'] !== $user['rol'];
    if ($roleChange && $id === $self) users_redirect('error', 'No puedes cambiar tu propio rol.');
    if (!$super && $data['role'] === 'superusuario') users_redirect('error', 'Solo el superusuario asigna el rol Superusuario.');
    if ($roleChange && $user['rol'] === 'superusuario' && other_superusers($db, $id) === 0) users_redirect('error', 'Debe quedar al menos un superusuario activo.');
    $needsCode = $roleChange && ($data['role'] === 'superusuario' || $user['rol'] === 'superusuario');
    $sql = 'UPDATE usuarios SET nombre_usuario = ?, correo_usuario = ?, area = ?, puesto = ?, jefe_directo = ?, correo_jefe = ?, fecha_ingreso = ?';
    $params = [$data['name'], $data['email'], $data['area'], $data['puesto'], $data['boss'] !== '' ? $data['boss'] : null,
        $data['bossEmail'] !== '' ? $data['bossEmail'] : null, $data['hire'] ?: null];
    if ($data['password'] !== '') { $sql .= ', contrasena_usuario = ?'; $params[] = password_hash($data['password'], PASSWORD_DEFAULT); }
    if ($roleChange && !$needsCode) { $sql .= ', rol = ?'; $params[] = $data['role']; }
    $params[] = $id;
    $db->prepare($sql . ' WHERE id = ?')->execute($params);
    // Los enlaces para crear o recuperar la contraseña que se mandaron al correo anterior ya no sirven.
    if (strtolower((string)$user['correo_usuario']) !== $data['email']) {
        $db->prepare('UPDATE password_resets SET usado_en = ? WHERE usuario_id = ? AND usado_en IS NULL')->execute([date('Y-m-d H:i:s'), $id]);
    }
    // Con la contraseña nueva se cierran las sesiones abiertas de esa cuenta (intranet_sesion_vigente),
    // salvo esta misma si alguien cambia la suya.
    if ($data['password'] !== '' && $id === $self) {
        $query = $db->prepare('SELECT contrasena_usuario FROM usuarios WHERE id = ?');
        $query->execute([$id]);
        $_SESSION['huella'] = intranet_huella_contrasena($query->fetchColumn() ?: null);
    }
    audit('usuario_editado', $data['name'] . ' <' . $data['email'] . '>' . ($data['password'] !== '' ? ' · contraseña cambiada' : ''));
    if ($roleChange && !$needsCode) audit('rol_cambiado', $data['name'] . ': ' . $labels[$user['rol']] . ' → ' . $labels[$data['role']]);
    if ($data['password'] !== '') {
        intranet_despues(fn() => mail_password_changed($data['email'], $data['name'], $actor));
    }
    if ($needsCode) {
        // El resto de los datos ya se guardó; el rol queda pendiente hasta confirmar el código enviado a TU correo.
        $description = 'Cambiar el rol de ' . $data['name'] . ' de ' . $labels[$user['rol']] . ' a ' . $labels[$data['role']];
        $sent = step_up_issue($db, ['id' => $id, 'rol' => $data['role']], $description);
        users_redirect($sent ? 'success' : 'error', $sent
            ? 'Te enviamos un código a tu correo. Escríbelo arriba para confirmar: ' . $description . '.'
            : 'No se pudo enviar el código de confirmación. Usa «Reenviar código» en un minuto.');
    }
    users_redirect('success', 'Se guardaron los cambios de ' . $data['name'] . ($roleChange ? ' (ahora es ' . $labels[$data['role']] . ')' : '')
        . ($data['password'] !== '' ? '. Se le avisó por correo del cambio de contraseña.' : '.'));
} catch (InvalidArgumentException $exception) {
    users_redirect('error', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Usuarios: ' . $exception->getMessage());
    users_redirect('error', 'No se pudo completar la operación. Intenta de nuevo.');
}
