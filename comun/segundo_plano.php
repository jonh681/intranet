<?php
declare(strict_types=1);

/*
 * Tareas lentas (los correos: cada envío por SMTP tarda unos segundos) que se hacen DESPUÉS de responder:
 * al terminar el script se entrega la respuesta completa al navegador (Content-Length + Connection: close),
 * se libera la sesión y luego se ejecutan las tareas. Así un botón responde al instante aunque mande correos.
 *
 *   intranet_despues(fn() => intranet_correo(...));
 *
 * Si algo ya se envió al navegador antes (encabezados enviados), las tareas se ejecutan igual, solo que el
 * navegador espera a que terminen.
 */
function intranet_despues(callable $task): void
{
    static $tasks = null;
    if ($tasks === null) {
        $tasks = [];
        // Desde aquí la salida se guarda para poder medirla y entregarla de una vez.
        ob_start();
        register_shutdown_function(function () use (&$tasks): void {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            ignore_user_abort(true);
            // mod_deflate guardaría la respuesta hasta que terminen las tareas: esta sale sin comprimir.
            if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
            if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
                while (ob_get_level() > 0) ob_end_flush();
                function_exists('fastcgi_finish_request') ? fastcgi_finish_request() : litespeed_finish_request();
            } else {
                $body = '';
                while (ob_get_level() > 0) $body = ob_get_clean() . $body;
                if (!headers_sent()) {
                    header('Content-Length: ' . strlen($body));
                    header('Connection: close');
                }
                echo $body;
                flush();
            }
            set_time_limit(120);
            foreach ($tasks as $task) {
                try {
                    $task();
                } catch (Throwable $e) {
                    error_log('Tarea en segundo plano: ' . $e->getMessage());
                }
            }
        });
    }
    $tasks[] = $task;
}
