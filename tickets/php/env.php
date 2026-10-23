<?php
declare(strict_types=1);

/**
 * Configuración privada (contraseñas) desde variables de entorno o un archivo .env.
 * Orden de búsqueda del archivo (se usa el primero que exista):
 *   1. La ruta de la variable de entorno TICKETS_ENV_FILE.
 *   2. intranet.env fuera de la carpeta pública. Con la intranet en public_html/intranet es /home/usuario/intranet.env
 *      (no se puede descargar desde internet). Con la intranet en la raíz de un dominio, la carpeta de arriba.
 *      Un intranet.env dentro de la carpeta pública del sitio (DOCUMENT_ROOT) nunca se usa.
 *      No es el tickets.env del sistema de tickets anterior: así cada uno usa su propia base.
 *   3. .env en tickets/ (uso local; .htaccess bloquea su descarga).
 * Una variable de entorno real del servidor tiene prioridad sobre el archivo. Ver .env.example.
 */
function env(string $key, string $default = ''): string
{
    static $values = null;
    if ($values === null) {
        $values = [];
        foreach (env_files() as $file) {
            if (@is_file($file) && @is_readable($file)) {
                $values = env_parse((string) file_get_contents($file));
                break;
            }
        }
    }
    $real = getenv($key);
    if (is_string($real) && $real !== '') return $real;
    // Un valor vacío en el archivo (p. ej. «TICKETS_SMTP_HOST=») usa el valor por omisión.
    return ($values[$key] ?? '') !== '' ? $values[$key] : $default;
}

function env_files(): array
{
    $custom = getenv('TICKETS_ENV_FILE');
    // Un intranet.env dentro de la carpeta pública se podría descargar (con la intranet en public_html/intranet,
    // la carpeta de arriba es public_html): no se usa y se avisa en el log.
    $root = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $public = $root !== '' ? realpath($root) : false;
    $norm = static fn(string $path): string => rtrim(str_replace('\\', '/', DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path), '/') . '/';
    $files = [];
    foreach ([dirname(__DIR__, 4), dirname(__DIR__, 3)] as $dir) {
        $file = $dir . DIRECTORY_SEPARATOR . 'intranet.env';
        $real = realpath($dir);
        if ($public !== false && $real !== false && str_starts_with($norm($real), $norm($public))) {
            if (@is_file($file)) error_log('Configuración: se ignora ' . $file . ' porque está dentro de la carpeta pública; muévelo fuera de ' . $public . '.');
            continue;
        }
        $files[] = $file;
    }
    // tickets/.env: uso local; tickets/.htaccess bloquea su descarga.
    return array_values(array_filter([is_string($custom) ? $custom : '', ...$files, dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env']));
}

/** Formato KEY=valor por línea; ignora líneas vacías y las que empiezan con #; acepta comillas "..." o '...'. */
function env_parse(string $content): array
{
    $values = [];
    foreach (preg_split('/\R/', $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $length = strlen($value);
        if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) $values[$key] = $value;
    }
    return $values;
}
