<?php
declare(strict_types=1);

/*
 * Cotizaciones de la intranet: los representantes médicos cotizan a sus clientes y envían pedidos a Compras.
 * Usa la sesión y la base de la intranet (asistencia/bootstrap.php); quien entra lo decide comun/acceso.php
 * (intranet_rol_cotizaciones): representantes médicos y el superusuario.
 *
 * Páginas por rol:
 *   representante  Cotizador (lista de precios, cotización general y venta mostrador) y Mis pedidos
 *   admin          + Pedidos de todos, Productos, Lista de precios y Clientes (agregar, editar y eliminar)
 *
 * Datos (instalar/migraciones.php, paso 9; carga inicial con instalar/importar_cotizaciones.php):
 *   cot_productos        productos para pedidos, con una columna por lista de precios (COT_LISTAS)
 *   cot_clientes         clientes y su lista de precios
 *   cot_lista_precios    pestaña «Precios»: precio público y con 25% de descuento por marca y línea
 *   cot_pedidos (+ cot_pedido_partidas)  historial: lo que envió cada representante, con los precios del servidor
 */

require_once __DIR__ . '/../asistencia/bootstrap.php';
require_once __DIR__ . '/../comun/barra.php';

// Bootstrap, Tom Select, SweetAlert, jsPDF y SheetJS vienen de CDN (como en la versión anterior del cotizador).
// Bootstrap y SweetAlert ponen estilos en línea: style-src necesita 'unsafe-inline'.
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; "
    . "img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

const IVA = 0.16;

/** Lista de precios del cliente => columna de cot_productos con ese precio. */
const COT_LISTAS = [
    'Lista de precios 15%' => 'precio_15',
    'Lista de precios 20%' => 'precio_20',
    'Lista de precios 25%' => 'precio_25',
    'Lista de precios 30%' => 'precio_30',
    'Lista de precios 33%' => 'precio_33',
    'Lista de precios 35%' => 'precio_35',
    'Lista de precios 40%' => 'precio_40',
    // Cruzadas a propósito: así estaban en el cotizador anterior (PHP/get_precio.php). Revisar con Compras.
    'Dermatologica Angelopolis' => 'medae',
    'MEDAE' => 'dermatologica_angelopolis',
    'Gema Muñoz' => 'gema_munoz',
    'Precio Base' => 'precio_base',
];

/** Listas que son un descuento sobre el precio base (antes, un caso especial para Farmacia Gusher). */
const COT_LISTAS_DESCUENTO = ['Lista de precios 34%' => 0.34];

/** Columnas de precio de cot_productos y su nombre en pantalla. */
const COT_PRECIOS = [
    'precio_base' => 'Precio base (público)', 'precio_15' => 'Lista 15%', 'precio_20' => 'Lista 20%', 'precio_25' => 'Lista 25%',
    'precio_30' => 'Lista 30%', 'precio_33' => 'Lista 33%', 'precio_35' => 'Lista 35%', 'precio_40' => 'Lista 40%',
    'dermatologica_angelopolis' => 'Dermatológica Angelópolis', 'gema_munoz' => 'Gema Muñoz', 'medae' => 'MEDAE',
];

/** Cliente con el que se calculan los precios de la venta de mostrador. */
const COT_CLIENTE_MOSTRADOR = 'FEDELE (VTAS MOSTRADOR)';

/** Clientes con 5% de descuento cuando el total con IVA llega al mínimo (solo en la cotización general). */
const COT_DESCUENTO_VOLUMEN = [
    'clientes' => ['INNOVADORA CARPRI', 'DERMA BALANCE', 'CARLOTA EUGENIA PRECOMA SALGADO'],
    'minimo' => 300000,
    'porcentaje' => 0.05,
];

/** A quién llegan los pedidos (Compras) y la copia oculta de auditoría. */
const COT_COMPRAS = ['name' => 'Samuel Perez', 'email' => 'sperez@fedele.com.mx'];
const COT_COPIA_OCULTA = ['fmartinez@fedele.com.mx'];

/** Datos que pide el formulario de envío, por pestaña y tipo (también los revisa el servidor). */
const COT_REQUERIDOS = [
    'general' => ['cotizacion' => ['correo', 'telefono', 'direccion'], 'pedido' => ['correo', 'comentarios']],
    'mostrador' => ['cotizacion' => ['nombre', 'correo', 'telefono', 'direccion'], 'pedido' => ['nombre', 'correo', 'telefono', 'direccion', 'comentarios']],
];

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

/** Persona con sesión en Cotizaciones: ['id', 'name', 'email', 'rol' => 'representante'|'admin'] o null. */
function cotUser(PDO $db): ?array
{
    $u = intranet_usuario($db);
    $rol = $u ? intranet_rol_cotizaciones($u) : null;
    if ($rol === null) return null;
    return ['id' => (int)$u['id'], 'name' => (string)$u['nombre_usuario'], 'email' => (string)$u['correo_usuario'], 'rol' => $rol];
}

/** Páginas que puede ver un rol: archivo => etiqueta del submenú. */
function cotPages(string $rol): array
{
    if ($rol !== 'admin') return ['index.php' => 'Cotizador', 'pedidos.php' => 'Mis pedidos'];
    return ['index.php' => 'Cotizador', 'pedidos.php' => 'Pedidos', 'productos.php' => 'Productos', 'lista_precios.php' => 'Lista de precios', 'clientes.php' => 'Clientes'];
}

/** Usuario con acceso a la página o corta la petición (sin sesión o sin acceso: a la entrada de la intranet; sin permiso: 403). */
function cotRequire(PDO $db, string $page): array
{
    $user = cotUser($db);
    if (!$user) {
        header('Location: ../', true, 303);
        exit;
    }
    if (!isset(cotPages($user['rol'])[$page])) {
        http_response_code(403);
        exit('Esta sección es solo para el superusuario.');
    }
    return $user;
}

/** Inicio de la página: estilos, encabezado de la intranet y submenú de Cotizaciones. $tomSelect: el cotizador lo usa. */
function cotHeader(PDO $db, array $user, string $page, string $title, bool $tomSelect = false): string
{
    $sections = [];
    foreach (cotPages($user['rol']) as $file => $label) $sections[] = ['href' => $file, 'label' => $label, 'active' => $file === $page];
    $css = 'css/app.css?v=' . (int)@filemtime(__DIR__ . '/css/app.css');
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' | ' . INTRANET_NOMBRE . '</title><link rel="icon" href="img/fedele.png">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
        . ($tomSelect ? '<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">' : '')
        . '<link href="' . h($css) . '" rel="stylesheet"></head>'
        . '<body data-csrf="' . h((string)$_SESSION['csrf']) . '">'
        . intranet_barra($db, 'cotizaciones', '../', $sections);
}

/** Scripts del final de la página: Bootstrap y SweetAlert siempre; $extra son URLs o archivos de js/ (con versión). */
function cotFooter(array $extra = []): string
{
    $out = '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>'
        . '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>';
    foreach ($extra as $src) {
        $out .= '<script src="' . h(str_starts_with($src, 'https://') ? $src : $src . '?v=' . (int)@filemtime(__DIR__ . '/' . $src)) . '"></script>';
    }
    return $out . '</body></html>';
}

/* ---------- Precios ---------- */

/** Nombres de las listas de precios que se pueden asignar a un cliente. */
function cotListas(): array
{
    return array_merge(array_keys(COT_LISTAS), array_keys(COT_LISTAS_DESCUENTO));
}

/**
 * Precio de un producto para un cliente: ['publico' => precio base, 'precio' => precio del cliente, 'origen'].
 * Sin precio en su lista (0), se usa el precio base.
 */
function cotPrecio(array $cliente, array $producto): array
{
    $base = (float)$producto['precio_base'];
    $lista = (string)$cliente['lista_precios'];
    if (isset(COT_LISTAS_DESCUENTO[$lista])) return ['publico' => $base, 'precio' => round($base * (1 - COT_LISTAS_DESCUENTO[$lista]), 2), 'origen' => 'descuento'];
    $precio = (float)($producto[COT_LISTAS[$lista] ?? 'precio_base'] ?? 0);
    return $precio > 0 ? ['publico' => $base, 'precio' => $precio, 'origen' => 'lista'] : ['publico' => $base, 'precio' => $base, 'origen' => 'base'];
}

function cotCliente(PDO $db, int $id): ?array
{
    $query = $db->prepare('SELECT * FROM cot_clientes WHERE id = ?');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function cotClienteMostrador(PDO $db): ?array
{
    $query = $db->prepare('SELECT * FROM cot_clientes WHERE nombre = ? ORDER BY id LIMIT 1');
    $query->execute([COT_CLIENTE_MOSTRADOR]);
    return $query->fetch() ?: null;
}

/**
 * Cotización de esa persona para convertirla en pedido, con sus partidas y 'convertida' (folio del pedido que ya salió
 * de ella y se envió, o null). null si no existe, no es suya o no es una cotización.
 */
function cotCotizacion(PDO $db, int $userId, int $id): ?array
{
    $query = $db->prepare("SELECT * FROM cot_pedidos WHERE id = ? AND usuario_id = ? AND tipo = 'cotizacion'");
    $query->execute([$id, $userId]);
    $cotizacion = $query->fetch();
    if (!$cotizacion) return null;
    $query = $db->prepare('SELECT numero_articulo, cantidad, precio, bonificacion FROM cot_pedido_partidas WHERE pedido_id = ? ORDER BY id');
    $query->execute([$id]);
    $cotizacion['partidas'] = $query->fetchAll();
    $query = $db->prepare("SELECT MIN(id) FROM cot_pedidos WHERE origen_id = ? AND tipo = 'pedido' AND enviado = 1");
    $query->execute([$id]);
    $cotizacion['convertida'] = $query->fetchColumn() ?: null;
    return $cotizacion;
}

/** Totales de un pedido. El 5% por volumen solo aplica en la cotización general y a los clientes de COT_DESCUENTO_VOLUMEN. */
function cotTotales(array $partidas, string $cliente, string $modalidad): array
{
    $suma = array_sum(array_map(fn($p) => $p['precio'] * $p['cantidad'], $partidas));
    $iva = $suma * IVA;
    $descuento = 0.0;
    $nombre = mb_strtolower($cliente);
    if ($modalidad === 'general' && $suma + $iva >= COT_DESCUENTO_VOLUMEN['minimo']) {
        foreach (COT_DESCUENTO_VOLUMEN['clientes'] as $c) {
            if (str_contains($nombre, mb_strtolower($c))) $descuento = ($suma + $iva) * COT_DESCUENTO_VOLUMEN['porcentaje'];
        }
    }
    return ['subtotal' => round($suma, 2), 'iva' => round($iva, 2), 'descuento' => round($descuento, 2), 'total' => round($suma + $iva - $descuento, 2),
        'piezas' => array_sum(array_column($partidas, 'cantidad'))];
}

/* ---------- Administración (solo superusuario) ---------- */

/**
 * Lo que administra el superusuario: tabla, columnas de la lista, campos del formulario y búsqueda.
 * Campo: [etiqueta, tipo (texto|entero|dinero|dinero_opcional|lista), máximo de caracteres, obligatorio, opciones].
 */
function cotEntidades(): array
{
    $precios = [];
    foreach (COT_PRECIOS as $col => $label) $precios[$col] = [$label, 'dinero', 0, $col === 'precio_base'];
    return [
        'productos' => [
            'tabla' => 'cot_productos', 'singular' => 'producto', 'orden' => 'fabricante, descripcion', 'filtro' => ['fabricante', 'Marca'],
            'buscar' => ['numero_articulo', 'descripcion', 'fabricante', 'codigo_barras'], 'unico' => ['numero_articulo', 'Ya existe un producto con ese número de artículo.'],
            'columnas' => ['numero_articulo' => 'SAP', 'descripcion' => 'Descripción', 'fabricante' => 'Marca', 'precio_base' => 'Precio base', 'precio_25' => 'Lista 25%'],
            'campos' => [
                'numero_articulo' => ['Número de artículo (SAP)', 'texto', 20, true],
                'descripcion' => ['Descripción', 'texto', 200, true],
                'fabricante' => ['Marca', 'texto', 100, true],
                'codigo_barras' => ['Código de barras', 'texto', 40, false],
            ] + $precios,
        ],
        'lista' => [
            'tabla' => 'cot_lista_precios', 'singular' => 'producto de la lista de precios', 'orden' => 'marca, orden, id', 'filtro' => ['marca', 'Marca'],
            'buscar' => ['descripcion', 'sap', 'codigo_barras', 'linea'],
            'columnas' => ['marca' => 'Marca', 'linea' => 'Línea', 'descripcion' => 'Descripción', 'sap' => 'SAP', 'publico' => 'Público s/IVA', 'descuento' => '25% dcto.'],
            'campos' => [
                'marca' => ['Marca', 'texto', 60, true],
                'linea' => ['Línea (opcional)', 'texto', 120, false],
                'descripcion' => ['Descripción', 'texto', 200, true],
                'sap' => ['SAP', 'texto', 20, false],
                'codigo_barras' => ['Código de barras', 'texto', 40, false],
                'publico' => ['Público sin IVA', 'dinero_opcional', 0, false],
                'publico_iva' => ['Público con IVA', 'dinero_opcional', 0, false],
                'descuento' => ['25% de descuento sin IVA', 'dinero_opcional', 0, false],
                'descuento_iva' => ['25% de descuento con IVA', 'dinero_opcional', 0, false],
                'orden' => ['Orden dentro de la marca', 'entero', 0, false],
            ],
        ],
        'clientes' => [
            'tabla' => 'cot_clientes', 'singular' => 'cliente', 'orden' => 'nombre', 'filtro' => ['lista_precios', 'Lista de precios'],
            'buscar' => ['nombre', 'codigo'],
            'columnas' => ['codigo' => 'Código', 'nombre' => 'Nombre', 'lista_precios' => 'Lista de precios'],
            'campos' => [
                'codigo' => ['Código de cliente (SAP)', 'entero', 0, false],
                'nombre' => ['Nombre', 'texto', 150, true],
                'lista_precios' => ['Lista de precios', 'lista', 0, true, cotListas()],
            ],
        ],
    ];
}

/** Valores del formulario ya validados para guardar; lanza InvalidArgumentException con el motivo. */
function cotValidar(array $entidad, array $datos): array
{
    $out = [];
    foreach ($entidad['campos'] as $col => [$label, $tipo, $max, $requerido]) {
        $valor = trim((string)($datos[$col] ?? ''));
        if ($valor === '') {
            if ($requerido) throw new InvalidArgumentException("Falta: $label.");
            $out[$col] = match ($tipo) { 'texto' => '', 'dinero' => 0, default => null };
            if ($col === 'orden') $out[$col] = 0;
            continue;
        }
        if ($tipo === 'texto') {
            if (mb_strlen($valor) > $max) throw new InvalidArgumentException("$label: máximo $max caracteres.");
            $out[$col] = $valor;
        } elseif ($tipo === 'entero') {
            if (!preg_match('/^\d{1,9}$/', $valor)) throw new InvalidArgumentException("$label: escribe solo números.");
            $out[$col] = (int)$valor;
        } elseif ($tipo === 'lista') {
            if (!in_array($valor, $entidad['campos'][$col][4], true)) throw new InvalidArgumentException("$label: elige una opción de la lista.");
            $out[$col] = $valor;
        } else {
            $valor = str_replace([',', '$', ' '], '', $valor);
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $valor)) throw new InvalidArgumentException("$label: escribe un precio válido (por ejemplo 1250.50).");
            $out[$col] = round((float)$valor, 2);
        }
    }
    return $out;
}
