# Subir Fedele 360 al servidor (cPanel)

Queda en **{DOMINIO}/intranet**, con su propia base y su propia configuración. Los sistemas anteriores
(`public_html/tickets`, su base y `/home/{USUARIO}/tickets.env`) **no se tocan** y siguen funcionando.

Archivos de esta carpeta:

| Archivo | Qué es | Dónde va |
|---|---|---|
| `intranet.zip` | El código | Se extrae en `public_html` (crea `public_html/intranet`) |
| `intranet.sql` | La base completa (usuarios, tickets, incidencias, vacaciones) | Se importa en phpMyAdmin |
| `intranet.env` | Contraseñas y llaves | `/home/{USUARIO}/intranet.env`, **fuera de `public_html`** |

> `intranet.env` tiene la contraseña del correo y la llave de las evidencias. No lo mandes por correo ni lo subas a `public_html`.

## 0. Antes de empezar

1. **PHP 8.1 o más nuevo** para el dominio: cPanel → *MultiPHP Manager* (o *Select PHP Version*). Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `zlib`.
2. **HTTPS válido**: cPanel → *SSL/TLS Status* → *Run AutoSSL*. Sin HTTPS no se puede iniciar sesión (la cookie es segura) ni pedir la ubicación en Asistencia.

## 1. Base de datos

1. cPanel → **Bases de datos MySQL**:
   - Crea la base `{USUARIO}_intranet`.
   - Crea el usuario `{USUARIO}_intranet` con una contraseña larga (usa el generador) y guárdala.
   - *Agregar usuario a la base*: elige **solo** `SELECT`, `INSERT`, `UPDATE` y `DELETE`. La aplicación no necesita más
     (la página Seguridad avisa si tiene permisos de sobra).
2. cPanel → **phpMyAdmin** → elige `{USUARIO}_intranet` en la izquierda → pestaña **Importar** → `intranet.sql` → **Importar**.
   Debe terminar sin errores y mostrar 15 tablas y 2 vistas (`asistencia_usuarios`, `personal_usuarios`).

## 2. Configuración privada

1. Abre `intranet.env` con el Bloc de notas y cambia `ESCRIBE_AQUI_LA_CONTRASENA_DE_LA_BASE` por la contraseña del paso 1.
   Revisa que `INTRANET_URL` sea `{DOMINIO}/intranet`.
2. cPanel → **Administrador de archivos** → la carpeta de inicio `/home/{USUARIO}` (la que contiene `public_html`, **no** adentro).
   Sube `intranet.env` ahí. Clic derecho → *Change Permissions* → `600`.

## 3. Código

1. Administrador de archivos → `public_html` → **Cargar** `intranet.zip`.
2. Clic derecho en `intranet.zip` → **Extract** → en `/public_html`. Debe quedar `public_html/intranet/index.php`.
3. Borra `intranet.zip` de `public_html`.

## 4. Probar

1. Abre **{DOMINIO}/intranet**: debe pedir iniciar sesión (no hay selector de usuarios en el servidor).
2. Entra con tu cuenta: como superusuario te llega un **código a tu correo**.
3. En **Tickets → Seguridad** revisa el estado. Todo debe salir en verde, salvo «Correos reales»: el modo prueba está activo a propósito.
4. Quien no tenga contraseña escribe su correo, deja la contraseña vacía y da Enter: le llega un enlace para crearla.
5. Prueba una solicitud de incidencia y un ticket: los avisos llegan a `jbautista@fedele.com.mx` y dicen a quién iban.

## Correos en modo prueba

Todos los avisos (tickets e incidencias) llegan solo a la dirección de `TICKETS_MODO_PRUEBA` en `intranet.env`.
Los **códigos de acceso** y los **enlaces de contraseña** sí van a su dueño, porque sin ellos nadie podría entrar.
Para enviarlos a sus destinatarios reales: edita `/home/{USUARIO}/intranet.env` y deja `TICKETS_MODO_PRUEBA=` vacío.

## Actualizar más adelante

Desde la carpeta de la intranet en tu computadora:

```
powershell -ExecutionPolicy Bypass -File tools\empaquetar.ps1 -Dominio {DOMINIO} -SoloCodigo
```

Genera solo `intranet.zip` (sin la base, la configuración ni las evidencias). Súbelo a `public_html` y extráelo aceptando reemplazar.
**Nunca vuelvas a importar un `intranet.sql` en el servidor: borraría los datos reales.**
Si un cambio necesita modificar la base, se hace con `instalar/migraciones.php` (por Terminal de cPanel:
`php ~/public_html/intranet/instalar/migraciones.php`, con un usuario de base que tenga permiso `ALTER`).

## Si algo falla

- **Pantalla «El servicio no está disponible»**: la base o la contraseña de `intranet.env` no coinciden, o el archivo no está en `/home/{USUARIO}/`.
- **No deja iniciar sesión / se regresa al login**: el sitio no está en HTTPS.
- **Error 500**: versión de PHP menor a 8.1. Los detalles quedan en `public_html/intranet/error_log`.
- **No llegan correos**: los que no salieron se guardan en `public_html/intranet/correos/` (sin acceso web) con el motivo.
