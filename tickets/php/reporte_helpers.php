<?php
declare(strict_types=1);

function report_filters(): array
{
    $result = [];
    foreach (report_filter_keys() as $key) {
        $value = $_GET[$key] ?? '';
        if (!is_string($value) || mb_strlen($value) > 200) throw new InvalidArgumentException('Filtro no válido.');
        $result[$key] = trim($value);
    }
    if (!in_array($result['estado'], ['', '0', '1', '2'], true)) throw new InvalidArgumentException('Estado no válido.');
    if ($result['empleado'] !== '' && !ctype_digit($result['empleado'])) throw new InvalidArgumentException('Empleado no válido.');
    foreach (['desde', 'hasta'] as $key) {
        if ($result[$key] === '') continue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $result[$key]);
        if (!$date || $date->format('Y-m-d') !== $result[$key]) throw new InvalidArgumentException('Fecha no válida.');
    }
    if ($result['desde'] && $result['hasta'] && $result['desde'] > $result['hasta']) throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');
    return $result;
}

function report_filter_keys(): array
{
    return ['estado', 'desde', 'hasta', 'q', 'empleado', 'area'];
}

/** Detalle (d) y solicitante (u) de cada ticket. Shared by reports and the support panel (soporte.php). */
function ticket_requester_join(): string
{
    // Tickets without details fall back to the creator email to find the employee and area.
    return ' LEFT JOIN ticket_detalles d ON d.ticket_id = t.id
        LEFT JOIN usuarios u ON u.id = COALESCE(d.usuario_id, (SELECT MIN(x.id) FROM usuarios x WHERE x.correo_usuario = t.creado_por))';
}

function report_query(array $filters): array
{
    $from = ' FROM tickets t' . ticket_requester_join() . '
        LEFT JOIN ticket_seguimiento v ON v.id = (SELECT MIN(s.id) FROM ticket_seguimiento s WHERE s.ticket_id = t.id AND s.accion = \'validar\')
        LEFT JOIN usuarios a ON a.id = v.usuario_id
        LEFT JOIN ticket_seguimiento r ON r.id = (SELECT MAX(s.id) FROM ticket_seguimiento s WHERE s.ticket_id = t.id AND s.accion = \'resolver\')';
    $where = []; $params = [];
    if ($filters['estado'] !== '') { $where[] = 't.estatus_ticket = ?'; $params[] = (int)$filters['estado']; }
    if ($filters['desde']) { $where[] = 't.fecha_ticket >= ?'; $params[] = $filters['desde']; }
    if ($filters['hasta']) { $where[] = 't.fecha_ticket <= ?'; $params[] = $filters['hasta']; }
    if ($filters['empleado'] !== '') { $where[] = 'u.id = ?'; $params[] = (int)$filters['empleado']; }
    if ($filters['area'] !== '') { $where[] = 'COALESCE(NULLIF(u.area, \'\'), \'Sin área\') = ?'; $params[] = $filters['area']; }
    if ($filters['q']) {
        $where[] = '(CAST(t.id AS CHAR) = ? OR t.creado_por LIKE ? OR u.nombre_usuario LIKE ?)';
        array_push($params, $filters['q'], '%' . $filters['q'] . '%', '%' . $filters['q'] . '%');
    }
    return [$from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params];
}

function report_columns(): string
{
    return 'SELECT t.*, u.nombre_usuario, COALESCE(NULLIF(u.area, \'\'), \'Sin área\') area, d.problemas, COALESCE(d.descripcion,t.comentarios) descripcion,
        d.creado_en, v.fecha validado_en, a.nombre_usuario validador, a.correo_usuario correo_validador,
        r.fecha resuelto_en, r.comentario solucion,
        TIMESTAMPDIFF(MINUTE,d.creado_en,v.fecha) minutos_validacion,
        TIMESTAMPDIFF(MINUTE,d.creado_en,r.fecha) minutos_resolucion';
}

function report_duration($minutes): string
{
    if ($minutes === null || (int)$minutes < 0) return 'Sin registro';
    return intdiv((int)$minutes, 60) . ' h ' . ((int)$minutes % 60) . ' min';
}

function report_csv_cell($value): string
{
    $value = (string)($value ?? '');
    // Prevent spreadsheet formulas in user-controlled cells.
    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'" . $value : $value;
}
