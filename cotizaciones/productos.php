<?php
// Productos para generar pedidos (solo superusuario): marca, SAP y el precio de cada lista de precios.
require __DIR__ . '/lib.php';
$user = cotRequire($db, 'productos.php');
$entidad = 'productos';
$pagina = 'productos.php';
$titulo = 'Productos';
$descripcion = 'Los productos que los representantes agregan a sus pedidos y cotizaciones, con el precio de cada lista.';
include __DIR__ . '/parciales/admin.php';
