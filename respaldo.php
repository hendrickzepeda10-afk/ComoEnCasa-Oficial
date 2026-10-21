<?php
include 'auth.php';
requerir_rol(['admin']);
include 'conexion.php';
include 'respaldo_lib.php';

// Descarga de un respaldo existente
if (isset($_GET['descargar'])) {
    $archivo = basename($_GET['descargar']);
    $ruta = RESPALDO_DIR . '/' . $archivo;
    if (preg_match('/^respaldo_\d{8}_\d{6}\.sql$/', $archivo) && is_file($ruta)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit();
    }
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $nombre = crear_respaldo($pdo);
        $mensaje = "Respaldo creado: $nombre";
        $tipo_mensaje = "success";
    } catch (Throwable $e) {
        $mensaje = "No se pudo crear el respaldo. Revisa que la carpeta 'respaldos' tenga permisos de escritura.";
        $tipo_mensaje = "error";
    }
}

$archivos = glob(RESPALDO_DIR . '/respaldo_*.sql') ?: [];
rsort($archivos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respaldo de Datos - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table-container { width: 100%; overflow-x: auto; margin-top: 15px; }
        .users-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        .users-table th, .users-table td { padding: 12px 14px; border-bottom: 1px solid #ebdcd0; }
        .users-table th { background-color: #fcf8f5; font-weight: 700; }
    </style>
</head>
<body>

    <?php include 'header.php'; ?>

    <div class="main-content">
        <div class="content-card" style="max-width: 800px; text-align: left;">
            <h1 style="font-size: 24px; margin-bottom: 5px;">Respaldo de Datos</h1>
            <p style="margin: 0 0 15px 0; font-size: 14px; color: var(--text-muted);">
                El sistema crea un respaldo automático una vez al día, cuando el administrador o el gerente inician sesión, y guarda los últimos <?php echo RESPALDO_MAX_ARCHIVOS; ?>.
            </p>

            <?php if (!empty($mensaje)): ?>
                <div class="alert-message <?php echo $tipo_mensaje; ?>"><?php echo htmlspecialchars($mensaje); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <button type="submit" class="btn-action" style="border: none; cursor: pointer; padding: 12px 22px;">
                    <i class="fa-solid fa-database"></i> Crear respaldo ahora
                </button>
            </form>

            <div class="table-container">
                <table class="users-table">
                    <thead>
                        <tr><th>Archivo</th><th>Fecha</th><th>Tamaño</th><th>Descargar</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($archivos) > 0): ?>
                            <?php foreach ($archivos as $ruta): ?>
                                <?php $nombre = basename($ruta); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($nombre); ?></td>
                                    <td><?php echo date('d/m/Y H:i', filemtime($ruta)); ?></td>
                                    <td><?php echo number_format(filesize($ruta) / 1024, 1); ?> KB</td>
                                    <td><a href="respaldo.php?descargar=<?php echo urlencode($nombre); ?>" style="color: var(--accent); font-weight: 600; text-decoration: none;"><i class="fa-solid fa-download"></i> Descargar</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 25px;">Todavía no hay respaldos.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; background: #fcf8f5; border: 1px solid #ebdcd0; border-radius: 10px; padding: 14px; font-size: 13px; line-height: 1.6;">
                <strong>Importante:</strong>
                <ul style="margin: 6px 0 0 18px; padding: 0;">
                    <li>Copia los respaldos a una memoria USB o a la nube con regularidad: si falla el disco de esta computadora, también se pierden.</li>
                    <li>Para restaurar: en phpMyAdmin elige la base <em>comoencasa_db</em> &rarr; Importar &rarr; selecciona el archivo .sql.</li>
                    <li>Los respaldos contienen información del negocio y los usuarios. No los compartas.</li>
                </ul>
            </div>

            <div style="margin-top: 25px; border-top: 1px solid #ebdcd0; padding-top: 15px;">
                <a href="dashboard.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">← Volver al inicio</a>
            </div>
        </div>
    </div>

</body>
</html>
