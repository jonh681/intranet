<?php
require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/ticket_helpers.php';
require_once __DIR__ . '/../php/soporte_helpers.php';
require_once __DIR__ . '/../php/correo.php';
require_once __DIR__ . '/../php/seguridad.php';
require_ticket_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ticket_redirect();
}
if (!valid_csrf()) {
    $_SESSION['ticket_error'] = 'No se pudo procesar el formulario. Vuelve a intentarlo con imágenes de hasta 1 MB.';
    ticket_redirect();
}
$description = is_string($_POST['descripcion'] ?? null) ? trim($_POST['descripcion']) : '';
$problems = $_POST['problemas'] ?? [];
$catalog = ticket_problems();
$validProblems = is_array($problems) && count($problems) >= 1 && count($problems) <= count($catalog);
if ($validProblems) {
    foreach ($problems as $problem) {
        if (!is_string($problem) || !isset($catalog[$problem])) {
            $validProblems = false;
            break;
        }
    }
}
$asksActivity = asks_activity_stop();
$activityStop = null;
if ($asksActivity && in_array($_POST['actividades_detenidas'] ?? null, ['si', 'no'], true)) {
    $activityStop = $_POST['actividades_detenidas'] === 'si' ? 1 : 0;
}
$_SESSION['ticket_old'] = [
    'descripcion' => mb_substr($description, 0, 5000),
    'problemas' => $validProblems ? array_values(array_unique($problems)) : [],
    'actividades_detenidas' => $activityStop,
];
$request = is_string($_POST['solicitud'] ?? null) ? $_POST['solicitud'] : '';
if (isset($_SESSION['ticket_sent'][$request])) {
    unset($_SESSION['ticket_old']);
    $_SESSION['ticket_success'] = 'El ticket #' . $_SESSION['ticket_sent'][$request] . ' ya fue creado.';
    ticket_redirect();
}
if (!$request || !hash_equals($_SESSION['ticket_form_token'] ?? '', $request)) {
    $_SESSION['ticket_error'] = 'El formulario venció. Revisa los datos e intenta de nuevo.';
    ticket_redirect();
}
$db = null;
try {
    if (!$validProblems) {
        throw new InvalidArgumentException('Selecciona al menos un problema de la lista.');
    }
    if (mb_strlen($description) < 10 || mb_strlen($description) > 5000) {
        throw new InvalidArgumentException('La descripción debe tener entre 10 y 5,000 caracteres.');
    }
    if ($asksActivity && $activityStop === null) {
        throw new InvalidArgumentException('Indica si tus actividades se detienen.');
    }
    $images = ticket_images($_FILES['evidencias'] ?? []);
    $db = database();
    $db->beginTransaction();
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    $query = $db->prepare('INSERT INTO tickets (fecha_ticket, creado_por, estatus_ticket, comentarios) VALUES (?, ?, 0, ?)');
    $query->execute([$now->format('Y-m-d'), $_SESSION['user']['email'], mb_substr($description, 0, 250)]);
    $id = (int) $db->lastInsertId();
    $query = $db->prepare('INSERT INTO ticket_detalles (ticket_id, usuario_id, problemas, descripcion, actividades_detenidas, solicitud, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$id, $_SESSION['user']['id'], json_encode(array_values(array_unique($problems)), JSON_THROW_ON_ERROR), $description, $activityStop, $request, $now->format('Y-m-d H:i:s')]);
    $query = $db->prepare('INSERT INTO ticket_evidencias (ticket_id, nombre, mime, tamano, contenido) VALUES (?, ?, ?, ?, ?)');
    foreach ($images as $index => $image) {
        $name = 'evidencia-' . ($index + 1) . '.' . $image['extension'];
        $query->execute([$id, $name, $image['mime'], $image['size'], protect_blob($image['content'])]); // cifrada en la base
    }
    $db->commit();
    $_SESSION['ticket_sent'][$request] = $id;
    $_SESSION['ticket_sent'] = array_slice($_SESSION['ticket_sent'], -10, null, true);
    $_SESSION['ticket_form_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['ticket_old']);
    $_SESSION['ticket_success'] = 'Ticket #' . $id . ' creado correctamente. Tu solicitud está abierta.';
    // El correo a soporte sale después de responder (comun/segundo_plano.php): la página no espera al servidor de correo.
    intranet_despues(fn() => mail_ticket_created($db, $id));
    $_SESSION['ticket_success'] .= ' Se avisará a soporte por correo.';
} catch (Throwable $exception) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($exception instanceof InvalidArgumentException) {
        $_SESSION['ticket_error'] = $exception->getMessage();
    } else {
        error_log('Crear ticket: ' . $exception->getMessage());
        $_SESSION['ticket_error'] = 'No se pudo guardar el ticket. Tus datos se conservaron; intenta de nuevo.';
    }
}
ticket_redirect();
