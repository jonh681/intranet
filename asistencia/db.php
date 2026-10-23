<?php
declare(strict_types=1);

// config.php del servidor (copia de config.example.php); vacío en XAMPP local.
function appConfig(): array {
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
        $config = is_file($file) ? (array)(require $file) : [];
    }
    return $config;
}

// Funciones que se pueden apagar por servidor en config.php ('features' => ['calendario' => false, ...]).
// Si no se indica, están encendidas.
function feature(string $name): bool {
    return (bool)(appConfig()['features'][$name] ?? true);
}

// Conexión a MySQL: la misma base unida de la intranet que usa tickets (TICKETS_DB_* en su .env / tickets.env).
// Así hay un solo lugar con los datos de acceso. Las variables ASISTENCIA_DB_* o config.php, si existen, tienen prioridad.
function connectDb(): PDO {
    require_once __DIR__ . '/../tickets/php/env.php';
    $config = appConfig()['db'] ?? [];
    $value = fn(string $key, string $tickets, string $default) => getenv('ASISTENCIA_DB_' . strtoupper($key)) ?: (string)($config[$key] ?? env($tickets, $default));
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $value('host', 'TICKETS_DB_HOST', '127.0.0.1'), $value('port', 'TICKETS_DB_PORT', '3307'), $value('name', 'TICKETS_DB_NAME', 'intranet')),
        $value('user', 'TICKETS_DB_USER', 'root'),
        $value('pass', 'TICKETS_DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}
