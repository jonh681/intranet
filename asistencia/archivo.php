<?php
// Descarga la evidencia de una incidencia: administradores o la persona a la que pertenece.
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$user = currentUser($db);
if (!feature('incidencias')) {
    http_response_code(404);
    exit('No disponible.');
}
$query = $db->prepare('SELECT usuario_id, archivo_nombre, archivo_ruta, archivo_tipo FROM incidencias WHERE id = ? AND archivo_ruta IS NOT NULL');
$query->execute([(int)($_GET['id'] ?? 0)]);
$file = $query->fetch();
// Administradores, o la propia persona con el documento que ella subió.
if (!$user || ($user['rol'] !== 'admin' && (!$file || (int)$file['usuario_id'] !== (int)$user['id']))) {
    http_response_code(403);
    exit('Acceso restringido.');
}
$path = $file ? UPLOADS_DIR . '/' . basename($file['archivo_ruta']) : null;
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
