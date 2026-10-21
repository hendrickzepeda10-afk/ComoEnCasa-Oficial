<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';
require_once __DIR__ . '/personal_lib.php';

try {
    $stmt = $pdo->query("SELECT id, usuario, rol, nombre_completo, fecha_nacimiento, dni, telefono, correo_electronico, domicilio, contacto_emergencia_nombre, contacto_emergencia_telefono, turno, documento_cv, documento_identidad, documento_estudios, documento_antecedentes FROM usuarios ORDER BY nombre_completo, usuario");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudieron cargar los expedientes del personal: ' . $e->getMessage());
    http_response_code(503);
    $usuarios = [];
    $error = 'No se pudieron cargar los expedientes. Verifica que se haya ejecutado database/migracion_expedientes_personal.sql.';
}

$etiquetas_rol = [
    'admin' => 'Administrador',
    'gerente_general' => 'Gerente General',
    'cajero' => 'Cajero',
    'chef' => 'Chef',
    'limpieza' => 'Limpieza',
    'mesero' => 'Mesero',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expedientes del Personal - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .personnel-records { display: grid; gap: 18px; }
        .personnel-record { background: #fff; border: 1px solid #ebdcd0; border-radius: 14px; padding: 22px; }
        .personnel-record h2 { margin: 0 0 14px; font-size: 1.35rem; }
        .personnel-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px 20px; }
        .personnel-details p { margin: 0; overflow-wrap: anywhere; }
        .personnel-documents { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .personnel-documents a, .personnel-documents span { border-radius: 8px; padding: 9px 12px; background: #f8f2ec; color: #593b2a; }
        .personnel-documents a { text-decoration: none; font-weight: 600; }
        .personnel-documents a:hover { background: #f0e2d4; }
        @media (max-width: 600px) { .personnel-record { padding: 16px; } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <section class="content-card">
            <h1>Expedientes del Personal</h1>
            <p>Información y documentos confidenciales. El acceso está limitado a administración y gerencia.</p>

            <?php if (isset($error)): ?>
                <div class="alert-message error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php elseif (!$usuarios): ?>
                <p>No hay cuentas de personal registradas.</p>
            <?php else: ?>
                <div class="personnel-records">
                    <?php foreach ($usuarios as $usuario): ?>
                        <article class="personnel-record">
                            <h2><?php echo htmlspecialchars($usuario['nombre_completo'] ?: $usuario['usuario'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <div class="personnel-details">
                                <p><strong>Usuario:</strong> <?php echo htmlspecialchars($usuario['usuario'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Rol:</strong> <?php echo htmlspecialchars($etiquetas_rol[$usuario['rol']] ?? $usuario['rol'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Fecha de nacimiento:</strong> <?php echo htmlspecialchars($usuario['fecha_nacimiento'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Identidad:</strong> <?php echo htmlspecialchars($usuario['dni'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($usuario['telefono'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Correo:</strong> <?php echo htmlspecialchars($usuario['correo_electronico'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Domicilio:</strong> <?php echo htmlspecialchars($usuario['domicilio'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Turno:</strong> <?php echo htmlspecialchars($usuario['turno'] ? ucfirst($usuario['turno']) : 'Sin asignar', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Emergencia:</strong> <?php echo htmlspecialchars($usuario['contacto_emergencia_nombre'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($usuario['contacto_emergencia_telefono'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="personnel-documents" aria-label="Documentos de <?php echo htmlspecialchars($usuario['usuario'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php foreach (documentos_personal_definicion() as $columna => $documento): ?>
                                    <?php if (!empty($usuario[$columna])): ?>
                                        <a href="descargar_documento_personal.php?id=<?php echo intval($usuario['id']); ?>&tipo=<?php echo urlencode($columna); ?>">
                                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>: pendiente</span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
