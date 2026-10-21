<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';
require_once __DIR__ . '/personal_lib.php';

$id = is_scalar($_GET['id'] ?? null) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$tipo = is_string($_GET['tipo'] ?? null) ? $_GET['tipo'] : '';
$definicion = documentos_personal_definicion();
if ($id < 1 || !isset($definicion[$tipo])) {
    http_response_code(400);
    exit('Solicitud de documento no válida.');
}

try {
    $columna = $tipo;
    $stmt = $pdo->prepare("SELECT usuario, {$columna} AS archivo FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudo consultar un documento del personal: ' . $e->getMessage());
    http_response_code(503);
    exit('No se pudo consultar el documento.');
}

$nombre_guardado = $documento['archivo'] ?? '';
if (!is_string($nombre_guardado) || !preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|png)$/', $nombre_guardado)) {
    http_response_code(404);
    exit('El documento no está disponible.');
}

$ruta = ruta_documentos_personal() . DIRECTORY_SEPARATOR . $nombre_guardado;
if (!is_file($ruta) || !is_readable($ruta)) {
    http_response_code(404);
    exit('El documento no está disponible.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($ruta);
$tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $tipos_permitidos, true)) {
    error_log('Tipo MIME no permitido al descargar documento del personal.');
    http_response_code(415);
    exit('El formato del documento no está permitido.');
}

$nombre_descarga = 'personal-' . $id . '-' . preg_replace('/[^a-z0-9]+/i', '-', $tipo) . '.' . pathinfo($nombre_guardado, PATHINFO_EXTENSION);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($ruta);
exit();
