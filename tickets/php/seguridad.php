<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/*
 * Funciones de seguridad compartidas: bitácora de auditoría, límites de intentos,
 * cifrado de datos en reposo y código de acceso por correo para soporte/supervisión.
 * Las tablas que usa se crean con instalar/migraciones.php (en la raíz de la intranet).
 */

function client_ip(): string
{
    // Solo REMOTE_ADDR: los encabezados X-Forwarded-For los puede inventar cualquiera.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

/** Registra un evento en la bitácora; nunca interrumpe la operación principal. */
function audit(string $action, string $detail = '', ?array $user = null): void
{
    $user = $user ?? ($_SESSION['user'] ?? null);
    try {
        database()->prepare('INSERT INTO auditoria (fecha, usuario_id, correo, ip, accion, detalle) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([date('Y-m-d H:i:s'), $user['id'] ?? null, isset($user['email']) ? substr((string)$user['email'], 0, 254) : null,
                client_ip(), substr($action, 0, 40), $detail !== '' ? mb_substr($detail, 0, 500) : null]);
    } catch (Throwable $exception) {
        error_log('Auditoría (' . $action . '): ' . $exception->getMessage());
    }
}

/* ---------- Límites de intentos (tabla login_attempts, clave genérica) ---------- */

function throttle_blocked(PDO $db, string $key, int $max, int $window = 900): bool
{
    $query = $db->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE email = ?');
    $query->execute([substr($key, 0, 254)]);
    $row = $query->fetch();
    return $row && (int)$row['attempts'] >= $max && (int)$row['last_attempt'] > time() - $window;
}

function throttle_hit(PDO $db, string $key, int $window = 900): void
{
    $db->prepare('INSERT INTO login_attempts (email, attempts, last_attempt) VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE attempts = CASE WHEN last_attempt <= ? THEN 1 ELSE attempts + 1 END, last_attempt = VALUES(last_attempt)')
        ->execute([substr($key, 0, 254), time(), time() - $window]);
}

function throttle_clear(PDO $db, string $key): void
{
    $db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([substr($key, 0, 254)]);
}

/* ---------- Cifrado en reposo (AES-256-GCM con TICKETS_APP_KEY del .env) ---------- */

const ENCRYPTED_PREFIX = 'ENC1';

function app_key(): ?string
{
    $key = base64_decode(env('TICKETS_APP_KEY'), true);
    return is_string($key) && strlen($key) === 32 ? $key : null;
}

function is_encrypted(string $data): bool
{
    return strncmp($data, ENCRYPTED_PREFIX, strlen(ENCRYPTED_PREFIX)) === 0;
}

/** Cifra datos binarios. Sin llave configurada los deja como están (la página Seguridad lo advierte). */
function protect_blob(string $data): string
{
    $key = app_key();
    if ($key === null || is_encrypted($data)) return $data;
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('No se pudo cifrar la información.');
    return ENCRYPTED_PREFIX . $iv . $tag . $cipher;
}

function reveal_blob(string $data): string
{
    if (!is_encrypted($data)) return $data;
    $key = app_key();
    if ($key === null) throw new RuntimeException('Falta TICKETS_APP_KEY para descifrar la información.');
    $offset = strlen(ENCRYPTED_PREFIX);
    $plain = openssl_decrypt(substr($data, $offset + 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, $offset, 12), substr($data, $offset + 12, 16));
    if ($plain === false) throw new RuntimeException('La información cifrada no es válida o la llave cambió.');
    return $plain;
}

/* ---------- Códigos de 6 dígitos por correo (inicio de sesión y confirmación de cambios sensibles) ---------- */

const ACCESS_CODE_TTL = 600;      // el código vale 10 minutos
const ACCESS_CODE_ATTEMPTS = 5;   // intentos por código
const ACCESS_CODE_RESEND = 60;    // segundos entre reenvíos

/** Soporte, supervisión y superusuario ven información de todos: se les pide código al iniciar sesión. */
function role_requires_code(string $role): bool
{
    // Empleados y gerentes ven solo lo suyo y lo de su equipo: entran con contraseña.
    return !in_array($role, ['empleado', 'gerente'], true);
}

function access_code_enabled(): bool
{
    // Interruptor de emergencia solo en el servidor (tickets.env): TICKETS_CODIGO_ACCESO=0 lo desactiva.
    return env('TICKETS_CODIGO_ACCESO', '1') !== '0';
}

/** Genera un código nuevo dentro de $slot (en la sesión). Solo se guarda su HMAC, nunca el código. */
function code_new(array &$slot): string
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $slot['salt'] = bin2hex(random_bytes(16));
    $slot['code_hash'] = hash_hmac('sha256', $code, $slot['salt']);
    $slot['expires'] = time() + ACCESS_CODE_TTL;
    $slot['attempts'] = 0;
    $slot['sent_at'] = time();
    return $code;
}

/** Comprueba un código. InvalidArgumentException = incorrecto o vencido; LengthException = se agotaron los intentos. */
function code_verify(array &$slot, string $input): void
{
    if (time() > ($slot['expires'] ?? 0)) throw new InvalidArgumentException('El código venció. Solicita uno nuevo.');
    $slot['attempts'] = ($slot['attempts'] ?? 0) + 1;
    $input = preg_replace('/\D/', '', $input);
    if (strlen($input) === 6 && hash_equals($slot['code_hash'] ?? '', hash_hmac('sha256', $input, $slot['salt'] ?? ''))) return;
    if ($slot['attempts'] >= ACCESS_CODE_ATTEMPTS) throw new LengthException('Demasiados códigos incorrectos.');
    throw new InvalidArgumentException('El código no es correcto. Te quedan ' . (ACCESS_CODE_ATTEMPTS - $slot['attempts']) . ' intentos.');
}

/** Se enviaron demasiados códigos a la cuenta: hay que esperar y empezar de nuevo (codigo.php regresa al login). */
class CodeThrottleException extends InvalidArgumentException {}

/** Envía el código del inicio de sesión pendiente al correo del usuario. */
function access_code_issue(PDO $db): bool
{
    $user = $_SESSION['pending_login']['user'];
    if (throttle_blocked($db, 'codigo:' . $user['email'], 5)) {
        throw new CodeThrottleException('Se enviaron demasiados códigos. Espera 15 minutos e inicia sesión de nuevo.');
    }
    $code = code_new($_SESSION['pending_login']);
    throttle_hit($db, 'codigo:' . $user['email']);
    require_once __DIR__ . '/correo.php';
    $sent = mail_access_code($user['email'], $user['name'], $code);
    audit($sent ? 'codigo_enviado' : 'codigo_no_enviado', '', $user);
    return $sent;
}

function access_code_check(string $input): bool
{
    code_verify($_SESSION['pending_login'], $input);
    return true;
}

/**
 * Confirmación extra para cambios de rol que involucran al superusuario: se envía un código al correo
 * del superusuario que hace el cambio y el cambio queda pendiente en $_SESSION['step_up'] hasta confirmarlo.
 */
function step_up_issue(PDO $db, array $change, string $description): bool
{
    $me = $_SESSION['user'];
    if (throttle_blocked($db, 'codigo:' . $me['email'], 5)) {
        throw new InvalidArgumentException('Se enviaron demasiados códigos. Espera 15 minutos.');
    }
    $_SESSION['step_up'] = $change + ['descripcion' => $description];
    $code = code_new($_SESSION['step_up']);
    throttle_hit($db, 'codigo:' . $me['email']);
    require_once __DIR__ . '/correo.php';
    $sent = mail_access_code($me['email'], $me['name'], $code, $description);
    audit($sent ? 'codigo_rol_enviado' : 'codigo_no_enviado', $description);
    return $sent;
}

/** Completa el inicio de sesión: nueva sesión, nuevo token y datos del usuario. $mfa = pasó el código. */
function login_complete(array $user, bool $mfa = false): void
{
    require_once __DIR__ . '/../../comun/acceso.php';
    $query = database()->prepare('SELECT contrasena_usuario FROM usuarios WHERE id = ?');
    $query->execute([$user['id']]);
    session_regenerate_id(true);
    $_SESSION = [
        'user' => $user,
        'mfa' => $mfa,
        // Si la contraseña cambia, esta sesión deja de valer (intranet_sesion_vigente).
        'huella' => intranet_huella_contrasena($query->fetchColumn() ?: null),
        'csrf' => bin2hex(random_bytes(32)),
        'last_activity' => time(),
        'created' => time(),
    ];
}

/* ---------- Enlaces de un solo uso para crear o recuperar la contraseña ---------- */

const RESET_TTL = ['crear' => 86400, 'recuperar' => 3600]; // 24 h para crearla, 60 min para recuperarla
// Largo de las contraseñas: mínimo PASSWORD_MIN caracteres; bcrypt solo usa los primeros 72 bytes.
const PASSWORD_MIN = 8;
const PASSWORD_MAX = 72;

/**
 * Dirección pública de tickets para los enlaces de los correos: TICKETS_URL o, si no está, INTRANET_URL + /tickets.
 * Nunca se usa el Host del navegador: evita enlaces falsificados.
 */
function system_url(): string
{
    $tickets = rtrim(env('TICKETS_URL'), '/');
    $intranet = rtrim(env('INTRANET_URL'), '/');
    return $tickets !== '' ? $tickets : ($intranet !== '' ? $intranet . '/tickets' : '');
}

/** Crea un enlace (invalida los anteriores del usuario) y lo envía a su correo. Devuelve true si se envió. */
function password_link_send(PDO $db, array $user, string $type, ?int $createdBy = null): bool
{
    if (system_url() === '') throw new RuntimeException('Falta INTRANET_URL en el archivo de configuración para poder enviar enlaces.');
    $token = bin2hex(random_bytes(32));
    $now = time();
    $db->prepare('UPDATE password_resets SET usado_en = ? WHERE usuario_id = ? AND usado_en IS NULL')->execute([date('Y-m-d H:i:s', $now), $user['id']]);
    $db->prepare('INSERT INTO password_resets (usuario_id, token_hash, tipo, creado_en, expira, creado_por) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], hash('sha256', $token), $type, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + RESET_TTL[$type]), $createdBy]);
    require_once __DIR__ . '/correo.php';
    return mail_password_link($user['email'], $user['name'], system_url() . '/modulos/restablecer.php?token=' . $token, $type);
}

/** Busca un enlace vigente y sin usar; devuelve sus datos y los del usuario, o null. */
function password_link_find(PDO $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $query = $db->prepare('SELECT r.id reset_id, r.tipo, u.id, u.nombre_usuario name, u.correo_usuario email
        FROM password_resets r JOIN usuarios u ON u.id = r.usuario_id
        WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira > ? AND u.activo = 1');
    $query->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
    return $query->fetch() ?: null;
}
