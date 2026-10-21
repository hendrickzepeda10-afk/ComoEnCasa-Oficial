<?php
include 'auth.php';
requerir_rol(['admin', 'chef']);
include 'conexion.php';
require_once __DIR__ . '/funciones.php';

$mensaje = '';
$tipo_mensaje = '';
$bebida_alcoholica_habilitada = false;

try {
    $columna_tipo = $pdo->query("SHOW COLUMNS FROM platillos LIKE 'tipo'")->fetch(PDO::FETCH_ASSOC);
    $bebida_alcoholica_habilitada = isset($columna_tipo['Type'])
        && strpos($columna_tipo['Type'], "'bebida_alcoholica'") !== false;
} catch (PDOException $e) {
    error_log('No se pudo comprobar los tipos de producto permitidos: ' . $e->getMessage());
    $mensaje = 'No se pudo comprobar la configuración de categorías del menú.';
    $tipo_mensaje = 'error';
}

// 1. Lógica para procesar la creación, actualización, cambio de estado o eliminación
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        $id_accion = intval($_POST['id']);

        if ($_POST['accion'] == 'eliminar') {
            try {
                $pdo->beginTransaction();
                $stmt_del_receta = $pdo->prepare("DELETE FROM recetas WHERE platillo_id = :id");
                $stmt_del_receta->execute(['id' => $id_accion]);

                $stmt_del = $pdo->prepare("DELETE FROM platillos WHERE id = :id");
                $stmt_del->execute(['id' => $id_accion]);

                $pdo->commit();
                $mensaje = "¡Platillo/Combo eliminado correctamente!";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $mensaje = "Error al eliminar: " . $e->getMessage();
                $tipo_mensaje = "error";
            }
        } elseif ($_POST['accion'] == 'cambiar_estado') {
            $nuevo_estado = $_POST['nuevo_estado'];
            try {
                $stmt_est = $pdo->prepare("UPDATE platillos SET estado = :estado WHERE id = :id");
                $stmt_est->execute(['estado' => $nuevo_estado, 'id' => $id_accion]);
                $mensaje = "¡El estado del platillo ha sido actualizado a " . ($nuevo_estado == 'activo' ? 'Activo' : 'Inhabilitado') . "!";
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                $mensaje = "Error al cambiar estado: " . $e->getMessage();
                $tipo_mensaje = "error";
            }
        }
    } else {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        $precio = trim($_POST['precio']);
        $tipo = trim($_POST['tipo']);
        $es_promocion = isset($_POST['es_promocion']) ? 1 : 0;

        // Procesar la subida de imagen
        $ruta_imagen = null;
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            $extensiones_permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            
            if (in_array($ext, $extensiones_permitidas)) {
                $directorio_imagenes = __DIR__ . DIRECTORY_SEPARATOR . 'img';
                if (!is_dir($directorio_imagenes)) {
                    mkdir($directorio_imagenes, 0775, true);
                }
                $nombre_archivo = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['imagen']['name']));
                $directorio_destino = $directorio_imagenes . DIRECTORY_SEPARATOR . $nombre_archivo;

                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $directorio_destino)) {
                    $ruta_imagen = 'img/' . $nombre_archivo;
                }
            }
        }

        if ($tipo === 'bebida_alcoholica' && !$bebida_alcoholica_habilitada) {
            $mensaje = 'No se guardó el producto: la base de datos aún no admite bebidas alcohólicas. Ejecuta database/migracion_bebidas_alcoholicas.sql en phpMyAdmin y vuelve a intentarlo.';
            $tipo_mensaje = 'error';
        } elseif (!empty($nombre) && $precio !== '' && in_array($tipo, ['platillo', 'combo', 'bebida', 'bebida_alcoholica'], true)) {
            try {
                if ($id > 0) {
                    if ($ruta_imagen) {
                        $stmt = $pdo->prepare("UPDATE platillos SET nombre = :nombre, descripcion = :descripcion, precio = :precio, tipo = :tipo, es_promocion = :es_promocion, imagen = :imagen WHERE id = :id");
                        $stmt->execute([
                            'nombre' => $nombre,
                            'descripcion' => $descripcion,
                            'precio' => $precio,
                            'tipo' => $tipo,
                            'es_promocion' => $es_promocion,
                            'imagen' => $ruta_imagen,
                            'id' => $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE platillos SET nombre = :nombre, descripcion = :descripcion, precio = :precio, tipo = :tipo, es_promocion = :es_promocion WHERE id = :id");
                        $stmt->execute([
                            'nombre' => $nombre,
                            'descripcion' => $descripcion,
                            'precio' => $precio,
                            'tipo' => $tipo,
                            'es_promocion' => $es_promocion,
                            'id' => $id
                        ]);
                    }
                    $mensaje = "¡Modificación guardada exitosamente!";
                } else {
                    $img_final = $ruta_imagen ?? 'img/default.svg';
                    $stmt = $pdo->prepare("INSERT INTO platillos (nombre, descripcion, precio, tipo, estado, imagen, es_promocion) VALUES (:nombre, :descripcion, :precio, :tipo, 'activo', :imagen, :es_promocion)");
                    $stmt->execute([
                        'nombre' => $nombre,
                        'descripcion' => $descripcion,
                        'precio' => $precio,
                        'tipo' => $tipo,
                        'imagen' => $img_final,
                        'es_promocion' => $es_promocion
                    ]);
                    $mensaje = "¡Platillo o combo registrado exitosamente!";
                }
                $tipo_mensaje = "success";
            } catch (PDOException $e) {
                $mensaje = "Error al guardar en la base de datos: " . $e->getMessage();
                $tipo_mensaje = "error";
            }
        } else {
            $mensaje = "Por favor, completa los campos obligatorios.";
            $tipo_mensaje = "error";
        }
    }
}

// 2. Obtener datos si se seleccionó un platillo para editar
$platillo_editar = null;
if (isset($_GET['id'])) {
    $id_edit = intval($_GET['id']);
    try {
        $stmt_e = $pdo->prepare("SELECT * FROM platillos WHERE id = :id");
        $stmt_e->execute(['id' => $id_edit]);
        $platillo_editar = $stmt_e->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mensaje = "Error al cargar el platillo: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// 3. Obtener la lista completa para mostrar la tabla
try {
    $stmt_list = $pdo->query("SELECT * FROM platillos ORDER BY tipo DESC, id DESC");
    $platillos = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $platillos = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Platillos y Combos - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php include 'header.php'; ?>

<div class="main-content wide-page">
    <div class="content-card wide-page-card" style="text-align: left;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 style="font-size: 24px; margin-bottom: 5px;">
                    <?php echo $platillo_editar ? 'Editar Producto' : 'Administración del Menú'; ?>
                </h1>
                <p style="margin: 0; font-size: 14px; color: var(--text-muted);">Agrega o modifica platillos, combos y bebidas con sus precios.</p>
            </div>
        </div>

        <!-- MENSAJE DE ALERTA -->
        <?php if (!empty($mensaje)): ?>
            <div class="alert-message <?php echo $tipo_mensaje; ?>" style="margin-bottom: 20px;">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- FORMULARIO CON SOPORTE PARA ARCHIVOS MULTIPART -->
        <div style="background-color: #fcf8f5; border: 1px solid #ebdcd0; padding: 22px; border-radius: 12px; margin-bottom: 25px;">
            <h3 style="margin-top: 0; color: var(--accent); font-size: 17px; margin-bottom: 15px;">
                <i class="fa-solid fa-utensils"></i> <?php echo $platillo_editar ? 'Modificar Elemento ID #' . $platillo_editar['id'] : 'Agregar Nuevo Platillo o Combo'; ?>
            </h3>

            <?php if (!$bebida_alcoholica_habilitada): ?>
                <div class="alert-message error" style="margin-bottom: 14px;">
                    La categoría de bebidas alcohólicas no está habilitada en la base de datos. Ejecuta
                    <code>database/migracion_bebidas_alcoholicas.sql</code> en phpMyAdmin para poder guardarlas por separado.
                </div>
            <?php endif; ?>

            <form method="POST" action="editar_platillo.php" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px;">
                <input type="hidden" name="id" value="<?php echo $platillo_editar ? $platillo_editar['id'] : 0; ?>">

                <!-- FILA DE CAMPOS BÁSICOS -->
                <div style="display: flex; gap: 18px; flex-wrap: wrap;">
                    <div style="flex: 2; min-width: 250px;">
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Nombre del Platillo o Combo:</label>
                        <input type="text" name="nombre" value="<?php echo $platillo_editar ? htmlspecialchars($platillo_editar['nombre']) : ''; ?>" placeholder="Ej. Pollo con Tajadas..." required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; font-size: 14px; box-sizing: border-box;">
                    </div>

                    <div style="flex: 1; min-width: 160px;">
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Tipo:</label>
                        <select name="tipo" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; background: #fff; font-size: 14px; box-sizing: border-box;">
                            <option value="platillo" <?php echo ($platillo_editar && $platillo_editar['tipo'] == 'platillo') ? 'selected' : ''; ?>>Platillo Individual</option>
                            <option value="combo" <?php echo ($platillo_editar && $platillo_editar['tipo'] == 'combo') ? 'selected' : ''; ?>>Combo / Promoción</option>
                            <option value="bebida" <?php echo ($platillo_editar && $platillo_editar['tipo'] == 'bebida') ? 'selected' : ''; ?>>Bebida</option>
                            <option value="bebida_alcoholica" <?php echo ($platillo_editar && $platillo_editar['tipo'] == 'bebida_alcoholica') ? 'selected' : ''; ?> <?php echo $bebida_alcoholica_habilitada ? '' : 'disabled'; ?>>Bebida alcohólica (21+, solo consumo local)</option>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 130px;">
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Precio (LPS):</label>
                        <input type="number" step="0.01" name="precio" value="<?php echo $platillo_editar ? $platillo_editar['precio'] : ''; ?>" placeholder="0.00" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; font-size: 14px; box-sizing: border-box;">
                    </div>
                </div>

                <label style="display: flex; align-items: center; gap: 9px; padding: 12px 14px; background: #fff8ed; border: 1px solid #f4d6a4; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="es_promocion" value="1" <?php echo ($platillo_editar && !empty($platillo_editar['es_promocion'])) ? 'checked' : ''; ?> style="width: 17px; height: 17px; accent-color: var(--accent);">
                    <span><i class="fa-solid fa-tag" style="color: var(--accent);"></i> Mostrar este producto en Promociones</span>
                </label>

                <!-- CAMPO DE DESCRIPCIÓN Y SUBIDA DE IMAGEN -->
                <div style="display: flex; gap: 18px; flex-wrap: wrap;">
                    <div style="flex: 2; min-width: 250px;">
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Descripción / Ingredientes incluidos:</label>
                        <textarea name="descripcion" rows="3" placeholder="Detalla qué incluye..." style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; font-size: 14px; box-sizing: border-box;"><?php echo $platillo_editar ? htmlspecialchars($platillo_editar['descripcion']) : ''; ?></textarea>
                    </div>

                    <!-- 📸 CAMPO DE IMAGEN -->
                    <div style="flex: 1; min-width: 220px; background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0;">
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 6px;">
                            <i class="fa-solid fa-image" style="color: var(--accent);"></i> Imagen del Platillo:
                        </label>
                        <?php if ($platillo_editar && !empty($platillo_editar['imagen'])): ?>
                            <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 10px;">
                                <img src="<?php echo htmlspecialchars($platillo_editar['imagen']); ?>" alt="Vista previa" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #ccc;">
                                <span style="font-size: 11px; color: var(--text-muted);">Imagen actual</span>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="imagen" accept="image/*" style="font-size: 12px; width: 100%;">
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 5px;">
                    <button type="submit" class="btn-action" style="padding: 10px 20px; border: none; cursor: pointer; font-size: 14px;">
                        <i class="fa-solid fa-floppy-disk"></i> <?php echo $platillo_editar ? 'Actualizar Cambios' : 'Guardar en el Menú'; ?>
                    </button>
                    <?php if ($platillo_editar): ?>
                        <a href="editar_platillo.php" style="background-color: #e5e0db; color: var(--text-main); padding: 10px 18px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center;">Cancelar Edición</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- TABLA DE PLATILLOS Y COMBOS -->
        <h3 style="font-size: 18px; margin-bottom: 10px; color: var(--text-main);">Productos Registrados</h3>
        <div class="table-container">
            <table class="users-table menu-products-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Imagen</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th>Precio</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($platillos) > 0): ?>
                        <?php foreach ($platillos as $p): ?>
                            <?php
                                $estado = isset($p['estado']) ? $p['estado'] : 'activo';
                                $es_inactivo = ($estado == 'inactivo');
                                $img_tabla = imagen_producto_disponible($p['imagen'] ?? '') ? $p['imagen'] : 'img/default.svg';
                            ?>
                            <tr class="<?php echo $es_inactivo ? 'pill-inactivo' : ''; ?>">
                                <td>#<?php echo $p['id']; ?></td>
                                <td>
                                    <img src="<?php echo htmlspecialchars($img_tabla); ?>" alt="<?php echo htmlspecialchars($p['nombre']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;">
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($p['nombre']); ?></strong>
                                    <?php if ($es_inactivo): ?>
                                        <span style="display: block; font-size: 10.5px; color: #c0392b; font-weight: bold;">(AGOTADO / INHABILITADO)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="type-pill <?php echo ($p['tipo'] == 'combo') ? 'pill-combo' : 'pill-platillo'; ?>">
                                        <?php
                                            $etiquetas_tipo = [
                                                'platillo' => 'Platillo individual',
                                                'combo' => 'Combo',
                                                'bebida' => 'Bebida',
                                                'bebida_alcoholica' => 'Bebida alcohólica · 21+'
                                            ];
                                            echo htmlspecialchars($etiquetas_tipo[$p['tipo']] ?? 'Tipo no reconocido');
                                        ?>
                                    </span>
                                    <?php if (!empty($p['es_promocion'])): ?>
                                        <span class="type-pill" style="display: inline-block; margin-top: 4px; background: #fff3d6; color: #9a5b00;">Promoción</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 13px; max-width: 210px;"><?php echo htmlspecialchars($p['descripcion']); ?></td>
                                <td><strong>LPS <?php echo number_format($p['precio'], 2); ?></strong></td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 6px; justify-content: center; align-items: center;">
                                        <a href="editar_platillo.php?id=<?php echo $p['id']; ?>" class="btn-action-table" title="Editar datos básicos">
                                            <i class="fa-solid fa-pen"></i> Editar
                                        </a>
                                        <a href="recetas_platillo.php?id=<?php echo $p['id']; ?>" class="btn-action-table" title="Configurar ingredientes y cantidades de la receta">
                                            <i class="fa-solid fa-list-check"></i> Receta
                                        </a>
                                        <!-- Botón Inhabilitar / Habilitar -->
                                        <form method="POST" action="editar_platillo.php" style="display:inline;">
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                            <?php if ($es_inactivo): ?>
                                                <input type="hidden" name="nuevo_estado" value="activo">
                                                <button type="submit" class="btn-enable-table" title="Habilitar platillo nuevamente">
                                                    <i class="fa-solid fa-check"></i> Habilitar
                                                </button>
                                            <?php else: ?>
                                                <input type="hidden" name="nuevo_estado" value="inactivo">
                                                <button type="submit" class="btn-disable-table" title="Inhabilitar por falta de insumos">
                                                    <i class="fa-solid fa-ban"></i> Inhabilitar
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <!-- Botón Eliminar -->
                                        <form method="POST" action="editar_platillo.php" onsubmit="return confirm('¿Estás seguro de eliminar permanentemente este platillo?');" style="display:inline;">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn-delete-table" title="Eliminar registro">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                No hay platillos o combos registrados todavía.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 25px; border-top: 1px solid #ebdcd0; padding-top: 15px;">
            <a href="dashboard.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">&leftarrow; Volver al inicio</a>
        </div>
    </div>
</div>

</body>
</html>