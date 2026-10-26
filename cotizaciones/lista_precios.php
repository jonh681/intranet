<?php
// Lista de precios (solo superusuario): lo que ven los representantes en la pestaña «Precios» del cotizador.
require __DIR__ . '/lib.php';
$user = cotRequire($db, 'lista_precios.php');
$entidad = 'lista';
$pagina = 'lista_precios.php';
$titulo = 'Lista de precios';
$descripcion = 'Precio público y con 25% de descuento por marca y línea. Es lo que ven los representantes en la pestaña «Precios».';
include __DIR__ . '/parciales/admin.php';
