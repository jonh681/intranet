<?php
require_once __DIR__ . '/php/bootstrap.php';
require_once __DIR__ . '/php/soporte_helpers.php';
header('Location: modulos/' . (isset($_SESSION['user']) ? user_home() : 'login.php'));
exit;
