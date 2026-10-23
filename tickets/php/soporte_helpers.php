<?php
declare(strict_types=1);

/*
 * Roles (columna usuarios.rol, se asigna en Usuarios → Editar; lo que ve cada uno en toda la intranet: comun/acceso.php):
 *   empleado     crea y ve sus propios tickets.
 *   gerente      en tickets, igual que empleado; además ve a su equipo en asistencia e incidencias.
 *   soporte      además atiende todos los tickets (panel de soporte).
 *   supervision  además ve el tablero, los reportes y todos los tickets (solo lectura).
 *   superusuario todo lo de supervisión + página Seguridad y administración de empleados y roles.
 * El rol se lee de la sesión, que require_ticket_user() actualiza desde la base en cada página.
 */
function role_labels(): array
{
    return ['empleado' => 'Empleado', 'gerente' => 'Gerente', 'soporte' => 'Soporte', 'supervision' => 'Supervisión', 'superusuario' => 'Superusuario'];
}

function current_role(): string
{
    $role = (string)($_SESSION['user']['rol'] ?? 'empleado');
    return isset(role_labels()[$role]) ? $role : 'empleado';
}

function is_support(): bool
{
    return current_role() === 'soporte';
}

function is_supervisor(): bool
{
    return in_array(current_role(), ['supervision', 'superusuario'], true);
}

function is_superuser(): bool
{
    return current_role() === 'superusuario';
}

/**
 * Administra las cuentas (pestaña Usuarios / Seguridad → Empleados): el superusuario y Supervisión.
 * Supervisión no asigna el rol Superusuario ni modifica cuentas de superusuario (ver modulos/usuarios.php).
 */
function can_manage_users(): bool
{
    return in_array(current_role(), ['superusuario', 'supervision'], true);
}

function asks_activity_stop(): bool
{
    // La pregunta de actividades detenidas se le hace a todos los usuarios con sesión.
    return trim($_SESSION['user']['email'] ?? '') !== '';
}

function activity_stop_label(?int $value): string
{
    return $value === null ? '' : ($value === 1 ? 'Sí, sus actividades se detienen' : 'No, sus actividades continúan');
}

function user_home(): string
{
    // El superusuario también es supervisor (is_supervisor): entra a Reportes, igual que supervisión.
    if (is_supervisor()) return 'supervision.php';
    if (is_support()) return 'soporte.php';
    return 'tickets.php';
}

function support_status(int $status): string
{
    return [0 => 'Pendiente', 2 => 'En atención', 1 => 'Resuelto'][$status] ?? 'Sin clasificar';
}

/** Badge de estado con los estilos status-0/1/2 de css/tailwind.css. */
function status_badge(int $status): string
{
    return '<span class="badge status-' . (in_array($status, [0, 1, 2], true) ? $status : 0) . '">' . escape(support_status($status)) . '</span>';
}

/** Encabezado de las páginas internas: el de toda la intranet (comun/barra.php) con las secciones de tickets. */
function app_header(string $current): void
{
    // Seguridad es una pestaña propia de la intranet; sus páginas no llevan las secciones de tickets.
    $security = $current === 'seguridad.php';
    // Soporte solo trabaja en su panel: no tiene "Mis tickets" (ver tickets.php).
    $links = $security || is_support() ? [] : ['tickets.php' => 'Mis tickets'];
    if (!$security && is_support()) $links['soporte.php'] = 'Panel de soporte';
    if (!$security && is_supervisor()) $links['supervision.php'] = 'Reportes';
    $sections = [];
    foreach ($links as $file => $label) $sections[] = ['href' => $file, 'label' => $label, 'active' => $file === $current];
    require_once __DIR__ . '/../../comun/barra.php';
    echo intranet_barra(database(), $security ? 'seguridad' : 'tickets', '../../', $sections);
}
