# Fedele 360

Una sola entrada (`index.php`) y un mismo inicio de sesión para tres apps: **Tickets**, **Asistencia** e **Incidencias**
(vacaciones, permisos e incapacidades). Todo usa la base `intranet` (MariaDB/MySQL).

## Estructura

```
intranet/
├── index.php            Entrada: manda a cada quien a su primera app (comun/acceso.php)
├── dev.php              Solo local (INTRANET_DEV=1): entrar eligiendo el tipo de usuario, sin contraseña
├── .htaccess            HTTPS y bloqueo de archivos internos para toda la intranet
├── comun/               Lo compartido por las tres apps
│   ├── sesion.php         Sesión única (la del inicio de sesión de tickets)
│   ├── acceso.php         Qué ve cada quien según su rol y puesto (única fuente de verdad)
│   ├── barra.php/.css     Encabezado con las pestañas de la intranet
│   ├── acciones.js        Avisos, confirmaciones y bloqueo de doble clic
│   ├── correo.php         Envío de TODOS los correos (una cuenta SMTP y un modo prueba, en tickets/.env)
│   ├── PHPMailer/         Librería de correo
│   ├── segundo_plano.php  Tareas después de responder (los correos no hacen esperar al usuario)
│   ├── pdf.php            Generador de PDF (formato de vacaciones)
│   ├── consumo.js, registrar_consumo.php  Mide los datos que usa cada dispositivo (reporte en asistencia/consumo.php)
│   └── dev.css
├── tickets/             Mesa de ayuda, inicio de sesión, Usuarios y Seguridad
│   ├── .env               Configuración privada local de TODA la intranet (en el servidor: /home/USUARIO/intranet.env)
│   ├── modulos/           Páginas
│   ├── php/               Funciones (conexión, avisos de tickets, seguridad, roles)
│   ├── script/ css/ img/
├── asistencia/          Jornada, visitas médicas y administración de asistencia
│   ├── config.php         Quién valida incidencias (copia de los correos) y llave de los enlaces firmados
│   ├── bootstrap.php, db.php  Sesión, base y utilidades que también usa incidencias/
│   ├── assets/            JS, estilos (assets/src/input.css → app.css) y librerías (Leaflet, xlsx)
│   └── uploads/           Evidencias de permisos e incapacidades (sin acceso web)
├── incidencias/         Mis incidencias, mi equipo, por revisar, todas, saldos y formato PDF
│   ├── lib_vacaciones.php   Saldos por aniversario, días hábiles y festivos (también lo usa asistencia)
│   ├── lib_solicitudes.php  Validación en dos pasos (jefe directo → quien valida) y sus correos
│   └── autorizar.php        Autorizar desde el correo (enlace firmado, sin sesión)
├── correos/             Correos que no se pudieron enviar (.eml), para revisarlos; sin acceso web
├── instalar/            Base de datos (solo por consola, sin acceso web)
│   ├── fusionar.php       Arma la base `intranet` desde las bases originales de tickets y asistencia (una vez)
│   └── migraciones.php    Cambios de estructura; se puede ejecutar las veces que sea
└── tools/               Solo local: compilar_css.bat (estilos), empaquetar.ps1 y PASOS_SERVIDOR.md (subir al servidor)
```

## Roles y puestos

`usuarios.rol`: empleado, gerente, soporte, supervision, superusuario. `usuarios.puesto`: oficina, dermoconsejera, representante.
Lo que ve cada quien sale solo de esos dos campos (`comun/acceso.php`):

| | Tickets | Asistencia | Incidencias |
|---|---|---|---|
| Representante médico | ✓ | registra jornada y visitas | ✓ |
| Dermoconsejera | — | registra jornada | ✓ |
| Oficina | ✓ | — | ✓ |
| Gerente | según su puesto | + su equipo si es de ventas (representante o dermoconsejera) | + su equipo |
| Soporte | solo Panel de soporte | — | — |
| Supervisión / Superusuario | reportes | administración | todo (valida quien está en `config.php > mail > cc`) |

El equipo de un gerente son las cuentas que lo tienen en `correo_jefe`.

## Tareas frecuentes

- **Cambiar estilos:** después de editar clases, `tools\compilar_css.bat` (o `tools\compilar_css.bat incidencias --watch`).
- **Actualizar la base:** `C:\xampp\php\php.exe instalar\migraciones.php`.
- **Correos de prueba:** `TICKETS_MODO_PRUEBA` (tickets/.env) manda todos los correos de la intranet a una sola dirección;
  vacío = destinatarios reales. `TICKETS_CORREO_ACTIVO=0` apaga los envíos (se guardan como .eml en correos/).

- **Subir al servidor:** `powershell -ExecutionPolicy Bypass -File tools\empaquetar.ps1 -Dominio https://tudominio.com`
  genera en `C:\xampp\despliegue_intranet\` el zip, la base y la configuración, con la guía `PASOS.md`.
  Para actualizar después, agrega `-SoloCodigo` (nunca se vuelve a subir la base).

El detalle de seguridad, contraseñas y correos de tickets está en `tickets/README.md`.
