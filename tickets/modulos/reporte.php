<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/reporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
require_ticket_user();
if (!is_supervisor()) { http_response_code(403); exit('Acceso exclusivo de supervisión.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
try {
    $filters = report_filters();
    [$from, $params] = report_query($filters);
    $query = database()->prepare(report_columns() . $from . ' ORDER BY t.id DESC');
    $query->execute($params);
    audit('reporte_csv', 'Filtros: ' . json_encode(array_filter($filters, static fn($v) => $v !== ''), JSON_UNESCAPED_UNICODE));
    $catalog = ticket_problems();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte-tickets-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Folio','Creación','Solicitante','Área','Correo creador','Estado','Problemas','Descripción','Validó','Correo validador','Fecha validación','Resuelto por','Fecha resolución','Solución','Minutos hasta validación','Minutos hasta resolución'], ',', '"', '');
    while ($row = $query->fetch()) {
        $problems = array_map(static function ($key) use ($catalog) { return $catalog[$key] ?? $key; }, json_decode($row['problemas'] ?? '[]', true) ?: []);
        $cells = [$row['id'], $row['creado_en'] ?: $row['fecha_ticket'], $row['nombre_usuario'], $row['area'], $row['creado_por'], support_status((int)$row['estatus_ticket']), implode(' | ', $problems), $row['descripcion'], $row['validador'], $row['correo_validador'], $row['validado_en'], $row['resuelto_por'], $row['resuelto_en'] ?: $row['fecha_solucion'], $row['solucion'], $row['minutos_validacion'], $row['minutos_resolucion']];
        fputcsv($output, array_map('report_csv_cell', $cells), ',', '"', '');
    }
    fclose($output);
} catch (InvalidArgumentException $exception) {
    http_response_code(400); echo $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Reporte: ' . $exception->getMessage());
    http_response_code(500); echo 'No se pudo generar el reporte.';
}
