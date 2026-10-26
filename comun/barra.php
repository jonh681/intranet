<?php
declare(strict_types=1);

require_once __DIR__ . '/acceso.php';
require_once __DIR__ . '/../asistencia/db.php'; // feature(): si las vacaciones están encendidas en este servidor

/*
 * Navegación única de la intranet, igual en todas las páginas (estilos en comun/barra.css, sin depender del CSS de cada app):
 *   1. Riel de apps (a la izquierda; en celular, barra inferior al alcance del pulgar): «Fedele 360» y las apps a las que
 *      tiene acceso (Tickets, Asistencia, Incidencias, Cotizaciones, Seguridad/Usuarios).
 *   2. Encabezado de la app (.ib-header, fijo arriba): nombre de la app, sus secciones (por ejemplo, Mis tickets y
 *      Reportes; con una sola sección no se muestran) y la persona con su rol y el botón para salir.
 *
 *   $activa     'tickets', 'asistencia', 'incidencias', 'cotizaciones' o 'seguridad'
 *   $raiz       ruta relativa de la página a la carpeta de la intranet ('../../' desde tickets/modulos, '../' desde asistencia)
 *   $secciones  [['href' => ..., 'label' => ..., 'active' => bool, 'badge' => int|null], ...] (rutas relativas a la página)
 */
function intranet_barra(PDO $db, string $activa, string $raiz, array $secciones = []): string
{
    $u = intranet_usuario($db);
    if (!$u) return '';
    $h = fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $svg = fn(string $paths, string $class = ''): string => '<svg' . ($class ? ' class="' . $class . '"' : '') . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    $apps = intranet_apps($u, feature('vacaciones'));
    $links = [
        'tickets' => ['Tickets', 'tickets/', '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>'],
        'asistencia' => ['Asistencia', 'asistencia/', '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>'],
        'incidencias' => ['Incidencias', 'incidencias/', '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>'],
        'cotizaciones' => ['Cotizaciones', 'cotizaciones/', '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>'],
        'seguridad' => ['Seguridad', 'tickets/modulos/seguridad.php', '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'],
    ];
    // Supervisión y superusuario no registran asistencia: la pestaña los lleva directo a la administración.
    if (in_array($u['rol'], ['superusuario', 'supervision'], true)) $links['asistencia'][1] = 'asistencia/admin.php';
    // Supervisión administra cuentas sin las protecciones ni la bitácora: para ellos la pestaña es «Usuarios».
    if ($u['rol'] !== 'superusuario') {
        $links['seguridad'][0] = 'Usuarios';
        $links['seguridad'][2] = '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>';
    }
    $visible = array_filter($links, fn($key) => $apps[$key], ARRAY_FILTER_USE_KEY);

    $tabs = '';
    foreach ($visible as $key => [$label, $href, $paths]) {
        $tabs .= '<a class="ib-tab" href="' . $h($raiz . $href) . '"' . ($key === $activa ? ' aria-current="page"' : '') . '>' . $svg($paths) . '<span>' . $h($label) . '</span></a>';
    }

    $name = trim((string)$u['nombre_usuario']) ?: (string)$u['correo_usuario'];
    $words = preg_split('/\s+/', $name) ?: [$name];
    $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    // Rol para los administradores; para los empleados, su puesto (el que define sus accesos).
    $role = ['superusuario' => 'Superusuario', 'soporte' => 'Soporte', 'supervision' => 'Supervisión'][$u['rol']]
        ?? (intranet_puestos()[$u['puesto']] ?? 'Empleado') . ($u['rol'] === 'gerente' ? ', gerente' : '');
    $dev = intranet_dev()
        ? '<a class="ib-dev" href="' . $h($raiz . 'dev.php') . '" title="Modo desarrollo: entrar como otro tipo de usuario">' . $svg('<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>') . '<span>Cambiar usuario</span></a>'
        : '';
    // Salir: el cierre de sesión de tickets (la sesión es la misma para toda la intranet).
    $logout = '<form class="ib-logout" action="' . $h($raiz . 'tickets/modulos/logout.php') . '" method="post">'
        . '<input type="hidden" name="csrf" value="' . $h((string)($_SESSION['csrf'] ?? '')) . '">'
        . '<button type="submit" title="Cerrar sesión" aria-label="Cerrar sesión">' . $svg('<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>') . '</button></form>';

    $sections = '';
    if (count($secciones) > 1) {
        foreach ($secciones as $s) {
            $badge = !empty($s['badge']) ? '<span class="ib-badge">' . (int)$s['badge'] . '</span>' : '';
            $sections .= '<a class="ib-section" href="' . $h($s['href']) . '"' . (!empty($s['active']) ? ' aria-current="page"' : '') . '>' . $h($s['label']) . $badge . '</a>';
        }
        $sections = '<nav class="ib-sections" aria-label="Secciones">' . $sections . '</nav>';
    }

    // acciones.js: avisos al guardar y botones que no se pueden presionar dos veces (toda la intranet).
    // consumo.js: mide los datos que usa la app en el dispositivo (reporte en asistencia/consumo.php).
    return '<link rel="stylesheet" href="' . $h($raiz . 'comun/barra.css') . '?v=' . (int)@filemtime(__DIR__ . '/barra.css') . '">'
        . '<script src="' . $h($raiz . 'comun/acciones.js') . '?v=' . (int)@filemtime(__DIR__ . '/acciones.js') . '" defer></script>'
        . '<script src="' . $h($raiz . 'comun/consumo.js') . '?v=' . (int)@filemtime(__DIR__ . '/consumo.js') . '" data-url="' . $h($raiz . 'comun/registrar_consumo.php')
        . '" data-csrf="' . $h((string)($_SESSION['csrf'] ?? '')) . '" defer></script>'
        . '<nav class="ib-rail' . (count($visible) < 2 ? ' ib-rail-solo' : '') . '" aria-label="Aplicaciones de la intranet">'
        . '<a class="ib-brand" href="' . $h($raiz) . '" aria-label="' . INTRANET_NOMBRE . ', inicio"><span class="ib-wordmark">Fedele</span><b class="ib-360">360</b></a>'
        . '<div class="ib-tabs">' . $tabs . '</div>'
        . '</nav>'
        . '<header class="ib-header">'
        . '<div class="ib">'
        . '<p class="ib-app">' . $h($links[$activa][0] ?? INTRANET_NOMBRE) . '</p>'
        . $sections
        . '<div class="ib-right">' . $dev
        . '<div class="ib-user"><span class="ib-who"><span class="ib-name">' . $h($name) . '</span><span class="ib-role">' . $h($role) . '</span></span>'
        . '<span class="ib-avatar" aria-hidden="true">' . $h($initials) . '</span></div>'
        . $logout . '</div>'
        . '</div>'
        . '</header>';
}
