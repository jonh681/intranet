<?php
declare(strict_types=1);

/*
 * Incidencias de la intranet: vacaciones, permisos e incapacidades en un solo lugar.
 * Usa la sesión y la base de asistencia (asistencia/bootstrap.php); las reglas están aquí (lib_vacaciones.php, lib_solicitudes.php).
 *
 * Datos:
 *   Vacaciones   vacaciones_solicitudes + vacaciones_dias (días hábiles; saldo por aniversario, ver lib_vacaciones.php)
 *   Permisos e incapacidades   incidencias + incidencia_fechas (con estado: pendiente, aprobada, rechazada, cancelada)
 * Solo lo aprobado justifica días en asistencia; lo que está en revisión ya aparta sus días para que no se encimen.
 * Validación en dos pasos (lib_solicitudes.php, de asistencia v2):
 *   pendiente (la autoriza el jefe directo) -> autorizada (la confirma quien valida incidencias) -> aprobada.
 *
 * Páginas por rol en incidencias (comun/acceso.php; todo el personal activo menos soporte):
 *   empleado  Mis incidencias
 *   gerente   + Incidencias de mi equipo (su equipo = quienes lo tienen como jefe directo)
 *   admin     + Por revisar, Todas las incidencias y Saldos de vacaciones (superusuario y supervisión entran como admin)
 */

require_once __DIR__ . '/../asistencia/bootstrap.php';
require_once __DIR__ . '/lib_vacaciones.php';
require_once __DIR__ . '/lib_solicitudes.php';
require_once __DIR__ . '/../asistencia/ui.php';
require_once __DIR__ . '/../comun/barra.php';

const INC_TYPES = ['vacaciones' => 'Vacaciones', 'permiso' => 'Permiso', 'incapacidad' => 'Incapacidad'];
const INC_STATUS = ['pendiente' => 'Con su jefe', 'autorizada' => 'Por confirmar', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'cancelada' => 'Cancelada'];
const INC_OPEN = ['pendiente', 'autorizada']; // en revisión: aún se pueden cancelar y ya apartan sus días
const INC_SICK_TYPES = ['Enfermedad general', 'Riesgo de trabajo', 'Maternidad'];

/** Páginas que puede ver un rol: archivo => etiqueta del submenú. */
function incPages(string $role, bool $hasTeam = false): array
{
    $pages = ['index.php' => 'Mis incidencias'];
    if ($role === 'gerente') $pages['equipo.php'] = 'Incidencias de mi equipo';
    if ($role === 'admin') $pages += ['revisar.php' => 'Por revisar', 'todas.php' => 'Todas las incidencias', 'saldos.php' => 'Saldos de vacaciones'];
    return $pages;
}

/** Usuario con acceso a incidencias o corta la petición (sin sesión: a la intranet; sin permiso: 403). */
/**
 * Persona con sesión en Incidencias (todo el personal activo menos soporte, registre o no jornada):
 * ['id', 'name', 'email', 'rol' => 'empleado'|'admin', 'valida'], o null. Ver comun/acceso.php.
 */
function incUser(PDO $db): ?array
{
    $u = intranet_usuario($db);
    $rol = $u ? intranet_rol_incidencias($u) : null;
    if ($rol === null) return null;
    return ['id' => (int)$u['id'], 'name' => $u['nombre_usuario'], 'email' => $u['correo_usuario'], 'rol' => $rol, 'valida' => intranet_valida_incidencias($u)];
}

function incRequire(PDO $db, string $page): array
{
    $user = incUser($db);
    if (!$user || !feature('vacaciones')) {
        header('Location: ../', true, 303);
        exit;
    }
    $user['equipo'] = subordinateIds($db, $user['id']);
    if (!isset(incPages($user['rol'], (bool)$user['equipo'])[$page])) {
        http_response_code(403);
        exit('Esta sección no está disponible para tu tipo de usuario.');
    }
    return $user;
}

/** Festivos oficiales del año anterior, el actual y el siguiente: fecha => nombre. */
function incHolidays(): array
{
    $year = (int)date('Y');
    return mxHolidays($year - 1) + mxHolidays($year) + mxHolidays($year + 1);
}

/**
 * Vacaciones, permisos e incapacidades en un solo formato, de la más reciente a la más antigua.
 * $userIds: solo esas personas (null = todas). $statuses: solo esos estados (null = todos).
 */
function incItems(PDO $db, ?array $userIds = null, ?array $statuses = null): array
{
    if ($userIds === []) return [];
    $people = $userIds === null ? '' : ' AND x.usuario_id IN (' . implode(',', array_map('intval', $userIds)) . ')';
    $states = $statuses === null ? '' : ' AND x.estado IN (' . implode(',', array_map(fn($s) => $db->quote($s), $statuses)) . ')';
    $items = [];
    // Jefe directo vigente (el mismo criterio que directManager(): su «Jefe directo» o, si no, su gerente de asistencia).
    $boss = 'LEFT JOIN usuarios g ON g.id = ' . bossSql('u');
    $who = 'u.nombre_usuario nombre, u.area, c.nombre_usuario creador, a.nombre_usuario autorizador, r.nombre_usuario revisor, g.nombre_usuario jefe';
    $vacations = $db->query("SELECT x.id, x.usuario_id, x.estado, x.dias, x.comentario, x.creado_en, x.creado_por, x.autorizado_en, x.revisado_en, x.motivo_revision,
            GROUP_CONCAT(d.fecha ORDER BY d.fecha) fechas, $who
        FROM vacaciones_solicitudes x JOIN usuarios u ON u.id = x.usuario_id LEFT JOIN vacaciones_dias d ON d.solicitud_id = x.id
        LEFT JOIN usuarios c ON c.id = x.creado_por LEFT JOIN usuarios a ON a.id = x.autorizado_por LEFT JOIN usuarios r ON r.id = x.revisado_por $boss
        WHERE 1 $people $states GROUP BY x.id");
    foreach ($vacations as $v) {
        $items[] = incItem($v, 'vacaciones', 'vacaciones', (int)$v['dias'], $v['comentario']);
    }
    $others = $db->query("SELECT x.*, GROUP_CONCAT(f.fecha ORDER BY f.fecha) fechas, $who
        FROM incidencias x JOIN usuarios u ON u.id = x.usuario_id LEFT JOIN incidencia_fechas f ON f.incidencia_id = x.id
        LEFT JOIN usuarios c ON c.id = x.creado_por LEFT JOIN usuarios a ON a.id = x.autorizado_por LEFT JOIN usuarios r ON r.id = x.revisado_por $boss
        WHERE 1 $people $states GROUP BY x.id");
    foreach ($others as $i) {
        // Una incidencia rechazada o cancelada ya liberó sus días: se muestran sus días hábiles de desde a hasta.
        if (!$i['fechas'] && $i['desde']) $i['fechas'] = implode(',', vacationWorkdays($i['desde'], $i['hasta'] ?: $i['desde']) ?: [$i['desde']]);
        $dates = $i['fechas'] ? explode(',', $i['fechas']) : [];
        $item = incItem($i, 'incidencias', $i['tipo'], count($dates), $i['descripcion']);
        $item += ['goce' => $i['goce'] === null ? null : (bool)$i['goce'], 'subtype' => $i['subtipo'], 'folio' => $i['folio'],
            'file' => $i['archivo_ruta'] ? ['name' => $i['archivo_nombre'], 'size' => (int)$i['archivo_tamano']] : null];
        $items[] = $item;
    }
    usort($items, fn($a, $b) => strcmp($b['dates'][0] ?? $b['created_at'], $a['dates'][0] ?? $a['created_at']) ?: strcmp($b['key'], $a['key']));
    return $items;
}

function incItem(array $row, string $source, string $type, int $days, ?string $note): array
{
    return [
        'key' => ($source === 'vacaciones' ? 'vac-' : 'inc-') . $row['id'], 'source' => $source, 'id' => (int)$row['id'], 'type' => $type,
        'status' => $row['estado'], 'dates' => $row['fechas'] ? explode(',', $row['fechas']) : [], 'days' => $days, 'note' => $note,
        'user' => ['id' => (int)$row['usuario_id'], 'name' => $row['nombre'], 'area' => $row['area']],
        'created_at' => $row['creado_en'], 'created_by' => $row['creador'], 'self_created' => (int)$row['creado_por'] === (int)$row['usuario_id'],
        'authorized_at' => $row['autorizado_en'], 'authorized_by' => $row['autorizador'], 'manager' => $row['jefe'],
        'reviewed_at' => $row['revisado_en'], 'reviewed_by' => $row['revisor'], 'reason' => $row['motivo_revision'],
        'goce' => null, 'subtype' => null, 'folio' => null, 'file' => null,
    ];
}

/** Días ya ocupados (vacaciones o incidencias pendientes o aprobadas) de una persona. */
function incBusy(PDO $db, int $userId): array
{
    $query = $db->prepare("SELECT d.fecha FROM vacaciones_dias d JOIN vacaciones_solicitudes s ON s.id = d.solicitud_id
        WHERE d.usuario_id = ? AND s.estado IN ('aprobada', 'pendiente', 'autorizada')
        UNION SELECT f.fecha FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id
        WHERE f.usuario_id = ? AND i.estado IN ('aprobada', 'pendiente', 'autorizada')");
    $query->execute([$userId, $userId]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

/** Saldo del año de vacaciones en curso de una persona, en el formato que usa el calendario. */
function incBalance(PDO $db, int $userId): ?array
{
    $summary = vacationSummaries($db, $userId)[0] ?? null;
    $current = $summary['current'] ?? null;
    return [
        'hire_date' => $summary['hire_date'] ?? null, 'years' => $summary['years'] ?? null,
        'period' => $current ? ['start' => $current['start'], 'end' => $current['end'], 'entitled' => $current['entitled'],
            'used' => $current['used'], 'pending' => $current['pending'], 'available' => $current['available']] : null,
        'next' => $summary['next'] ?? null,
        'periods' => array_map(fn($p) => ['year' => $p['year'], 'start' => $p['start'], 'end' => $p['end'], 'current' => $p['current'],
            'entitled' => $p['entitled'], 'used' => $p['used'], 'available' => $p['available'], 'expired' => $p['expired'],
            'before_system' => $p['before_system'] ?? false], array_slice($summary['periods'] ?? [], 0, 4)),
    ];
}

/** Nombres de quienes pueden validar incidencias (correos de asistencia/config.php > mail > cc). */
function incValidators(PDO $db): array
{
    return array_column(adminContacts($db), 'name');
}

/** Solicitudes que esperan a quien valida incidencias (contador de «Por revisar»). */
function incPendingCount(PDO $db): int
{
    return (int)$db->query("SELECT (SELECT COUNT(*) FROM vacaciones_solicitudes WHERE estado = 'autorizada') + (SELECT COUNT(*) FROM incidencias WHERE estado = 'autorizada')")->fetchColumn();
}

/** Datos para JavaScript sin ejecutar nada (CSP): <script type="application/json">. */
function incJson(string $id, array $data): string
{
    return '<script type="application/json" id="' . esc($id) . '">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . '</script>';
}

/** Inicio del documento: <head> y el encabezado de la intranet con las secciones de Incidencias. */
function incHeader(PDO $db, array $user, string $page, string $title): string
{
    $pages = incPages($user['rol'], !empty($user['equipo']));
    $pending = $user['rol'] === 'admin' ? incPendingCount($db) : 0;
    $teamPending = isset($pages['equipo.php']) ? managerPendingCount($db, $user['id']) : 0;
    $sections = [];
    foreach ($pages as $file => $label) {
        $sections[] = ['href' => $file, 'label' => $label, 'active' => $file === $page, 'badge' => ['revisar.php' => $pending, 'equipo.php' => $teamPending][$file] ?? null];
    }
    $css = 'css/app.css?v=' . (int)@filemtime(__DIR__ . '/css/app.css');
    $js = 'js/incidencias.js?v=' . (int)@filemtime(__DIR__ . '/js/incidencias.js');
    return '<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="' . esc($_SESSION['csrf']) . '">
    <title>' . esc($title) . ' · Incidencias | ' . INTRANET_NOMBRE . '</title>
    <link rel="stylesheet" href="' . $css . '">
    <script src="' . $js . '" defer></script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased" data-page="' . esc(basename($page, '.php')) . '">
' . intranet_barra($db, 'incidencias', '../', $sections);
}

/** Encabezado de página: rótulo, título, texto y botones. */
function incTitle(string $eyebrow, string $title, string $text, string $actions = ''): string
{
    return '<div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-sm font-medium text-brand-600">' . esc($eyebrow) . '</p>
        <h1 class="mt-1 text-3xl font-bold text-slate-900">' . esc($title) . '</h1>
        <p class="mt-2 max-w-3xl text-slate-500">' . esc($text) . '</p></div>
        <div class="flex shrink-0 flex-wrap gap-2">' . $actions . '</div>
    </div>';
}

/**
 * Ventana para registrar una incidencia. Con $people (administración), primero se elige a la persona; queda aprobada
 * si quien registra valida incidencias ($approves) y en revisión si no. Sin $people es la propia solicitud (en revisión).
 * El calendario lo dibuja js/incidencias.js igual al de asistencia: vacaciones = inicio y fin dentro del año
 * de vacaciones; permiso = uno o varios días; incapacidad = inicio y fin.
 */
function incModal(?array $people = null, bool $approves = false): string
{
    $types = [
        ['vacaciones', 'Vacaciones', 'plane', 'Inicio y fin'],
        ['permiso', 'Permiso', 'clipboard', 'Uno o varios días'],
        ['incapacidad', 'Incapacidad', 'heart-pulse', 'Con documentación'],
    ];
    $cards = '';
    foreach ($types as [$value, $label, $iconName, $hint]) {
        $cards .= '<label class="cursor-pointer rounded-2xl border-2 border-slate-200 p-3 text-center transition hover:border-primary/40 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
            <input type="radio" name="tipo" value="' . $value . '" class="sr-only">
            <span class="mx-auto grid size-10 place-items-center rounded-full bg-secondary text-primary">' . icon($iconName, 'size-5') . '</span>
            <span class="mt-2 block text-sm font-semibold">' . $label . '</span><span class="mt-0.5 block text-[11px] leading-tight text-slate-500">' . $hint . '</span>
        </label>';
    }
    $person = '';
    if ($people !== null) {
        $options = '';
        foreach ($people as $p) $options .= '<option value="' . (int)$p['id'] . '">' . esc($p['name'] . ($p['area'] ? ' · ' . $p['area'] : '')) . '</option>';
        $person = '<label class="block"><span class="mb-1.5 block text-sm font-semibold">Persona</span>
            <select name="usuario" id="inc-person" class="block w-full rounded-[10px] px-3 py-2.5 text-sm ring-1 ring-slate-300" required><option value="">Elige a la persona…</option>' . $options . '</select></label>';
    }
    $sick = '';
    foreach (INC_SICK_TYPES as $s) $sick .= '<option>' . esc($s) . '</option>';
    $field = 'block w-full rounded-[10px] px-3 py-2.5 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-primary';
    return '<dialog id="inc-modal" class="m-auto w-[calc(100%-2rem)] max-w-2xl overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40" data-for-others="' . ($people !== null ? '1' : '0') . '" data-approves="' . ($approves ? '1' : '0') . '">
    <form id="inc-form" class="flex max-h-[94dvh] flex-col" novalidate>
        <div class="flex shrink-0 items-start justify-between gap-3 bg-navy px-5 py-5 text-white sm:px-6">
            <div class="flex items-center gap-3">
                <span class="grid size-11 place-items-center rounded-full bg-white/10">' . icon('calendar-check', 'size-5') . '</span>
                <div>
                    <h2 class="text-lg font-bold">' . ($people !== null ? 'Registrar incidencia de otra persona' : 'Registrar incidencia') . '</h2>
                    <p class="text-sm text-white/60" id="inc-modal-sub">' . ($people !== null ? ($approves ? 'Queda aprobada al guardarla.' : 'Queda por confirmar de quien valida incidencias.')
                        : 'La autoriza tu jefe directo y después la confirma Administración. Mientras, esos días quedan apartados.') . '</p>
                </div>
            </div>
            <button type="button" class="grid size-8 place-items-center rounded-full hover:bg-white/10" data-close aria-label="Cerrar">' . icon('x', 'size-4') . '</button>
        </div>
        <div class="flex flex-col gap-5 overflow-y-auto p-5 sm:p-6">
            ' . $person . '
            <fieldset>
                <legend class="mb-2 text-sm font-semibold">Tipo de incidencia</legend>
                <div class="grid grid-cols-3 gap-2">' . $cards . '</div>
            </fieldset>
            <div class="flex flex-col gap-4" id="inc-fields" hidden>
                <div>
                    <div class="mb-2 flex items-end justify-between gap-2">
                        <span class="text-sm font-medium" id="picker-label"></span>
                        <button type="button" class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100" id="picker-clear">Limpiar</button>
                    </div>
                    <div class="rounded-2xl border border-slate-200 p-3" id="picker"></div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                        <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-primary"></span>Días elegidos</span>
                        <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-warning/30 ring-1 ring-warning/60"></span>Festivo (no se descuenta)</span>
                        <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-info/20 ring-1 ring-info/50"></span>Ya ocupado</span>
                    </div>
                    <p class="mt-2 min-h-5 text-sm font-medium" id="picker-summary"></p>
                </div>
                <div data-only="permiso" hidden>
                    <span class="mb-1.5 block text-sm font-medium">Tipo de permiso</span>
                    <div class="grid grid-cols-2 gap-2 text-sm font-medium">
                        <label class="cursor-pointer rounded-[10px] py-2 text-center ring-1 ring-slate-300 has-[:checked]:bg-primary has-[:checked]:text-white has-[:checked]:ring-primary"><input type="radio" name="goce" value="1" class="sr-only" checked>Con goce de sueldo</label>
                        <label class="cursor-pointer rounded-[10px] py-2 text-center ring-1 ring-slate-300 has-[:checked]:bg-primary has-[:checked]:text-white has-[:checked]:ring-primary"><input type="radio" name="goce" value="0" class="sr-only">Sin goce de sueldo</label>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2" data-only="incapacidad" hidden>
                    <label class="block"><span class="mb-1.5 block text-sm font-medium">Tipo</span><select name="subtipo" class="' . $field . '">' . $sick . '</select></label>
                    <label class="block"><span class="mb-1.5 block text-sm font-medium">Folio IMSS <span class="font-normal text-slate-400">(opcional)</span></span><input name="folio" maxlength="30" class="' . $field . '" placeholder="Ej. AB123456" autocomplete="off"></label>
                </div>
                <label class="block"><span class="mb-1.5 block text-sm font-medium" id="inc-desc-label">Descripción</span>
                    <textarea rows="3" name="descripcion" maxlength="2000" class="' . $field . '" placeholder="Motivo o detalles"></textarea></label>
                <label class="block">
                    <span class="mb-1.5 flex items-center gap-1.5 text-sm font-medium">' . icon('paperclip', 'size-4') . '<span id="inc-file-label">Evidencia</span></span>
                    <input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/*" class="block w-full rounded-[10px] text-sm ring-1 ring-slate-300 file:mr-3 file:border-0 file:bg-secondary file:px-3 file:py-2.5 file:font-medium file:text-primary">
                    <span class="mt-1.5 block text-xs text-slate-500">PDF o imagen (JPG, PNG, WEBP, HEIC), hasta 10 MB.</span>
                </label>
                <p class="rounded-[10px] border border-error/30 bg-error/10 px-3 py-2 text-sm text-error" id="inc-error" role="alert" hidden></p>
            </div>
        </div>
        <div class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
            <button type="button" class="rounded-[10px] px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-200" data-close>Cancelar</button>
            <button class="rounded-[10px] bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-40" id="inc-submit" disabled>Enviar a revisión</button>
        </div>
    </form>
</dialog>';
}

/** "17 nov 2026" (o sin año). */
function incDate(string $day, bool $year = true): string
{
    $months = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $t = strtotime($day);
    return date('j', $t) . ' ' . $months[(int)date('n', $t)] . ($year ? ' ' . date('Y', $t) : '');
}

/** "17 – 20 nov 2026", "28 nov – 4 dic 2026" o una lista de días sueltos para permisos. */
function incRange(array $dates, bool $list = false): string
{
    if (!$dates) return '—';
    $first = $dates[0];
    $last = end($dates);
    if ($first === $last) return incDate($first);
    $consecutive = true;
    foreach ($dates as $k => $d) if ($k > 0 && date('Y-m-d', strtotime($dates[$k - 1] . ' +1 day')) !== $d) $consecutive = false;
    if ($list && !$consecutive) return implode(', ', array_map(fn($d) => incDate($d, false), $dates)) . ' ' . date('Y', strtotime($last));
    if (substr($first, 0, 7) === substr($last, 0, 7)) return (int)substr($first, 8) . ' – ' . incDate($last);
    return incDate($first, substr($first, 0, 4) !== substr($last, 0, 4)) . ' – ' . incDate($last);
}

/** En qué paso va una solicitud: «Autorizó Mayra Reynoso el 3 nov 2026 · Falta la confirmación». */
function incProgress(array $i): string
{
    $parts = [];
    if ($i['authorized_by']) $parts[] = 'Autorizó ' . $i['authorized_by'] . ' el ' . incDate(substr((string)$i['authorized_at'], 0, 10));
    $parts[] = match ($i['status']) {
        'pendiente' => 'Espera la autorización de ' . ($i['manager'] ?: 'su jefe directo'),
        'autorizada' => $i['authorized_by'] ? 'Falta la confirmación de Administración' : 'Sin jefe directo: la confirma Administración',
        'aprobada' => 'Aprobada' . ($i['reviewed_by'] ? ' por ' . $i['reviewed_by'] : ''),
        'rechazada' => 'Rechazada' . ($i['reviewed_by'] ? ' por ' . $i['reviewed_by'] : '') . ($i['reason'] ? ': ' . $i['reason'] : ''),
        default => 'Cancelada',
    };
    return implode(' · ', $parts);
}

/**
 * Tarjeta de una solicitud en revisión con sus botones. $buttons: [[acción, decisión, etiqueta, primario], ...]
 * ($acción = 'revisar' para quien valida o 'autorizar' para el jefe). $note: texto en lugar de botones.
 */
function incRequestCard(array $i, array $buttons, string $note = '', ?array $balance = null): string
{
    $look = ['vacaciones' => ['bg-sky-50 text-sky-700', 'plane'], 'permiso' => ['bg-violet-50 text-violet-700', 'clipboard'], 'incapacidad' => ['bg-rose-50 text-rose-700', 'heart-pulse']];
    [$tag, $iconName] = $look[$i['type']];
    $days = fn(int $n) => $n . ($n === 1 ? ' día hábil' : ' días hábiles');
    $detail = match ($i['type']) {
        // El saldo disponible ya descuenta las solicitudes en revisión: es lo que le queda si se aprueba.
        'vacaciones' => $days($i['days']) . ($balance ? ' · al aprobarla le quedan ' . $balance['available'] . ' de ' . $balance['entitled'] : ''),
        'permiso' => $days($i['days']) . ' · ' . ($i['goce'] ? 'con goce de sueldo' : 'sin goce de sueldo'),
        default => $days($i['days']) . ' · ' . mb_strtolower((string)$i['subtype']) . ($i['folio'] ? ' · folio IMSS ' . $i['folio'] : ''),
    };
    $actions = '';
    foreach ($buttons as [$action, $decision, $label, $primary]) {
        $actions .= '<button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold ' . ($primary ? 'bg-brand-600 text-white hover:bg-brand-700' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50') . '"'
            . ' data-review="' . $decision . '" data-action="' . $action . '" data-source="' . $i['source'] . '" data-id="' . $i['id'] . '" data-name="' . esc($i['user']['name']) . '">' . esc($label) . '</button>';
    }
    return '<article class="rounded-xl border border-slate-200 bg-white p-5" data-item="' . esc($i['key']) . '">
        <div class="flex flex-wrap items-center gap-3">' . incPerson($i['user']['name'], (string)$i['user']['area'])
        . '<span class="ml-auto inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ' . $tag . '">' . icon($iconName, 'size-3.5') . esc(INC_TYPES[$i['type']]) . '</span></div>
        <p class="mt-4 font-semibold text-slate-900">' . esc(incRange($i['dates'], $i['type'] === 'permiso')) . '</p>
        <p class="text-sm text-slate-500">' . esc($detail) . '</p>'
        . ($i['note'] ? '<p class="mt-2 text-sm text-slate-600">“' . esc($i['note']) . '”</p>' : '')
        . ($i['file'] ? '<a href="archivo.php?id=' . $i['id'] . '" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">' . icon('paperclip', 'size-4') . esc($i['file']['name']) . '</a>' : '')
        . '<p class="mt-2 text-xs text-slate-500">Solicitada el ' . esc(incDate(substr($i['created_at'], 0, 10))) . ($i['self_created'] ? '' : ' por ' . esc((string)$i['created_by'])) . ' · ' . esc(incProgress($i)) . '</p>
        <div class="mt-4 flex flex-wrap items-center gap-2">' . ($actions ?: '<span class="text-sm text-slate-500">' . esc($note) . '</span>') . '</div>
    </article>';
}

/** Ventana para escribir el motivo de un rechazo (Por revisar e Incidencias de mi equipo). */
function incRejectModal(): string
{
    return '<dialog id="reject-modal" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40">
    <form id="reject-form" class="p-6" novalidate>
        <h2 class="text-lg font-semibold text-slate-900">Rechazar la solicitud de <span data-name></span></h2>
        <p class="mt-1 text-sm text-slate-500">Se le envía el motivo por correo y lo verá en sus incidencias.</p>
        <label class="mt-4 block"><span class="mb-1.5 block text-sm font-medium">Motivo</span>
            <textarea name="motivo" rows="3" maxlength="255" required class="block w-full rounded-[10px] px-3 py-2.5 text-sm ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Ej. Cierre de mes: elige otras fechas"></textarea></label>
        <p class="mt-3 rounded-[10px] border border-error/30 bg-error/10 px-3 py-2 text-sm text-error" id="reject-error" role="alert" hidden></p>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="rounded-[10px] px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100" data-close>Cancelar</button>
            <button class="rounded-[10px] bg-error px-5 py-2.5 text-sm font-semibold text-white hover:bg-error/90">Rechazar</button>
        </div>
    </form>
</dialog>';
}

/** Persona: iniciales y nombre (con subtítulo). */
function incPerson(string $name, string $sub = ''): string
{
    $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2))));
    return '<div class="flex items-center gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700">' . esc($initials) . '</span>'
        . '<div class="min-w-0"><div class="truncate font-medium text-slate-900">' . esc($name) . '</div>' . ($sub !== '' ? '<div class="truncate text-xs text-slate-500">' . esc($sub) . '</div>' : '') . '</div></div>';
}
