<?php
declare(strict_types=1);

/*
 * Envío de correos de toda la intranet (tickets e incidencias): una sola cuenta SMTP y un solo modo prueba,
 * configurados en tickets/.env:
 *   SMTP_PASS                contraseña de no-responder@fedele.com.mx (TICKETS_SMTP_HOST si el servidor no es mail.fedele.com.mx)
 *   TICKETS_MODO_PRUEBA      un correo = TODO llega solo ahí (el correo dice a quién iba); vacío = destinatarios reales
 *   TICKETS_CORREO_ACTIVO=0  no se envía nada
 * Lo que no sale (envíos apagados o error del servidor) se guarda como .eml en correos/ para revisarlo.
 * Quien llama suele usar intranet_despues() (segundo_plano.php) para no hacer esperar al usuario.
 */

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/../tickets/php/env.php';

const INTRANET_CORREOS_DIR = __DIR__ . '/../correos';
const INTRANET_REMITENTE = 'no-responder@fedele.com.mx';

/**
 * $to, $cc: correos ('ana@fedele.com.mx') o personas (['name' => 'Ana', 'email' => 'ana@fedele.com.mx']).
 * $opt: text (versión sin formato; si falta se saca del HTML), from_name, reply_to, urgent (prioridad alta),
 *       attachments ([['contenido', 'nombre', 'mime']]), bcc (copias ocultas; en modo prueba no se usan)
 *       e ignorar_prueba (códigos y enlaces de contraseña: solo al dueño).
 * Nunca lanza excepción: un correo fallido no debe impedir guardar lo que se hizo. Devuelve true si salió.
 */
function intranet_correo(array $to, array $cc, string $subject, string $html, array $opt = []): bool
{
    $people = static function (array $list): array {
        $out = [];
        foreach ($list as $person) {
            $email = strtolower(trim(is_array($person) ? (string)($person['email'] ?? '') : (string)$person));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($out[$email])) $out[$email] = is_array($person) ? trim((string)($person['name'] ?? '')) : '';
        }
        return $out;
    };
    $to = $people($to);
    $cc = array_diff_key($people($cc), $to);
    $bcc = array_diff_key($people($opt['bcc'] ?? []), $to, $cc);
    if (!$to) return false;
    $text = (string)($opt['text'] ?? '');
    if ($text === '') {
        $text = html_entity_decode(trim(preg_replace('/[ \t]+/', ' ', strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/h\d)[^>]*>/i', "\n", $html)))), ENT_QUOTES, 'UTF-8');
    }

    $test = env('TICKETS_MODO_PRUEBA');
    if (filter_var($test, FILTER_VALIDATE_EMAIL) && empty($opt['ignorar_prueba'])) {
        // Modo prueba: solo llega al buzón de pruebas; se anota a quién iba en realidad.
        $list = static fn(array $l): string => implode(', ', array_keys($l));
        $note = 'Correo de prueba. En producción iría a: ' . $list($to) . ($cc ? ' · CC: ' . $list($cc) : '');
        $banner = '<div style="max-width:620px;margin:0 auto 12px;padding:10px 14px;background:#fff4df;border:1px solid #f0d9a8;border-radius:8px;font:12px Arial,sans-serif;color:#7a5412">'
            . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</div>';
        $html = preg_match('/<body[^>]*>/i', $html) ? preg_replace_callback('/<body[^>]*>/i', static fn($m) => $m[0] . $banner, $html, 1) : $banner . $html;
        $text = $note . "\n\n" . $text;
        $subject = '[Prueba] ' . $subject;
        $to = [$test => ''];
        $cc = [];
        $bcc = [];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->XMailer = 'PHPMailer';
        $mail->isSMTP();
        $mail->Host = env('TICKETS_SMTP_HOST', 'mail.fedele.com.mx');
        $mail->SMTPAuth = true;
        $mail->Username = INTRANET_REMITENTE;
        $mail->Password = env('SMTP_PASS');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->setFrom(INTRANET_REMITENTE, (string)($opt['from_name'] ?? 'Fedele 360'));
        foreach ($to as $email => $name) $mail->addAddress($email, $name);
        foreach ($cc as $email => $name) $mail->addCC($email, $name);
        foreach ($bcc as $email => $name) $mail->addBCC($email, $name);
        $replyTo = (string)($opt['reply_to'] ?? '');
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
        if (!empty($opt['urgent'])) {
            // Prioridad alta: Outlook y la mayoría de los clientes muestran la marca de importancia.
            $mail->Priority = 1;
            $mail->addCustomHeader('Importance', 'High');
        }
        foreach ($opt['attachments'] ?? [] as $file) $mail->addStringAttachment($file['contenido'], $file['nombre'], PHPMailer::ENCODING_BASE64, $file['mime']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        if (env('TICKETS_CORREO_ACTIVO', '1') === '0') {
            intranet_correo_guardar($mail, 'Envíos apagados (TICKETS_CORREO_ACTIVO=0)');
            return false;
        }
        // Si el servidor de correo rechaza la conexión (p. ej. varios envíos al mismo tiempo), se reintenta un par de veces.
        for ($try = 1; ; $try++) {
            try {
                $mail->send();
                return true;
            } catch (Throwable $e) {
                if ($try >= 3 || !str_contains($e->getMessage(), 'connect')) throw $e;
                $mail->smtpClose();
                sleep(2 * $try);
            }
        }
    } catch (Throwable $e) {
        error_log('Correo no enviado (' . $subject . '): ' . $e->getMessage());
        intranet_correo_guardar($mail, $e->getMessage());
        return false;
    }
}

/** Guarda el correo como .eml en correos/ (sin acceso web) con el motivo por el que no salió. */
function intranet_correo_guardar(PHPMailer $mail, string $reason): void
{
    try {
        $mail->preSend();
        $raw = $mail->getSentMIMEMessage();
    } catch (Throwable) {
        return;
    }
    if (!is_dir(INTRANET_CORREOS_DIR)) @mkdir(INTRANET_CORREOS_DIR, 0750, true);
    if (!is_file(INTRANET_CORREOS_DIR . '/.htaccess')) @file_put_contents(INTRANET_CORREOS_DIR . '/.htaccess', "Require all denied\n");
    // Las copias ocultas no van en el mensaje: se anotan para saber a quién más iba.
    $bcc = implode(', ', array_column($mail->getBccAddresses(), 0));
    @file_put_contents(INTRANET_CORREOS_DIR . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml',
        'X-No-Enviado: ' . preg_replace('/[\r\n]+/', ' ', $reason) . "\r\n" . ($bcc !== '' ? "X-Copia-Oculta: $bcc\r\n" : '') . $raw);
}
