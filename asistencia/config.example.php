<?php
// Copia este archivo como config.php en el servidor. config.php no se sube a git y el .htaccess bloquea su acceso.
// La base de datos, la cuenta de correo (SMTP) y el modo prueba se toman de tickets/.env (o tickets.env fuera de public_html).
return [
    // Opcional: apagar funciones en este servidor (por defecto todas encendidas).
    // 'features' => ['calendario' => false, 'incidencias' => false, 'vacaciones' => false],
    'mail' => [
        // Quien valida incidencias (copia de cada solicitud); varios separados por coma.
        'cc' => 'alopez@fedele.com.mx',
        'from_name' => 'Fedele 360 · Incidencias',
    ],
    // Llave secreta (64 caracteres al azar) para firmar los enlaces de "Autorizar" de los correos.
    'app_key' => '',
];
