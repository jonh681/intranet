<?php
// Utilidades de vista compartidas por index.php y admin.php.

function esc(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

// Ruta de un archivo de assets/ con su versión (fecha de modificación): al publicar cambios,
// el navegador descarga el archivo nuevo en lugar de usar el que tenía guardado.
function asset(string $file): string {
    $path = __DIR__ . '/assets/' . $file;
    return 'assets/' . $file . (is_file($path) ? '?v=' . filemtime($path) : '');
}

// Iconos Lucide (https://lucide.dev), licencia ISC.
function icon(string $name, string $class = 'size-5'): string {
    $paths = [
        'log-in' => '<path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
        'log-out' => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
        'map-pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'utensils' => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'list' => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'check' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
        'timer' => '<path d="M10 2h4"/><path d="M12 14l3-3"/><circle cx="12" cy="14" r="8"/>',
        'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'search' => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'layout' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'user-x' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5"/><path d="m22 8-5 5"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'trash' => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'stethoscope' => '<path d="M11 2v2"/><path d="M5 2v2"/><path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>',
        'file-sheet' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M8 17h2"/><path d="M14 17h2"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'plane' => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
        'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'heart-pulse' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>',
        'paperclip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'circle-alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'calendar-check' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
        'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    ];
    return '<svg class="' . $class . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

// Corta la petición si no hay sesión (redirige al login) o si el usuario no puede ver la página.
// $allowManager: los gerentes también entran (en modo lectura, con datos solo de su equipo).
function requireAdminPage(PDO $db, bool $allowManager = false): array {
    $user = currentUser($db);
    if (!$user) {
        header('Location: ./');
        exit;
    }
    if ($user['rol'] !== 'admin' && !($allowManager && $user['rol'] === 'gerente')) {
        http_response_code(403);
        exit('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Asistencia | Fedele 360</title><link rel="stylesheet" href="' . esc(asset('app.css')) . '"><body class="min-h-screen grid place-items-center bg-base-200 text-base-content font-sans p-4"><div class="card bg-base-100 border border-base-300 shadow-sm max-w-sm"><div class="card-body"><h1 class="text-xl font-bold">Acceso restringido</h1><p class="text-base-content/60">Esta sección es solo para administradores.</p><a class="btn btn-primary mt-2" href="./">Volver a mi jornada</a></div></div></body></html>');
    }
    return $user;
}

// Página "Mi equipo": solo para gerentes.
function requireManagerPage(PDO $db): array {
    $user = currentUser($db);
    if (!$user) {
        header('Location: ./');
        exit;
    }
    if ($user['rol'] !== 'gerente') {
        http_response_code(403);
        exit('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Asistencia | Fedele 360</title><link rel="stylesheet" href="' . esc(asset('app.css')) . '"><body class="min-h-screen grid place-items-center bg-base-200 text-base-content font-sans p-4"><div class="card bg-base-100 border border-base-300 shadow-sm max-w-sm"><div class="card-body"><h1 class="text-xl font-bold">Acceso restringido</h1><p class="text-base-content/60">Esta sección es solo para gerentes.</p><a class="btn btn-primary mt-2" href="./">Volver a mi jornada</a></div></div></body></html>');
    }
    return $user;
}

// Encabezado único de la intranet (comun/barra.php) con las secciones de asistencia.
// $sections: [[archivo, clave, etiqueta], ...]; $active: clave de la sección actual.
function intranetBar(array $sections = [], string $active = ''): string {
    require_once __DIR__ . '/../comun/barra.php';
    $items = array_map(fn($s) => ['href' => $s[0], 'label' => $s[2], 'active' => $s[1] === $active], $sections);
    return intranet_barra($GLOBALS['db'], 'asistencia', '../', $items);
}

// Encabezado de las páginas personales: Mi jornada (quien registra jornada) y, para gerentes, Mi equipo.
// (Vacaciones, permisos e incapacidades viven en la pestaña Incidencias de la intranet.)
function userHeader(array $user, string $active): string {
    $sections = $user['registra'] ? [['./', 'jornada', 'Mi jornada']] : [];
    if ($user['rol'] === 'gerente') $sections[] = ['equipo.php', 'equipo', 'Mi equipo'];
    return intranetBar($sections, $active);
}

// Encabezado de la administración (Supervisión y superusuario): Asistencia y Visitas.
// (Vacaciones están en Incidencias y las cuentas en Usuarios/Seguridad.)
function adminHeader(array $user, string $active): string {
    return intranetBar([['admin.php', 'asistencia', 'Asistencia'], ['visitas.php', 'visitas', 'Visitas'], ['consumo.php', 'consumo', 'Consumo de datos']], $active);
}

// Barra de filtros de Asistencia y Visitas: periodo, actualizar, reporte, búsqueda, área y persona.
function adminFilters(string $searchPlaceholder, string $employeeLabel): string {
    $preset = fn(string $key, string $label) => '<button class="btn btn-sm join-item" type="button" data-preset="' . $key . '">' . $label . '</button>';
    $report = fn(string $key, string $label, string $hint) => '<li><button type="button" data-report="' . $key . '" class="flex flex-col items-start gap-0"><span class="font-medium">' . $label . '</span><span class="text-xs text-base-content/55">' . $hint . '</span></button></li>';
    return '<section class="card bg-base-100 border border-base-300 shadow-sm" aria-label="Filtros">
        <div class="card-body p-4 gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="join" role="group" aria-label="Periodo">' . $preset('today', 'Hoy') . $preset('yesterday', 'Ayer') . $preset('week', 'Semana') . $preset('month', 'Mes') . '</div>
                <div class="flex items-center gap-2">
                    <label class="input input-sm w-auto">' . icon('calendar', 'size-4 opacity-50') . '<input type="date" id="from" aria-label="Desde"></label>
                    <span class="text-base-content/40 text-sm">a</span>
                    <label class="input input-sm w-auto"><input type="date" id="to" aria-label="Hasta"></label>
                </div>
                <div class="flex gap-2 ml-auto">
                    <button class="btn btn-sm btn-ghost btn-square" id="refresh" type="button" aria-label="Actualizar">' . icon('refresh', 'size-4') . '</button>
                    <details class="dropdown dropdown-end" id="report-menu">
                        <summary class="btn btn-sm btn-primary gap-2">' . icon('download', 'size-4') . 'Descargar reporte' . icon('chevron-down', 'size-3.5 opacity-70') . '</summary>
                        <ul class="dropdown-content menu bg-base-100 rounded-box border border-base-300 shadow-xl z-20 w-72 p-2 mt-2">
                            <li class="menu-title flex-row items-center gap-2">' . icon('file-sheet', 'size-4') . 'Excel con los filtros actuales</li>'
                            . $report('attendance', 'Asistencia', 'Jornadas: entrada, comida, salida y horas')
                            . $report('visits', 'Visitas', 'Médico, inicio, fin, duración y ubicaciones')
                            . $report('both', 'Asistencia y visitas', 'Un archivo con una hoja para cada una') . '
                        </ul>
                    </details>
                </div>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,13rem)_minmax(0,16rem)] gap-2">
                <label class="input input-sm w-full sm:col-span-2 lg:col-span-1">' . icon('search', 'size-4 opacity-50') . '<input type="search" id="search" placeholder="' . esc($searchPlaceholder) . '" autocomplete="off"></label>
                <select class="select select-sm w-full" id="area" aria-label="Área"><option value="">Todas las áreas</option></select>
                <select class="select select-sm w-full" id="employee" aria-label="' . esc($employeeLabel) . '"><option value="">' . esc($employeeLabel) . '</option></select>
            </div>
        </div>
    </section>';
}
