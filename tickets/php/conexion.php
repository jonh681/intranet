<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function getConexion(): PDO
{
    // Los datos de acceso viven en tickets.env / .env (ver php/env.php y .env.example).
    $host = env('TICKETS_DB_HOST', 'localhost');
    $dbname = env('TICKETS_DB_NAME', 'tickets');
    $username = env('TICKETS_DB_USER', 'root');
    $password = env('TICKETS_DB_PASSWORD');
    $port = env('TICKETS_DB_PORT', '3306');
    return new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
