<?php
declare(strict_types=1);
/*
 * Jefe directo: el «Jefe directo» de la cuenta (correo_jefe), ver bossSql() en lib_solicitudes.php.
 *
 * Acciones de Incidencias (POST con el token CSRF en el encabezado X-CSRF-Token; JSON o multipart con archivo).
 * Validación en dos pasos (asistencia v2, lib_solicitudes.php):
 *   pendiente (la autoriza el jefe directo) -> autorizada (la confirma quien valida incidencias) -> aprobada.
 *   Quien no tiene jefe directo empieza como autorizada. En cada paso se avisa por correo (notifyRequest).
 *
 *   crear      la propia solicitud, o para administración, a nombre de otra persona: aprobada si la registra quien
 *              valida incidencias; si no, autorizada (espera a quien valida)
 *   cancelar   una solicitud propia que aún no se aprueba
 *   cancelar_aprobada  quien valida cancela unas vacaciones ya aprobadas (con motivo)
 *   autorizar  el jefe directo autoriza o rechaza (con motivo) lo que pide su equipo
 *   revisar    quien valida incidencias (asistencia/config.php > mail > cc) aprueba o rechaza; también sin esperar al jefe
 *   ajustar    administración ajusta el saldo de vacaciones del año en curso
 */
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Método no permitido.'], 405);
if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error' => 'La sesión cambió. Recarga la página.'], 403);
if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) respond(['error' => 'El archivo es demasiado grande.'], 413);
    $body = $_POST;
} else {
    $body = json_decode((string)file_get_contents('php://input'), true);
}
if (!is_array($body)) respond(['error' => 'Solicitud no válida.'], 400);
$me = incUser($db);
if (!$me || !feature('vacaciones')) respond(['error' => 'Inicia sesión para continuar.'], 401);
$admin = $me['rol'] === 'admin';
$action = (string)($body['action'] ?? '');
$text = fn(string $key): string => trim((string)($body[$key] ?? ''));
// Las solicitudes vienen de dos tablas: vacaciones_solicitudes ('vacaciones') e incidencias ('incidencia').
$kind = fn(): string => $text('source') === 'vacaciones' ? 'vacaciones' : 'incidencia';

try {
    if ($action === 'crear') {
        $type = $text('tipo');
        if (!isset(INC_TYPES[$type])) respond(['error' => 'Elige el tipo de incidencia.'], 422);
        $target = $text('usuario') === '' ? $me['id'] : (int)$text('usuario');
        $forOther = $target !== $me['id'];
        if ($forOther && !$admin) respond(['error' => 'Solo administración registra incidencias de otras personas.'], 403);
        $exists = $db->prepare('SELECT COUNT(*) FROM personal_usuarios WHERE id = ? AND eliminado_en IS NULL');
        $exists->execute([$target]);
        if (!$exists->fetchColumn()) respond(['error' => 'Esa persona no tiene una cuenta activa.'], 404);
        // Propia: empieza con su jefe directo (o con quien valida si no tiene jefe). Por otra persona: ya viene de administración.
        $status = $forOther ? ($me['valida'] ? 'aprobada' : 'autorizada') : initialStatus($db, $target);

        $dates = json_decode($text('fechas') ?: '[]', true);
        if (!is_array($dates) || !$dates) respond(['error' => 'Elige los días en el calendario.'], 422);
        $dates = array_values(array_unique(array_map('strval', $dates)));
        sort($dates);
        // Solo de hoy en adelante: los días pasados no se pueden pedir ni registrar.
        $min = date('Y-m-d');
        $max = date('Y-m-d', strtotime('+1 year'));
        foreach ($dates as $date) {
            if (!validDate($date, $min, $max)) respond(['error' => 'Solo se pueden elegir días de hoy en adelante (hasta un año).'], 422);
        }
        $description = $text('descripcion');
        if (mb_strlen($description) > 2000) respond(['error' => 'La descripción admite hasta 2000 caracteres.'], 422);

        if ($type === 'vacaciones') {
            // Mismas reglas que asistencia: dentro del año de vacaciones, solo días hábiles y sin pasar del saldo.
            $result = createVacationRequest($db, $target, $dates[0], end($dates), $description, $status, $me['id']);
            if (isset($result['error'])) respond(['error' => $result['error']], $result['code']);
            $id = $result['id'];
            // A quien trabaja fines de semana se le cuentan también sábados y domingos: no son «hábiles».
            $days = $result['days'] . ($result['weekends'] ? ($result['days'] === 1 ? ' día' : ' días') : ($result['days'] === 1 ? ' día hábil' : ' días hábiles'));
        } else {
            // Como en asistencia v2: permisos e incapacidades solo cuentan días hábiles (sin fines de semana ni festivos).
            $dates = array_values(array_intersect($dates, vacationWorkdays($dates[0], end($dates))));
            if (!$dates) respond(['error' => 'Esos días no son hábiles (fines de semana o festivos).'], 422);
            $extra = [];
            if ($type === 'permiso') {
                if (mb_strlen($description) < 3) respond(['error' => 'Escribe el motivo del permiso.'], 422);
                $extra['goce'] = $text('goce') === '0' ? 0 : 1;
            } else {
                $extra['subtipo'] = $text('subtipo');
                if (!in_array($extra['subtipo'], INC_SICK_TYPES, true)) respond(['error' => 'Elige el tipo de incapacidad.'], 422);
                $extra['folio'] = $text('folio') ?: null;
                if ($extra['folio'] !== null && !preg_match('/^[A-Za-z0-9\-]{1,30}$/', $extra['folio'])) respond(['error' => 'El folio admite letras, números y guiones (hasta 30).'], 422);
                if ($description === '') $description = $extra['subtipo'];
            }
            $file = $_FILES['archivo'] ?? null;
            if ($file && $file['error'] === UPLOAD_ERR_NO_FILE) $file = null;
            $result = createIncident($db, $target, $type, $description, $dates, $file, $me['id'], $status, $extra);
            if (isset($result['error'])) respond(['error' => $result['error']], $result['code']);
            $id = $result['id'];
            $days = count($dates) . (count($dates) === 1 ? ' día hábil' : ' días hábiles');
        }
        $source = $type === 'vacaciones' ? 'vacaciones' : 'incidencia';
        // Avisos: la propia solicitud va a su jefe (o a quien valida); la registrada por administración, a quien valida.
        if (!$forOther) notifyRequest($db, 'nueva', $source, $id);
        elseif ($status === 'autorizada') notifyRequest($db, 'registrada', $source, $id);
        $next = match ($status) {
            'aprobada' => 'Quedó aprobada.',
            'pendiente' => 'Primero la autoriza ' . (directManager($db, $target)['name'] ?? 'su jefe directo') . ' y después la confirma ' . adminContact($db)['name'] . '.',
            default => adminContact($db)['name'] . ' la confirmará.',
        };
        respond(['ok' => true, 'message' => 'Solicitud de ' . mb_strtolower(INC_TYPES[$type]) . " registrada ($days). $next"]);
    }

    if ($action === 'cancelar') {
        if (!cancelOwnRequest($db, $kind(), (int)$text('id'), $me['id'])) respond(['error' => 'Solo puedes cancelar solicitudes que aún no se aprueban.'], 409);
        respond(['ok' => true, 'message' => 'Solicitud cancelada: esos días quedaron libres.']);
    }

    // Quien valida puede cancelar unas vacaciones ya aprobadas por el jefe y por Administración (Todas las incidencias).
    if ($action === 'cancelar_aprobada') {
        if (!$me['valida']) respond(['error' => 'Solo quien valida incidencias puede cancelar vacaciones aprobadas.'], 403);
        $reason = $text('motivo');
        if ($reason === '' || mb_strlen($reason) > 200) respond(['error' => 'Escribe el motivo de la cancelación (hasta 200 caracteres).'], 422);
        $id = (int)$text('id');
        if (!cancelApprovedVacation($db, $id, $me['id'], $reason)) respond(['error' => 'Esas vacaciones ya no están aprobadas (quizá ya se cancelaron).'], 409);
        notifyRequest($db, 'cancelada_admin', 'vacaciones', $id);
        respond(['ok' => true, 'message' => 'Vacaciones canceladas: los días regresaron a su saldo y se le avisó por correo.']);
    }

    if ($action === 'autorizar') {
        // reviewRequest() comprueba que sea el jefe directo de la persona.
        $result = reviewRequest($db, $kind(), (int)$text('id'), $text('decision'), $text('motivo'), $me['id'], 'jefe');
        if (isset($result['error'])) respond(['error' => $result['error']], $result['code']);
        notifyRequest($db, $result['event'], $kind(), (int)$text('id'));
        respond(['ok' => true, 'pending' => managerPendingCount($db, $me['id']),
            'message' => $text('decision') === 'autorizar' ? 'Autorizada: ahora la confirma ' . adminContact($db)['name'] . '.' : 'Solicitud rechazada; se le avisó por correo.']);
    }

    if ($action === 'revisar') {
        if (!$me['valida']) respond(['error' => 'No tienes permiso para validar incidencias.'], 403);
        $table = $kind() === 'vacaciones' ? 'vacaciones_solicitudes' : 'incidencias';
        $owner = $db->prepare("SELECT usuario_id FROM $table WHERE id = ?");
        $owner->execute([(int)$text('id')]);
        // Nadie aprueba sus propias solicitudes.
        if ((int)$owner->fetchColumn() === $me['id']) respond(['error' => 'Tu propia solicitud la valida otra persona.'], 403);
        $result = reviewRequest($db, $kind(), (int)$text('id'), $text('decision'), $text('motivo'), $me['id'], 'admin');
        if (isset($result['error'])) respond(['error' => $result['error']], $result['code']);
        notifyRequest($db, $result['event'], $kind(), (int)$text('id'));
        respond(['ok' => true, 'pending' => incPendingCount($db), 'message' => $text('decision') === 'aprobar' ? 'Solicitud aprobada.' : 'Solicitud rechazada; se le avisó por correo.']);
    }

    if ($action === 'ajustar') {
        if (!$admin) respond(['error' => 'Solo administración ajusta saldos.'], 403);
        $userId = (int)$text('usuario');
        $days = filter_var($text('dias'), FILTER_VALIDATE_INT);
        $reason = $text('motivo');
        $current = vacationSummaries($db, $userId)[0]['current'] ?? null;
        if (!$current) respond(['error' => 'Esa persona no tiene un año de vacaciones en curso.'], 422);
        if ($days === false || $days === 0 || abs($days) > 60) respond(['error' => 'Indica de -60 a 60 días (distinto de 0).'], 422);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) respond(['error' => 'Escribe el motivo del ajuste.'], 422);
        $db->prepare('INSERT INTO vacaciones_ajustes (usuario_id, periodo_inicio, dias, motivo, creado_por) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $current['start'], $days, $reason, $me['id']]);
        respond(['ok' => true, 'message' => 'Ajuste guardado.']);
    }

    respond(['error' => 'Acción no válida.'], 400);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('incidencias/api: ' . $e->getMessage());
    respond(['error' => 'No se pudo completar la operación. Intenta de nuevo.'], 500);
}
