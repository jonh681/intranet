<?php
// Clientes (solo superusuario): nombre, código y la lista de precios con la que se les cotiza.
require __DIR__ . '/lib.php';
$user = cotRequire($db, 'clientes.php');
$entidad = 'clientes';
$pagina = 'clientes.php';
$titulo = 'Clientes';
$descripcion = 'Los clientes que eligen los representantes al cotizar. Su lista de precios define el precio de cada producto.';
include __DIR__ . '/parciales/admin.php';
