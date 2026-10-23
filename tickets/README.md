# Portal de tickets de soporte · Fedele

> Este documento describe la parte de **tickets**. La estructura de toda la intranet, roles y tareas frecuentes están en el `README.md` de la raíz; los estilos se compilan con `tools/compilar_css.bat` y la base se actualiza con `instalar/migraciones.php` (ya no existen `tools/` ni `instalar/seguridad.sql` dentro de tickets).

Sistema web para que los empleados de Fedele reporten problemas de TI, el área de soporte los atienda, supervisión dé seguimiento con reportes y un superusuario administre cuentas, roles y seguridad.

- **Tecnología:** PHP 8 (sin frameworks) + MySQL/MariaDB con PDO, PHPMailer para correos y Tailwind CSS v4 compilado para el diseño.
- **Dónde corre:** local en XAMPP (`C:\xampp\htdocs\tickets`) y en producción en un hosting con cPanel (`public_html/tickets`).
- **Desarrollado por:** Ing. Jonathan Bautista.

---

## Índice

1. [Roles y permisos](#1-roles-y-permisos)
2. [Cómo funciona, paso a paso](#2-cómo-funciona-paso-a-paso)
3. [Contraseñas: crear, recuperar y borrar](#3-contraseñas-crear-recuperar-y-borrar)
4. [Estructura de archivos](#4-estructura-de-archivos)
5. [Base de datos](#5-base-de-datos)
6. [Configuración (.env)](#6-configuración-env)
7. [Seguridad: qué protege cada medida](#7-seguridad-qué-protege-cada-medida)
8. [Correos que envía el sistema](#8-correos-que-envía-el-sistema)
9. [Evidencias (fotos y capturas)](#9-evidencias-fotos-y-capturas)
10. [Diseño y estilos (Tailwind)](#10-diseño-y-estilos-tailwind)
11. [Instalación local](#11-instalación-local)
12. [Despliegue al servidor](#12-despliegue-al-servidor)
13. [Tareas frecuentes](#13-tareas-frecuentes)
14. [Solución de problemas](#14-solución-de-problemas)
15. [Pruebas realizadas](#15-pruebas-realizadas)

---

## 1. Roles y permisos

Cada cuenta tiene **un rol**, guardado en la columna `usuarios.rol`. Lo asigna el superusuario en **Seguridad → Empleados → Editar**.

| Acción | Empleado | Soporte | Supervisión | Superusuario |
|---|:-:|:-:|:-:|:-:|
| Crear tickets y ver **sus** tickets | ✅ | ✅ | ✅ | ✅ |
| Ver tickets y evidencias de **todos** | — | ✅ | ✅ | ✅ |
| Validar y resolver tickets (panel de soporte) | — | ✅ | — | — |
| Tablero de supervisión, gráficas y reporte CSV | — | — | ✅ | ✅ |
| Página **Seguridad** (protecciones y bitácora) | — | — | — | ✅ |
| Agregar, editar, desactivar y eliminar empleados | — | — | — | ✅ |
| **Asignar roles** | — | — | — | ✅ |
| Enviar enlaces de recuperación y borrar contraseñas de empleados | — | — | — | ✅ |
| Código de acceso por correo al iniciar sesión | — | ✅ | ✅ | ✅ |

Reglas de los roles:
- **Dar o quitar el rol Superusuario pide un código extra.** Se envía al correo del superusuario que hace el cambio, y el cambio no se aplica hasta escribirlo (ver [sección 2](#cambiar-el-rol-de-alguien)).
- **Nadie puede cambiar su propio rol**, y siempre debe quedar **al menos un superusuario activo**.
- **Una cuenta nueva no puede nacer como Superusuario:** se crea con otro rol y luego se cambia, con código.
- **Los cambios de rol aplican en el siguiente clic** de la persona afectada. Si gana un rol que exige código y su sesión no pasó por él, se le cierra la sesión para que vuelva a entrar con código.
- Los permisos se revisan **en el servidor** en cada página. Si alguien sin permiso escribe la dirección a mano, recibe "403 Acceso exclusivo".

> Antes, los roles estaban en `php/soporte_config.php`, `php/supervision_config.php` y `php/superusuario_config.php`. Esos archivos quedaron **obsoletos**: ya no se leen, se conservan solo como referencia y no se suben al servidor.

---

## 2. Cómo funciona, paso a paso

### Inicio de sesión (`modulos/login.php` → `modulos/validacion.php` → `modulos/codigo.php`)
1. La persona escribe su correo y su contraseña.
   - **Si la deja vacía y da Enter** (primera vez), recibe un enlace para crear su contraseña (ver [sección 3](#3-contraseñas-crear-recuperar-y-borrar)).
2. **Límites de intentos:** 5 fallos por correo o 20 por IP en 15 minutos bloquean el acceso durante 15 minutos.
3. Se compara la contraseña con el hash bcrypt guardado. Si una cuenta antigua tenía la contraseña en texto normal, se valida y en ese momento se convierte a hash.
4. **Cuenta desactivada:** no entra.
5. **Empleado:** entra directo a "Mis tickets".
6. **Soporte, supervisión o superusuario:** se le envía un **código de 6 dígitos** a su correo y no entra hasta escribirlo en `codigo.php`.
7. Todo queda en la **bitácora**.

### Empleado: crear un ticket (`modulos/tickets.php` → `modulos/crear_ticket.php`)
1. **Crear ticket:** elige los problemas, describe qué pasa (de 10 a 5,000 caracteres) e indica si sus actividades están detenidas.
2. Puede adjuntar **hasta 3 imágenes**. El navegador las reduce antes de enviarlas (ver [sección 9](#9-evidencias-fotos-y-capturas)).
3. El servidor valida todo y guarda el ticket en una **transacción**. Un token de un solo uso evita que se dupliquen tickets.
4. Se envía el aviso por correo, normal o **urgente** si las actividades están detenidas.

### Soporte: atender (`modulos/soporte.php`)
- Bandeja con búsqueda y pestañas (Por validar · En atención · Resueltos · Todos). Los tickets con actividades detenidas llevan una etiqueta roja.
- Estados de un ticket: `0` Pendiente → **Validar** → `2` En atención → **Resolver** (solución obligatoria) → `1` Resuelto.
- Cada cambio se guarda en una transacción que bloquea el ticket. También queda en `ticket_seguimiento` y en la bitácora, y se avisa por correo.

### Supervisión: seguimiento (`modulos/supervision.php`, `modulos/reporte.php`)
- Filtros, tarjetas de totales, gráfica por área, registros con tiempos de atención y detalle de cada ticket.
- **CSV:** incluye todos los registros filtrados, con protección contra fórmulas de Excel. Cada descarga queda en la bitácora.
- Solo lectura.

### Superusuario: Seguridad (`modulos/seguridad.php` + `modulos/usuarios.php`)
- **Protecciones:** semáforo de código de acceso, llave de cifrado, HTTPS, contraseñas, evidencias, permisos de MySQL, correo real y `TICKETS_URL`.
- **Proteger datos existentes:** convierte a hash las contraseñas en texto y cifra las evidencias antiguas.
- **Empleados:**
  - Agregar (rol y contraseña inicial opcional).
  - Editar datos, **rol** o contraseña.
  - 🔑 **Enviar enlace** para crear o restablecer la contraseña.
  - Desactivar o reactivar.
  - Eliminar.
  - **Borrar contraseñas de empleados.**
- **Cuentas con acceso total** y **bitácora** de los últimos 150 eventos.

### Cambiar el rol de alguien
1. Seguridad → Empleados → ✏️ Editar → elige el nuevo **Rol** → Guardar.
2. Si el cambio **no** involucra a Superusuario (por ejemplo, Empleado → Soporte), se aplica de inmediato.
3. Si **da o quita** Superusuario:
   1. Los demás datos se guardan, pero el rol queda **pendiente**.
   2. Te llega un correo (franja roja) con un código de 6 dígitos que dice exactamente qué cambio vas a confirmar.
   3. En Seguridad aparece un recuadro rojo: escribe el código y pulsa **Confirmar**. También puedes **reenviar** el código (cada 60 segundos) o **cancelar**.
   4. El código vence en 10 minutos. Con 5 errores, el cambio se cancela.
   5. Al confirmar se vuelven a revisar las reglas (no es tu propio rol y queda al menos un superusuario).

---

## 3. Contraseñas: crear, recuperar y borrar

### Primera vez (o después de un borrado): el empleado crea su contraseña
1. En el login escribe **solo su correo** y da **Enter**.
2. Siempre ve el mismo mensaje: «Si tu correo está registrado y todavía no tienes contraseña, te enviamos un enlace…». **Es igual exista o no la cuenta**, para que nadie pueda averiguar qué correos están registrados.
3. Si la cuenta existe, está activa y no tiene contraseña, le llega un correo con el botón **Crear mi contraseña** (vale **24 horas** y **un solo uso**).
4. El enlace abre `modulos/restablecer.php`, una pantalla pequeña donde escribe y confirma su contraseña (de 8 a 72 caracteres, sin contener su correo).
5. Al aceptarla, **se le redirige al login** con el aviso «Tu contraseña se guardó correctamente».

¿Por qué con enlace y no directo en pantalla? Si la contraseña se pudiera crear con solo escribir el correo, cualquiera que conozca el correo de un compañero (son fáciles de adivinar) podría adelantarse, crearle la contraseña y entrar a su cuenta. Con el enlace, **solo el dueño del buzón puede crearla**.

Límites: 3 enlaces por correo y 20 solicitudes por IP cada hora.

### Olvidé mi contraseña
1. El empleado le pide al **superusuario** que lo ayude (no hay autoservicio de recuperación).
2. El superusuario va a Seguridad → Empleados y pulsa 🔑 **Enviar enlace** junto a la persona.
3. A la persona le llega el botón **Restablecer mi contraseña** (vale **60 minutos** y un solo uso), en la misma pantalla de `restablecer.php`.
4. Al guardar, se le redirige al login.
5. Pedir un enlace nuevo **invalida los anteriores** de esa persona.

### Borrar las contraseñas de todos los empleados
- Seguridad → Empleados → **Borrar contraseñas de empleados**. Hay que escribir `BORRAR` para confirmar.
- Solo afecta a cuentas con rol **Empleado**. Soporte, supervisión y superusuario conservan la suya.
- Requiere que `TICKETS_URL` esté configurada y el correo activo; si no, el botón está deshabilitado, porque nadie podría crear su contraseña nueva.
- Después, cada empleado entra con «correo + Enter» y crea la suya.

### Cómo se protegen los enlaces
- El token es aleatorio (256 bits). En la base **solo se guarda su hash SHA-256** (tabla `password_resets`), así que ni con acceso a la base se pueden usar los enlaces.
- Se marca como usado dentro de una transacción, por lo que no se puede usar dos veces ni desde dos pestañas a la vez.
- El enlace se arma con `TICKETS_URL` y **nunca** con la dirección que manda el navegador. Esto evita el ataque de *password reset poisoning*, en el que se falsifica el enlace del correo.
- Los correos con enlaces y códigos **ignoran el modo prueba**: solo los recibe el dueño de la cuenta.

---

## 4. Estructura de archivos

```
tickets/
├── index.php                  Redirige al login o a la página de inicio según el rol.
├── .htaccess                  HTTPS obligatorio en el servidor y bloqueo de archivos sensibles.
├── .env                       (solo local, NO se sube) Contraseñas y llave. Ver .env.example.
├── .env.example               Plantilla de la configuración privada, sin valores reales.
├── modulos/                   Páginas que abre el navegador.
│   ├── login.php              Inicio de sesión (contraseña opcional para pedir el enlace de primera vez).
│   ├── validacion.php         Revisa la contraseña y los límites, envía enlaces de primera vez y decide si pide código.
│   ├── codigo.php             Pide el código de acceso por correo (soporte, supervisión, superusuario).
│   ├── restablecer.php        Pantalla pequeña para crear o restablecer la contraseña desde el enlace del correo.
│   ├── logout.php             Cierra la sesión.
│   ├── tickets.php            "Mis tickets" y ventana para crear un ticket.
│   ├── crear_ticket.php       Guarda un ticket nuevo con sus evidencias (cifradas).
│   ├── evidencia.php          Entrega una imagen solo a quien tiene permiso (y la descifra).
│   ├── soporte.php            Panel de soporte.
│   ├── supervision.php        Tablero de supervisión.
│   ├── reporte.php            CSV de supervisión.
│   ├── seguridad.php          (Superusuario) Protecciones, empleados y roles, cuentas con acceso total y bitácora.
│   └── usuarios.php           (Superusuario) Acciones sobre cuentas y roles; confirmación con código.
├── php/                       Código interno. Bloqueado desde el navegador (php/.htaccess).
│   ├── bootstrap.php          Sesión segura, encabezados de seguridad, zona horaria (México) y conexión.
│   ├── env.php                Lee tickets.env / .env y las variables de entorno.
│   ├── conexion.php           Conexión PDO con los datos del .env.
│   ├── seguridad.php          Bitácora, límites, cifrado, códigos (inicio de sesión y cambio de rol) y enlaces.
│   ├── soporte_helpers.php    Roles (is_support, is_supervisor, is_superuser), encabezado común y estados.
│   ├── ticket_helpers.php     Catálogo de problemas, validación de imágenes y revisión de la cuenta en cada página.
│   ├── reporte_helpers.php    Filtros y consultas del tablero y del CSV.
│   ├── correo.php             Diseño y envío de todos los correos (PHPMailer).
│   ├── correo_config.php      Servidor SMTP, remitente, lista de respaldo de soporte y destinatarios urgentes.
│   ├── *_config.php (3)       OBSOLETOS (roles anteriores). No se suben.
│   └── PHPMailer/             Librería de correo.
├── script/
│   ├── tickets.js             Ventana de crear ticket, reducción de fotos y envío.
│   ├── supervision.js         Filtros del tablero.
│   └── seguridad.js           Búsqueda de empleados, ventanas de agregar/editar/eliminar/borrar y confirmaciones.
├── css/
│   ├── tailwind.css           Fuente del diseño. NO se sube.
│   └── app.css                CSS compilado. Es el que se sube.
├── img/logo.png               Logo.
├── instalar/seguridad.sql     Tablas y columnas de seguridad. Se importa en phpMyAdmin.
└── tools/                     Solo local: tailwindcss.exe y compilar_css.bat.
```

Archivos que existen solo en local y no se suben: `php/crear_usuario.php`, `php/preparar_tickets.php`, `php/preparar_soporte.php`, los tres `*_config.php` de roles y los CSS antiguos (`styles.css`, `login.css`, `soporte.css`, `supervision.css` y `ticket-modal.css`).

---

## 5. Base de datos

| Tabla | Para qué sirve |
|---|---|
| `usuarios` | Cuentas: `id`, `nombre_usuario`, `correo_usuario` (único), `contrasena_usuario` (hash bcrypt, o NULL si aún no la crea), `area`, `jefe_directo`, `correo_jefe`, **`activo`** (1/0) y **`rol`** (`empleado`, `soporte`, `supervision`, `superusuario`). |
| `tickets` | Tabla original: fecha, creador, `estatus_ticket` (0 pendiente, 2 en atención, 1 resuelto), resumen, fecha y autor de la solución. |
| `ticket_detalles` | Problemas (JSON), descripción completa, `actividades_detenidas`, token anti-duplicados y fecha. |
| `ticket_evidencias` | Imágenes. El `contenido` va **cifrado** (empieza con `ENC1`). |
| `ticket_seguimiento` | Historial de validaciones y resoluciones. |
| `password_resets` | Enlaces para crear o recuperar contraseña: `token_hash` (SHA-256), `tipo` (`crear`/`recuperar`), `expira`, `usado_en` y `creado_por` (el superusuario, si lo envió él). |
| `login_attempts` | Contadores de límites: por correo, `ip:…`, `codigo:…`, `enlace:…` y `enlace-ip:…`. |
| `auditoria` | Bitácora: fecha, usuario, correo, IP, acción y detalle. |

Eventos de la bitácora:

| Acción | Significado |
|---|---|
| `login_ok` / `logout` | Inicio y cierre de sesión. |
| `login_fallido` / `login_bloqueado` / `login_desactivado` | Contraseña incorrecta, límite alcanzado o cuenta desactivada. |
| `contrasena_ok` | Contraseña correcta de un administrador; falta el código. |
| `codigo_enviado` / `codigo_no_enviado` / `codigo_fallido` | Estado del código (inicio de sesión o cambio de rol). |
| `codigo_rol_enviado` / `rol_cambiado` / `rol_cancelado` | Cambio de rol: código enviado, aplicado o cancelado. |
| `enlace_crear_enviado` / `enlace_recuperar_enviado` / `enlace_no_enviado` / `enlace_solicitado` | Enlaces de contraseña: enviado (primera vez o por el superusuario), falló, o solicitado sin efecto (correo inexistente o con contraseña). |
| `contrasena_creada` / `contrasena_restablecida` | La persona guardó su contraseña desde el enlace. |
| `contrasenas_borradas` | Borrado masivo de contraseñas de empleados. |
| `ver_ticket` / `ver_evidencia` / `reporte_csv` | Consultas de información sensible. |
| `ticket_validado` / `ticket_resuelto` | Acciones de soporte. |
| `usuario_creado` / `usuario_editado` / `usuario_desactivado` / `usuario_activado` / `usuario_eliminado` | Administración de cuentas. |
| `proteccion_datos` | «Proteger datos existentes». |

---

## 6. Configuración (.env)

`php/env.php` lee el **primer archivo que exista** de esta lista:
1. La ruta en la variable de entorno `TICKETS_ENV_FILE`.
2. **`tickets.env` dos carpetas arriba del sistema.** En el servidor es `/home/jonath70/tickets.env`, **fuera de `public_html`**, así que no se puede descargar desde internet.
3. **`.env` en la carpeta del sistema** (local; el `.htaccess` impide descargarlo).

Una variable de entorno real del servidor tiene prioridad. Un valor vacío (`CLAVE=`) usa el valor por omisión.

| Variable | Qué hace |
|---|---|
| `TICKETS_DB_HOST`, `TICKETS_DB_PORT`, `TICKETS_DB_NAME` | Ubicación de la base (local `localhost`/`3307`/`tickets`; servidor `localhost`/`3306`/`jonath70_tickets`). |
| `TICKETS_DB_USER`, `TICKETS_DB_PASSWORD` | Usuario de MySQL. En el servidor, **solo** con SELECT, INSERT, UPDATE y DELETE. |
| `SMTP_PASS` | Contraseña de `no-responder@fedele.com.mx`. |
| `TICKETS_SMTP_HOST` | Servidor de correo (opcional; por omisión `mail.fedele.com.mx`). |
| `TICKETS_URL` | **Obligatoria para los enlaces de contraseña.** Dirección pública, por ejemplo `https://dominio.com/tickets`. También agrega el botón «Ver en el panel» a los avisos. |
| `TICKETS_MODO_PRUEBA` | Con un correo, los avisos de tickets y de cambio de contraseña llegan solo ahí. Los códigos y los enlaces de contraseña **no** se desvían. |
| `TICKETS_APP_KEY` | ⚠️ **Llave de cifrado** de las evidencias. Si se pierde o se cambia, las evidencias cifradas no se pueden abrir. |
| `TICKETS_CODIGO_ACCESO` | `1` = código de acceso activo. `0` = desactivado, **solo para emergencias**. |
| `TICKETS_CORREO_ACTIVO` | `0` = no se envía ningún correo. ⚠️ Sin correo no llegan los códigos ni los enlaces. |

Generar una llave: `C:\xampp\php\php.exe -r "echo base64_encode(random_bytes(32));"`

Otros ajustes en el código:
- **Correo** (`php/correo_config.php`): servidor y remitente. `'soporte'` es solo un **respaldo**: los avisos de tickets nuevos llegan a **todas las cuentas activas con rol Soporte**, y a esta lista solo si no hay ninguna. `'urgente'` define quién recibe además los tickets con actividades detenidas (`jlarac@fedele.com.mx`).
- **Catálogo de problemas:** `ticket_problems()` en `php/ticket_helpers.php`.

---

## 7. Seguridad: qué protege cada medida

| Medida | Qué protege | Cómo funciona | Dónde está |
|---|---|---|---|
| **Código de acceso por correo** | Que alguien con la contraseña robada de un administrador vea todo. | Código de 6 dígitos al correo del dueño. Vence en 10 minutos, 5 intentos y 5 envíos cada 15 minutos. Se guarda como HMAC dentro de la sesión del servidor. | `php/seguridad.php` (`code_*`, `access_code_*`), `codigo.php` |
| **Código extra para Superusuario** | Que alguien que tome una sesión de superusuario cree otro superusuario o quite al legítimo. | Dar o quitar ese rol exige un código nuevo enviado al superusuario que hace el cambio. | `step_up_issue()`, `usuarios.php` |
| **Roles en la base y revisados en cada clic** | Que una persona conserve permisos que ya le quitaron. | `require_ticket_user()` relee `activo` y `rol` en cada página. Si alguien gana permisos sin haber pasado por el código, debe volver a entrar. | `php/ticket_helpers.php` |
| **Reglas de roles** | Quedarse sin administradores o autoasignarse permisos. | Nadie cambia su propio rol, siempre queda al menos un superusuario y no se crean superusuarios directamente. | `usuarios.php` |
| **Contraseñas con bcrypt** | Que una copia robada de la base revele contraseñas. | Hash irreversible. Las antiguas en texto se convierten al iniciar sesión o con «Proteger datos existentes». | `validacion.php`, `seguridad.php` |
| **Enlaces de contraseña seguros** | Que alguien cree o restablezca la contraseña de otra persona. | Token de 256 bits, solo su hash en la base, un solo uso, caducidad (24 h / 60 min) y siempre enviado al correo del dueño. | `password_link_*`, `restablecer.php` |
| **Mensajes que no revelan cuentas** | Averiguar qué correos están registrados. | «Correo + Enter» responde lo mismo exista o no la cuenta, y los errores de inicio de sesión son genéricos. | `validacion.php` |
| **Protección contra enlaces falsificados** | *Password reset poisoning*. | Los enlaces usan `TICKETS_URL`, nunca el encabezado Host del navegador. | `system_url()` |
| **Límite de intentos** | Fuerza bruta y abuso del correo. | 5 fallos por correo y 20 por IP cada 15 minutos; 3 enlaces por correo y 20 por IP cada hora. | `throttle_*` |
| **Evidencias cifradas (AES-256-GCM)** | Fuga de una copia de la base o de un respaldo. | Cada imagen se cifra con `TICKETS_APP_KEY`, que vive fuera de la base. GCM también detecta alteraciones. | `protect_blob`/`reveal_blob` |
| **Control de acceso a evidencias** | Ver imágenes ajenas cambiando el número en la URL. | Solo el dueño del ticket, soporte o supervisión. | `evidencia.php` |
| **Bitácora** | Investigar quién vio, descargó o cambió qué. | Tabla `auditoria`, visible para el superusuario. | `audit()` |
| **Desactivar cuentas** | Exempleados con acceso. | `activo = 0` impide entrar y cierra la sesión abierta. | `ticket_helpers.php` |
| **Avisos por correo** | Cambios sin que la persona se entere. | Se avisa al dueño cuando un superusuario cambia su contraseña. | `mail_password_changed()` |
| **Sesiones seguras** | Robo de sesión. | Cookie `HttpOnly`, `SameSite`, `Secure` y `__Host-` con HTTPS. Se regenera al iniciar sesión. Caduca tras 30 minutos sin actividad o 10 horas en total. | `bootstrap.php` |
| **Token CSRF** | Acciones hechas desde otro sitio. | Token secreto en cada formulario. | `valid_csrf()` |
| **Encabezados de seguridad** | XSS, clickjacking y fugas de URLs con tokens. | CSP estricta, `X-Frame-Options`, `Referrer-Policy: same-origin`, `Permissions-Policy`, HSTS y `no-store`. | `bootstrap.php` |
| **Sin errores visibles** | Fugas de rutas o consultas. | `display_errors=0`, errores solo al log. | `bootstrap.php` |
| **Consultas preparadas y escape** | Inyección SQL y XSS. | PDO con `?` y `escape()` en toda salida. | Todo el proyecto |
| **HTTPS obligatorio** y **archivos internos bloqueados** | Espionaje en la red y descarga de configuración. | `.htaccess` redirige a HTTPS y bloquea `php/`, `instalar/`, `tools/`, `.env`, `.sql`, etc. | `.htaccess` |
| **Secretos fuera del código y de la web** | Fuga de contraseñas por el código o el zip. | `tickets.env` fuera de `public_html`, nunca en git ni en el zip. | `env.php` |
| **Privilegios mínimos en MySQL** | Daño a tablas u otras bases (por ejemplo, cotizaciones). | La aplicación no crea tablas; su usuario solo necesita SELECT, INSERT, UPDATE y DELETE. Seguridad avisa si tiene permisos de más. | `instalar/seguridad.sql` |
| **CSV seguro** y **validación de imágenes** | Fórmulas maliciosas y archivos disfrazados. | Celdas neutralizadas; se revisa el tipo real, el peso y las dimensiones. | `reporte_helpers.php`, `ticket_helpers.php` |

**Lo que el sistema no puede proteger por sí solo:**
- **cPanel:** activa su verificación en dos pasos (Seguridad → Two-Factor Authentication). Quien entre ahí tiene acceso a todo.
- **Los buzones de correo:** los códigos y enlaces son tan seguros como el correo de cada persona.
- **Los respaldos:** guarda `tickets.env` y los respaldos de la base en un lugar seguro, por separado.

---

## 8. Correos que envía el sistema

| Evento | Para | Copia | Notas |
|---|---|---|---|
| Ticket nuevo (actividades **no** detenidas) | Cuentas con rol Soporte | Creador y su jefe directo | Franja azul e imágenes adjuntas. |
| Ticket nuevo (actividades **sí** detenidas) | Soporte y `jlarac@fedele.com.mx` | Creador y su jefe directo | **Urgente:** franja roja, `URGENTE · …` y prioridad alta. |
| Ticket validado / resuelto | Creador | Su jefe directo y soporte | Observaciones o solución. |
| Código de acceso | Quien inicia sesión (soporte, supervisión, superusuario) | — | Ignora el modo prueba. |
| Código para confirmar un cambio de rol | El superusuario que hace el cambio | — | Franja roja, prioridad alta; indica el cambio exacto. Ignora el modo prueba. |
| Crea tu contraseña | El empleado que pidió el enlace con «correo + Enter» | — | Vale 24 horas y un solo uso. Ignora el modo prueba. |
| Restablece tu contraseña | La persona a quien el superusuario le envió el enlace | — | Vale 60 minutos y un solo uso. Ignora el modo prueba. |
| Contraseña cambiada | La persona afectada | — | Indica quién la cambió. |

Si un correo falla, la operación se guarda igual y el error queda en el log de PHP.

---

## 9. Evidencias (fotos y capturas)

1. **Navegador** (`script/tickets.js`): las imágenes de más de 1 MB se reducen a un máximo de 1920 px y se recomprimen; las pequeñas no se tocan. El envío se hace con `fetch` y `FormData` (compatible con iPad).
2. **Servidor** (`ticket_images()`): máximo 3 imágenes JPG, PNG o WebP de 1 MB y 20 megapíxeles, con verificación del tipo real.
3. **Guardado:** cifrado AES-256-GCM.
4. **Consulta** (`evidencia.php`): se revisa el permiso, se descifra y se registra en la bitácora cuando la abre soporte o supervisión.

---

## 10. Diseño y estilos (Tailwind)

- Tailwind CSS v4 **compilado**. No se usa CDN porque la política CSP solo permite archivos propios.
- La fuente es `css/tailwind.css` y el resultado es `css/app.css`. Recompila después de cambiar clases en `modulos/`, `script/` o `php/soporte_helpers.php`:
  ```powershell
  tools\compilar_css.bat          # una vez
  tools\compilar_css.bat --watch  # al guardar
  ```
- Si falta `tools/tailwindcss.exe`, descarga `tailwindcss-windows-x64.exe` desde https://github.com/tailwindlabs/tailwindcss/releases.
- Compatibilidad con iPad y Safari antiguos: los modales usan `flex-auto` y `dvh`, y las leyendas envuelven su contenido en un `<span>`.

---

## 11. Instalación local

1. Enciende Apache y MySQL en XAMPP. El MySQL local usa el puerto **3307**.
2. Importa las tablas y columnas de seguridad (se puede repetir):
   ```powershell
   Get-Content instalar\seguridad.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root -P 3307 -h 127.0.0.1 tickets
   ```
3. Copia `.env.example` como `.env` y llénalo, incluida `TICKETS_URL=http://localhost/tickets` y una `TICKETS_APP_KEY` nueva.
4. Abre http://localhost/tickets/, entra como superusuario → Seguridad → **Proteger datos existentes**.

---

## 12. Despliegue al servidor

Los paquetes se generan en `Documents\despliegue_tickets\` y nunca llevan contraseñas ni llaves.

1. Confirma que `https://tu-dominio/tickets/` funciona con certificado válido (cPanel → SSL/TLS Status → Run AutoSSL).
2. phpMyAdmin → `jonath70_tickets` → **Importar `seguridad.sql`**. Agrega `rol` y `password_resets`, y traslada los roles anteriores (solo la primera vez). Se puede repetir sin riesgo.
3. En `/home/jonath70/tickets.env`, **agrega `TICKETS_URL=https://tu-dominio/tickets`**. Sin ella no se pueden enviar enlaces de contraseña.
4. Sube el zip a `public_html/` y extráelo sobre `tickets`, aceptando reemplazar.
5. Entra como superusuario y revisa que todo en **Seguridad → Protecciones** esté en ✓.
6. Cuando quieras que los empleados creen su propia contraseña: **Borrar contraseñas de empleados**.

Nunca vuelvas a importar un respaldo completo de la base encima de la del servidor: borrarías los datos nuevos.

---

## 13. Tareas frecuentes

| Quiero… | Cómo |
|---|---|
| Agregar un empleado | Seguridad → Empleados → **Agregar**. Sin contraseña: la persona la crea con «correo + Enter». |
| Dar acceso de soporte o supervisión | Editar → Rol → Soporte o Supervisión. Aplica de inmediato y le pedirá código al entrar. |
| Hacer a alguien superusuario | Editar → Rol → Superusuario → escribe el código que te llega. |
| Que alguien recupere su contraseña | 🔑 **Enviar enlace** junto a la persona (vale 60 minutos). |
| Que todos los empleados creen contraseña nueva | **Borrar contraseñas de empleados** → escribe `BORRAR`. |
| Que alguien deje de entrar | **Desactivar** (se conserva su historial). |
| Desactivar el modo prueba | `TICKETS_MODO_PRUEBA=` vacío en `tickets.env`. |
| El correo no funciona y nadie de soporte puede entrar | Emergencia: `TICKETS_CODIGO_ACCESO=0` en `tickets.env`; vuelve a `1` en cuanto se resuelva. |
| Ver quién hizo algo | Seguridad → Bitácora o la tabla `auditoria`. |

---

## 14. Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| "No se pudo enviar el código" | SMTP caído, `SMTP_PASS` incorrecta o correo apagado. | Revisa `tickets.env`. En una emergencia, `TICKETS_CODIGO_ACCESO=0`. |
| El empleado dio Enter y no le llega el enlace | No existe la cuenta, ya tiene contraseña, está desactivada, falta `TICKETS_URL` o se alcanzó el límite (3 por hora). | Revisa la bitácora (`enlace_*`). El superusuario puede usar 🔑 Enviar enlace. |
| «Enlace no válido» | Venció, ya se usó o se pidió uno más nuevo. | Pedir otro enlace. |
| El botón «Borrar contraseñas» o 🔑 está deshabilitado | Falta `TICKETS_URL` o el correo está apagado. | Configúralos en `tickets.env`. |
| "Tus permisos cambiaron. Inicia sesión de nuevo" | Te asignaron un rol que exige código. | Inicia sesión y escribe el código. |
| "Tu cuenta está desactivada" | La desactivó el superusuario. | Reactivarla. |
| "Demasiados intentos" | Límites de seguridad. | Esperar 15 minutos o borrar la fila en `login_attempts`. |
| Las evidencias no abren ("la llave cambió") | Se cambió o perdió `TICKETS_APP_KEY`. | Restaura el `tickets.env` original. |
| "No se pudo cargar el estado de seguridad" | Falta importar `instalar/seguridad.sql`. | Impórtalo. |
| La página no abre tras subir `.htaccess` | No hay SSL. | AutoSSL en cPanel. |

---

## 15. Pruebas realizadas

Todas las pruebas automáticas se hicieron sobre **copias temporales** de la base, con el correo dirigido a un servidor inexistente para no enviar nada, y después las copias se eliminaron.

- **Roles y contraseñas (32/32):**
  - Asignar Soporte se aplica de inmediato y cierra la sesión sin código de esa persona.
  - Dar y quitar Superusuario queda pendiente: un código incorrecto no lo aplica y el correcto sí.
  - No puedes cambiar tu propio rol (pero sí editar tus datos) y no se crean superusuarios directamente.
  - «Correo + Enter» crea un enlace de 24 horas, con el mismo mensaje para correos inexistentes.
  - La pantalla de restablecer rechaza contraseñas cortas o que no coinciden, redirige al login con aviso y el enlace no sirve una segunda vez ni vencido.
  - La recuperación del superusuario crea un enlace de 60 minutos e invalida los anteriores.
  - El borrado masivo exige `BORRAR`, afecta solo a empleados y deja sin efecto las contraseñas viejas.
  - La bitácora registra todo.
- **Superusuario (26/26):** permisos de Seguridad y gestión de cuentas (alta, edición, desactivación con cierre de sesión, reactivación, eliminación y protecciones).
- **Seguridad general (20/20):** código de acceso, fuerza bruta, escalada de privilegios, migración de contraseñas y cifrado de evidencias (idénticas byte por byte), y bitácora.
- **Fotos e iPad:** reducción de fotos de 8 MB y 15 MB a menos de 1 MB, aceptadas por la validación real del servidor en WebKit reciente y en WebKit 16 (Safari de iPad). El modal se ve completo en WebKit 16.
