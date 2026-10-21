<?php
/**
 * Conexión única a MySQL. Todas las páginas la incluyen para compartir
 * la misma configuración de errores, codificación y zona horaria.
 */

date_default_timezone_set('America/Tegucigalpa');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'comoencasa_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD');
$password = $password === false ? '' : $password;

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Consultas preparadas reales: evita que el servidor reciba
            // los valores interpolados en el texto de la consulta.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '-06:00'");
} catch (PDOException $e) {
    error_log('No se pudo establecer conexión con la base de datos: ' . $e->getMessage());
    http_response_code(503);
    die("El sistema no puede conectarse en este momento. Inténtalo de nuevo más tarde.");
}
