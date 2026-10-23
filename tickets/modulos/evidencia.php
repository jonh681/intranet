<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/seguridad.php';
require_ticket_user();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    exit('Evidencia no encontrada.');
}
try {
    $db = database();
    $all = is_support() || is_supervisor();
    $query = $db->prepare('SELECT e.ticket_id, e.nombre, e.mime, e.tamano, e.contenido
        FROM ticket_evidencias e JOIN ticket_detalles d ON d.ticket_id = e.ticket_id
        WHERE e.id = ?' . ($all ? '' : ' AND d.usuario_id = ?'));
    $query->execute($all ? [$id] : [$id, $_SESSION['user']['id']]);
    $file = $query->fetch();
    if (!$file || !in_array($file['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(404);
        exit('Evidencia no encontrada.');
    }
    $content = reveal_blob($file['contenido']); // las evidencias se guardan cifradas
    if ($all) audit('ver_evidencia', 'Ticket #' . $file['ticket_id'] . ', evidencia ' . $id);
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: inline; filename="' . basename($file['nombre']) . '"');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    echo $content;
} catch (Throwable $exception) {
    error_log('Evidencia: ' . $exception->getMessage());
    http_response_code(500);
    echo 'No se pudo abrir la evidencia.';
}
