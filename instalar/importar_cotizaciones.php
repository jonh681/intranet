<?php
declare(strict_types=1);

/*
 * Carga inicial de Cotizaciones en la base de la intranet, desde los datos del cotizador anterior (instalar/datos/):
 *   articulos.sql      -> cot_productos      (productos para pedidos y sus listas de precios)
 *   clientes.sql       -> cot_clientes       (sin los clientes repetidos)
 *   lista_precios.php  -> cot_lista_precios  (pestaña «Precios»)
 * Primero aplica las migraciones. Solo llena las tablas que están vacías, así que se puede ejecutar las veces que sea;
 * con --reemplazar borra lo que haya y vuelve a cargar (se pierden los cambios hechos en la intranet).
 *
 *   C:\xampp\php\php.exe instalar\importar_cotizaciones.php [--reemplazar]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/migraciones.php';
require_once __DIR__ . '/../tickets/php/env.php';

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('TICKETS_DB_HOST', 'localhost'), env('TICKETS_DB_PORT', '3306'), env('TICKETS_DB_NAME', 'intranet')),
    env('TICKETS_DB_USER', 'root'), env('TICKETS_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$reemplazar = in_array('--reemplazar', $argv, true);
foreach (intranet_migrar($db) as $cambio) echo "Migración: $cambio\n";

/** Carga un volcado de phpMyAdmin en una tabla temporal (cot_tmp_<tabla>) y devuelve su nombre. */
$cargarVolcado = function (string $archivo, string $tabla) use ($db): string {
    $tmp = 'cot_tmp_' . $tabla;
    $db->exec("DROP TABLE IF EXISTS $tmp");
    $sql = str_replace("`$tabla`", "`$tmp`", (string)file_get_contents($archivo));
    foreach (preg_split('/;\s*\R/', $sql) as $sentencia) {
        // Solo la tabla y sus datos: sin los SET, START TRANSACTION ni comentarios del volcado.
        $sentencia = trim(preg_replace('/^\s*(--.*)?$/m', '', $sentencia));
        if (preg_match('/^(CREATE TABLE|INSERT INTO|ALTER TABLE)\b/i', $sentencia)) $db->exec($sentencia);
    }
    return $tmp;
};
$vacia = fn(string $tabla) => (int)$db->query("SELECT COUNT(*) FROM $tabla")->fetchColumn() === 0;
$preparar = function (string $tabla) use ($db, $reemplazar, $vacia): bool {
    if ($reemplazar) $db->exec("DELETE FROM $tabla");
    if ($vacia($tabla)) return true;
    echo "$tabla ya tiene datos: no se tocó (usa --reemplazar para volver a cargarla).\n";
    return false;
};

// Productos
if ($preparar('cot_productos')) {
    $tmp = $cargarVolcado(__DIR__ . '/datos/articulos.sql', 'articulos');
    $n = $db->exec("INSERT INTO cot_productos (numero_articulo, descripcion, fabricante, codigo_barras, precio_base, precio_15, precio_20, precio_25,
            precio_30, precio_33, precio_35, precio_40, dermatologica_angelopolis, gema_munoz, medae, actualizado_en)
        SELECT TRIM(Numero_Articulo), TRIM(REGEXP_REPLACE(Descripcion, ' {2,}', ' ')), TRIM(Fabricante), TRIM(Codigo_Barras), Precio_Base, Precios_15, Precios_20,
            Precio_25, Precio_30, Precios_33, Precio_35, Precios_40, Dermatologica_Angelopolis, Gema_Munoz, Medae, NOW()
        FROM $tmp ORDER BY ID");
    $db->exec("DROP TABLE $tmp");
    echo "cot_productos: $n productos.\n";
}

// Clientes (el volcado trae algunos repetidos: código, nombre y lista iguales)
if ($preparar('cot_clientes')) {
    $tmp = $cargarVolcado(__DIR__ . '/datos/clientes.sql', 'clientes');
    $n = $db->exec("INSERT INTO cot_clientes (codigo, nombre, lista_precios, actualizado_en)
        SELECT Codigo_Cliente, TRIM(Nombre_Cliente), TRIM(Lista_Precios), NOW() FROM $tmp
        GROUP BY Codigo_Cliente, TRIM(Nombre_Cliente), TRIM(Lista_Precios) ORDER BY MIN(ID)");
    $total = (int)$db->query("SELECT COUNT(*) FROM $tmp")->fetchColumn();
    $db->exec("DROP TABLE $tmp");
    echo "cot_clientes: $n clientes (" . ($total - $n) . " repetidos sin cargar).\n";
}

// Lista de precios
if ($preparar('cot_lista_precios')) {
    $catalogo = require __DIR__ . '/datos/lista_precios.php';
    $insert = $db->prepare('INSERT INTO cot_lista_precios (marca, linea, sap, codigo_barras, descripcion, publico, publico_iva, descuento, descuento_iva, orden, actualizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $n = 0;
    foreach ($catalogo as $marca) {
        $orden = 0;
        foreach ($marca['grupos'] as $grupo) {
            foreach ($grupo['productos'] as [$barras, $sap, $desc, $pubSin, $pubCon, $dctoSin, $dctoCon]) {
                $insert->execute([$marca['nombre'], (string)($grupo['titulo'] ?? ''), $sap, $barras, $desc, $pubSin, $pubCon, $dctoSin, $dctoCon, $orden += 10]);
                $n++;
            }
        }
    }
    echo "cot_lista_precios: $n productos.\n";
}
