<?php
// Formato de vacaciones en PDF (evidencia de una solicitud ya validada), con el historial de su saldo como en el formato en papel.
// Solo lo descarga quien valida incidencias (asistencia/config.php > mail > cc), desde Todas las incidencias.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/../comun/pdf.php';

$user = incUser($db);
if (!$user || !$user['valida']) {
    http_response_code(403);
    exit('Acceso restringido.');
}
$query = $db->prepare("SELECT s.id, s.usuario_id, s.dias, s.revisado_en, u.nombre_usuario, u.area, u.fecha_ingreso, u.jefe_directo,
        (SELECT j.nombre_usuario FROM usuarios j WHERE j.correo_usuario = TRIM(u.correo_jefe) LIMIT 1) jefe_cuenta
    FROM vacaciones_solicitudes s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ? AND s.estado = 'aprobada'");
$query->execute([(int)($_GET['id'] ?? 0)]);
$request = $query->fetch();
if (!$request) {
    http_response_code(404);
    exit('La solicitud no existe o todavía no está validada.');
}

// Historial hasta esta validación: solicitudes aprobadas antes o junto con esta, y los ajustes de saldo.
$validated = $request['revisado_en'] ?: date('Y-m-d H:i:s');
$history = $db->prepare("SELECT s.id, s.dias, GROUP_CONCAT(d.fecha ORDER BY d.fecha) fechas FROM vacaciones_solicitudes s JOIN vacaciones_dias d ON d.solicitud_id = s.id
    WHERE s.usuario_id = ? AND s.estado = 'aprobada' AND (s.revisado_en <= ? OR s.id = ?) GROUP BY s.id ORDER BY MIN(d.fecha), s.id");
$history->execute([$request['usuario_id'], $validated, $request['id']]);
$requests = array_map(fn($r) => ['id' => (int)$r['id'], 'dates' => explode(',', $r['fechas'])], $history->fetchAll());
$adjust = $db->prepare('SELECT periodo_inicio, dias, motivo FROM vacaciones_ajustes WHERE usuario_id = ? AND creado_en <= ? ORDER BY creado_en, id');
$adjust->execute([$request['usuario_id'], $validated]);
$adjustments = $adjust->fetchAll();
$mine = current(array_filter($requests, fn($r) => $r['id'] === (int)$request['id']));
$myDates = $mine['dates'] ?? [];

// ---------- Fechas en español ----------
const FMT_MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const FMT_DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
function fmtLong(string $date): string
{
    $d = new DateTimeImmutable($date);
    return FMT_DAYS[(int)$d->format('N') - 1] . ', ' . (int)$d->format('j') . ' de ' . FMT_MONTHS[(int)$d->format('n') - 1] . ' de ' . $d->format('Y');
}
function fmtShort(string $date): string
{
    $d = new DateTimeImmutable($date);
    return $d->format('d') . '-' . mb_substr(FMT_MONTHS[(int)$d->format('n') - 1], 0, 3) . '-' . $d->format('y');
}
/** Lista de días agrupada por mes: «28 y 29 de julio de 2025», «30 de julio y 1 de agosto de 2025». */
function fmtDates(array $dates, bool $long = false): string
{
    if (count($dates) === 1 && $long) return fmtLong($dates[0]);
    $groups = [];
    foreach ($dates as $date) $groups[substr($date, 0, 7)][] = (int)substr($date, 8);
    $parts = [];
    $years = array_unique(array_map(fn($m) => substr($m, 0, 4), array_keys($groups)));
    foreach ($groups as $month => $days) {
        $list = count($days) > 1 ? implode(', ', array_slice($days, 0, -1)) . ' y ' . end($days) : (string)$days[0];
        $parts[] = $list . ' de ' . FMT_MONTHS[(int)substr($month, 5) - 1] . (count($years) > 1 ? ' de ' . substr($month, 0, 4) : '');
    }
    $text = count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts) : $parts[0];
    return count($years) > 1 ? $text : $text . ' de ' . $years[0];
}

// ---------- Filas de la tabla: por periodo, el aniversario y luego cada movimiento con su saldo ----------
$rows = [];
if ($request['fecha_ingreso']) {
    $periods = array_reverse(vacationPeriods($request['fecha_ingreso'], max(substr($validated, 0, 10), end($myDates) ?: ''), [], []));
    $blocks = [];
    foreach ($periods as $p) {
        $balance = $p['granted'];
        $block = [['kind' => 'period', 'period' => ((int)substr($p['start'], 0, 4) - 1) . '-' . substr($p['start'], 0, 4), 'years' => $p['year'],
            'law' => $p['granted'], 'text' => 'ANIVERSARIO', 'balance' => $balance, 'note' => fmtShort($p['start'])]];
        foreach ($adjustments as $a) {
            if ($a['periodo_inicio'] !== $p['start']) continue;
            $balance += (int)$a['dias'];
            $block[] = ['kind' => 'move', 'days' => (int)$a['dias'], 'text' => ((int)$a['dias'] < 0 ? 'Tomados antes del sistema' : 'Días adicionales') . ($a['motivo'] ? ': ' . $a['motivo'] : ''), 'balance' => $balance];
        }
        foreach ($requests as $r) {
            if ($r['dates'][0] < $p['start'] || $r['dates'][0] > $p['end']) continue;
            $balance -= count($r['dates']);
            $block[] = ['kind' => 'move', 'days' => -count($r['dates']), 'text' => fmtDates($r['dates']), 'balance' => $balance, 'mine' => $r['id'] === (int)$request['id']];
        }
        $blocks[] = $block;
    }
    // Los aniversarios viejos sin movimientos no se muestran: la tabla empieza en el primer periodo con días tomados
    // o ajustes (o, si no hay ninguno, en el actual) y de ahí en adelante se agrega cada aniversario.
    while (count($blocks) > 1 && count($blocks[0]) === 1) array_shift($blocks);
    $rows = array_merge(...$blocks ?: [[]]);
}

// ---------- Dibujo (carta horizontal) ----------
$pdf = new IntranetPdf();
$pdf->addPage();
$pdf->jpeg(__DIR__ . '/../tickets/img/logo.jpeg', 341, 18, 110, 110);
$left = 48;
$right = 744;
$fields = [
    'FECHA:' => fmtLong(substr($validated, 0, 10)),
    'NOMBRE:' => (string)$request['nombre_usuario'],
    'DEPARTAMENTO DE:' => (string)$request['area'],
    'DIAS SOLICITADOS:' => (string)$request['dias'],
    'FECHA SOLICITADA:' => $myDates ? fmtDates($myDates, true) : '',
];
$y = 150;
foreach ($fields as $label => $value) {
    $pdf->text($left, $y, $label, 8.5, true);
    $pdf->text(200, $y - 2, $value, 9, false, 'C', $right - 200);
    $pdf->line(200, $y + 3, $right, $y + 3);
    $y += 22;
}
// Firmas: el nombre de cada quien dentro de su renglón (el del jefe sale de su cuenta, si no, de «Jefe directo»).
$boss = trim((string)($request['jefe_cuenta'] ?: $request['jefe_directo']));
$pdf->text($left, $y, 'FIRMA DEL TRABAJADOR', 8.5, true);
$pdf->rect(200, $y - 12, $right - 200, 14, null);
$pdf->text(200, $y - 1.5, (string)$request['nombre_usuario'], 9, false, 'C', $right - 200);
$y += 22;
$pdf->text($left, $y, 'FIRMA DEL JEFE INMEDIATO', 8.5, true);
$pdf->rect(200, $y - 12, $right - 200, 14, null);
$pdf->text(200, $y - 1.5, $boss !== '' ? $boss : 'Sin jefe directo registrado', 9, false, 'C', $right - 200, $boss !== '' ? [0, 0, 0] : [150, 150, 150]);
$pdf->rect(200, $y + 2, $right - 200, 14, null);

// Tabla
$widths = [58, 64, 68, 62, 218, 140, 86];
$xs = [$left];
foreach ($widths as $w) $xs[] = end($xs) + $w;
$red = [128, 0, 0];
$cream = [255, 230, 153];
$blue = [197, 217, 241];
$header = function (float $y) use ($pdf, $xs, $widths, $red, $cream, $request): float {
    $pdf->rect($xs[0], $y, $xs[7] - $xs[0], 17, $red);
    $pdf->rect($xs[6], $y, $widths[6], 17, [237, 125, 49]);
    $pdf->text($xs[0], $y + 12, 'V-' . $request['id'], 8.5, true, 'C', $widths[0], $cream);
    $pdf->text($xs[1], $y + 12, (string)$request['nombre_usuario'], 8.5, true, 'L', $xs[5] - $xs[1], $cream);
    $pdf->text($xs[5], $y + 12, 'FECHA DE INGRESO', 8.5, true, 'L', $widths[5], $cream);
    $pdf->text($xs[6], $y + 12, $request['fecha_ingreso'] ? fmtShort($request['fecha_ingreso']) : 'Sin registrar', 9, true, 'C', $widths[6], $cream);
    $y += 17;
    $titles = ['Periodo', 'Antigüedad', 'Días por ley', "Días\ndisfrutados", 'Fecha en que disfruto las vacaciones', 'Días a favor o en contra', 'Observaciones'];
    foreach ($titles as $i => $title) {
        $pdf->rect($xs[$i], $y, $widths[$i], 30, null);
        $lines = explode("\n", $title);
        foreach ($lines as $n => $line) $pdf->text($xs[$i], $y + 18 - (count($lines) - 1) * 5 + $n * 10, $line, 8, true, 'C', $widths[$i]);
    }
    return $y + 30;
};
$y = $header($y + 36);
$rowH = 13.5;
$minRows = max(count($rows), 12);
for ($n = 0; $n < $minRows; $n++) {
    if ($y + $rowH > 590) {
        $pdf->addPage();
        $y = $header(36);
    }
    $row = $rows[$n] ?? null;
    $period = $row && $row['kind'] === 'period';
    foreach ($widths as $i => $w) $pdf->rect($xs[$i], $y, $w, $rowH, $period ? $blue : null);
    if ($row) {
        $base = $y + 9.8;
        $bold = !empty($row['mine']);
        if ($period) {
            $pdf->text($xs[0], $base, $row['period'], 7.5, false, 'C', $widths[0]);
            $pdf->text($xs[1], $base, (string)$row['years'], 7.5, false, 'C', $widths[1]);
            $pdf->text($xs[2], $base, (string)$row['law'], 7.5, false, 'C', $widths[2]);
            $pdf->text($xs[4], $base, $row['text'], 9, false, 'C', $widths[4]);
            $pdf->text($xs[6], $base, $row['note'], 9, false, 'C', $widths[6]);
        } else {
            $pdf->text($xs[3], $base, ($row['days'] > 0 ? '+' : '') . $row['days'], 7.5, $bold, 'C', $widths[3], $row['days'] < 0 ? [220, 20, 60] : [0, 128, 0]);
            $pdf->text($xs[4], $base, $row['text'], 7.5, $bold, 'C', $widths[4]);
        }
        $pdf->text($xs[5], $base, (string)$row['balance'], 7.5, $bold, 'C', $widths[5], $row['balance'] < 0 ? [220, 20, 60] : [0, 0, 0]);
    }
    $y += $rowH;
}
if (!$request['fecha_ingreso']) $pdf->text($left, $y + 14, 'Falta registrar la fecha de ingreso en Usuarios: no se puede calcular el historial de vacaciones.', 8, false, 'L', 0, [220, 20, 60]);

$name = 'Vacaciones ' . $request['nombre_usuario'] . ' V-' . $request['id'] . '.pdf';
header('Content-Type: application/pdf');
header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\w.\- ]/u', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
echo $pdf->output();
