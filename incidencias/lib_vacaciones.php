<?php
declare(strict_types=1);
// Cálculo de vacaciones según la Ley Federal del Trabajo (reforma 2023).
// Intranet: las personas salen de la vista personal_usuarios (todo el personal, registre o no jornada).
// Reglas de la empresa:
//  - Los días se generan en cada aniversario de la fecha de ingreso (sin adelantos antes del primero).
//  - Solo se pueden usar dentro de ese periodo (de un aniversario al siguiente); lo no usado se pierde.
//  - El empleado las pide; las autoriza su jefe directo y las confirma Administración (lib_solicitudes.php).
//  - Las dermoconsejeras trabajan fines de semana: a ellas también se les cuentan sábados y domingos (los festivos no).
//    El administrador también puede registrarlas directamente (quedan aprobadas).

// Días de descanso obligatorio (artículo 74 de la Ley Federal del Trabajo) de un año: fecha => nombre.
function mxHolidays(int $year): array {
    // n-ésimo lunes del mes.
    $monday = function (int $n, int $month) use ($year): string {
        $first = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $offset = (8 - (int)$first->format('N')) % 7;
        return $first->modify('+' . ($offset + ($n - 1) * 7) . ' days')->format('Y-m-d');
    };
    $days = [
        "$year-01-01" => 'Año Nuevo',
        $monday(1, 2) => 'Día de la Constitución',
        $monday(3, 3) => 'Natalicio de Benito Juárez',
        "$year-05-01" => 'Día del Trabajo',
        "$year-09-16" => 'Día de la Independencia',
        $monday(3, 11) => 'Día de la Revolución',
        "$year-12-25" => 'Navidad',
    ];
    // Transmisión del Poder Ejecutivo Federal: 1 de octubre cada seis años (2024, 2030, ...).
    if ($year >= 2024 && ($year - 2024) % 6 === 0) $days["$year-10-01"] = 'Transmisión del Poder Ejecutivo';
    ksort($days);
    return $days;
}

// Días hábiles (lunes a viernes, sin festivos oficiales) entre dos fechas, inclusive.
// $weekends: también sábados y domingos (quien trabaja fines de semana, ver vacationWeekendIds()).
function vacationWorkdays(string $from, string $to, bool $weekends = false): array {
    $holidays = [];
    $out = [];
    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
        $year = (int)$d->format('Y');
        $holidays[$year] ??= mxHolidays($year);
        if (($weekends || (int)$d->format('N') <= 5) && !isset($holidays[$year][$d->format('Y-m-d')])) $out[] = $d->format('Y-m-d');
    }
    return $out;
}

// Personas que trabajan fines de semana (dermoconsejeras): sus vacaciones también se toman en sábado y domingo.
function vacationWeekendIds(PDO $db): array {
    return array_map('intval', $db->query("SELECT id FROM usuarios WHERE puesto = 'dermoconsejera'")->fetchAll(PDO::FETCH_COLUMN));
}

// Días de vacaciones por años de servicio cumplidos: 12, 14, 16, 18, 20 y luego +2 cada 5 años.
function lftVacationDays(int $years): int {
    if ($years < 1) return 0;
    if ($years <= 5) return 10 + $years * 2;
    return 22 + intdiv($years - 6, 5) * 2;
}

// Periodos de vacaciones de una persona hasta $today, del más reciente al más antiguo.
// $dates: ['aprobada' => [fechas], 'pendiente' => [fechas en revisión]]; $adjustments: filas de vacaciones_ajustes.
function vacationPeriods(string $hireDate, string $today, array $dates, array $adjustments): array {
    $hire = new DateTimeImmutable($hireDate);
    $periods = [];
    for ($k = 1; ; $k++) {
        $start = $hire->modify("+$k years")->format('Y-m-d');
        if ($start > $today) break;
        $end = $hire->modify('+' . ($k + 1) . ' years')->modify('-1 day')->format('Y-m-d');
        $inside = fn(array $list) => count(array_filter($list, fn($d) => $d >= $start && $d <= $end));
        $adjust = array_values(array_filter($adjustments, fn($a) => $a['periodo_inicio'] === $start));
        $granted = lftVacationDays($k);
        $adjusted = array_sum(array_map(fn($a) => (int)$a['dias'], $adjust));
        // Ajustes separados: días extra otorgados y días tomados fuera del sistema (saldo inicial).
        $extra = array_sum(array_map(fn($a) => max(0, (int)$a['dias']), $adjust));
        $prior = array_sum(array_map(fn($a) => max(0, -(int)$a['dias']), $adjust));
        $taken = $inside($dates['aprobada'] ?? []);
        $pending = $inside($dates['pendiente'] ?? []);
        $available = $granted + $adjusted - $taken - $pending;
        $current = $end >= $today;
        $periods[] = [
            'year' => $k, 'start' => $start, 'end' => $end, 'current' => $current,
            'granted' => $granted, 'adjusted' => $adjusted, 'extra' => $extra, 'prior' => $prior, 'taken' => $taken, 'pending' => $pending,
            // Totales para mostrar: lo que le corresponde (ley + extra) y lo usado (sistema + antes del sistema).
            'entitled' => $granted + $extra, 'used' => $taken + $prior,
            'available' => $current ? max(0, $available) : 0,
            'expired' => $current ? 0 : max(0, $available),
            'adjustments' => array_map(fn($a) => [
                'id' => (int)$a['id'], 'days' => (int)$a['dias'], 'reason' => $a['motivo'], 'created_at' => $a['creado_en'],
            ], $adjust),
        ];
    }
    return array_reverse($periods);
}

// Crea una solicitud de vacaciones del rango [from, to] (solo días hábiles; con fines de semana para las dermoconsejeras) validando periodo, días ocupados y saldo.
// $status: 'aprobada' (la registra el administrador), 'pendiente' (la pide el empleado y espera a su jefe)
// o 'autorizada' (la pide alguien sin jefe directo: espera a Administración).
// Devuelve ['days' => n, 'id' => id, 'weekends' => cuenta fines de semana] o ['error' => mensaje, 'code' => http].
function createVacationRequest(PDO $db, int $userId, string $from, string $to, string $comment, string $status, int $actorId): array {
    $summary = vacationSummaries($db, $userId)[0] ?? null;
    if (!$summary) return ['error' => 'El usuario no existe.', 'code' => 404];
    $current = $summary['current'];
    if (!$current) return ['error' => $summary['hire_date'] ? 'Aun no cumple un año: todavia no tiene dias de vacaciones.' : 'Falta registrar la fecha de ingreso; pidela al administrador.', 'code' => 422];
    if (!validDate($from, $current['start'], $current['end']) || !validDate($to, $from, $current['end'])) {
        return ['error' => 'Las fechas deben estar dentro del periodo actual (' . date('d/m/Y', strtotime($current['start'])) . ' al ' . date('d/m/Y', strtotime($current['end'])) . ').', 'code' => 422];
    }
    if (mb_strlen($comment) > 500) return ['error' => 'El comentario admite hasta 500 caracteres.', 'code' => 422];
    $days = vacationWorkdays($from, $to, $summary['weekends']);
    if (!$days) return ['error' => $summary['weekends'] ? 'Ese rango solo tiene dias festivos.' : 'Ese rango no tiene dias habiles (fines de semana o festivos).', 'code' => 422];
    $db->beginTransaction();
    $db->prepare('SELECT id FROM personal_usuarios WHERE id = ? FOR UPDATE')->execute([$userId]);
    $in = implode(',', array_fill(0, count($days), '?'));
    $taken = $db->prepare("SELECT d.fecha FROM vacaciones_dias d JOIN vacaciones_solicitudes s ON s.id = d.solicitud_id
        WHERE d.usuario_id = ? AND s.estado IN ('aprobada', 'pendiente', 'autorizada') AND d.fecha IN ($in)
        UNION SELECT f.fecha FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id
        WHERE f.usuario_id = ? AND i.estado IN ('aprobada', 'pendiente', 'autorizada') AND f.fecha IN ($in) ORDER BY 1");
    $taken->execute([$userId, ...$days, $userId, ...$days]);
    if ($busy = $taken->fetchAll(PDO::FETCH_COLUMN)) {
        $db->rollBack();
        return ['error' => 'Ya hay vacaciones, permiso o incapacidad el ' . implode(', ', array_map(fn($d) => date('d/m/Y', strtotime($d)), $busy)) . '.', 'code' => 409];
    }
    // El saldo se vuelve a calcular dentro de la transacción (las solicitudes pendientes ya apartan sus días).
    $available = vacationSummaries($db, $userId)[0]['current']['available'];
    if (count($days) > $available) {
        $db->rollBack();
        return ['error' => 'Solo quedan ' . $available . ' dias disponibles; ese rango usa ' . count($days) . '.', 'code' => 422];
    }
    $approved = $status === 'aprobada';
    $db->prepare('INSERT INTO vacaciones_solicitudes (usuario_id, estado, dias, comentario, creado_por, revisado_por, revisado_en) VALUES (?, ?, ?, ?, ?, ?, ' . ($approved ? 'NOW()' : 'NULL') . ')')
        ->execute([$userId, $status, count($days), $comment ?: null, $actorId, $approved ? $actorId : null]);
    $requestId = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO vacaciones_dias (solicitud_id, usuario_id, fecha) VALUES (?, ?, ?)');
    foreach ($days as $day) $insert->execute([$requestId, $userId, $day]);
    $db->commit();
    return ['days' => count($days), 'id' => $requestId, 'weekends' => $summary['weekends']];
}

// Vacaciones aprobadas en el formato de incidencias (para que asistencia las cuente como justificadas).
function vacationIncidents(PDO $db, string $from, string $to, ?int $userId = null): array {
    $query = $db->prepare("SELECT s.id, s.usuario_id, s.comentario, s.creado_en, d.fecha FROM vacaciones_dias d
        JOIN vacaciones_solicitudes s ON s.id = d.solicitud_id
        WHERE s.estado = 'aprobada' AND d.fecha BETWEEN ? AND ?" . ($userId ? ' AND s.usuario_id = ' . (int)$userId : '') . ' ORDER BY s.id, d.fecha');
    $query->execute([$from, $to]);
    $out = [];
    foreach ($query as $r) {
        $key = 'vac-' . $r['id'];
        $out[$key] ??= ['id' => $key, 'user_id' => (int)$r['usuario_id'], 'type' => 'vacaciones', 'source' => 'vacaciones',
            'description' => $r['comentario'], 'file' => null, 'created_at' => $r['creado_en'], 'dates' => []];
        $out[$key]['dates'][] = $r['fecha'];
    }
    return array_values($out);
}

// Resumen de vacaciones de cada usuario activo (para la pestaña Vacaciones).
function vacationSummaries(PDO $db, ?int $onlyUser = null): array {
    $today = date('Y-m-d');
    $where = $onlyUser ? 'AND u.id = ' . (int)$onlyUser : '';
    $users = $db->query("SELECT u.id, u.nombre, u.correo, u.area, u.rol, u.fecha_ingreso FROM personal_usuarios u WHERE u.eliminado_en IS NULL $where ORDER BY u.nombre")->fetchAll();
    $dates = [];
    foreach ($db->query("SELECT d.usuario_id, d.fecha, s.estado FROM vacaciones_dias d JOIN vacaciones_solicitudes s ON s.id = d.solicitud_id WHERE s.estado IN ('aprobada', 'pendiente', 'autorizada')") as $r) {
        // Pendientes (con su jefe) y autorizadas (con Administración) apartan sus días por igual.
        $dates[(int)$r['usuario_id']][$r['estado'] === 'aprobada' ? 'aprobada' : 'pendiente'][] = $r['fecha'];
    }
    $requests = [];
    $rows = $db->query("SELECT s.*, GROUP_CONCAT(d.fecha ORDER BY d.fecha) AS fechas, c.nombre AS creador, a.nombre AS autorizador, r.nombre AS revisor
        FROM vacaciones_solicitudes s LEFT JOIN vacaciones_dias d ON d.solicitud_id = s.id
        LEFT JOIN personal_usuarios c ON c.id = s.creado_por LEFT JOIN personal_usuarios a ON a.id = s.autorizado_por LEFT JOIN personal_usuarios r ON r.id = s.revisado_por
        GROUP BY s.id ORDER BY s.creado_en DESC, s.id DESC");
    foreach ($rows as $s) {
        $requests[(int)$s['usuario_id']][] = [
            'id' => (int)$s['id'], 'status' => $s['estado'], 'days' => (int)$s['dias'], 'dates' => $s['fechas'] ? explode(',', $s['fechas']) : [],
            'comment' => $s['comentario'], 'created_at' => $s['creado_en'], 'created_by' => $s['creador'],
            'authorized_at' => $s['autorizado_en'], 'authorized_by' => $s['autorizador'],
            'reviewed_at' => $s['revisado_en'], 'reviewed_by' => $s['revisor'], 'reason' => $s['motivo_revision'],
        ];
    }
    $busy = [];
    foreach ($db->query("SELECT f.usuario_id, f.fecha FROM incidencia_fechas f JOIN incidencias i ON i.id = f.incidencia_id WHERE i.estado IN ('aprobada', 'pendiente', 'autorizada')") as $r) $busy[(int)$r['usuario_id']][] = $r['fecha'];
    $adjustments = [];
    foreach ($db->query('SELECT * FROM vacaciones_ajustes ORDER BY creado_en, id') as $a) {
        $adjustments[(int)$a['usuario_id']][] = $a;
    }
    // Desde cuándo se registran vacaciones aquí: los periodos que terminaron antes no se marcan como vencidos.
    $weekendIds = array_flip(vacationWeekendIds($db));
    $since = $db->query('SELECT LEAST(COALESCE((SELECT MIN(DATE(creado_en)) FROM vacaciones_ajustes), CURDATE()),
        COALESCE((SELECT MIN(DATE(creado_en)) FROM vacaciones_solicitudes), CURDATE()))')->fetchColumn();
    return array_map(function (array $u) use ($today, $dates, $adjustments, $since, $requests, $busy, $weekendIds) {
        $id = (int)$u['id'];
        $base = ['id' => $id, 'name' => $u['nombre'], 'email' => $u['correo'], 'area' => $u['area'], 'rol' => $u['rol'], 'hire_date' => $u['fecha_ingreso'],
            'requests' => $requests[$id] ?? [], 'busy' => $busy[$id] ?? [], 'weekends' => isset($weekendIds[$id])];
        if (!$u['fecha_ingreso']) return $base + ['periods' => [], 'current' => null, 'next' => null, 'years' => null];
        $hire = new DateTimeImmutable($u['fecha_ingreso']);
        $since = $hire->diff(new DateTimeImmutable($today));
        $periods = $u['fecha_ingreso'] <= $today ? vacationPeriods($u['fecha_ingreso'], $today, $dates[$id] ?? [], $adjustments[$id] ?? []) : [];
        foreach ($periods as &$period) {
            $period['before_system'] = !$period['current'] && $period['end'] < $since && !$period['taken'] && !$period['adjustments'];
            if ($period['before_system']) $period['expired'] = 0;
        }
        unset($period);
        $nextYear = count($periods) + 1;
        return $base + [
            'years' => $since->invert ? 0 : $since->y,
            'months' => $since->invert ? 0 : $since->m,
            'periods' => $periods,
            'current' => $periods[0]['current'] ?? false ? $periods[0] : null,
            'next' => ['date' => $hire->modify("+$nextYear years")->format('Y-m-d'), 'days' => lftVacationDays($nextYear)],
        ];
    }, $users);
}
