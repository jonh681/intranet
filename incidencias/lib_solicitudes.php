<?php
declare(strict_types=1);
// Solicitudes que pide el empleado (vacaciones, permisos e incapacidades) y su validación en dos pasos:
//   pendiente  -> la autoriza o rechaza su jefe directo (si no tiene jefe, empieza como autorizada)
//   autorizada -> la confirma o rechaza Administración
//   aprobada   -> se aplica (descuenta vacaciones / justifica asistencia)
// En cada paso se avisa por correo (comun/correo.php). Requiere lib_vacaciones.php.
require_once __DIR__ . '/../comun/segundo_plano.php';
require_once __DIR__ . '/../comun/correo.php';
require_once __DIR__ . '/../comun/acceso.php'; // intranet_validadores()
// Intranet: «Administración» es quien tiene el permiso Valida incidencias (Seguridad → Empleados); las pantallas
// están en incidencias/ (Mis incidencias, Incidencias de mi equipo y Por revisar).

const REQUEST_TYPES = ['vacaciones' => 'Vacaciones', 'permiso' => 'Permiso', 'incapacidad' => 'Incapacidad'];

// Id del jefe directo de la fila $b de usuarios (expresión SQL). Intranet: es la cuenta activa con el correo de su
// «Jefe directo» (correo_jefe, Usuarios → Editar). Nadie es su propio jefe.
// Quien no tiene jefe (por ejemplo, Dirección) manda sus solicitudes directo a quien valida incidencias.
function bossSql(string $b): string {
    return "(SELECT j.id FROM usuarios j WHERE j.correo_usuario = TRIM($b.correo_jefe) AND j.activo = 1 AND j.id <> $b.id LIMIT 1)";
}

// Jefe directo activo de una persona, o null (su solicitud va directo a Administración).
function directManager(PDO $db, int $userId): ?array {
    $query = $db->prepare('SELECT m.id, m.nombre_usuario, m.correo_usuario FROM usuarios b JOIN usuarios m ON m.id = ' . bossSql('b') . ' WHERE b.id = ?');
    $query->execute([$userId]);
    $row = $query->fetch();
    return $row ? ['id' => (int)$row['id'], 'name' => $row['nombre_usuario'], 'email' => $row['correo_usuario']] : null;
}

// Personas activas cuyo jefe directo es $managerId.
function subordinateIds(PDO $db, int $managerId): array {
    $query = $db->prepare('SELECT b.id FROM usuarios b WHERE b.activo = 1 AND ' . bossSql('b') . ' = ?');
    $query->execute([$managerId]);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

// Estado con el que nace la solicitud de un empleado.
function initialStatus(PDO $db, int $userId): string {
    return directManager($db, $userId) ? 'pendiente' : 'autorizada';
}

// Solicitudes de vacaciones e incidencias en un formato común.
// $filter: users (ids), status (estados), kind ('vacaciones'|'incidencia'), id, since (fecha de la última decisión).
function listRequests(PDO $db, array $filter = []): array {
    $out = [];
    foreach (['vacaciones', 'incidencia'] as $kind) {
        if (isset($filter['kind']) && $filter['kind'] !== $kind) continue;
        $vac = $kind === 'vacaciones';
        $t = $vac ? 's' : 'i';
        $where = ['u.eliminado_en IS NULL'];
        $params = [];
        if (isset($filter['id'])) { $where[] = "$t.id = ?"; $params[] = (int)$filter['id']; }
        if (isset($filter['users'])) {
            if (!$filter['users']) continue;
            $where[] = "$t.usuario_id IN (" . implode(',', array_map('intval', $filter['users'])) . ')';
        }
        if (isset($filter['status'])) {
            $where[] = "$t.estado IN (" . implode(',', array_fill(0, count($filter['status']), '?')) . ')';
            array_push($params, ...$filter['status']);
        }
        if (isset($filter['since'])) { $where[] = "COALESCE($t.revisado_en, $t.autorizado_en, $t.creado_en) >= ?"; $params[] = $filter['since']; }
        $sql = $vac
            ? "SELECT s.id, s.usuario_id, 'vacaciones' AS tipo, s.estado, s.dias, s.comentario AS texto, NULL AS archivo_nombre, s.creado_en,
                s.autorizado_en, s.revisado_en, s.motivo_revision, NULL AS desde, NULL AS hasta,
                (SELECT GROUP_CONCAT(d.fecha ORDER BY d.fecha) FROM vacaciones_dias d WHERE d.solicitud_id = s.id) AS fechas"
            : "SELECT i.id, i.usuario_id, i.tipo, i.estado, NULL AS dias, i.descripcion AS texto, i.archivo_nombre, i.creado_en,
                i.autorizado_en, i.revisado_en, i.motivo_revision, i.desde, i.hasta,
                (SELECT GROUP_CONCAT(f.fecha ORDER BY f.fecha) FROM incidencia_fechas f WHERE f.incidencia_id = i.id) AS fechas";
        $sql .= ", u.nombre, u.correo, u.area, u.representante, c.nombre AS creador, a.nombre AS autorizador, r.nombre AS revisor, g.id AS gid, g.nombre_usuario AS gnombre, g.correo_usuario AS gcorreo
            FROM " . ($vac ? 'vacaciones_solicitudes s' : 'incidencias i') . " JOIN personal_usuarios u ON u.id = $t.usuario_id
            LEFT JOIN personal_usuarios c ON c.id = $t.creado_por LEFT JOIN personal_usuarios a ON a.id = $t.autorizado_por LEFT JOIN personal_usuarios r ON r.id = $t.revisado_por
            JOIN usuarios ub ON ub.id = u.id LEFT JOIN usuarios g ON g.id = " . bossSql('ub') . "
            WHERE " . implode(' AND ', $where);
        $query = $db->prepare($sql);
        $query->execute($params);
        foreach ($query as $row) {
            $dates = $row['fechas'] ? explode(',', $row['fechas']) : [];
            // Una incidencia rechazada o cancelada ya no tiene días guardados: se usa su rango.
            if (!$dates && $row['desde']) $dates = array_values(array_unique([$row['desde'], $row['hasta'] ?: $row['desde']]));
            $days = $vac ? (int)$row['dias'] : ($row['fechas'] ? count($dates)
                : ($row['desde'] ? (int)(new DateTimeImmutable($row['desde']))->diff(new DateTimeImmutable($row['hasta'] ?: $row['desde']))->days + 1 : 0));
            $out[] = [
                'kind' => $kind, 'id' => (int)$row['id'], 'key' => ($vac ? 'v' : 'i') . $row['id'], 'type' => $row['tipo'], 'status' => $row['estado'],
                'user' => ['id' => (int)$row['usuario_id'], 'name' => $row['nombre'], 'email' => $row['correo'], 'area' => $row['area'], 'rep' => (bool)$row['representante']],
                'manager' => $row['gid'] ? ['id' => (int)$row['gid'], 'name' => $row['gnombre'], 'email' => $row['gcorreo']] : null,
                'dates' => $dates, 'days' => $days, 'comment' => $row['texto'], 'file' => $row['archivo_nombre'] ? ['name' => $row['archivo_nombre']] : null,
                'created_at' => $row['creado_en'], 'created_by' => $row['creador'],
                'authorized_at' => $row['autorizado_en'], 'authorized_by' => $row['autorizador'],
                'reviewed_at' => $row['revisado_en'], 'reviewed_by' => $row['revisor'], 'reason' => $row['motivo_revision'],
            ];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);
    return $out;
}

// Registra una incidencia (permiso o incapacidad) con sus días y su archivo.
// $status: 'aprobada' (la registra el administrador), 'pendiente' o 'autorizada' (la pide el empleado).
// Devuelve ['id' => id] o ['error' => mensaje, 'code' => http].
// $extra (intranet): ['goce' => 1|0|null, 'subtipo' => ?string, 'folio' => ?string].
function createIncident(PDO $db, int $userId, string $type, string $description, array $dates, ?array $file, int $actorId, string $status, array $extra = []): array {
    if (!in_array($type, ['vacaciones', 'permiso', 'incapacidad'], true)) return ['error' => 'Tipo de incidencia no valido.', 'code' => 422];
    if ($type === 'vacaciones' && feature('vacaciones')) return ['error' => 'Las vacaciones se registran en la pestaña Vacaciones.', 'code' => 422];
    if (!$dates) return ['error' => 'Selecciona al menos un dia.', 'code' => 422];
    $dates = array_values(array_unique(array_map('strval', $dates)));
    sort($dates);
    foreach ($dates as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return ['error' => 'Fecha no valida.', 'code' => 422];
    }
    if (count($dates) > 120) return ['error' => 'Una incidencia puede cubrir hasta 120 dias.', 'code' => 422];
    if ($type !== 'vacaciones' && $description === '') return ['error' => 'Escribe una descripcion.', 'code' => 422];
    if (mb_strlen($description) > 2000) return ['error' => 'La descripcion admite hasta 2000 caracteres.', 'code' => 422];
    if ($type === 'incapacidad' && !$file) return ['error' => 'Adjunta la documentacion de la incapacidad.', 'code' => 422];
    if ($type === 'vacaciones') { $description = ''; $file = null; }
    $stored = null;
    if ($file) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > MAX_UPLOAD_BYTES) return ['error' => 'El archivo supera los 10 MB.', 'code' => 413];
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['error' => 'No se pudo recibir el archivo.', 'code' => 422];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(UPLOAD_TYPES[$mime])) return ['error' => 'Formato no permitido: usa PDF, JPG, PNG, WEBP o HEIC.', 'code' => 422];
        $stored = ['name' => mb_substr(basename((string)$file['name']), 0, 255), 'path' => bin2hex(random_bytes(16)) . '.' . UPLOAD_TYPES[$mime], 'mime' => $mime, 'size' => (int)$file['size']];
    }
    $db->beginTransaction();
    $in = implode(',', array_fill(0, count($dates), '?'));
    $taken = $db->prepare("SELECT f.fecha FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id
        WHERE f.usuario_id = ? AND i.estado IN ('aprobada', 'pendiente', 'autorizada') AND f.fecha IN ($in)
        UNION SELECT d.fecha FROM vacaciones_dias d JOIN vacaciones_solicitudes s ON s.id = d.solicitud_id
        WHERE d.usuario_id = ? AND s.estado IN ('aprobada', 'pendiente', 'autorizada') AND d.fecha IN ($in) ORDER BY 1");
    $taken->execute([$userId, ...$dates, $userId, ...$dates]);
    if ($busy = $taken->fetchAll(PDO::FETCH_COLUMN)) {
        $db->rollBack();
        return ['error' => 'Ya hay vacaciones, permiso o incapacidad el ' . implode(', ', array_map(fn($d) => date('d/m/Y', strtotime($d)), $busy)) . '.', 'code' => 409];
    }
    $approved = $status === 'aprobada';
    $db->prepare('INSERT INTO incidencias (usuario_id, tipo, goce, subtipo, folio, descripcion, archivo_nombre, archivo_ruta, archivo_tipo, archivo_tamano, creado_por, estado, desde, hasta, revisado_por, revisado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($approved ? 'NOW()' : 'NULL') . ')')
        ->execute([$userId, $type, $extra['goce'] ?? null, $extra['subtipo'] ?? null, $extra['folio'] ?? null, $description ?: null,
            $stored['name'] ?? null, $stored['path'] ?? null, $stored['mime'] ?? null, $stored['size'] ?? null,
            $actorId, $status, $dates[0], end($dates), $approved ? $actorId : null]);
    $incidentId = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO incidencia_fechas (incidencia_id, usuario_id, fecha) VALUES (?, ?, ?)');
    foreach ($dates as $date) $insert->execute([$incidentId, $userId, $date]);
    if ($stored) {
        if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0750, true);
        if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $stored['path'])) { $db->rollBack(); return ['error' => 'No se pudo guardar el archivo.', 'code' => 500]; }
    }
    $db->commit();
    return ['id' => $incidentId];
}

// Decide una solicitud. $stage 'jefe': autorizar|rechazar (solo su jefe directo, solo pendientes).
// $stage 'admin': aprobar|rechazar (pendientes o autorizadas). Devuelve ['user_id' => id, 'event' => evento] o error.
function reviewRequest(PDO $db, string $kind, int $id, string $decision, string $reason, int $actorId, string $stage): array {
    $table = $kind === 'vacaciones' ? 'vacaciones_solicitudes' : 'incidencias';
    $valid = $stage === 'jefe' ? ['autorizar', 'rechazar'] : ['aprobar', 'rechazar'];
    if (!in_array($kind, ['vacaciones', 'incidencia'], true) || !in_array($decision, $valid, true)) return ['error' => 'Decision no valida.', 'code' => 422];
    if ($decision === 'rechazar' && (mb_strlen($reason) < 3 || mb_strlen($reason) > 255)) return ['error' => 'Escribe el motivo del rechazo.', 'code' => 422];
    $db->beginTransaction();
    $query = $db->prepare("SELECT usuario_id, estado FROM $table WHERE id = ? FOR UPDATE");
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) { $db->rollBack(); return ['error' => 'La solicitud no existe.', 'code' => 404]; }
    $userId = (int)$row['usuario_id'];
    if ($stage === 'jefe') {
        if ((directManager($db, $userId)['id'] ?? 0) !== $actorId) { $db->rollBack(); return ['error' => 'Esa persona no forma parte de tu equipo.', 'code' => 403]; }
        if ($row['estado'] !== 'pendiente') { $db->rollBack(); return ['error' => 'La solicitud ya no espera tu autorizacion.', 'code' => 409]; }
    } elseif (!in_array($row['estado'], ['pendiente', 'autorizada'], true)) {
        $db->rollBack();
        return ['error' => 'La solicitud ya no esta pendiente.', 'code' => 409];
    }
    if ($decision === 'autorizar') {
        $db->prepare("UPDATE $table SET estado = 'autorizada', autorizado_por = ?, autorizado_en = NOW() WHERE id = ?")->execute([$actorId, $id]);
        $event = 'autorizada';
    } elseif ($decision === 'aprobar') {
        $db->prepare("UPDATE $table SET estado = 'aprobada', revisado_por = ?, revisado_en = NOW(), motivo_revision = NULL WHERE id = ?")->execute([$actorId, $id]);
        $event = 'aprobada';
    } else {
        $db->prepare("UPDATE $table SET estado = 'rechazada', revisado_por = ?, revisado_en = NOW(), motivo_revision = ? WHERE id = ?")->execute([$actorId, $reason, $id]);
        // Los días de una incidencia rechazada quedan libres (las vacaciones solo cuentan si están vigentes).
        if ($kind === 'incidencia') $db->prepare('DELETE FROM incidencia_fechas WHERE incidencia_id = ?')->execute([$id]);
        $event = $stage === 'jefe' ? 'rechazada_jefe' : 'rechazada';
    }
    $db->commit();
    return ['user_id' => $userId, 'event' => $event];
}

// El empleado cancela su solicitud mientras nadie la ha aprobado.
function cancelOwnRequest(PDO $db, string $kind, int $id, int $userId): bool {
    $table = $kind === 'vacaciones' ? 'vacaciones_solicitudes' : 'incidencias';
    $db->beginTransaction();
    $cancel = $db->prepare("UPDATE $table SET estado = 'cancelada', revisado_por = ?, revisado_en = NOW(), motivo_revision = 'Cancelada por el empleado'
        WHERE id = ? AND usuario_id = ? AND estado IN ('pendiente', 'autorizada')");
    $cancel->execute([$userId, $id, $userId]);
    $ok = $cancel->rowCount() > 0;
    if ($ok && $kind === 'incidencia') $db->prepare('DELETE FROM incidencia_fechas WHERE incidencia_id = ?')->execute([$id]);
    $db->commit();
    return $ok;
}

// Quien valida cancela unas vacaciones ya aprobadas (por jefe y Administración): sus días regresan al saldo.
function cancelApprovedVacation(PDO $db, int $id, int $actorId, string $reason): bool {
    $cancel = $db->prepare("UPDATE vacaciones_solicitudes SET estado = 'cancelada', revisado_por = ?, revisado_en = NOW(), motivo_revision = ?
        WHERE id = ? AND estado = 'aprobada'");
    $cancel->execute([$actorId, mb_substr('Cancelada por Administración: ' . $reason, 0, 255), $id]);
    return $cancel->rowCount() > 0;
}

// Cuántas solicitudes de su equipo esperan la autorización de un jefe.
function managerPendingCount(PDO $db, int $managerId): int {
    $count = 0;
    foreach (['vacaciones' => 'vacaciones_solicitudes', 'incidencias' => 'incidencias'] as $feature => $table) {
        if (!feature($feature)) continue;
        $query = $db->prepare("SELECT COUNT(*) FROM $table t JOIN usuarios b ON b.id = t.usuario_id WHERE t.estado = 'pendiente' AND b.activo = 1 AND " . bossSql('b') . ' = ?');
        $query->execute([$managerId]);
        $count += (int)$query->fetchColumn();
    }
    return $count;
}

// ---- Correos ----
function mailDay(string $day): string {
    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $weekdays = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $d = new DateTimeImmutable($day);
    return $weekdays[(int)$d->format('w')] . ' ' . (int)$d->format('j') . ' ' . $months[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

function mailRange(array $dates): string {
    if (!$dates) return '—';
    return count($dates) === 1 || $dates[0] === end($dates) ? mailDay($dates[0]) : 'del ' . mailDay($dates[0]) . ' al ' . mailDay(end($dates));
}

// Correo armado con tablas y estilos en línea (Outlook y otros programas ignoran márgenes y colores en <div>).
// $buttons: lista de [texto, enlace, color de fondo].
// $after: bloque extra debajo del detalle (el avance de la solicitud).
function mailTemplate(string $title, string $intro, array $rows, array $buttons, string $after = ''): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $font = 'font-family:Arial,Helvetica,sans-serif;';
    $table = '';
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') continue;
        $table .= '<tr><td width="120" valign="top" style="' . $font . 'font-size:14px;color:#5b6b82;padding:9px 12px 9px 0;border-top:1px solid #dde4ef">' . $e($label) . '</td>'
            . '<td valign="top" style="' . $font . 'font-size:14px;color:#0f1f38;font-weight:bold;padding:9px 0;border-top:1px solid #dde4ef">' . nl2br($e($value)) . '</td></tr>';
    }
    $links = '';
    foreach ($buttons as $i => [$label, $url, $color]) {
        $links .= ($i ? '<td width="10" style="font-size:0">&nbsp;</td>' : '')
            . '<td bgcolor="' . $color . '" style="background:' . $color . ';border-radius:8px;padding:12px 22px">'
            . '<a href="' . $e($url) . '" target="_blank" style="' . $font . 'font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none"><span style="color:#ffffff">' . $e($label) . '</span></a></td>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f2f5fa" style="background:#f2f5fa"><tr><td align="center" style="padding:20px 12px">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:560px;background:#ffffff;border:1px solid #dde4ef;border-radius:12px">'
        . '<tr><td bgcolor="#0b1f3a" style="background:#0b1f3a;padding:16px 24px;border-radius:12px 12px 0 0;' . $font . 'font-size:18px;font-weight:bold;color:#ffffff">Fedele 360</td></tr>'
        . '<tr><td style="padding:24px 24px 8px;' . $font . 'font-size:21px;font-weight:bold;color:#0f1f38">' . $e($title) . '</td></tr>'
        . ($intro !== '' ? '<tr><td style="padding:0 24px 8px;' . $font . 'font-size:14px;line-height:21px;color:#33435c">' . $e($intro) . '</td></tr>' : '')
        . '<tr><td style="padding:8px 24px 4px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $table . '</table></td></tr>'
        . ($after !== '' ? '<tr><td style="padding:16px 24px 0">' . $after . '</td></tr>' : '')
        . ($links ? '<tr><td style="padding:18px 24px 26px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $links . '</tr></table></td></tr>' : '<tr><td style="padding:0 0 18px"></td></tr>')
        . '</table></td></tr></table>';
}

// Dirección de la intranet para los enlaces de los correos; $page es relativa a su carpeta (p. ej. 'incidencias/revisar.php').
// En el servidor sale de INTRANET_URL (intranet.env); en local, de la dirección con la que se entró.
function appUrl(string $page = ''): string {
    require_once __DIR__ . '/../tickets/php/env.php';
    $base = rtrim((string)(appConfig()['mail']['app_url'] ?? '') ?: env('INTRANET_URL'), '/');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        // Ruta web de la intranet: su carpeta relativa a la raíz del sitio.
        $root = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $here = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
        $dir = $root !== '' && str_starts_with(strtolower($here), strtolower($root)) ? substr($here, strlen($root)) : '';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($dir, '/');
    }
    return $base . '/' . $page;
}

// Enlace firmado para que el jefe directo autorice desde el correo sin iniciar sesión (incidencias/autorizar.php).
// La firma incluye al jefe: si cambia el jefe de la persona, el enlace deja de servir.
function requestToken(string $kind, int $id, int $managerId): string {
    $key = (string)(appConfig()['app_key'] ?? '');
    if (strlen($key) < 32) return '';
    return substr(hash_hmac('sha256', "jefe|$kind|$id|$managerId", $key), 0, 40);
}

function authorizeUrl(string $kind, int $id, int $managerId, bool $reject = false): string {
    $token = requestToken($kind, $id, $managerId);
    return $token ? appUrl('incidencias/autorizar.php?' . http_build_query(['k' => $kind, 'id' => $id, 't' => $token] + ($reject ? ['r' => 1] : []))) : '';
}

// Cómo se nombra lo que se pidió: "solicitó vacaciones", "autorizó el permiso de...", "tus vacaciones".
function requestPhrases(string $type): array {
    return [
        'vacaciones' => ['vacaciones', 'las vacaciones', 'tus vacaciones'],
        'permiso' => ['un permiso', 'el permiso', 'tu permiso'],
        'incapacidad' => ['una incapacidad', 'la incapacidad', 'tu incapacidad'],
    ][$type] ?? ['una solicitud', 'la solicitud', 'tu solicitud'];
}

// Quienes dan la confirmación final: los correos de config.php > mail > cc (comun/acceso.php, intranet_validadores()),
// con su nombre si tienen cuenta en la intranet.
function adminContacts(PDO $db): array {
    static $contacts = null;
    if ($contacts !== null) return $contacts;
    $contacts = [];
    $name = $db->prepare('SELECT nombre_usuario FROM usuarios WHERE correo_usuario = ? AND activo = 1');
    foreach (intranet_validadores() as $email) {
        $name->execute([$email]);
        $contacts[] = ['name' => $name->fetchColumn() ?: 'Administración', 'email' => $email];
    }
    return $contacts;
}

// Nombre para los textos ("Angélica López la confirmará"): una persona o «Administración».
function adminContact(PDO $db): array {
    $contacts = adminContacts($db);
    return count($contacts) === 1 ? $contacts[0] : ['name' => 'Administración', 'email' => $contacts[0]['email'] ?? ''];
}

// Avance de la solicitud paso por paso (correos y tarjetas de Incidencias).
// state: done | current | rejected | todo.
function requestProgress(array $r, string $adminName): array {
    $day = fn(?string $v) => $v ? mailDay(substr($v, 0, 10)) : '';
    $steps = [['who' => $r['user']['name'], 'role' => 'Solicitud', 'state' => 'done', 'text' => 'Enviada el ' . $day($r['created_at'])]];
    $manager = $r['manager']['name'] ?? null;
    $bossRejected = $r['status'] === 'rechazada' && !$r['authorized_by'] && $manager && $r['reviewed_by'] === $manager;
    if ($boss = $r['authorized_by'] ?: $manager) {
        [$state, $text] = match (true) {
            (bool)$r['authorized_by'] => ['done', 'Autorizó el ' . $day($r['authorized_at'])],
            $bossRejected => ['rejected', 'Rechazó el ' . $day($r['reviewed_at'])],
            $r['status'] === 'pendiente' => ['current', 'Pendiente de autorizar'],
            $r['status'] === 'cancelada' => ['todo', 'Se canceló antes'],
            default => ['todo', 'No fue necesario'],
        };
        $steps[] = ['who' => $boss, 'role' => 'Jefe directo', 'state' => $state, 'text' => $text];
    }
    $adminRejected = $r['status'] === 'rechazada' && !$bossRejected;
    // Cancelada por Administración después de aprobarse (si la cancela el propio empleado, el revisor es él mismo).
    $adminCancelled = $r['status'] === 'cancelada' && $r['reviewed_by'] && $r['reviewed_by'] !== $r['user']['name'];
    [$state, $text] = match (true) {
        $r['status'] === 'aprobada' => ['done', 'Confirmó el ' . $day($r['reviewed_at'])],
        $adminCancelled => ['rejected', 'Canceló el ' . $day($r['reviewed_at'])],
        $adminRejected => ['rejected', 'Rechazó el ' . $day($r['reviewed_at'])],
        $r['status'] === 'autorizada' => ['current', 'Pendiente de confirmar'],
        $r['status'] === 'cancelada' => ['todo', 'Se canceló antes'],
        default => ['todo', 'Confirma después del jefe directo'],
    };
    $steps[] = ['who' => in_array($state, ['done', 'rejected'], true) ? ($r['reviewed_by'] ?: $adminName) : $adminName, 'role' => 'Administración', 'state' => $state, 'text' => $text];
    return $steps;
}

// El avance en el correo: una fila por paso con su icono.
function mailProgress(array $steps): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $font = 'font-family:Arial,Helvetica,sans-serif;';
    $look = ['done' => ['#2f9e6e', '&#10003;', '#2f9e6e'], 'current' => ['#d9a032', '&#8230;', '#a8740f'], 'rejected' => ['#c0392b', '&#10005;', '#c0392b'], 'todo' => ['#c5cfdc', '&nbsp;', '#8a97a8']];
    $rows = '';
    foreach ($steps as $step) {
        [$bg, $mark, $tone] = $look[$step['state']];
        $rows .= '<tr><td width="34" valign="top" style="padding:6px 10px 6px 0"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="24" height="24" align="center" bgcolor="' . $bg . '" style="background:' . $bg . ';border-radius:12px;' . $font . 'font-size:13px;font-weight:bold;color:#ffffff;line-height:24px">' . $mark . '</td></tr></table></td>'
            . '<td valign="top" style="padding:6px 0;' . $font . 'font-size:14px;color:#0f1f38"><b>' . $e($step['who']) . '</b>'
            . ($step['who'] !== $step['role'] ? ' <span style="color:#8a97a8;font-size:12px">' . $e($step['role']) . '</span>' : '') . '<br>'
            . '<span style="color:' . $tone . ';font-size:13px' . ($step['state'] === 'current' ? ';font-weight:bold' : '') . '">' . $e($step['text']) . '</span></td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f6f8fb" style="background:#f6f8fb;border:1px solid #dde4ef;border-radius:10px">'
        . '<tr><td style="padding:12px 16px 4px;' . $font . 'font-size:12px;font-weight:bold;letter-spacing:1px;color:#5b6b82">PROCESO DE LA SOLICITUD</td></tr>'
        . '<tr><td style="padding:0 16px 10px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr></table>';
}

// Avisa por correo según el paso: nueva, registrada (intranet: administración la registró por la persona y espera
// a quien valida), autorizada, rechazada_jefe, aprobada o rechazada.
// La persona que pidió recibe un correo en cada paso; todos los correos llevan el avance completo.
function notifyRequest(PDO $db, string $event, string $kind, int $id): void {
    try {
        $r = listRequests($db, ['kind' => $kind, 'id' => $id])[0] ?? null;
        if (!$r) return;
        $admin = adminContacts($db);
        $adminName = adminContact($db)['name'];
        $employee = ['name' => $r['user']['name'], 'email' => $r['user']['email']];
        $manager = $r['manager'] ? [['name' => $r['manager']['name'], 'email' => $r['manager']['email']]] : [];
        $type = REQUEST_TYPES[$r['type']] ?? 'Solicitud';
        [$asked, $the, $yours] = requestPhrases($r['type']);
        $range = mailRange($r['dates']);
        $days = $r['days'] . ($r['kind'] === 'vacaciones' ? ($r['days'] === 1 ? ' día hábil' : ' días hábiles') : ($r['days'] === 1 ? ' día' : ' días'));
        $who = implode(' · ', array_filter([$r['user']['name'], $r['user']['rep'] ? 'Representante médico' : null, $r['user']['area']]));
        $rows = ['Persona' => $who, 'Tipo' => $type, 'Fechas' => $range, 'Días' => $days, 'Comentario' => $r['comment'], 'Documento' => $r['file']['name'] ?? null];
        $progress = mailProgress(requestProgress($r, $adminName));
        $green = '#2f9e6e';
        $navy = '#13315c';
        $gray = '#8a97a8';
        $mine = [['Ver mis incidencias', appUrl('incidencias/'), $navy]];
        $boss = $r['authorized_by'] ?: ($r['manager']['name'] ?? '');
        // Cada correo: [para, copia, asunto, título, texto, filas, botones].
        $mails = [];
        switch ($event) {
            case 'nueva':
                $authorize = $manager ? authorizeUrl($kind, $id, $r['manager']['id']) : '';
                $mails[] = [$manager ?: $admin, $manager ? $admin : [], "{$r['user']['name']} solicitó $asked ($range)", "{$r['user']['name']} solicitó $asked", '', $rows,
                    $authorize
                        ? [['Autorizar', $authorize, $green], ['Rechazar', authorizeUrl($kind, $id, $r['manager']['id'], true), $gray]]
                        : [[$manager ? 'Revisar solicitud' : 'Revisar y confirmar', appUrl($manager ? 'incidencias/equipo.php' : 'incidencias/revisar.php'), $navy]]];
                $mails[] = [[$employee], [], 'Recibimos tu solicitud de ' . mb_strtolower($type) . " ($range)", 'Tu solicitud ya está en proceso',
                    $manager ? "Primero la autoriza tu jefe directo, {$r['manager']['name']}, y después $adminName la confirma. Te avisaremos en cada paso."
                        : "$adminName la revisará para confirmarla. Te avisaremos cuando lo haga.", $rows, $mine];
                break;
            case 'registrada':
                $mails[] = [$admin, [], "{$r['created_by']} registró $asked de {$r['user']['name']}", "{$r['created_by']} registró $asked de {$r['user']['name']}",
                    'La registró Administración a nombre de la persona. Falta tu confirmación para que se aplique.', $rows + ['Registró' => $r['created_by']],
                    [['Revisar y confirmar', appUrl('incidencias/revisar.php'), $navy]]];
                $mails[] = [[$employee], [], "{$r['created_by']} registró $yours ($range)", "{$r['created_by']} registró $yours",
                    "Ahora falta que $adminName la confirme. Te avisaremos en cuanto lo haga.", $rows, $mine];
                break;
            case 'autorizada':
                $mails[] = [$admin, [], "$boss autorizó $the de {$r['user']['name']}", "$boss autorizó $the de {$r['user']['name']}",
                    'Su jefe directo ya la autorizó. Falta tu confirmación para que se aplique.', $rows + ['Autorizó' => "$boss (jefe directo)"],
                    [['Revisar y confirmar', appUrl('incidencias/revisar.php'), $navy]]];
                $mails[] = [[$employee], [], "$boss autorizó $yours ($range)", "$boss autorizó $yours",
                    "Ahora falta que $adminName la confirme. Te avisaremos en cuanto lo haga.", $rows, $mine];
                break;
            case 'rechazada_jefe':
            case 'rechazada':
                $mails[] = [[$employee], $event === 'rechazada_jefe' ? $admin : $manager, 'Tu solicitud de ' . mb_strtolower($type) . " ($range) fue rechazada",
                    "{$r['reviewed_by']} rechazó $yours", 'Si lo necesitas, puedes pedir otras fechas.', $rows + ['Motivo' => $r['reason']], $mine];
                break;
            case 'cancelada_admin':
                $mails[] = [[$employee], $manager, 'Se cancelaron tus vacaciones' . " ($range)", "{$r['reviewed_by']} canceló $yours",
                    'Ya estaban aprobadas, pero se cancelaron. Esos días regresaron a tus días disponibles.', $rows + ['Motivo' => $r['reason']], $mine];
                break;
            case 'aprobada':
                $mails[] = [[$employee], $manager, 'Aprobada: tu solicitud de ' . mb_strtolower($type) . " ($range)",
                    "¡{$r['reviewed_by']} confirmó $yours!",
                    $r['kind'] === 'vacaciones' ? 'Quedó aprobada y ya se descontó de tus días disponibles.' : 'Quedó aprobada y ya justifica esos días en tu asistencia.', $rows, $mine];
                break;
        }
        foreach ($mails as [$to, $cc, $subject, $title, $intro, $mailRows, $buttons]) {
            // Se envían después de responder (comun/segundo_plano.php): el botón no espera al servidor de correo.
            $html = mailTemplate($title, $intro, $mailRows, $buttons, $progress);
            $fromName = (string)(appConfig()['mail']['from_name'] ?? INTRANET_NOMBRE);
            if ($to) intranet_despues(fn() => intranet_correo($to, $cc, $subject, $html, ['from_name' => $fromName]));
        }
    } catch (Throwable $e) {
        error_log('Aviso por correo no enviado: ' . $e->getMessage());
    }
}
