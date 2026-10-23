<?php
// Descarga el comprobante de un permiso o incapacidad: lo ve la propia persona, su jefe directo y administración.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = incUser($db);
if (!$user) {
    http_response_code(403);
    exit('Acceso restringido.');
}
$query = $db->prepare('SELECT usuario_id, archivo_nombre, archivo_ruta, archivo_tipo FROM incidencias WHERE id = ? AND archivo_ruta IS NOT NULL');
$query->execute([(int)($_GET['id'] ?? 0)]);
$file = $query->fetch();
$allowed = $file && ($user['rol'] === 'admin' || (int)$file['usuario_id'] === $user['id']
    || in_array((int)$file['usuario_id'], subordinateIds($db, $user['id']), true));
$path = $allowed ? UPLOADS_DIR . '/' . basename($file['archivo_ruta']) : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}
$name = $file['archivo_nombre'] ?: basename($path);
header('Content-Type: ' . $file['archivo_tipo']);
header('Content-Length: ' . filesize($path));
// Siempre como descarga: el navegador no ejecuta ni muestra el contenido dentro del sitio.
header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\w.\- ]/u', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
readfile($path);
