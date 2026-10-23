<?php
declare(strict_types=1);

require_once __DIR__ . '/ticket_helpers.php';
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/../../comun/segundo_plano.php';
require_once __DIR__ . '/../../comun/correo.php';

/** Un correo de tickets con el envío común de la intranet (comun/correo.php: cuenta SMTP, modo prueba y respaldo .eml). */
function mail_send(array $to, array $cc, string $subject, string $html, string $text, string $replyTo = '', array $attachments = [], bool $urgent = false, bool $ignoreTestMode = false): bool
{
    $config = require __DIR__ . '/correo_config.php';
    return intranet_correo($to, $cc, $subject, $html, ['text' => $text, 'reply_to' => $replyTo, 'attachments' => $attachments,
        'urgent' => $urgent, 'ignorar_prueba' => $ignoreTestMode, 'from_name' => $config['nombre_remitente']]);
}

function mail_ticket(PDO $db, int $id): ?array
{
    $query = $db->prepare('SELECT t.id, t.creado_por, d.problemas, COALESCE(d.descripcion, t.comentarios) descripcion,
            d.actividades_detenidas, COALESCE(d.creado_en, t.fecha_ticket) creado_en,
            u.nombre_usuario, u.correo_usuario, u.area, u.jefe_directo, u.correo_jefe
        FROM tickets t
        LEFT JOIN ticket_detalles d ON d.ticket_id = t.id
        LEFT JOIN usuarios u ON u.id = COALESCE(d.usuario_id, (SELECT MIN(x.id) FROM usuarios x WHERE x.correo_usuario = t.creado_por))
        WHERE t.id = ?');
    $query->execute([$id]);
    $ticket = $query->fetch();
    return $ticket ?: null;
}

/**
 * New ticket: to support (Norberto), copy to the creator and their direct boss.
 * If the requester's activities are stopped, the "urgente" recipients are added and the message is marked urgent.
 */
function mail_ticket_created(PDO $db, int $id): bool
{
    $ticket = mail_ticket($db, $id);
    if (!$ticket) return false;
    $config = require __DIR__ . '/correo_config.php';
    $creator = $ticket['correo_usuario'] ?: $ticket['creado_por'];
    $name = $ticket['nombre_usuario'] ?: $creator;
    $query = $db->prepare('SELECT nombre, mime, contenido FROM ticket_evidencias WHERE ticket_id = ? ORDER BY id');
    $query->execute([$id]);
    $files = $query->fetchAll();
    foreach ($files as &$file) {
        $file['nombre'] = 'ticket-' . $id . '-' . $file['nombre'];
        $file['contenido'] = reveal_blob($file['contenido']); // las evidencias se guardan cifradas
    }
    unset($file);
    $rows = mail_ticket_rows($ticket);
    $rows['Evidencias'] = $files ? count($files) . (count($files) === 1 ? ' imagen adjunta' : ' imágenes adjuntas') . ' a este correo' : 'Sin imágenes';
    $urgent = (int)($ticket['actividades_detenidas'] ?? 0) === 1;
    if ($urgent) {
        $html = mail_layout('Ticket urgente #' . $id, $name . ' reportó un problema que detiene sus actividades. Se requiere solución urgente.', $rows, 'Descripción', (string)$ticket['descripcion'], '#c62828', 'Atender ahora en el panel de soporte', 'modulos/soporte.php?id=' . $id,
            'SOLUCIÓN URGENTE · Las actividades del solicitante están detenidas');
        $text = "URGENTE: las actividades del solicitante están detenidas.\n\nTicket urgente #$id de $name.\n\n" . mail_text_rows($rows) . "\nDescripción:\n" . $ticket['descripcion'];
        $subject = 'URGENTE · Ticket #' . $id . ' · ' . $name . ' · Actividades detenidas';
        return mail_send(array_merge(support_recipients($db), $config['urgente'] ?? []), [$creator, $ticket['correo_jefe']], $subject, $html, $text, (string)$creator, $files, true);
    }
    $html = mail_layout('Nuevo ticket #' . $id, 'Se registró una nueva solicitud de soporte de ' . $name . '.', $rows, 'Descripción', (string)$ticket['descripcion'], '#345ce4', 'Ver en el panel de soporte', 'modulos/soporte.php?id=' . $id);
    $text = "Nuevo ticket #$id de $name.\n\n" . mail_text_rows($rows) . "\nDescripción:\n" . $ticket['descripcion'];
    $subject = 'Nuevo ticket #' . $id . ' · ' . $name;
    return mail_send(support_recipients($db), [$creator, $ticket['correo_jefe']], $subject, $html, $text, (string)$creator, $files);
}

/** Validation or resolution: to the creator, copy to their direct boss and support. */
function mail_ticket_status(PDO $db, int $id, string $action, string $comment, string $actor): bool
{
    $ticket = mail_ticket($db, $id);
    if (!$ticket) return false;
    $config = require __DIR__ . '/correo_config.php';
    $creator = $ticket['correo_usuario'] ?: $ticket['creado_por'];
    $resolved = $action === 'resolver';
    $title = ($resolved ? 'Ticket resuelto #' : 'Ticket validado #') . $id;
    $intro = $resolved
        ? $actor . ' marcó tu solicitud como resuelta.'
        : $actor . ' validó tu solicitud; ahora está en atención.';
    $rows = mail_ticket_rows($ticket);
    $rows[$resolved ? 'Resuelto por' : 'Validado por'] = $actor;
    $label = $resolved ? 'Solución aplicada' : 'Observaciones de soporte';
    $html = mail_layout($title, $intro, $rows, $label, $comment !== '' ? $comment : 'Sin observaciones.', $resolved ? '#2f9a58' : '#345ce4', 'Ver mis tickets', 'modulos/tickets.php');
    $text = "$title\n$intro\n\n" . mail_text_rows($rows) . "\n$label:\n" . ($comment !== '' ? $comment : 'Sin observaciones.');
    return mail_send([$creator], array_merge([$ticket['correo_jefe']], support_recipients($db)), $title . ' · ' . ($resolved ? 'Resuelto' : 'En atención'), $html, $text);
}

/** Código de acceso de soporte/supervisión: solo al dueño de la cuenta, nunca al buzón de pruebas. */
function mail_access_code(string $email, string $name, string $code, string $purpose = ''): bool
{
    // $purpose vacío = inicio de sesión; con texto = confirmación de un cambio sensible (p. ej. un rol de superusuario).
    $rows = ['Código' => $code, 'Válido por' => '10 minutos', 'Fecha' => date('d/m/Y H:i'), 'Dirección IP' => client_ip()];
    if ($purpose !== '') $rows = ['Cambio a confirmar' => $purpose] + $rows;
    $note = $purpose === ''
        ? 'Si no fuiste tú quien intentó entrar, cambia tu contraseña de inmediato y avisa al área de sistemas. Nunca compartas este código: el personal de sistemas jamás te lo pedirá.'
        : 'Si no solicitaste este cambio, no escribas el código y revisa la bitácora de Seguridad. Nunca compartas este código.';
    $title = $purpose === '' ? 'Tu código de acceso: ' . $code : 'Código para confirmar un cambio: ' . $code;
    $intro = $purpose === '' ? 'Hola ' . $name . ', usa este código para terminar de iniciar sesión en el panel.' : 'Hola ' . $name . ', escribe este código en la página Seguridad para confirmar el cambio.';
    $html = mail_layout($title, $intro, $rows, 'Importante', $note, $purpose === '' ? '#1f52c4' : '#c62828', '', '');
    $text = "$title\nVálido por 10 minutos.\n" . ($purpose !== '' ? "Cambio: $purpose\n" : '') . "\n$note";
    return mail_send([$email], [], ($purpose === '' ? 'Código de acceso: ' : 'Confirma un cambio de rol: ') . $code, $html, $text, '', [], $purpose !== '', true);
}

/** Enlace para crear (primera vez) o recuperar la contraseña. Solo al dueño de la cuenta, nunca al buzón de pruebas. */
function mail_password_link(string $email, string $name, string $link, string $type): bool
{
    $create = $type === 'crear';
    $rows = ['Cuenta' => $email, 'Válido por' => $create ? '24 horas' : '60 minutos', 'Uso' => 'Una sola vez'];
    $note = $create
        ? 'Si no pediste crear tu contraseña, ignora este correo: nadie podrá usar tu cuenta sin este enlace.'
        : 'Si no pediste recuperar tu contraseña, ignora este correo y avisa al área de sistemas.';
    $html = mail_layout($create ? 'Crea tu contraseña' : 'Restablece tu contraseña',
        'Hola ' . $name . ', abre el botón para ' . ($create ? 'crear la contraseña de tu cuenta de Fedele 360.' : 'escribir una contraseña nueva.'),
        $rows, 'Importante', $note, '#1f52c4', $create ? 'Crear mi contraseña' : 'Restablecer mi contraseña', '', absoluteLink: $link);
    $text = ($create ? 'Crea tu contraseña' : 'Restablece tu contraseña') . " en este enlace (vale " . ($create ? '24 horas' : '60 minutos') . " y un solo uso):\n$link\n\n$note";
    return mail_send([$email], [], $create ? 'Crea tu contraseña de Fedele 360' : 'Restablece tu contraseña de Fedele 360', $html, $text, '', [], false, true);
}

/** Correos de las cuentas activas con rol Soporte; si no hay ninguna, la lista 'soporte' de correo_config.php. */
function support_recipients(PDO $db): array
{
    $emails = $db->query("SELECT correo_usuario FROM usuarios WHERE rol = 'soporte' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN);
    return $emails ?: (require __DIR__ . '/correo_config.php')['soporte'];
}

/** Aviso al usuario cuando un supervisor cambia su contraseña. */
function mail_password_changed(string $email, string $name, string $actor): bool
{
    $rows = ['Cambiada por' => $actor, 'Fecha' => date('d/m/Y H:i')];
    $note = 'Si no solicitaste este cambio, avisa de inmediato al área de sistemas.';
    $html = mail_layout('Tu contraseña fue cambiada', 'Hola ' . $name . ', la contraseña de tu cuenta de Fedele 360 fue cambiada.', $rows, 'Importante', $note, '#c62828', '', '');
    return mail_send([$email], [], 'Tu contraseña de Fedele 360 fue cambiada', $html, "Tu contraseña fue cambiada por $actor.\n\n$note");
}

function mail_ticket_rows(array $ticket): array
{
    $catalog = ticket_problems();
    $problems = array_map(static fn($key) => $catalog[$key] ?? $key, json_decode($ticket['problemas'] ?? '[]', true) ?: []);
    $rows = [
        'Folio' => '#' . $ticket['id'],
        'Solicitante' => ($ticket['nombre_usuario'] ?: '') . ' <' . ($ticket['correo_usuario'] ?: $ticket['creado_por']) . '>',
        'Área' => $ticket['area'] ?: 'Sin área',
        'Jefe directo' => $ticket['jefe_directo'] ?: 'Sin registro',
        'Fecha' => date('d/m/Y H:i', strtotime((string)$ticket['creado_en'])),
        'Problemas' => $problems ? implode(', ', $problems) : 'Sin clasificar',
    ];
    if ($ticket['actividades_detenidas'] !== null) {
        $rows['¿Actividades detenidas?'] = (int)$ticket['actividades_detenidas'] === 1 ? 'Sí' : 'No';
    }
    return $rows;
}

function mail_text_rows(array $rows): string
{
    $text = '';
    foreach ($rows as $label => $value) $text .= $label . ': ' . $value . "\n";
    return $text;
}

/** $alert (optional) shows a red urgency banner at the top of the message. */
function mail_layout(string $title, string $intro, array $rows, string $blockTitle, string $block, string $color, string $linkLabel, string $path, string $alert = '', string $absoluteLink = ''): string
{
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $config = require __DIR__ . '/correo_config.php';
    $table = '';
    foreach ($rows as $label => $value) {
        $table .= '<tr><td style="padding:8px 0;color:#728096;font-size:13px;width:170px;vertical-align:top">' . $e($label) . '</td>'
            . '<td style="padding:8px 0;color:#18253c;font-size:13px;font-weight:600">' . $e((string)$value) . '</td></tr>';
    }
    // $absoluteLink (p. ej. el enlace de contraseña) tiene prioridad; si no, se arma con url_sistema + $path.
    $href = $absoluteLink !== '' ? $absoluteLink : ($config['url_sistema'] !== '' && $path !== '' ? rtrim($config['url_sistema'], '/') . '/' . $path : '');
    $link = $href !== ''
        ? '<p style="margin:26px 0 0"><a href="' . $e($href) . '" style="display:inline-block;padding:12px 20px;background:' . $color . ';color:#ffffff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600">' . $e($linkLabel) . '</a></p>'
        : '';
    return '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f5f7fb;font-family:Segoe UI,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e6eaf2;border-radius:14px;overflow:hidden">'
        . '<tr><td style="height:5px;background:' . $color . '"></td></tr>'
        . ($alert !== ''
            ? '<tr><td style="padding:14px 32px;background:#fdecec;border-bottom:1px solid #f6c7c7;color:#a01d1d;font-size:14px;font-weight:700">&#9888; ' . $e($alert) . '</td></tr>'
            : '')
        . '<tr><td style="padding:28px 32px">'
        . '<p style="margin:0;font-size:11px;letter-spacing:2px;color:#5268a0;font-weight:700">FEDELE 360 · SOPORTE TI</p>'
        . '<h1 style="margin:10px 0 6px;font-size:22px;color:#18253c">' . $e($title) . '</h1>'
        . '<p style="margin:0 0 20px;font-size:14px;color:#637089">' . $e($intro) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #edf0f5;border-bottom:1px solid #edf0f5">' . $table . '</table>'
        . '<p style="margin:22px 0 8px;font-size:13px;font-weight:700;color:#24334e">' . $e($blockTitle) . '</p>'
        . '<div style="padding:14px 16px;background:#f7f9fc;border-radius:8px;font-size:13px;line-height:1.6;color:#43516a;white-space:pre-wrap">' . $e($block) . '</div>'
        . $link
        . '</td></tr>'
        . '<tr><td style="padding:16px 32px;background:#f7f9fc;font-size:11px;color:#8a96aa">Mensaje automático de Fedele 360.</td></tr>'
        . '</table></body></html>';
}
