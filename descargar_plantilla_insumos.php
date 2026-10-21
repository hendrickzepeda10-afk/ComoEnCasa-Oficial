<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);

$nombre_archivo = 'plantilla_insumos.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Pragma: no-cache');
header('Expires: 0');

$salida = fopen('php://output', 'wb');
if ($salida === false) {
    http_response_code(500);
    exit('No se pudo generar la plantilla de insumos.');
}

fwrite($salida, "\xEF\xBB\xBF");
fputcsv($salida, [
    'Nombre',
    'Unidad base',
    'Unidad de compra',
    'Factor de conversion',
    'Existencia inicial (empaques)',
    'Stock minimo',
], ';', '"', '');
fclose($salida);
