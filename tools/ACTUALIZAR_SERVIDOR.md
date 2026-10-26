# Actualizar Fedele 360 en el servidor

Para cuando la intranet **ya está instalada** en **{DOMINIO}/intranet**. Se sube solo el código y se aplican los
cambios de la base; los datos reales del servidor (usuarios, tickets, asistencia, incidencias, evidencias) no se tocan.

**Esta versión trae:** la app Cotizaciones, «Soporte» como nuevo nombre de Tickets (con la solución visible para el
empleado), el diseño nuevo y correcciones de seguridad. Por una de ellas, **todos tendrán que volver a iniciar sesión
una vez** después de actualizar: avísalo antes y hazlo en un horario con poco uso (unos 20 minutos).

## 1. Genera el paquete (en tu computadora)

En PowerShell, desde `C:\xampp\htdocs\intranet`:

```
powershell -ExecutionPolicy Bypass -File tools\empaquetar.ps1 -Dominio {DOMINIO} -SoloCodigo
```

Crea `C:\xampp\despliegue_intranet\<fecha>\` con `intranet.zip` (el código) y esta guía. El zip **no** lleva
`asistencia/config.php`, las evidencias ni la configuración privada: las del servidor se conservan.

## 2. Respaldo (antes de tocar nada)

1. cPanel → **phpMyAdmin** → base `{USUARIO}_intranet` → pestaña **Exportar** → *Rápido*, formato SQL → **Exportar**.
   Guarda el archivo en tu computadora.
2. cPanel → **Administrador de archivos** → `public_html` → clic derecho en la carpeta `intranet` → **Compress** →
   *Zip Archive* → nombre `intranet_respaldo_FECHA.zip`. Descárgalo y luego bórralo del servidor.

Con estos dos archivos puedes regresar a la versión anterior (ver «Si algo sale mal»).

## 3. Sube el código

1. Administrador de archivos → `public_html` → **Cargar** → `intranet.zip`.
2. Clic derecho en `intranet.zip` → **Extract** → en `/public_html`, aceptando **reemplazar** los archivos.
3. Borra `intranet.zip` de `public_html`.

## 4. Actualiza la base de datos

Crea las tablas de Cotizaciones (productos, clientes, lista de precios, pedidos), las que falten de versiones
anteriores, y carga los datos iniciales de Cotizaciones. Se puede repetir sin riesgo: solo agrega lo que falta.

1. El usuario de la base solo tiene permisos de datos y aquí se crean tablas. cPanel → **Bases de datos MySQL** →
   *Usuarios actuales* → `{USUARIO}_intranet` → modificar privilegios → marca **TODOS LOS PRIVILEGIOS** → guardar.
2. cPanel → **Terminal** y ejecuta:

   ```
   php ~/public_html/intranet/instalar/importar_cotizaciones.php
   ```

   Debe terminar con algo como:

   ```
   Migración: cot_productos: productos para pedidos y cotizaciones
   ...
   cot_productos: 165 productos.
   cot_clientes: 2709 clientes (9 repetidos sin cargar).
   cot_lista_precios: 135 productos.
   ```

   Si dice «ya tiene datos: no se tocó», ya estaba cargado: está bien.
3. **Regresa los privilegios** a solo `SELECT`, `INSERT`, `UPDATE` y `DELETE` (mismo lugar que el paso 1).

¿Tu cPanel no tiene Terminal? Pide a soporte del hosting que ejecute el comando del paso 2, o avísame y te preparo
un archivo para importarlo en phpMyAdmin.

## 5. Revisa la configuración privada

En `/home/{USUARIO}/intranet.env` (fuera de `public_html`) no hay nada nuevo que agregar en esta versión. Confirma solo:

- `TICKETS_MODO_PRUEBA=jbautista@fedele.com.mx` mientras pruebas (todos los avisos llegan solo ahí).
- Que **no** exista la línea `INTRANET_DEV` (el modo desarrollo es solo para tu computadora).

## 6. Prueba (con el modo prueba activo)

Entra a **{DOMINIO}/intranet** (te pedirá iniciar sesión de nuevo y, como superusuario, un código por correo).

| Qué probar | Cómo | Debe pasar |
|---|---|---|
| Seguridad | Riel → **Seguridad** | Todo en verde, salvo «Correos reales» (el modo prueba está activo a propósito) |
| Soporte | Crea un ticket; con la cuenta de soporte, valídalo y resuélvelo | Te llegan los avisos; en *Mis tickets* aparece la solución |
| Asistencia | Desde un celular: registra entrada y una visita | Pide permiso de ubicación y guarda el registro |
| Incidencias | Pide un permiso para un día próximo | Te llega el aviso; el jefe lo autoriza y Administración lo aprueba |
| Cotizaciones | Como representante, manda una cotización y un pedido de prueba | Te llegan con su PDF (y Excel en el pedido) y aparecen en *Mis pedidos* |
| Cotizaciones (admin) | Como superusuario: **Productos**, **Lista de precios**, **Clientes** | Se ven los 165 productos, 135 precios y 2,709 clientes |
| Celulares | Abre la intranet en 2 o 3 teléfonos del equipo | Se ve completa y la barra de apps queda abajo (iPhone con iOS 16.4 o más nuevo) |

En modo prueba **también las cotizaciones a clientes y los pedidos a Compras** llegan solo a tu correo: nadie de fuera
recibe nada todavía.

## 7. Activa los correos reales

Cuando todo funcione: edita `/home/{USUARIO}/intranet.env`, deja `TICKETS_MODO_PRUEBA=` vacío y guarda.
Desde ese momento los pedidos llegan a Compras (Samuel Pérez, con copia oculta a fmartinez) y las cotizaciones a los clientes.

## 8. Avisa al equipo

- Todos deben volver a iniciar sesión una vez.
- «Tickets» ahora se llama **Soporte**; ahí mismo ven la solución de sus solicitudes.
- Los representantes médicos tienen la nueva pestaña **Cotizaciones**: el pedido sale a su nombre, sin elegir representante.

## Antes de activar los correos reales, decide

- **Listas de precios cruzadas:** «Dermatológica Angelópolis» usa los precios de MEDAE y al revés (así venía del
  cotizador anterior). Confírmalo con Compras; si está mal, se corrige en una línea.
- **Samuel Pérez y Mayra Reynoso** ya no pueden enviar pedidos (antes usaban PIN): ¿se les da acceso?
- **15 productos sin marca** no aparecen en el cotizador hasta que se les asigne una en Cotizaciones → Productos.
- **Contraseña del correo:** el cotizador anterior la tenía escrita en su código. Si esa versión estuvo en algún
  servidor o se compartió, cámbiala (y actualiza `SMTP_PASS` en `intranet.env`).

## Si algo sale mal

- **«El servicio no está disponible»:** revisa que la base y su contraseña en `intranet.env` coincidan.
- **Error 500:** el detalle queda en `public_html/intranet/error_log`. Revisa que PHP sea 8.1 o más nuevo.
- **El paso 4 falla con «command denied» o «Access denied»:** faltó darle todos los privilegios al usuario de la base.
- **No llegan correos:** los que no salieron se guardan en `public_html/intranet/correos/` con el motivo.
- **Regresar a la versión anterior:** borra `public_html/intranet`, sube y extrae `intranet_respaldo_FECHA.zip` en
  `public_html`. Las tablas nuevas de Cotizaciones no estorban a la versión anterior; si quieres quitarlas, en
  phpMyAdmin importa el respaldo SQL del paso 2.
