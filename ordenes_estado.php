<?php
// Devuelve (en JSON) una huella de las órdenes de cocina para refrescar la pantalla sin recargarla.
include 'auth.php';
requerir_rol(['chef', 'admin'], true);
session_write_close();   // no bloquear la sesión mientras se consulta

include 'conexion.php';
include 'funciones.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    echo json_encode(firma_ordenes_cocina(consultar_ordenes_cocina($pdo)));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db']);
}
