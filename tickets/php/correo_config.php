<?php
// Avisos de tickets: destinatarios y textos. La cuenta SMTP y el modo prueba son de toda la intranet (comun/correo.php, .env).
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/seguridad.php'; // system_url()
return [
    // TICKETS_CORREO_ACTIVO=0 en el .env apaga todos los envíos (pruebas locales o emergencias).
    'activo' => env('TICKETS_CORREO_ACTIVO', '1') !== '0',
    // Modo prueba: si TICKETS_MODO_PRUEBA tiene un correo, TODOS los avisos se envían solo ahí, sin copias. Vacío = destinatarios reales.
    'modo_prueba' => env('TICKETS_MODO_PRUEBA'),
    'nombre_remitente' => 'Fedele 360 · Soporte',
    // Destinatario principal de los tickets nuevos (Norberto Nolasco, soporte).
    'soporte' => ['nnolasco@fedele.com.mx'],
    // Destinatarios adicionales cuando el solicitante indica que sus actividades se detienen (ticket urgente).
    'urgente' => ['jlarac@fedele.com.mx'],
    // Dirección pública del sistema para incluir un enlace en los correos, p. ej. https://servidor/tickets. Vacío = sin enlace.
    'url_sistema' => system_url(),
];
