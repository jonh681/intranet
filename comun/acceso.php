<?php
declare(strict_types=1);

/*
 * Quién entra a qué en la intranet. Un solo lugar para las reglas; nadie las elige a mano: salen del rol y el puesto
 * de la cuenta (tabla usuarios: rol y puesto, se asignan en Usuarios → Editar).
 *
 *   Puesto de un empleado o gerente   Tickets   Asistencia            Incidencias   Cotizaciones
 *     Representante médico              sí       registra jornada       sí            cotiza y envía pedidos
 *     Dermoconsejera                    —        registra jornada       sí            —
 *     Oficina                           sí       —                      sí            —
 *
 *   Rol
 *     empleado      lo de su puesto.
 *     gerente       lo de su puesto + ve a su equipo en asistencia y en incidencias (su equipo = quienes lo tienen como jefe directo).
 *     soporte       solo su panel de soporte en tickets.
 *     supervision   reportes de tickets, administra asistencia e incidencias y la pestaña Usuarios.
 *     superusuario  todo, además Seguridad y la administración de Cotizaciones (productos, lista de precios y clientes).
 *
 * Las incidencias las autoriza primero el jefe directo (correo_jefe) y las confirma quien valida: los correos de
 * asistencia/config.php > mail > cc (Administración).
 */

/** Puestos y su nombre en pantalla. */
function intranet_puestos(): array
{
    return ['oficina' => 'Oficina', 'dermoconsejera' => 'Dermoconsejera', 'representante' => 'Representante médico'];
}

/** Nombre del sistema: encabezado, títulos de las páginas y correos. */
const INTRANET_NOMBRE = 'Fedele 360';

/** Puestos que registran jornada en asistencia. */
const INTRANET_REGISTRAN_ASISTENCIA = ['representante', 'dermoconsejera'];

/** Registra su jornada en asistencia (empleado o gerente con puesto de campo). */
function intranet_registra_asistencia(array $u): bool
{
    return (int)$u['activo'] === 1 && in_array($u['rol'], ['empleado', 'gerente'], true) && in_array($u['puesto'], INTRANET_REGISTRAN_ASISTENCIA, true);
}

/** Rol efectivo en asistencia ('empleado', 'gerente' o 'admin') o null si no tiene acceso. $u: fila de usuarios. */
function intranet_rol_asistencia(array $u): ?string
{
    if ((int)$u['activo'] !== 1) return null;
    if (in_array($u['rol'], ['superusuario', 'supervision'], true)) return 'admin';
    // Solo el gerente de ventas (de campo: representante o dermoconsejera) ve a su equipo en asistencia;
    // un gerente de oficina no entra a asistencia (a su equipo lo ve en Incidencias).
    if (!intranet_registra_asistencia($u)) return null;
    return $u['rol'] === 'gerente' ? 'gerente' : 'empleado';
}

/** Rol en incidencias ('empleado', 'gerente' o 'admin') o null: todo el personal activo, menos soporte. */
function intranet_rol_incidencias(array $u): ?string
{
    if ((int)$u['activo'] !== 1 || $u['rol'] === 'soporte') return null;
    if (in_array($u['rol'], ['superusuario', 'supervision'], true)) return 'admin';
    return $u['rol'] === 'gerente' ? 'gerente' : 'empleado';
}

/**
 * Rol en cotizaciones ('representante' o 'admin') o null. Solo los representantes médicos (empleado o gerente con puesto
 * de representante) cotizan y envían pedidos; el superusuario además administra productos, lista de precios y clientes.
 */
function intranet_rol_cotizaciones(array $u): ?string
{
    if ((int)$u['activo'] !== 1) return null;
    if ($u['rol'] === 'superusuario') return 'admin';
    return in_array($u['rol'], ['empleado', 'gerente'], true) && $u['puesto'] === 'representante' ? 'representante' : null;
}

/** Ve Tickets: todos, menos las dermoconsejeras. */
function intranet_ve_tickets(array $u): bool
{
    return (int)$u['activo'] === 1 && !(in_array($u['rol'], ['empleado', 'gerente'], true) && $u['puesto'] === 'dermoconsejera');
}

/** Dirección: siempre se pueden elegir como jefe directo, además de los gerentes (Cesar Jordan y Brenda Quintanar). */
const INTRANET_DIRECCION = ['cjordan@fedele.com.mx', 'bquintanar@fedele.com.mx'];

/**
 * Quienes se pueden elegir como jefe directo en Usuarios: Dirección (Cesar y Brenda), quien valida incidencias (Angélica,
 * ver intranet_validadores()) y las cuentas activas con rol Gerente. 'direccion' = 1 para los dos primeros grupos.
 */
function intranet_jefes(PDO $db): array
{
    $top = array_values(array_unique([...INTRANET_DIRECCION, ...intranet_validadores()]));
    $in = implode(',', array_fill(0, count($top), '?'));
    $query = $db->prepare("SELECT nombre_usuario nombre, LOWER(correo_usuario) correo, LOWER(correo_usuario) IN ($in) direccion FROM usuarios
        WHERE activo = 1 AND (rol = 'gerente' OR LOWER(correo_usuario) IN ($in)) ORDER BY direccion DESC, nombre_usuario");
    $query->execute([...$top, ...$top]);
    return $query->fetchAll();
}

/** Correos de quienes confirman (validan) incidencias: asistencia/config.php > mail > cc, separados por comas. */
function intranet_validadores(): array
{
    if (!function_exists('appConfig')) require_once __DIR__ . '/../asistencia/db.php';
    $cc = (string)(appConfig()['mail']['cc'] ?? '');
    return array_values(array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', $cc)), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
}

/** Puede aprobar o rechazar incidencias: administración (supervisión o superusuario) con su correo entre los validadores. */
function intranet_valida_incidencias(array $u): bool
{
    return intranet_rol_incidencias($u) === 'admin' && in_array(strtolower((string)$u['correo_usuario']), intranet_validadores(), true);
}

/**
 * Huella de la contraseña guardada en la sesión al entrar (login_complete). Si la contraseña cambia (se restablece,
 * se crea o la cambia un administrador), las sesiones abiertas con la anterior dejan de valer en su siguiente clic.
 */
function intranet_huella_contrasena(?string $hash): string
{
    return hash('sha256', 'sesion|' . (string)$hash);
}

/** La sesión se abrió con la contraseña que la cuenta tiene ahora. */
function intranet_sesion_vigente(?string $hash): bool
{
    return hash_equals((string)($_SESSION['huella'] ?? ''), intranet_huella_contrasena($hash));
}

/** Fila de usuarios de la persona con sesión, o null (sin sesión, o la contraseña cambió desde que entró). */
function intranet_usuario(PDO $db): ?array
{
    $id = (int)($_SESSION['user']['id'] ?? 0);
    if ($id <= 0) return null;
    $query = $db->prepare('SELECT id, nombre_usuario, correo_usuario, area, puesto, jefe_directo, correo_jefe, activo, rol, fecha_ingreso, contrasena_usuario FROM usuarios WHERE id = ?');
    $query->execute([$id]);
    $u = $query->fetch(PDO::FETCH_ASSOC);
    if (!$u || !intranet_sesion_vigente($u['contrasena_usuario'])) return null;
    unset($u['contrasena_usuario']);
    return $u;
}

/** Apps visibles para la persona: ['tickets' => bool, 'asistencia' => bool, 'incidencias' => bool, 'cotizaciones' => bool, 'seguridad' => bool]. */
function intranet_apps(array $u, bool $incidenciasActivas = true): array
{
    $active = (int)$u['activo'] === 1;
    return ['tickets' => intranet_ve_tickets($u), 'asistencia' => intranet_rol_asistencia($u) !== null,
        'incidencias' => $incidenciasActivas && intranet_rol_incidencias($u) !== null,
        'cotizaciones' => intranet_rol_cotizaciones($u) !== null,
        'seguridad' => $active && in_array($u['rol'], ['superusuario', 'supervision'], true)];
}

/** Primera página que puede ver (ruta relativa a la carpeta de la intranet), o null si ninguna. */
function intranet_inicio(array $u, bool $incidenciasActivas = true): ?string
{
    $apps = intranet_apps($u, $incidenciasActivas);
    if ($apps['tickets']) return 'tickets/';
    if ($apps['asistencia']) {
        $rol = intranet_rol_asistencia($u);
        return $rol === 'admin' ? 'asistencia/admin.php' : (intranet_registra_asistencia($u) ? 'asistencia/' : 'asistencia/equipo.php');
    }
    return $apps['incidencias'] ? 'incidencias/' : null;
}

/** Modo desarrollo: entrar eligiendo el tipo de usuario, sin contraseña. Solo con INTRANET_DEV=1 y desde esta misma computadora. */
function intranet_dev(): bool
{
    if (!function_exists('env')) require_once __DIR__ . '/../tickets/php/env.php';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // Detrás de un proxy o túnel en la misma máquina todo llega desde 127.0.0.1: si la petición trae encabezados
    // de reenvío o un Host que no es local, no viene de esta computadora.
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP',
        'HTTP_VIA', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_CLIENT_IP'] as $header) {
        if (isset($_SERVER[$header])) return false;
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return env('INTRANET_DEV') === '1' && in_array($ip, ['127.0.0.1', '::1'], true)
        && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}
