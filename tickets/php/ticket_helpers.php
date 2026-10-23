<?php
declare(strict_types=1);

function ticket_problems(): array
{
    return [
        'equipo' => 'Computadora o equipo',
        'internet' => 'Internet o red',
        'correo' => 'Correo electrónico',
        'accesos' => 'Accesos y contraseñas',
        'software' => 'Programas y aplicaciones',
        'impresora' => 'Impresora o escáner',
        'otro' => 'Otro problema',
    ];
}

function require_ticket_user(): void
{
    if (!isset($_SESSION['user'])) {
        header('Location: login.php', true, 303);
        exit;
    }
    // En cada página se vuelve a leer la cuenta: si el superusuario la desactivó, la eliminó o le cambió el rol,
    // el cambio aplica en el siguiente clic.
    $query = database()->prepare('SELECT activo, rol, puesto, contrasena_usuario FROM usuarios WHERE id = ?');
    $query->execute([$_SESSION['user']['id']]);
    $account = $query->fetch();
    $message = '';
    require_once __DIR__ . '/../../comun/acceso.php';
    if (!$account || (int)$account['activo'] !== 1) {
        $message = 'Tu sesión se cerró porque la cuenta fue desactivada. Contacta al área de sistemas.';
    } elseif (!intranet_sesion_vigente($account['contrasena_usuario'])) {
        // La contraseña cambió después de entrar (restablecida o cambiada por un administrador).
        $message = 'Tu sesión se cerró porque la contraseña de la cuenta cambió. Inicia sesión de nuevo.';
    } else {
        $_SESSION['user']['rol'] = (string)$account['rol'];
        // Las dermoconsejeras no usan Tickets (comun/acceso.php): se van a la entrada de la intranet.
        if (in_array($account['rol'], ['empleado', 'gerente'], true) && $account['puesto'] === 'dermoconsejera') {
            header('Location: ../../', true, 303);
            exit;
        }
        // Si ahora tiene un rol que exige código y esta sesión no pasó por él, debe volver a entrar con código.
        require_once __DIR__ . '/seguridad.php';
        if (role_requires_code($_SESSION['user']['rol']) && access_code_enabled() && empty($_SESSION['mfa'])) {
            $message = 'Tus permisos cambiaron. Inicia sesión de nuevo para confirmar tu identidad con un código.';
        }
    }
    if ($message !== '') {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'error' => $message];
        session_regenerate_id(true);
        header('Location: login.php', true, 303);
        exit;
    }
}

function ticket_redirect(): void
{
    // script/tickets.js sends the form with fetch (to upload the reduced photos); it navigates on its own,
    // so the session messages are kept for tickets.php instead of being consumed by a followed redirect.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        exit(json_encode(['redirect' => 'tickets.php']));
    }
    header('Location: tickets.php', true, 303);
    exit;
}

/** Validate every upload before starting the database transaction. */
function ticket_images(array $files): array
{
    if (!$files) {
        return [];
    }
    foreach (['name', 'tmp_name', 'error', 'size'] as $field) {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            throw new InvalidArgumentException('La carga de imágenes no es válida.');
        }
    }
    if (count($files['error']) > 3) {
        throw new InvalidArgumentException('Puedes adjuntar hasta 3 imágenes por ticket.');
    }
    $images = [];
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files['error'] as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('No se pudo cargar una imagen. Elige archivos de hasta 1 MB e intenta de nuevo.');
        }
        $path = $files['tmp_name'][$index] ?? null;
        if (!is_string($path) || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('La imagen recibida no es válida.');
        }
        $size = filesize($path);
        if (!$size || $size > 1024 * 1024) {
            throw new InvalidArgumentException('Cada imagen debe pesar como máximo 1 MB.');
        }
        $mime = $finfo->file($path);
        $dimensions = @getimagesize($path);
        if (!isset($types[$mime]) || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('Solo se admiten imágenes JPG, PNG y WebP.');
        }
        if ($dimensions[0] * $dimensions[1] > 20000000) {
            throw new InvalidArgumentException('La imagen es demasiado grande. Usa una versión de hasta 20 megapíxeles.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer la evidencia.');
        }
        $images[] = ['mime' => $mime, 'extension' => $types[$mime], 'size' => $size, 'content' => $content];
    }
    return $images;
}
