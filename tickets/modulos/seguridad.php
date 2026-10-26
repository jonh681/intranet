<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
require_once __DIR__ . '/../../comun/acceso.php'; // accesos que salen del puesto
require_ticket_user();
// Superusuario: protecciones, cuentas y bitácora. Supervisión con «Administra usuarios»: solo la sección Empleados.
if (!can_manage_users()) { http_response_code(403); exit('Acceso exclusivo del superusuario.'); }
$super = is_superuser();

$success = $_SESSION['security_success'] ?? '';
$usersSuccess = $_SESSION['users_success'] ?? '';
$usersError = $_SESSION['users_error'] ?? '';
unset($_SESSION['security_success'], $_SESSION['users_success'], $_SESSION['users_error']);
$roleLabels = role_labels();
// Puesto de cada empleado: de él salen sus accesos (comun/acceso.php); nadie los elige a mano.
$puestos = intranet_puestos();
// Cambio de rol esperando el código enviado al correo del superusuario (ver modulos/usuarios.php).
$stepUp = $_SESSION['step_up'] ?? null;
if ($stepUp && time() > ($stepUp['expires'] ?? 0)) { unset($_SESSION['step_up']); $stepUp = null; }
$error = '';
$plainSql = "contrasena_usuario IS NOT NULL AND contrasena_usuario <> '' AND contrasena_usuario NOT REGEXP '^[$](2[aby]|argon2)'";
$plainEvidence = "LEFT(contenido, 4) <> 'ENC1'";

try {
    $db = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$super) { http_response_code(403); exit('Acceso exclusivo del superusuario.'); }
        if (!valid_csrf()) throw new InvalidArgumentException('El formulario venció. Intenta de nuevo.');
        set_time_limit(300);
        // 1) Contraseñas guardadas en texto: se convierten a hash bcrypt (irreversible). Siguen funcionando igual al iniciar sesión.
        $hashed = 0;
        foreach ($db->query('SELECT id, contrasena_usuario FROM usuarios WHERE ' . $plainSql)->fetchAll() as $row) {
            $query = $db->prepare('UPDATE usuarios SET contrasena_usuario = ? WHERE id = ? AND BINARY contrasena_usuario = ?');
            $query->execute([password_hash((string)$row['contrasena_usuario'], PASSWORD_DEFAULT), $row['id'], $row['contrasena_usuario']]);
            $hashed += $query->rowCount();
        }
        // 2) Evidencias sin cifrar: se cifran con la llave TICKETS_APP_KEY.
        $encrypted = 0;
        if (app_key() !== null) {
            foreach ($db->query('SELECT id FROM ticket_evidencias WHERE ' . $plainEvidence)->fetchAll(PDO::FETCH_COLUMN) as $evidenceId) {
                $query = $db->prepare('SELECT contenido FROM ticket_evidencias WHERE id = ?');
                $query->execute([$evidenceId]);
                $content = (string)$query->fetchColumn();
                if (is_encrypted($content)) continue;
                $db->prepare('UPDATE ticket_evidencias SET contenido = ? WHERE id = ?')->execute([protect_blob($content), $evidenceId]);
                $encrypted++;
            }
        }
        audit('proteccion_datos', $hashed . ' contraseñas protegidas, ' . $encrypted . ' evidencias cifradas');
        $_SESSION['security_success'] = 'Listo: ' . $hashed . ' contraseñas convertidas a hash y ' . $encrypted . ' evidencias cifradas.';
        header('Location: seguridad.php', true, 303);
        exit;
    }

    $plainPasswords = (int)$db->query('SELECT COUNT(*) FROM usuarios WHERE ' . $plainSql)->fetchColumn();
    $plainFiles = (int)$db->query('SELECT COUNT(*) FROM ticket_evidencias WHERE ' . $plainEvidence)->fetchColumn();
    $totalFiles = (int)$db->query('SELECT COUNT(*) FROM ticket_evidencias')->fetchColumn();
    // Privilegios del usuario de la base de datos que usa el sistema.
    $grants = implode("\n", $db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
    $tooMuch = (bool)preg_match('/GRANT ALL|\bON \*\.\*|\b(CREATE|DROP|ALTER|GRANT OPTION|FILE|SUPER|PROCESS)\b/i', preg_replace('/GRANT USAGE ON \*\.\*[^\n]*/i', '', $grants));
    $admins = $db->query("SELECT nombre_usuario nombre, correo_usuario correo, rol, activo FROM usuarios WHERE rol IN ('superusuario', 'supervision', 'soporte')
        ORDER BY FIELD(rol, 'superusuario', 'supervision', 'soporte'), nombre_usuario")->fetchAll();
    $withoutPassword = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND (contrasena_usuario IS NULL OR contrasena_usuario = '')")->fetchColumn();
    $events = $super ? $db->query('SELECT fecha, correo, ip, accion, detalle FROM auditoria ORDER BY id DESC LIMIT 150')->fetchAll() : [];
    // Cuentas de toda la intranet (tabla usuarios: lo que ve cada quien sale de su rol y su puesto, comun/acceso.php).
    $employees = $db->query('SELECT u.id, u.nombre_usuario, u.correo_usuario, u.area, u.puesto, u.jefe_directo, u.correo_jefe, u.activo, u.rol, u.fecha_ingreso,
        (u.contrasena_usuario IS NULL OR u.contrasena_usuario = \'\') sin_contrasena,
        (SELECT COUNT(*) FROM ticket_detalles d WHERE d.usuario_id = u.id) tickets,
        (SELECT COUNT(*) FROM ticket_seguimiento s WHERE s.usuario_id = u.id) movimientos,
        (SELECT COUNT(*) FROM registros r WHERE r.usuario_id = u.id) + (SELECT COUNT(*) FROM vacaciones_solicitudes v WHERE v.usuario_id = u.id)
            + (SELECT COUNT(*) FROM incidencias i WHERE i.usuario_id = u.id) + (SELECT COUNT(*) FROM visitas x WHERE x.usuario_id = u.id) asistencia
        FROM usuarios u ORDER BY u.activo DESC, u.nombre_usuario')->fetchAll();
    $validators = intranet_validadores();
    $bosses = intranet_jefes($db);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Seguridad: ' . $exception->getMessage());
    $error = 'No se pudo cargar el estado de seguridad. Ejecuta instalar/migraciones.php.';
}
$mail = require __DIR__ . '/../php/correo_config.php';
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$checks = [
    ['Código de acceso para soporte y supervisión', access_code_enabled(), 'Activo: se pide un código por correo en cada inicio de sesión.', 'Desactivado con TICKETS_CODIGO_ACCESO=0 en tickets.env.'],
    ['Llave de cifrado (TICKETS_APP_KEY)', app_key() !== null, 'Configurada: las evidencias nuevas se guardan cifradas.', 'Falta en tickets.env: las evidencias se guardan sin cifrar.'],
    ['Conexión cifrada (HTTPS)', $https, 'La información viaja cifrada entre el navegador y el servidor.', 'Sin HTTPS: en el servidor activa el certificado SSL en cPanel.'],
    ['Contraseñas protegidas', isset($plainPasswords) && $plainPasswords === 0, 'Todas las contraseñas están guardadas como hash.', ($plainPasswords ?? '?') . ' contraseñas siguen en texto normal. Usa «Proteger datos existentes».'],
    ['Evidencias cifradas', isset($plainFiles) && $plainFiles === 0, 'Todas las evidencias están cifradas.', ($plainFiles ?? '?') . ' de ' . ($totalFiles ?? '?') . ' evidencias sin cifrar. Usa «Proteger datos existentes».'],
    ['Permisos mínimos en la base de datos', isset($tooMuch) && !$tooMuch, 'El usuario de la base solo puede leer y escribir datos.', 'El usuario de la base tiene más permisos de los necesarios. Crea uno solo con SELECT, INSERT, UPDATE y DELETE.'],
    // Igual que comun/correo.php: el modo prueba solo aplica si TICKETS_MODO_PRUEBA es un correo válido.
    ['Correos reales (sin modo prueba)', !filter_var($mail['modo_prueba'], FILTER_VALIDATE_EMAIL), $mail['modo_prueba'] === ''
        ? 'Los avisos llegan a sus destinatarios reales.'
        : 'TICKETS_MODO_PRUEBA («' . $mail['modo_prueba'] . '») no es un correo válido: se ignora y los avisos llegan a sus destinatarios reales.',
        'Modo prueba activo: los avisos van a ' . $mail['modo_prueba'] . '.'],
    ['Dirección de la intranet (INTRANET_URL)', system_url() !== '', 'Configurada: los enlaces para crear o recuperar contraseña apuntan a ' . system_url() . '.', 'Falta INTRANET_URL en intranet.env: no se pueden enviar enlaces para crear o recuperar contraseñas.'],
];
$canSendLinks = system_url() !== '' && $mail['activo'];
$labels = ['login_ok' => 'Inicio de sesión', 'login_fallido' => 'Contraseña incorrecta', 'login_bloqueado' => 'Acceso bloqueado', 'contrasena_ok' => 'Contraseña correcta',
    'codigo_enviado' => 'Código enviado', 'codigo_no_enviado' => 'Código no enviado', 'codigo_fallido' => 'Código incorrecto', 'logout' => 'Cierre de sesión',
    'ver_ticket' => 'Consultó ticket', 'ver_evidencia' => 'Abrió evidencia', 'reporte_csv' => 'Descargó reporte CSV', 'usuario_editado' => 'Editó usuario',
    'usuario_eliminado' => 'Eliminó usuario', 'ticket_validado' => 'Validó ticket', 'ticket_resuelto' => 'Resolvió ticket', 'proteccion_datos' => 'Protegió datos',
    'usuario_creado' => 'Agregó usuario', 'usuario_desactivado' => 'Desactivó usuario', 'usuario_activado' => 'Reactivó usuario', 'login_desactivado' => 'Intento con cuenta desactivada',
    'rol_cambiado' => 'Cambió rol', 'rol_cancelado' => 'Canceló cambio de rol', 'codigo_rol_enviado' => 'Código de cambio de rol',
    'enlace_crear_enviado' => 'Enlace para crear contraseña', 'enlace_recuperar_enviado' => 'Envió enlace de recuperación', 'enlace_no_enviado' => 'Enlace no enviado',
    'enlace_solicitado' => 'Pidió enlace (sin efecto)', 'contrasena_creada' => 'Creó su contraseña', 'contrasena_restablecida' => 'Restableció su contraseña',
    'contrasenas_borradas' => 'Borró contraseñas de empleados'];
$alerts = ['login_fallido', 'login_bloqueado', 'codigo_fallido', 'codigo_no_enviado', 'usuario_eliminado', 'usuario_desactivado', 'login_desactivado',
    'enlace_no_enviado', 'contrasenas_borradas', 'rol_cambiado'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seguridad | Fedele 360</title>
    <link rel="stylesheet" href="../css/app.css?v=<?= (int) filemtime(__DIR__ . '/../css/app.css') ?>">
    <script src="../script/seguridad.js?v=<?= (int) filemtime(__DIR__ . '/../script/seguridad.js') ?>" defer></script>
</head>
<body>
<?php app_header('seguridad.php'); ?>
<main class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <?php if ($super): ?>
        <p class="eyebrow">Superusuario</p>
        <h1 class="page-title mt-1">Seguridad del sistema</h1>
        <p class="mt-2 max-w-3xl text-slate-500">Estado de las protecciones, cuentas con acceso total y bitácora de quién entró, qué consultó y qué cambió.</p>
        <?php else: ?>
        <p class="eyebrow">Supervisión</p>
        <h1 class="page-title mt-1">Usuarios de la intranet</h1>
        <p class="mt-2 max-w-3xl text-slate-500">Agrega, edita, desactiva o elimina las cuentas de tickets, asistencia e incidencias. Solo el superusuario asigna el rol Superusuario y modifica esas cuentas.</p>
        <?php endif; ?>
    </div>
    <?php if ($error): ?><div class="alert-error" role="alert"><?= escape($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" role="status"><?= escape($success) ?></div><?php endif; ?>

    <?php if ($super): ?>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        <section class="card p-5 sm:p-6" aria-labelledby="checks-title">
            <h2 id="checks-title" class="font-semibold text-slate-900">Protecciones</h2>
            <ul class="mt-4 divide-y divide-slate-100">
                <?php foreach ($checks as [$title, $ok, $good, $bad]): ?>
                <li class="flex gap-3 py-3">
                    <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full <?= $ok ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>" aria-hidden="true"><?= $ok ? '✓' : '!' ?></span>
                    <div><p class="text-sm font-medium text-slate-900"><?= escape($title) ?> <span class="sr-only"><?= $ok ? '(correcto)' : '(revisar)' ?></span></p><p class="text-sm text-slate-500"><?= escape($ok ? $good : $bad) ?></p></div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$error): ?>
            <form method="post" class="mt-4 flex flex-col gap-3 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <p class="text-sm text-slate-600">Convierte a hash las contraseñas guardadas en texto y cifra las evidencias anteriores. Es seguro repetirlo.</p>
                <button class="btn btn-primary shrink-0">Proteger datos existentes</button>
            </form>
            <?php endif; ?>
        </section>
        <section class="card p-5 sm:p-6" aria-labelledby="admins-title">
            <h2 id="admins-title" class="font-semibold text-slate-900">Cuentas con acceso total</h2>
            <p class="mt-1 text-sm text-slate-500">Ven los tickets y evidencias de todos y entran con código por correo. El rol se cambia en Empleados → Editar; dar o quitar el rol Superusuario pide un código extra.</p>
            <ul class="mt-4 space-y-2">
                <?php foreach ($admins ?? [] as $admin): ?>
                <li class="flex items-center justify-between gap-3 rounded-lg border border-slate-100 px-3 py-2 <?= (int)$admin['activo'] === 1 ? '' : 'opacity-60' ?>">
                    <div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900"><?= escape((string)$admin['nombre']) ?><?= (int)$admin['activo'] === 1 ? '' : ' · desactivada' ?></p><p class="truncate text-xs text-slate-500"><?= escape((string)$admin['correo']) ?></p></div>
                    <span class="tag shrink-0 <?= $admin['rol'] === 'superusuario' ? 'bg-red-50 text-red-700' : '' ?>"><?= escape($roleLabels[$admin['rol']] ?? $admin['rol']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($withoutPassword)): ?><p class="mt-4 text-xs text-slate-500"><?= (int)$withoutPassword ?> cuentas activas aún no tienen contraseña: la crearán con el enlace que reciben al escribir su correo y dar Enter.</p><?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <section class="card scroll-mt-24 overflow-hidden" id="usuarios" aria-labelledby="users-title">
        <div class="flex flex-col gap-4 border-b border-slate-100 p-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 id="users-title" class="flex items-center gap-2 font-semibold text-slate-900">Empleados <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= count($employees ?? []) ?></span></h2>
                <p class="mt-1 text-sm text-slate-500">Agrega cuentas, asigna roles, envía enlaces de recuperación, desactiva o elimina. Desactivar bloquea el acceso y conserva el historial.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-secondary" href="../../asistencia/consumo.php" title="Datos que usa Fedele 360 en el dispositivo de cada persona">Consumo de datos</a>
                <?php if ($super): ?><button type="button" class="btn btn-secondary" id="wipe-passwords" <?= $canSendLinks ? '' : 'disabled title="Configura INTRANET_URL y activa el correo primero"' ?>>Borrar contraseñas de empleados</button><?php endif; ?>
                <button type="button" class="btn btn-primary" id="add-user">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke-linecap="round"/></svg>
                    Agregar empleado
                </button>
            </div>
        </div>
        <?php if ($super && $stepUp): ?>
        <div class="mx-5 mt-4 rounded-xl border border-red-200 bg-red-50 p-4" role="alert">
            <p class="text-sm font-semibold text-red-800">Confirma el cambio con el código que te enviamos a tu correo</p>
            <p class="mt-1 text-sm text-red-700"><?= escape($stepUp['descripcion']) ?>. El código vence en 10 minutos.</p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                <form action="usuarios.php" method="post" class="flex gap-2">
                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="accion" value="confirmar_rol">
                    <label class="sr-only" for="codigo-rol">Código de confirmación</label>
                    <input id="codigo-rol" name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required placeholder="000000" class="input w-36 text-center font-semibold tracking-[0.3em]">
                    <button class="btn btn-danger">Confirmar</button>
                </form>
                <form action="usuarios.php" method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><button name="accion" value="reenviar_rol" class="btn btn-secondary">Reenviar código</button></form>
                <form action="usuarios.php" method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><button name="accion" value="cancelar_rol" class="btn btn-secondary">Cancelar cambio</button></form>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($usersSuccess): ?><div class="alert-success mx-5 mt-4" role="status"><?= escape($usersSuccess) ?></div><?php endif; ?>
        <?php if ($usersError): ?><div class="alert-error mx-5 mt-4" role="alert"><?= escape($usersError) ?></div><?php endif; ?>
        <?php $userAreas = array_values(array_unique(array_filter(array_map(fn($e) => trim((string)$e['area']), $employees ?? []))));
            sort($userAreas, SORT_LOCALE_STRING); ?>
        <div class="grid gap-3 px-5 pt-4 sm:grid-cols-2 lg:grid-cols-[minmax(14rem,1fr)_repeat(4,minmax(9rem,11rem))]">
            <label class="relative sm:col-span-2 lg:col-span-1">
                <span class="sr-only">Buscar empleado</span>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4" stroke-linecap="round"/></svg>
                <input type="search" id="users-filter" placeholder="Buscar por nombre, correo, área o rol" autocomplete="off" class="input pl-9">
            </label>
            <label><span class="sr-only">Área o departamento</span>
                <select id="users-area" class="input"><option value="">Todas las áreas</option><?php foreach ($userAreas as $areaName): ?><option value="<?= escape(mb_strtolower($areaName)) ?>"><?= escape($areaName) ?></option><?php endforeach; ?></select>
            </label>
            <label><span class="sr-only">Puesto</span>
                <select id="users-puesto" class="input"><option value="">Todos los puestos</option><?php foreach ($puestos as $value => $puestoName): ?><option value="<?= $value ?>"><?= escape($puestoName) ?></option><?php endforeach; ?></select>
            </label>
            <label><span class="sr-only">Rol</span>
                <select id="users-role" class="input"><option value="">Todos los roles</option><?php foreach ($roleLabels as $value => $roleName): ?><option value="<?= $value ?>"><?= escape($roleName) ?></option><?php endforeach; ?></select>
            </label>
            <label><span class="sr-only">Estado</span>
                <select id="users-status" class="input"><option value="">Todos los estados</option><option value="1">Activos</option><option value="0">Desactivados</option></select>
            </label>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[60rem] text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-5 py-3">Empleado</th><th class="px-3 py-3">Área</th><th class="px-3 py-3">Rol en tickets</th><th class="px-3 py-3">Puesto y accesos</th><th class="px-3 py-3">Estado</th><th class="px-3 py-3">Tickets</th><th class="px-5 py-3 text-right">Acciones</th></tr></thead>
                <tbody class="divide-y divide-slate-100" id="user-list" data-paginar>
                <?php foreach ($employees ?? [] as $employee):
                    $name = $employee['nombre_usuario'] ?: $employee['correo_usuario'];
                    $role = isset($roleLabels[$employee['rol']]) ? $employee['rol'] : 'empleado';
                    $active = (int)$employee['activo'] === 1;
                    $self = (int)$employee['id'] === (int)$_SESSION['user']['id'];
                    $protected = $self || $role === 'superusuario';
                    $locked = !$super && $role === 'superusuario';
                    $hasHistory = (int)$employee['tickets'] + (int)$employee['movimientos'] + (int)$employee['asistencia'] > 0;
                    $noPassword = (int)$employee['sin_contrasena'] === 1;
                    // Accesos tal como los calcula la intranet a partir del rol y el puesto (comun/acceso.php).
                    $apps = intranet_apps($employee + ['activo' => 1]);
                    $isAdmin = in_array($role, ['superusuario', 'supervision'], true);
                    $data = ['id' => (int)$employee['id'], 'nombre' => (string)$employee['nombre_usuario'], 'correo' => (string)$employee['correo_usuario'], 'area' => (string)$employee['area'],
                        'jefe' => (string)$employee['jefe_directo'], 'correo_jefe' => (string)$employee['correo_jefe'], 'rol' => $role, 'yo' => $self,
                        'puesto' => (string)$employee['puesto'], 'fecha_ingreso' => (string)$employee['fecha_ingreso']];
                ?>
                    <tr class="user-item <?= $active ? '' : 'bg-slate-50 text-slate-400' ?>" data-user="<?= escape(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>" data-active="<?= $active ? '1' : '0' ?>"
                        data-area="<?= escape(mb_strtolower(trim((string)$employee['area']))) ?>" data-puesto="<?= escape((string)$employee['puesto']) ?>" data-rol="<?= $role ?>"
                        data-search="<?= escape(mb_strtolower($name . ' ' . $employee['correo_usuario'] . ' ' . $employee['area'] . ' ' . $roleLabels[$role])) ?>">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full text-sm font-semibold <?= $active ? 'bg-brand-50 text-brand-700' : 'bg-slate-200 text-slate-500' ?>" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                                <div class="min-w-0"><p class="truncate font-medium <?= $active ? 'text-slate-900' : 'text-slate-500' ?>"><?= escape($name) ?><?= $self ? ' <span class="text-xs font-normal text-slate-400">(tú)</span>' : '' ?></p><p class="truncate text-xs text-slate-500"><?= escape((string)$employee['correo_usuario']) ?></p></div>
                            </div>
                        </td>
                        <td class="px-3 py-3"><?= escape($employee['area'] ?: 'Sin área') ?></td>
                        <td class="px-3 py-3"><span class="tag <?= $role === 'empleado' ? '' : ($role === 'superusuario' ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-700') ?>"><?= escape($roleLabels[$role]) ?></span></td>
                        <td class="px-3 py-3">
                            <p class="text-sm text-slate-700"><?= escape($isAdmin ? 'Administra por su rol' : ($role === 'soporte' ? 'Soporte' : $puestos[$employee['puesto']] . ($role === 'gerente' ? ($employee['puesto'] === 'oficina' ? ' · gerente' : ' · gerente de ventas') : ''))) ?></p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <?php foreach (['tickets' => 'Tickets', 'asistencia' => 'Asistencia', 'incidencias' => 'Incidencias'] as $app => $appName): if (!$apps[$app]) continue; ?>
                                <span class="tag"><?= $appName ?></span>
                                <?php endforeach; ?>
                                <?php if ($isAdmin && in_array(strtolower((string)$employee['correo_usuario']), $validators ?? [], true)): ?><span class="tag bg-emerald-50 text-emerald-700" title="Confirma incidencias (asistencia/config.php > mail > cc)">Valida incidencias</span><?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                <?= $active ? '<span class="badge status-1">Activo</span>' : '<span class="badge bg-slate-100 text-slate-500 ring-slate-300">Desactivado</span>' ?>
                                <?php if ($noPassword): ?><span class="badge status-0" title="Creará su contraseña con el enlace que recibe al escribir su correo y dar Enter">Sin contraseña</span><?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3 py-3 tabular-nums"><?= (int)$employee['tickets'] ?></td>
                        <td class="px-5 py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn-icon" data-edit <?= $locked ? 'disabled title="Solo el superusuario modifica esta cuenta"' : 'title="Editar datos, rol o contraseña"' ?> aria-label="Editar a <?= escape($name) ?>">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="pointer-events-none size-4" aria-hidden="true"><path d="M13.5 3.5l3 3L7 16H4v-3z" stroke-linejoin="round"/></svg>
                                </button>
                                <form action="usuarios.php" method="post" data-confirm="<?= escape('¿Enviar a ' . $employee['correo_usuario'] . ' un enlace para ' . ($noPassword ? 'crear' : 'restablecer') . ' su contraseña?') ?>">
                                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= (int)$employee['id'] ?>"><input type="hidden" name="accion" value="enviar_enlace">
                                    <button class="btn-icon hover:bg-brand-50 hover:text-brand-700" <?= $active && $canSendLinks && !$locked ? '' : 'disabled' ?>
                                        title="<?= !$active ? 'Reactiva la cuenta para enviarle un enlace' : (!$canSendLinks ? 'Configura INTRANET_URL y el correo para enviar enlaces' : 'Enviar enlace para ' . ($noPassword ? 'crear' : 'restablecer') . ' su contraseña') ?>" aria-label="Enviar enlace de contraseña a <?= escape($name) ?>">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="pointer-events-none size-4" aria-hidden="true"><circle cx="6.5" cy="12.5" r="3.5"/><path d="m9 10 7-7M13.5 5.5l1.5 1.5M11.5 7.5 13 9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                </form>
                                <form action="usuarios.php" method="post" data-confirm="<?= escape($active ? '¿Desactivar a ' . $name . '? Ya no podrá iniciar sesión, pero se conserva su historial.' : '¿Reactivar a ' . $name . '?') ?>">
                                    <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= (int)$employee['id'] ?>"><input type="hidden" name="accion" value="<?= $active ? 'desactivar' : 'activar' ?>">
                                    <button class="btn-icon <?= $active ? 'hover:bg-amber-50 hover:text-amber-700' : 'hover:bg-emerald-50 hover:text-emerald-700' ?>" <?= $protected ? 'disabled' : '' ?>
                                        title="<?= $protected ? 'No se puede desactivar tu cuenta ni la de un superusuario' : ($active ? 'Desactivar' : 'Reactivar') ?>" aria-label="<?= $active ? 'Desactivar' : 'Reactivar' ?> a <?= escape($name) ?>">
                                        <?php if ($active): ?><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="pointer-events-none size-4" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="m5 5 10 10"/></svg>
                                        <?php else: ?><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="pointer-events-none size-4" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?>
                                    </button>
                                </form>
                                <button type="button" class="btn-icon hover:bg-red-50 hover:text-red-600" data-delete <?= $protected || $hasHistory || !in_array($role, ['empleado', 'gerente'], true) ? 'disabled' : '' ?>
                                    title="<?= $protected ? 'No se puede eliminar tu cuenta ni la de un superusuario' : (!in_array($role, ['empleado', 'gerente'], true) ? 'Solo se eliminan cuentas con rol Empleado o Gerente; cambia el rol o desactívala' : ($hasHistory ? 'Tiene tickets, registros de asistencia o incidencias: desactívalo para conservar el historial' : 'Eliminar')) ?>" aria-label="Eliminar a <?= escape($name) ?>">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="pointer-events-none size-4" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="py-10 text-center text-sm text-slate-400" id="users-empty" hidden>No hay empleados que coincidan con la búsqueda.</p>
        </div>
    </section>

    <?php if ($super): ?>
    <section class="card overflow-hidden" aria-labelledby="log-title">
        <div class="border-b border-slate-100 p-5">
            <h2 id="log-title" class="font-semibold text-slate-900">Bitácora <span class="text-sm font-normal text-slate-400">· últimos 150 eventos</span></h2>
            <p class="mt-1 text-sm text-slate-500">Los eventos en rojo pueden indicar un intento de acceso indebido. La bitácora completa está en la tabla <code class="text-xs">auditoria</code>.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-5 py-3">Fecha</th><th class="px-3 py-3">Usuario</th><th class="px-3 py-3">Evento</th><th class="px-3 py-3">Detalle</th><th class="px-5 py-3">IP</th></tr></thead>
                <tbody class="divide-y divide-slate-100" data-paginar>
                <?php foreach ($events ?? [] as $event): $alert = in_array($event['accion'], $alerts, true); ?>
                    <tr class="<?= $alert ? 'bg-red-50/60' : '' ?>">
                        <td class="px-5 py-2.5 whitespace-nowrap text-slate-500 tabular-nums"><?= escape(date('d/m/Y H:i:s', strtotime($event['fecha']))) ?></td>
                        <td class="px-3 py-2.5 text-slate-700"><?= escape((string)($event['correo'] ?? '—')) ?></td>
                        <td class="px-3 py-2.5 font-medium <?= $alert ? 'text-red-700' : 'text-slate-900' ?>"><?= escape($labels[$event['accion']] ?? $event['accion']) ?></td>
                        <td class="px-3 py-2.5 text-slate-500"><?= escape((string)($event['detalle'] ?? '')) ?></td>
                        <td class="px-5 py-2.5 text-slate-400 tabular-nums"><?= escape($event['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($events)): ?><tr><td colspan="5" class="px-5 py-10 text-center text-slate-400">Aún no hay eventos registrados.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</main>
<dialog class="modal max-w-xl" id="user-form" aria-labelledby="user-form-title" data-super="<?= $super ? '1' : '0' ?>" data-password-min="<?= PASSWORD_MIN ?>">
    <form action="usuarios.php" method="post" class="flex min-h-0 flex-auto flex-col">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="id">
        <div class="modal-header">
            <div><p class="eyebrow" data-mode-label>Nuevo empleado</p><h2 id="user-form-title" class="mt-0.5 text-xl font-bold text-slate-900" data-mode-title>Agregar empleado</h2></div>
            <button type="button" class="btn-icon" data-close aria-label="Cerrar"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="pointer-events-none size-5" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/></svg></button>
        </div>
        <div class="modal-body grid gap-4 sm:grid-cols-2 [&_input]:mt-1.5">
            <label class="field-label sm:col-span-2">Nombre completo *<input name="nombre" required maxlength="200" class="input" autocomplete="off"></label>
            <label class="field-label">Correo *<input type="email" name="correo" required maxlength="200" class="input" autocomplete="off"></label>
            <label class="field-label">Área *<input name="area" required maxlength="50" list="area-options" class="input" autocomplete="off"></label>
            <label class="field-label sm:col-span-2">Jefe directo
                <select name="correo_jefe" class="input mt-1.5">
                    <option value="">Sin jefe directo</option>
                    <?php foreach (['Dirección y Administración' => true, 'Gerentes' => false] as $group => $direction): ?>
                        <optgroup label="<?= $group ?>">
                            <?php foreach ($bosses ?? [] as $boss): if ((bool)$boss['direccion'] !== $direction) continue; ?>
                                <option value="<?= escape($boss['correo']) ?>"><?= escape($boss['nombre'] . ' · ' . $boss['correo']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="help-text -mt-2 sm:col-span-2">Aparecen Cesar, Brenda, Angélica (quien valida incidencias) y quienes tienen el rol Gerente. Sus solicitudes de incidencias le llegan primero a esta persona.</p>
            <label class="field-label sm:col-span-2">Rol
                <select name="rol" class="input mt-1.5">
                    <?php foreach ($roleLabels as $value => $roleName): ?><option value="<?= $value ?>"><?= escape($roleName) ?></option><?php endforeach; ?>
                </select>
            </label>
            <p class="help-text -mt-2 sm:col-span-2" data-role-help>Empleado: lo de su puesto · Gerente: además ve a su equipo (quienes lo tienen como jefe directo) en incidencias; si su puesto es representante o dermoconsejera (gerente de ventas), también en asistencia · Soporte: solo el panel de soporte · Supervisión: reportes, administración de asistencia e incidencias y usuarios · Superusuario: todo + Seguridad. Dar o quitar Superusuario pide un código enviado a tu correo.</p>
            <label class="field-label sm:col-span-2"><span data-password-label>Contraseña inicial (opcional)</span><input type="password" name="contrasena" minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" autocomplete="new-password" class="input"></label>
            <p class="help-text -mt-2 sm:col-span-2" data-password-help>Si la dejas vacía, la persona la crea con el enlace que recibe al escribir su correo y dar Enter.</p>
            <fieldset class="grid gap-4 border-t border-slate-100 pt-4 sm:col-span-2 sm:grid-cols-2">
                <legend class="sr-only">Asistencia e incidencias</legend>
                <p class="text-sm font-semibold text-slate-900 sm:col-span-2">Puesto e ingreso</p>
                <label class="field-label">Puesto
                    <select name="puesto" class="input mt-1.5" data-puesto>
                        <?php foreach ($puestos as $value => $puestoName): ?><option value="<?= $value ?>"><?= escape($puestoName) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field-label">Fecha de ingreso<input type="date" name="fecha_ingreso" class="input"></label>
                <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 sm:col-span-2" data-access-preview></p>
                <p class="help-text -mt-2 sm:col-span-2">Los accesos se asignan solos: Representante médico → Tickets, Asistencia e Incidencias · Dermoconsejera → Asistencia e Incidencias · Oficina → Tickets e Incidencias. La fecha de ingreso calcula los días de vacaciones; las solicitudes las autoriza primero el jefe directo y las confirma Administración.</p>
            </fieldset>
            <datalist id="area-options"><?php foreach (array_unique(array_filter(array_column($employees ?? [], 'area'))) as $area): ?><option value="<?= escape($area) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close>Cancelar</button>
            <button type="submit" class="btn btn-primary" data-mode-submit>Agregar empleado</button>
        </div>
    </form>
</dialog>
<dialog class="modal max-w-md" id="wipe-modal" aria-labelledby="wipe-title">
    <form action="usuarios.php" method="post">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="borrar_contrasenas">
        <div class="px-6 pt-6 pb-5">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-red-100 text-red-600" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><path d="M12 8v5M12 16.5h.01M10.3 3.9 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <h2 id="wipe-title" class="mt-4 text-center text-lg font-semibold text-slate-900">¿Borrar las contraseñas de todos los empleados?</h2>
            <p class="mt-2 text-center text-sm text-slate-500">Solo afecta a cuentas con rol <strong>Empleado</strong> (soporte, supervisión y superusuario conservan la suya). La próxima vez que entren escribirán su correo, darán Enter y crearán una contraseña nueva con el enlace que les llegue. No se puede deshacer.</p>
            <label class="field-label mt-5">Escribe <strong>BORRAR</strong> para confirmar<input name="confirmacion" required pattern="BORRAR" autocomplete="off" class="input mt-1.5"></label>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close>Cancelar</button>
            <button type="submit" class="btn btn-danger">Borrar contraseñas</button>
        </div>
    </form>
</dialog>
<dialog class="modal max-w-md" id="user-delete" aria-labelledby="user-delete-title">
    <form action="usuarios.php" method="post">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="id">
        <div class="px-6 pt-6 pb-5 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-red-100 text-red-600" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><path d="M12 8v5M12 16.5h.01M10.3 3.9 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <h2 id="user-delete-title" class="mt-4 text-lg font-semibold text-slate-900">¿Eliminar a <span data-name></span>?</h2>
            <p class="mt-2 text-sm text-slate-500">La cuenta se borrará de forma permanente. Si solo quieres impedir que entre, mejor desactívala.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close>Cancelar</button>
            <button type="submit" class="btn btn-danger">Sí, eliminar</button>
        </div>
    </form>
</dialog>
</body>
</html>
