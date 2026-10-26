# Prepara todo lo que se sube al servidor (cPanel) en C:\xampp\despliegue_intranet\<fecha>\:
#   intranet.zip     el código (sin herramientas, modo desarrollo, fuentes de estilos ni configuración privada)
#   intranet.sql     la base 'intranet' completa para importar en phpMyAdmin (sin DEFINER: cPanel no lo permite)
#   intranet.env     la configuración privada del servidor (va en /home/USUARIO/intranet.env, NUNCA en public_html)
#   PASOS.md         la guía de instalación
# Uso (PowerShell, desde la carpeta de la intranet):
#   powershell -ExecutionPolicy Bypass -File tools\empaquetar.ps1 -Dominio https://tudominio.com
# Actualizaciones (la intranet ya está en el servidor): -SoloCodigo genera solo intranet.zip, sin la base, sin la
# configuración y sin las evidencias locales, para no pisar los datos reales del servidor.
param(
    [string]$Dominio = 'https://TU-DOMINIO',
    [string]$UsuarioCpanel = 'jonath70',
    [switch]$SoloCodigo
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$root = Split-Path -Parent $PSScriptRoot
$out = Join-Path 'C:\xampp\despliegue_intranet' (Get-Date -Format 'yyyy-MM-dd_HHmm')
New-Item -ItemType Directory -Force $out | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)

# ---------- Configuración local (tickets/.env) ----------
$envLocal = @{}
foreach ($line in [IO.File]::ReadAllLines((Join-Path $root 'tickets\.env'), $utf8)) {
    if ($line -match '^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$') { $envLocal[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
function Get-EnvValue([string]$key, [string]$default = '') { if ($envLocal.ContainsKey($key) -and $envLocal[$key] -ne '') { $envLocal[$key] } else { $default } }

# ---------- 1. Código ----------
# Fuera del paquete: herramientas locales, modo desarrollo, documentación, fuentes de estilos, instalador de la fusión,
# configuración privada y archivos generados (correos no enviados).
$exclude = @(
    '(^|/)\.(git|claude|vscode|idea)/', '^tools/', '^asistencia[Vv]\d+/', '^dev\.php$', '^comun/dev\.css$', '^instalar/fusionar\.php$', '\.md$', '(^|/)\.gitignore$',
    '^tickets/\.env', '^tickets/\.claude/', '^asistencia/config\.example\.php$', '^asistencia/assets/src/',
    '^(tickets|incidencias)/css/tailwind\.css$', '^correos/.+\.eml$'
)
# En una actualización tampoco van la configuración de asistencia ni las evidencias: las del servidor se conservan.
if ($SoloCodigo) { $exclude += @('^asistencia/config\.php$', '^asistencia/uploads/(?!\.htaccess$)') }
$zipPath = Join-Path $out 'intranet.zip'
$zip = [IO.Compression.ZipFile]::Open($zipPath, 'Create')
$count = 0
try {
    Get-ChildItem -LiteralPath $root -Recurse -File -Force | ForEach-Object {
        $rel = $_.FullName.Substring($root.Length + 1).Replace('\', '/')
        foreach ($pattern in $exclude) { if ($rel -match $pattern) { return } }
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, "intranet/$rel", 'Optimal') | Out-Null
        $count++
    }
} finally { $zip.Dispose() }
Write-Host "intranet.zip: $count archivos"
if ($SoloCodigo) {
    # Guía de actualización (respaldo, código, migraciones, pruebas) con el dominio y el usuario de cPanel.
    $update = Join-Path $PSScriptRoot 'ACTUALIZAR_SERVIDOR.md'
    if (Test-Path $update) {
        [IO.File]::WriteAllText((Join-Path $out 'PASOS.md'), [IO.File]::ReadAllText($update, $utf8).Replace('{DOMINIO}', $Dominio.TrimEnd('/')).Replace('{USUARIO}', $UsuarioCpanel), $utf8)
    }
    Write-Host "Listo (solo código): $out"
    return
}

# ---------- 2. Base de datos ----------
$dump = 'C:\xampp\mysql\bin\mysqldump.exe'
$db = Get-EnvValue 'TICKETS_DB_NAME' 'intranet'
$common = @('-h', (Get-EnvValue 'TICKETS_DB_HOST' '127.0.0.1').Replace('localhost', '127.0.0.1'), '-P', (Get-EnvValue 'TICKETS_DB_PORT' '3307'), '-u', (Get-EnvValue 'TICKETS_DB_USER' 'root'),
    '--default-character-set=utf8mb4', '--single-transaction', '--skip-lock-tables', '--hex-blob', '--net-buffer-length=262144', '--no-create-db', '--skip-triggers', '--skip-comments')
$pass = Get-EnvValue 'TICKETS_DB_PASSWORD'
if ($pass -ne '') { $common += "--password=$pass" }
# Bitácora, intentos de acceso y enlaces de contraseña son de las pruebas locales: solo su estructura.
$volatile = @('auditoria', 'login_attempts', 'password_resets')
$dataFile = Join-Path $out 'datos.tmp.sql'
$structFile = Join-Path $out 'estructura.tmp.sql'
& $dump @common ($volatile | ForEach-Object { "--ignore-table=$db.$_" }) "--result-file=$dataFile" $db
if ($LASTEXITCODE -ne 0) { throw 'mysqldump falló (¿está encendido MySQL en XAMPP?)' }
& $dump @common '--no-data' "--result-file=$structFile" $db @volatile
if ($LASTEXITCODE -ne 0) { throw 'mysqldump falló' }
$sql = "-- Base de la intranet Fedele ($(Get-Date -Format 'yyyy-MM-dd HH:mm')). Importar en phpMyAdmin en la base vacía del servidor.`n" +
    [IO.File]::ReadAllText($dataFile, $utf8) + "`n" + [IO.File]::ReadAllText($structFile, $utf8)
# cPanel no deja crear vistas con DEFINER de otro usuario: las vistas quedan con los permisos de quien las consulta.
$sql = [regex]::Replace($sql, '/\*!50013 DEFINER=`[^`]*`@`[^`]*` SQL SECURITY DEFINER \*/', '/*!50013 SQL SECURITY INVOKER */')
$sql = [regex]::Replace($sql, 'DEFINER=`[^`]*`@`[^`]*`\s*', '')
[IO.File]::WriteAllText((Join-Path $out 'intranet.sql'), $sql, $utf8)
Remove-Item $dataFile, $structFile
Write-Host ("intranet.sql: {0:N0} KB" -f ((Get-Item (Join-Path $out 'intranet.sql')).Length / 1KB))

# ---------- 3. Configuración privada del servidor ----------
$intranetUrl = $Dominio.TrimEnd('/') + '/intranet'
$envServer = @"
# Configuración privada de la intranet en el servidor. Va en /home/$UsuarioCpanel/intranet.env (fuera de public_html).
# NO la subas dentro de public_html ni la compartas.

# Base de datos (cPanel → Bases de datos MySQL: nombre y usuario completos, con prefijo)
TICKETS_DB_HOST=localhost
TICKETS_DB_PORT=3306
TICKETS_DB_NAME=${UsuarioCpanel}_intranet
TICKETS_DB_USER=${UsuarioCpanel}_intranet
TICKETS_DB_PASSWORD=ESCRIBE_AQUI_LA_CONTRASENA_DE_LA_BASE

# Cuenta no-responder@fedele.com.mx
SMTP_PASS=$(Get-EnvValue 'SMTP_PASS')

# MODO PRUEBA: todos los avisos llegan solo aquí. Para enviarlos a sus destinatarios reales, déjalo vacío.
# (Los códigos de acceso y los enlaces de contraseña siempre van a su dueño, para que pueda entrar.)
TICKETS_MODO_PRUEBA=$(Get-EnvValue 'TICKETS_MODO_PRUEBA' 'jbautista@fedele.com.mx')

# Dirección pública de la intranet (enlaces de los correos)
INTRANET_URL=$intranetUrl

# Llave de cifrado de las evidencias de tickets: la MISMA de la base local, para que se puedan abrir. No la cambies.
TICKETS_APP_KEY=$(Get-EnvValue 'TICKETS_APP_KEY')

# Código de acceso por correo para soporte, supervisión y superusuario.
TICKETS_CODIGO_ACCESO=1
TICKETS_CORREO_ACTIVO=1
"@
[IO.File]::WriteAllText((Join-Path $out 'intranet.env'), $envServer.Replace("`r`n", "`n"), $utf8)

# ---------- 4. Guía ----------
$guide = Join-Path $PSScriptRoot 'PASOS_SERVIDOR.md'
if (Test-Path $guide) {
    [IO.File]::WriteAllText((Join-Path $out 'PASOS.md'), [IO.File]::ReadAllText($guide, $utf8).Replace('{DOMINIO}', $Dominio.TrimEnd('/')).Replace('{USUARIO}', $UsuarioCpanel), $utf8)
}
Write-Host "Listo: $out"
