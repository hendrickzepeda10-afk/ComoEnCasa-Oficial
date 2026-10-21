<?php
include 'auth.php';
requerir_rol(['admin', 'chef', 'gerente_general']);
include 'conexion.php';

$mensaje = '';
$tipo_mensaje = '';

// Obtener el ID del platillo
$platillo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($platillo_id <= 0) {
    header("Location: editar_platillo.php");
    exit();
}

// 1. Obtener información del platillo
try {
    $stmt_p = $pdo->prepare("SELECT * FROM platillos WHERE id = :id");
    $stmt_p->execute(['id' => $platillo_id]);
    $platillo = $stmt_p->fetch(PDO::FETCH_ASSOC);

    if (!$platillo) {
        header("Location: editar_platillo.php");
        exit();
    }
} catch (PDOException $e) {
    header("Location: editar_platillo.php");
    exit();
}

// 2. Procesar la actualización con validación estricta de checkbox
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_receta'])) {
    $activos = isset($_POST['activo']) ? $_POST['activo'] : [];
    $cantidades = isset($_POST['cantidades']) ? $_POST['cantidades'] : [];

    $error_validacion = !csrf_peticion_valida() || !is_array($activos) || !is_array($cantidades);

    if (!$error_validacion) {
        foreach ($activos as $insumo_id => $val) {
            $cant = $cantidades[$insumo_id] ?? '';
            if (
                !ctype_digit((string) $insumo_id)
                || !is_scalar($cant)
                || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', trim((string) $cant))
                || (float) $cant <= 0
            ) {
                $error_validacion = true;
                break;
            }
        }
    }

    if ($error_validacion) {
        if (!csrf_peticion_valida()) {
            http_response_code(403);
        }
        $mensaje = "Error: la solicitud o alguna cantidad no es válida. Actualiza la página y revisa las cantidades.";
        $tipo_mensaje = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt_insumos = $pdo->query("SELECT id FROM insumos");
            $todos_los_ids = $stmt_insumos->fetchAll(PDO::FETCH_COLUMN);

            foreach ($todos_los_ids as $insumo_id) {
                $esta_marcado = isset($activos[$insumo_id]);
                $cantidad_ingresada = $cantidades[$insumo_id] ?? '';
                $cantidad_necesaria = is_scalar($cantidad_ingresada) ? trim((string) $cantidad_ingresada) : '';

                $stmt_check = $pdo->prepare("SELECT id FROM recetas WHERE platillo_id = :pid AND insumo_id = :iid");
                $stmt_check->execute(['pid' => $platillo_id, 'iid' => $insumo_id]);
                $receta_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);

                // Solo se guarda si está marcado Y tiene cantidad válida
                if ($esta_marcado && $cantidad_necesaria !== '' && floatval($cantidad_necesaria) > 0) {
                    if ($receta_existente) {
                        $stmt_upd = $pdo->prepare("UPDATE recetas SET cantidad_necesaria = :cant WHERE id = :rid");
                        $stmt_upd->execute(['cant' => $cantidad_necesaria, 'rid' => $receta_existente['id']]);
                    } else {
                        $stmt_ins = $pdo->prepare("INSERT INTO recetas (platillo_id, insumo_id, cantidad_necesaria) VALUES (:pid, :iid, :cant)");
                        $stmt_ins->execute(['pid' => $platillo_id, 'iid' => $insumo_id, 'cant' => $cantidad_necesaria]);
                    }
                } else {
                    if ($receta_existente) {
                        $stmt_del = $pdo->prepare("DELETE FROM recetas WHERE id = :rid");
                        $stmt_del->execute(['rid' => $receta_existente['id']]);
                    }
                }
            }

            $pdo->commit();
            $mensaje = "¡Receta actualizada de forma segura!";
            $tipo_mensaje = "success";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('No se pudo actualizar la receta del platillo: ' . $e->getMessage());
            $mensaje = "No se pudo guardar la receta. Inténtalo de nuevo.";
            $tipo_mensaje = "error";
        }
    }
}

// 3. Obtener los insumos actuales de la receta
try {
    $stmt_recetas = $pdo->prepare("SELECT insumo_id, cantidad_necesaria FROM recetas WHERE platillo_id = :pid");
    $stmt_recetas->execute(['pid' => $platillo_id]);
    $recetas_actuales = [];
    while ($row = $stmt_recetas->fetch(PDO::FETCH_ASSOC)) {
        $recetas_actuales[$row['insumo_id']] = $row['cantidad_necesaria'];
    }
} catch (PDOException $e) {
    $recetas_actuales = [];
}

// 4. Obtener todos los insumos disponibles en el inventario
try {
    $stmt_insumos = $pdo->query("SELECT * FROM insumos ORDER BY nombre ASC");
    $todos_insumos = $stmt_insumos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $todos_insumos = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Receta - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
      
   
    <script>
        function toggleInput(checkbox, id) {
            const input = document.getElementById('cant_' + id);
            if (checkbox.checked) {
                input.disabled = false;
                input.focus();
                if(input.value === '' || input.value === '0' || input.value === '0.00') {
                    input.value = '';
                }
            } else {
                input.disabled = true;
                input.value = ''; // Se limpia si se desmarca para evitar errores
            }
        }
    </script>
</head>
<body>

    <?php include 'header.php'; ?>

    <div class="main-content wide-page recipe-page">
        
        <div class="content-card recipe-card" style="text-align: left;">
            
            <header class="recipe-header">
                <div class="recipe-title">
                    <span class="recipe-type">
                        <?php
                            $etiquetas_tipo_receta = [
                                'platillo' => 'Platillo individual',
                                'combo' => 'Combo',
                                'bebida' => 'Bebida',
                                'bebida_alcoholica' => 'Bebida alcohólica · 21+',
                            ];
                            echo htmlspecialchars($etiquetas_tipo_receta[$platillo['tipo']] ?? 'Producto', ENT_QUOTES, 'UTF-8');
                        ?>
                    </span>
                    <h1>Receta para: <?php echo htmlspecialchars($platillo['nombre']); ?></h1>
                    <?php if (trim((string) $platillo['descripcion']) !== ''): ?>
                        <p><?php echo htmlspecialchars($platillo['descripcion']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="recipe-price">
                    <span>Precio</span>
                    <strong>LPS <?php echo number_format($platillo['precio'], 2); ?></strong>
                </div>
            </header>

            <div class="recipe-top-actions">
                <a href="editar_platillo.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al Menú de Platillos</a>
            </div>

            <?php if (!empty($mensaje)): ?>
                <div class="alert-message <?php echo $tipo_mensaje; ?>">
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php csrf_campo(); ?>
                <input type="hidden" name="guardar_receta" value="1">

                <div class="recipe-toolbar">
                    <h2>La cantidad de la receta se mide en la unidad base indicada para cada insumo</h2>
                    <button type="submit" class="btn-action">
                        <i class="fa-solid fa-floppy-disk"></i> Guardar Receta Segura
                    </button>
                </div>

                <?php if (count($todos_insumos) > 0): ?>
                    <div class="recipe-search" role="search">
                        <label for="buscar-insumo-receta"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar insumo</label>
                        <input id="buscar-insumo-receta" type="search" placeholder="Escribe el nombre del insumo..." autocomplete="off" aria-controls="tabla-insumos-receta">
                        <span id="resultado-busqueda-receta" class="recipe-search-count" aria-live="polite"><?php echo count($todos_insumos); ?> insumos</span>
                    </div>
                <?php endif; ?>

                <!-- TABLA DE INSUMOS CON CONTROL DE CHECKBOX -->
                <div class="table-container recipe-table-wrap">
                    <table class="users-table recipe-table" id="tabla-insumos-receta">
                        <thead>
                            <tr>
                                <th class="recipe-include-heading">Incluir</th>
                                <th>Insumo en Inventario</th>
                                <th>Unidad</th>
                                <th>Stock Actual en Almacén</th>
                                <th>Cantidad consumida por platillo (unidad base)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($todos_insumos) > 0): ?>
                                <?php foreach ($todos_insumos as $ins): ?>
                                    <?php 
                                        $ins_id = $ins['id'];
                                        $esta_en_receta = array_key_exists($ins_id, $recetas_actuales);
                                        $cant_guardada = $esta_en_receta ? $recetas_actuales[$ins_id] : '';
                                    ?>
                                    <tr data-insumo-row data-insumo-nombre="<?php echo htmlspecialchars($ins['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <td class="recipe-include-cell">
                                            <input type="checkbox" 
                                                   name="activo[<?php echo $ins_id; ?>]" 
                                                   value="1" 
                                                   class="checkbox-receta"
                                                   id="chk_<?php echo $ins_id; ?>"
                                                   onchange="toggleInput(this, <?php echo $ins_id; ?>)"
                                                   <?php echo $esta_en_receta ? 'checked' : ''; ?>>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($ins['nombre']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($ins['unidad']); ?></td>
                                        <td class="recipe-stock">
                                            <span style="font-weight: 600; <?php echo ($ins['cantidad'] <= 0) ? 'color: #c0392b;' : 'color: #27ae60;'; ?>">
                                                <?php echo number_format($ins['cantidad'], 2); ?>
                                            </span>
                                        </td>
                                        <td class="recipe-quantity-cell">
                                            <div class="recipe-quantity-control">
                                                <input type="number"
                                                       step="0.01"
                                                       min="0.01"
                                                       name="cantidades[<?php echo $ins_id; ?>]"
                                                       id="cant_<?php echo $ins_id; ?>"
                                                       value="<?php echo $cant_guardada; ?>"
                                                       placeholder="0.00"
                                                       class="input-cantidad"
                                                       <?php echo $esta_en_receta ? '' : 'disabled'; ?>>
                                                <small><?php echo htmlspecialchars($ins['unidad'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="sin-resultados-insumo" hidden>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 25px;">
                                        No se encontraron insumos con ese nombre.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 25px;">
                                        No hay insumos registrados en el inventario todavía. <a href="agregar_insumo.php">Agrega algunos aquí</a>.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                
            </form>

        </div>

    </div>

    <script>
        (() => {
            const search = document.getElementById('buscar-insumo-receta');
            const rows = Array.from(document.querySelectorAll('[data-insumo-row]'));
            const noResults = document.getElementById('sin-resultados-insumo');
            const count = document.getElementById('resultado-busqueda-receta');
            if (!search || rows.length === 0 || !noResults || !count) return;

            const normalizar = value => value
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLocaleLowerCase('es');

            const tbody = rows[0].parentElement;
            const ordenOriginal = new Map(rows.map((row, index) => [row, index]));
            const mostrarSeleccionadosPrimero = () => {
                rows.sort((a, b) => {
                    const seleccionadoA = a.querySelector('.checkbox-receta').checked;
                    const seleccionadoB = b.querySelector('.checkbox-receta').checked;
                    return Number(seleccionadoB) - Number(seleccionadoA)
                        || ordenOriginal.get(a) - ordenOriginal.get(b);
                }).forEach(row => tbody.insertBefore(row, noResults));
            };

            mostrarSeleccionadosPrimero();
            rows.forEach(row => {
                row.querySelector('.checkbox-receta').addEventListener('change', mostrarSeleccionadosPrimero);
            });

            search.addEventListener('input', () => {
                const termino = normalizar(search.value.trim());
                let visibles = 0;

                rows.forEach(row => {
                    const coincide = normalizar(row.dataset.insumoNombre || '').includes(termino);
                    row.hidden = !coincide;
                    if (coincide) visibles++;
                });

                noResults.hidden = visibles !== 0;
                count.textContent = visibles + (visibles === 1 ? ' insumo' : ' insumos');
            });
        })();
    </script>

</body>
</html>