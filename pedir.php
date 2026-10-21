<?php
include 'auth.php';
if (isset($_SESSION['user_id'], $_SESSION['rol']) && rol_actual() !== 'admin') {
    header('Location: dashboard.php');
    exit();
}
include 'conexion.php';
include 'funciones.php';

$token_dispositivo = token_dispositivo(); // cookie del dispositivo (para recuperar el pedido)
$usuario_id = isset($_SESSION['user_id'], $_SESSION['rol']) ? intval($_SESSION['user_id']) : null;

$mensaje = '';
$tipo_mensaje = '';
$ticket_generado = null;
$nombre_cliente_registrado = '';
$numero_mesa_ticket = null;
$tipo_servicio_ticket = null;
$direccion_entrega_ticket = '';
$telefono_cliente_ticket = '';
$categorias_menu = ['platillo', 'combo', 'bebida', 'bebida_alcoholica', 'promociones'];

// Inicializar el carrito en sesión si no existe
if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

// Recuperar mensajes temporales de la sesión (patrón PRG)
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}

if (isset($_GET['sesion_cerrada']) && $_GET['sesion_cerrada'] === '1') {
    $mensaje = 'Sesión cerrada. Puedes hacer un pedido sin cuenta o iniciar sesión como personal.';
    $tipo_mensaje = 'success';
}

if (isset($_SESSION['ticket_generado'])) {
    $ticket_generado = $_SESSION['ticket_generado'];
    $nombre_cliente_registrado = $_SESSION['nombre_cliente_registrado'];
    $numero_mesa_ticket = $_SESSION['numero_mesa_ticket'] ?? null;
    $tipo_servicio_ticket = $_SESSION['tipo_servicio_ticket'] ?? null;
    $direccion_entrega_ticket = $_SESSION['direccion_entrega_ticket'] ?? '';
    $telefono_cliente_ticket = $_SESSION['telefono_cliente_ticket'] ?? '';
    unset(
        $_SESSION['ticket_generado'],
        $_SESSION['nombre_cliente_registrado'],
        $_SESSION['numero_mesa_ticket'],
        $_SESSION['tipo_servicio_ticket'],
        $_SESSION['direccion_entrega_ticket'],
        $_SESSION['telefono_cliente_ticket']
    );
}

if (
    isset($_GET['servicio'])
    && is_string($_GET['servicio'])
    && $_GET['servicio'] === 'domicilio'
) {
    $_SESSION['tipo_servicio_seleccionado'] = 'domicilio';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['accion'] ?? '') === 'seleccionar_servicio'
) {
    $servicio_seleccionado = $_POST['tipo_servicio'] ?? '';
    if (
        !is_string($servicio_seleccionado)
        || !in_array($servicio_seleccionado, ['domicilio', 'para_llevar', 'comer_aqui'], true)
    ) {
        $_SESSION['mensaje'] = 'Selecciona cómo deseas recibir tu pedido.';
        $_SESSION['tipo_mensaje'] = 'error';
        header('Location: pedir.php');
        exit();
    }

    $_SESSION['tipo_servicio_seleccionado'] = $servicio_seleccionado;
    if ($servicio_seleccionado === 'domicilio') {
        header('Location: domicilio.php');
    } else {
        header('Location: pedir.php');
    }
    exit();
}

// Porciones disponibles por platillo según el stock (solo platillos con receta)
try {
    $disponibles = porciones_disponibles($pdo);
} catch (PDOException $e) {
    $disponibles = [];
}

// Busca un platillo ACTIVO en la base de datos (el precio y el nombre nunca vienen del navegador)
function buscar_platillo_activo($pdo, $id) {
    $stmt = $pdo->prepare("SELECT id, nombre, precio, estado, descripcion, imagen, tipo FROM platillos WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p || (isset($p['estado']) && $p['estado'] === 'inactivo')) {
        return null;
    }
    return $p;
}

function fecha_limite_edad($edad_minima) {
    $zona_honduras = new DateTimeZone('America/Tegucigalpa');
    return (new DateTimeImmutable('today', $zona_honduras))->modify('-' . intval($edad_minima) . ' years');
}

function cumple_edad_minima($fecha_nacimiento, $edad_minima) {
    if (!is_string($fecha_nacimiento) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_nacimiento)) {
        return false;
    }

    $zona_honduras = new DateTimeZone('America/Tegucigalpa');
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha_nacimiento, $zona_honduras);
    $errores = DateTimeImmutable::getLastErrors();
    if (
        !$fecha
        || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))
        || $fecha->format('Y-m-d') !== $fecha_nacimiento
    ) {
        return false;
    }

    return $fecha <= fecha_limite_edad($edad_minima);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {

    // 1. Agregar producto al carrito
    if ($_POST['accion'] == 'agregar') {
        $id_producto = intval($_POST['id_producto'] ?? 0);
        $cantidad = max(1, min(10, intval($_POST['cantidad'] ?? 1)));
        try {
            $platillo = buscar_platillo_activo($pdo, $id_producto);
        } catch (PDOException $e) {
            $platillo = null;
        }

        if (!$platillo) {
            $_SESSION['mensaje'] = "Ese platillo no está disponible en este momento.";
            $_SESSION['tipo_mensaje'] = "error";
        } elseif (
            ($platillo['tipo'] ?? '') === 'bebida_alcoholica'
            && ($_SESSION['tipo_servicio_seleccionado'] ?? '') !== 'comer_aqui'
        ) {
            $_SESSION['mensaje'] = 'Las bebidas alcohólicas solo se pueden pedir para consumir dentro del restaurante.';
            $_SESSION['tipo_mensaje'] = 'error';
        } else {
            $es_bebida = in_array(
                strtolower(trim((string) ($platillo['tipo'] ?? 'platillo'))),
                ['bebida', 'bebida_alcoholica'],
                true
            );
            $especificaciones_ingresadas = $es_bebida ? '' : ($_POST['especificaciones'] ?? '');
            $especificaciones = is_scalar($especificaciones_ingresadas)
                ? mb_substr(trim((string) $especificaciones_ingresadas), 0, 250, 'UTF-8')
                : '';

            $en_carrito = 0;
            foreach ($_SESSION['carrito'] as $item) {
                if ($item['id'] == $id_producto) {
                    $en_carrito += intval($item['cantidad']);
                }
            }

            $limite = $disponibles[$id_producto] ?? null;
            if ($limite !== null && ($en_carrito + $cantidad) > $limite) {
                $restante = max(0, $limite - $en_carrito);
                $_SESSION['mensaje'] = "No hay insumos suficientes para esa cantidad de \"" . $platillo['nombre'] . "\". Puedes agregar " . $restante . " más.";
                $_SESSION['tipo_mensaje'] = "error";
            } else {
                $encontrado = false;
                foreach ($_SESSION['carrito'] as &$item) {
                    if (
                        $item['id'] == $id_producto
                        && trim((string) ($item['especificaciones'] ?? '')) === $especificaciones
                    ) {
                        $item['cantidad'] += $cantidad;
                        $encontrado = true;
                        break;
                    }
                }
                unset($item);

                if (!$encontrado) {
                    $_SESSION['carrito'][] = [
                        'id' => intval($platillo['id']),
                        'nombre' => $platillo['nombre'],
                        'precio' => floatval($platillo['precio']),
                        'tipo' => $platillo['tipo'] ?? 'platillo',
                        'cantidad' => $cantidad,
                        'especificaciones' => $especificaciones
                    ];
                }

                if ($usuario_id !== null) {
                    guardar_borrador($pdo, $usuario_id, $token_dispositivo, $_SESSION['carrito']);
                }

                $_SESSION['mensaje'] = "¡Producto agregado correctamente!";
                $_SESSION['tipo_mensaje'] = "success";
            }
        }
    }

    // 2. Vaciar pedido
    if ($_POST['accion'] == 'limpiar') {
        $_SESSION['carrito'] = [];
        if ($usuario_id !== null) {
            borrar_borrador($pdo, $usuario_id, $token_dispositivo);
        }
        $_SESSION['mensaje'] = "Se ha reiniciado tu selección.";
        $_SESSION['tipo_mensaje'] = "info";
    }

    // Quitar una línea específica del carrito, incluso si comparte producto con otra indicación.
    if ($_POST['accion'] == 'eliminar_item') {
        $indice_ingresado = $_POST['indice'] ?? '';
        if (
            is_scalar($indice_ingresado)
            && ctype_digit((string) $indice_ingresado)
            && array_key_exists((int) $indice_ingresado, $_SESSION['carrito'])
        ) {
            $item_eliminado = $_SESSION['carrito'][(int) $indice_ingresado];
            unset($_SESSION['carrito'][(int) $indice_ingresado]);
            $_SESSION['carrito'] = array_values($_SESSION['carrito']);

            if ($usuario_id !== null) {
                if ($_SESSION['carrito']) {
                    guardar_borrador($pdo, $usuario_id, $token_dispositivo, $_SESSION['carrito']);
                } else {
                    borrar_borrador($pdo, $usuario_id, $token_dispositivo);
                }
            }

            $_SESSION['mensaje'] = $item_eliminado['nombre'] . " se quitó de tu selección.";
            $_SESSION['tipo_mensaje'] = "info";
        } else {
            $_SESSION['mensaje'] = "No se pudo quitar ese producto. Actualiza la página e inténtalo de nuevo.";
            $_SESSION['tipo_mensaje'] = "error";
        }
    }

    // 3. Confirmar pedido: guardar pedido, detalle y orden de cocina en una sola operación
    if ($_POST['accion'] == 'confirmar_autoservicio') {
        $nombre_ingresado = $_POST['nombre_cliente'] ?? '';
        $nombre_cliente_registrado = is_scalar($nombre_ingresado)
            ? trim((string) $nombre_ingresado)
            : '';
        $telefono_cliente = is_scalar($_POST['telefono_cliente'] ?? null)
            ? trim((string) $_POST['telefono_cliente'])
            : '';
        $direccion_entrega = is_scalar($_POST['direccion_entrega'] ?? null)
            ? trim((string) $_POST['direccion_entrega'])
            : '';
        $tipo_servicio = is_scalar($_POST['tipo_servicio'] ?? null)
            ? (string) $_POST['tipo_servicio']
            : '';
        $es_formulario_domicilio = ($_POST['origen_checkout'] ?? '') === 'domicilio';
        if ($tipo_servicio !== 'domicilio' || !$es_formulario_domicilio) {
            $telefono_cliente = '';
        }
        $numero_mesa = null;
        $servicio_valido = true;

        if (
            ($es_formulario_domicilio && $tipo_servicio !== 'domicilio')
            || (!$es_formulario_domicilio && !in_array($tipo_servicio, ['para_llevar', 'comer_aqui'], true))
            || (
                !empty($_SESSION['tipo_servicio_seleccionado'])
                && $_SESSION['tipo_servicio_seleccionado'] !== $tipo_servicio
            )
        ) {
            $_SESSION['mensaje'] = "Selecciona el formulario correcto para el tipo de pedido.";
            $_SESSION['tipo_mensaje'] = "error";
            $servicio_valido = false;
        } elseif ($tipo_servicio === 'comer_aqui') {
            $mesa_ingresada = $_POST['numero_mesa'] ?? '';
            if (!is_scalar($mesa_ingresada) || !ctype_digit((string) $mesa_ingresada) || intval($mesa_ingresada) < 1 || intval($mesa_ingresada) > 10) {
                $_SESSION['mensaje'] = "Selecciona una mesa válida del 1 al 10.";
                $_SESSION['tipo_mensaje'] = "error";
                $servicio_valido = false;
            } else {
                $numero_mesa = intval($mesa_ingresada);
            }
        } elseif ($tipo_servicio === 'domicilio' && ($direccion_entrega === '' || mb_strlen($direccion_entrega, 'UTF-8') > 255)) {
            $_SESSION['mensaje'] = "Escribe una dirección de entrega válida (máximo 255 caracteres).";
            $_SESSION['tipo_mensaje'] = "error";
            $servicio_valido = false;
        }

        if ($servicio_valido && empty($nombre_cliente_registrado)) {
            $_SESSION['mensaje'] = "Por favor, escribe tu nombre para poder generar el ticket.";
            $_SESSION['tipo_mensaje'] = "error";
        } elseif ($servicio_valido && (
            mb_strlen($nombre_cliente_registrado, 'UTF-8') > 100
            || ($tipo_servicio === 'domicilio' && !preg_match('/^[0-9+\s().-]{7,20}$/', $telefono_cliente))
        )) {
            $_SESSION['mensaje'] = $tipo_servicio === 'domicilio'
                ? "Escribe un nombre válido y un teléfono de contacto (de 7 a 20 caracteres)."
                : "Escribe un nombre válido (máximo 100 caracteres).";
            $_SESSION['tipo_mensaje'] = "error";
        } elseif ($servicio_valido && !empty($_SESSION['carrito'])) {
            try {
                $pdo->beginTransaction();

                // Revalidar cada platillo contra la base de datos (precio, estado y stock)
                $subtotal_neto = 0;
                $detalle = [];
                $lista_platillos = [];
                $cantidades_por_platillo = [];
                $nombres_por_platillo = [];
                $contiene_bebida_alcoholica = false;
                foreach ($_SESSION['carrito'] as $item) {
                    $platillo = buscar_platillo_activo($pdo, intval($item['id']));
                    if (!$platillo) {
                        throw new Exception("El platillo \"" . $item['nombre'] . "\" ya no está disponible. Quítalo de tu selección.");
                    }
                    if (($platillo['tipo'] ?? '') === 'bebida_alcoholica') {
                        $contiene_bebida_alcoholica = true;
                        if ($tipo_servicio !== 'comer_aqui') {
                            throw new Exception('Las bebidas alcohólicas solo se pueden pedir para consumir dentro del restaurante.');
                        }
                    }
                    $platillo_id = intval($platillo['id']);
                    $cantidad_item = intval($item['cantidad']);
                    $cantidades_por_platillo[$platillo_id] = ($cantidades_por_platillo[$platillo_id] ?? 0) + $cantidad_item;
                    $nombres_por_platillo[$platillo_id] = $platillo['nombre'];
                    $precio = floatval($platillo['precio']);
                    $subtotal_neto += $precio * $cantidad_item;
                    $especificaciones_item = trim((string) ($item['especificaciones'] ?? ''));
                    $detalle[] = [
                        'id' => $platillo_id,
                        'nombre' => $platillo['nombre'],
                        'precio' => $precio,
                        'cantidad' => $cantidad_item,
                        'especificaciones' => $especificaciones_item
                    ];
                    $linea_cocina = $cantidad_item . " x " . $platillo['nombre'];
                    if ($especificaciones_item !== '') {
                        $linea_cocina .= " (Indicaciones: " . $especificaciones_item . ")";
                    }
                    $lista_platillos[] = $linea_cocina;
                }

                if ($contiene_bebida_alcoholica && !cumple_edad_minima($_POST['fecha_nacimiento'] ?? null, 21)) {
                    $fecha_limite = fecha_limite_edad(21)->format('d/m/Y');
                    throw new Exception('Para pedir bebidas alcohólicas debes tener 21 años cumplidos. La fecha de nacimiento debe ser ' . $fecha_limite . ' o anterior; el personal verificará tu identidad oficial.');
                }

                if ($contiene_bebida_alcoholica && ($_POST['confirmacion_edad'] ?? '') !== '1') {
                    throw new Exception('Confirma que tienes 21 años o más para pedir bebidas alcohólicas.');
                }

                if ($contiene_bebida_alcoholica) {
                    $fecha_nacimiento = is_scalar($_POST['fecha_nacimiento'] ?? null)
                        ? (string) $_POST['fecha_nacimiento']
                        : '';
                    $identidad_ingresada = is_scalar($_POST['numero_identidad'] ?? null)
                        ? preg_replace('/[\s-]/', '', (string) $_POST['numero_identidad'])
                        : '';
                    if (!is_string($identidad_ingresada) || !preg_match('/^\d{13}$/', $identidad_ingresada)) {
                        throw new Exception('Para pedir bebidas alcohólicas debes ingresar tu número de identidad hondureño de 13 dígitos. El personal verificará el documento oficial en el restaurante.');
                    }
                    if (substr($identidad_ingresada, 4, 4) !== substr($fecha_nacimiento, 0, 4)) {
                        throw new Exception('El año de nacimiento de la identidad no coincide con la fecha de nacimiento ingresada.');
                    }
                }

                foreach ($cantidades_por_platillo as $platillo_id => $cantidad_total) {
                    $limite = $disponibles[$platillo_id] ?? null;
                    if ($limite !== null && $cantidad_total > $limite) {
                        throw new Exception("No hay insumos suficientes para " . $cantidad_total . " de \"" . $nombres_por_platillo[$platillo_id] . "\".");
                    }
                }

                $gran_total = round($subtotal_neto * 1.15, 2);
                $ahora = date('Y-m-d H:i:s');

                $stmt = $pdo->prepare("INSERT INTO pedidos (cliente, telefono_cliente, tipo_servicio, direccion_entrega, numero_mesa, total, estado, fecha) VALUES (?, ?, ?, ?, ?, ?, 'Pendiente', ?)");
                $stmt->execute([
                    $nombre_cliente_registrado,
                    $tipo_servicio === 'domicilio' ? $telefono_cliente : null,
                    $tipo_servicio,
                    $tipo_servicio === 'domicilio' ? $direccion_entrega : null,
                    $numero_mesa,
                    $gran_total,
                    $ahora
                ]);
                $id_pedido_generado = $pdo->lastInsertId();

                $stmt_det = $pdo->prepare("INSERT INTO detalle_pedido (pedido_id, platillo_id, nombre, precio_unitario, cantidad, especificaciones) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($detalle as $d) {
                    $stmt_det->execute([
                        $id_pedido_generado,
                        $d['id'],
                        $d['nombre'],
                        $d['precio'],
                        $d['cantidad'],
                        $d['especificaciones']
                    ]);
                }

                $stmt_chef = $pdo->prepare("INSERT INTO pedidos_chef (pedido_id, cliente, platillos, estado, fecha) VALUES (?, ?, ?, 'En espera', ?)");
                $stmt_chef->execute([$id_pedido_generado, $nombre_cliente_registrado, implode(', ', $lista_platillos), $ahora]);

                $pdo->commit();

                if ($usuario_id !== null) {
                    borrar_borrador($pdo, $usuario_id, $token_dispositivo);
                }

                $_SESSION['ticket_generado'] = $id_pedido_generado;
                $_SESSION['nombre_cliente_registrado'] = $nombre_cliente_registrado;
                $_SESSION['numero_mesa_ticket'] = $numero_mesa;
                $_SESSION['tipo_servicio_ticket'] = $tipo_servicio;
                $_SESSION['direccion_entrega_ticket'] = $tipo_servicio === 'domicilio' ? $direccion_entrega : '';
                $_SESSION['telefono_cliente_ticket'] = $tipo_servicio === 'domicilio' ? $telefono_cliente : '';
                $_SESSION['mensaje'] = $tipo_servicio === 'domicilio'
                    ? "Pedido a domicilio recibido. El restaurante coordinará la entrega y el pago con los datos proporcionados."
                    : ($tipo_servicio === 'comer_aqui'
                        ? "Pedido enviado a cocina. Puedes pagar en caja después."
                        : "Pedido recibido correctamente. Pasa a caja para recibir tu pedido y pagar.");
                $_SESSION['tipo_mensaje'] = "success";
                $_SESSION['carrito'] = [];
                unset($_SESSION['tipo_servicio_seleccionado']);

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['mensaje'] = ($e instanceof PDOException)
                    ? "No se pudo registrar tu pedido. Avisa al personal e intenta de nuevo."
                    : $e->getMessage();
                $_SESSION['tipo_mensaje'] = "error";
            }
        }
    }

    // 4. Recuperar el pedido que quedó sin terminar (por ejemplo, tras un corte de luz)
    if ($_POST['accion'] == 'recuperar_borrador' && $usuario_id !== null) {
        $borrador = obtener_borrador($pdo, $usuario_id, $token_dispositivo);
        if ($borrador) {
            $recuperados = [];
            $omitidos = [];
            $cantidades_recuperadas = [];
            foreach ($borrador['items'] as $item) {
                try {
                    $platillo = buscar_platillo_activo($pdo, intval($item['id'] ?? 0));
                } catch (PDOException $e) {
                    $platillo = null;
                }
                $nombre_item = $item['nombre'] ?? 'Platillo';
                $cant = max(1, min(10, intval($item['cantidad'] ?? 1)));
                $especificaciones_item = is_scalar($item['especificaciones'] ?? '')
                    ? mb_substr(trim((string) ($item['especificaciones'] ?? '')), 0, 250, 'UTF-8')
                    : '';

                if (!$platillo) {
                    $omitidos[] = $nombre_item;
                    continue;
                }

                $platillo_id = intval($platillo['id']);
                $limite = $disponibles[$platillo_id] ?? null;
                if ($limite !== null) {
                    $restante = max(0, $limite - ($cantidades_recuperadas[$platillo_id] ?? 0));
                    $cant = min($cant, $restante);
                }
                if ($cant < 1) {
                    $omitidos[] = $platillo['nombre'];
                    continue;
                }
                $cantidades_recuperadas[$platillo_id] = ($cantidades_recuperadas[$platillo_id] ?? 0) + $cant;

                $recuperados[] = [
                    'id' => $platillo_id,
                    'nombre' => $platillo['nombre'],
                    'precio' => floatval($platillo['precio']),
                    'tipo' => $platillo['tipo'] ?? 'platillo',
                    'cantidad' => $cant,
                    'especificaciones' => $especificaciones_item
                ];
            }

            $_SESSION['carrito'] = $recuperados;
            guardar_borrador($pdo, $usuario_id, $token_dispositivo, $recuperados);

            $texto = "Pedido recuperado. Revisa tu selección y continúa.";
            if (count($omitidos) > 0) {
                $texto .= " No se pudo recuperar (ya no disponible o sin insumos): " . implode(', ', $omitidos) . ".";
            }
            $_SESSION['mensaje'] = $texto;
            $_SESSION['tipo_mensaje'] = "success";
        }
    }

    // 5. Ignorar el pedido recuperado
    if ($_POST['accion'] == 'descartar_borrador' && $usuario_id !== null) {
        borrar_borrador($pdo, $usuario_id, $token_dispositivo);
        $_SESSION['mensaje'] = "Se ignoró el pedido recuperado. Puedes empezar uno nuevo.";
        $_SESSION['tipo_mensaje'] = "info";
    }

    // Redirección para evitar que el pedido se repita al actualizar (F5)
    $categoria_redireccion = in_array($_POST['accion'], ['agregar', 'eliminar_item'], true)
        ? ($_POST['categoria'] ?? 'platillo')
        : 'platillo';
    if (!is_string($categoria_redireccion) || !in_array($categoria_redireccion, $categorias_menu, true)) {
        $categoria_redireccion = 'platillo';
    }
    if (($_POST['origen_checkout'] ?? '') === 'domicilio' && $_POST['accion'] === 'confirmar_autoservicio') {
        header('Location: domicilio.php');
    } else {
        header("Location: pedir.php?categoria=" . rawurlencode($categoria_redireccion));
    }
    exit();
}

// ¿Hay un pedido sin terminar guardado (por ejemplo, tras un corte de luz)?
$borrador_pendiente = null;
if ($usuario_id !== null && empty($_SESSION['carrito']) && !$ticket_generado) {
    $borrador_pendiente = obtener_borrador($pdo, $usuario_id, $token_dispositivo);
}

// Menú: solo platillos activos
$categoria_ingresada = $_GET['categoria'] ?? 'platillo';
$categoria_filtro = is_string($categoria_ingresada)
    ? strtolower(trim($categoria_ingresada))
    : 'platillo';
if (!in_array($categoria_filtro, $categorias_menu, true)) {
    $categoria_filtro = 'platillo';
}

try {
    $stmt_platillos = $pdo->query("SELECT * FROM platillos WHERE estado IS NULL OR estado <> 'inactivo' ORDER BY id DESC");
    $platillos = $stmt_platillos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $platillos = [];
}

$tipo_servicio_seleccionado = $_SESSION['tipo_servicio_seleccionado'] ?? '';
if ($categoria_filtro === 'bebida_alcoholica' && $tipo_servicio_seleccionado !== 'comer_aqui') {
    $mensaje = 'Las bebidas alcohólicas solo están disponibles para consumir dentro del restaurante.';
    $tipo_mensaje = 'error';
}
$promociones = array_values(array_filter($platillos, static function ($platillo) use ($tipo_servicio_seleccionado) {
    return !empty($platillo['es_promocion'])
        && ($tipo_servicio_seleccionado === 'comer_aqui' || ($platillo['tipo'] ?? '') !== 'bebida_alcoholica');
}));
$total_platillos = count($platillos);
$mostrar_promociones = $categoria_filtro === 'promociones';

if ($categoria_filtro === 'promociones') {
    $platillos = [];
} else {
    $platillos = array_values(array_filter($platillos, static function ($platillo) use ($categoria_filtro, $tipo_servicio_seleccionado) {
        if (!empty($platillo['es_promocion'])) {
            return false;
        }
        if (($platillo['tipo'] ?? '') === 'bebida_alcoholica' && $tipo_servicio_seleccionado !== 'comer_aqui') {
            return false;
        }
        $tipo = strtolower(trim((string) ($platillo['tipo'] ?? 'platillo')));
        return $tipo === $categoria_filtro;
    }));
}

$carrito_tiene_bebida_alcoholica = false;
if (!empty($_SESSION['carrito'])) {
    $ids_carrito = array_values(array_unique(array_filter(array_map(
        static function ($item) {
            return intval($item['id'] ?? 0);
        },
        $_SESSION['carrito']
    ))));
    if ($ids_carrito) {
        $marcadores = implode(',', array_fill(0, count($ids_carrito), '?'));
        $stmt_tipos_carrito = $pdo->prepare("SELECT id, tipo FROM platillos WHERE id IN ($marcadores)");
        $stmt_tipos_carrito->execute($ids_carrito);
        foreach ($stmt_tipos_carrito->fetchAll(PDO::FETCH_ASSOC) as $producto_carrito) {
            if (($producto_carrito['tipo'] ?? '') === 'bebida_alcoholica') {
                $carrito_tiene_bebida_alcoholica = true;
                break;
            }
        }
    }
}

$fecha_nacimiento_maxima = fecha_limite_edad(21);
$mostrar_selector_servicio = !$ticket_generado && (
    (isset($_GET['seleccionar_servicio']) && $_GET['seleccionar_servicio'] === '1')
    || (
        empty($_SESSION['tipo_servicio_seleccionado'])
        && empty($_SESSION['carrito'])
    )
);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haz tu pedido - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="order-page">

<?php include 'header.php'; ?>

<?php if ($mostrar_selector_servicio): ?>
    <main class="kiosco-page-wrapper">
        <section class="kiosco-header service-choice-header">
            <h1><i class="fa-solid fa-utensils"></i> ¿Cómo deseas recibir tu pedido?</h1>
            <p>Antes de elegir tus platillos, dinos cómo deseas recibir tu pedido: a domicilio, para llevar o comer aquí. Si eliges domicilio, te llevaremos al formulario de entrega.</p>
        </section>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-custom <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8'); ?>" role="status">
                <i class="fa-solid <?php echo ($tipo_mensaje === 'error') ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                <span><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <div class="service-choice-grid">
            <form method="POST" action="pedir.php">
                <input type="hidden" name="accion" value="seleccionar_servicio">
                <input type="hidden" name="tipo_servicio" value="domicilio">
                <button type="submit" class="service-choice-card">
                    <i class="fa-solid fa-truck" aria-hidden="true"></i>
                    <strong>Entrega a domicilio</strong>
                    <span>Completa la dirección y tus datos de contacto.</span>
                    <span class="service-choice-action">Ver menu</span>
                </button>
            </form>
            <form method="POST" action="pedir.php">
                <input type="hidden" name="accion" value="seleccionar_servicio">
                <input type="hidden" name="tipo_servicio" value="para_llevar">
                <button type="submit" class="service-choice-card">
                    <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                    <strong>Para llevar</strong>
                    <span>Elige tus platillos y pasa a caja por tu pedido.</span>
                    <span class="service-choice-action">Ver menú</span>
                </button>
            </form>
            <form method="POST" action="pedir.php">
                <input type="hidden" name="accion" value="seleccionar_servicio">
                <input type="hidden" name="tipo_servicio" value="comer_aqui">
                <button type="submit" class="service-choice-card">
                    <i class="fa-solid fa-chair" aria-hidden="true"></i>
                    <strong>Comer aquí</strong>
                    <span>Elige tus platillos y selecciona tu mesa al confirmar.</span>
                    <span class="service-choice-action">Ver menú</span>
                </button>
            </form>
        </div>
    </main>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

<div class="kiosco-page-wrapper">

    <!-- ENCABEZADO -->
    <div class="kiosco-header">
        <h1><i class="fa-solid fa-cash-register"></i> Haz tu pedido</h1>
        <p>Arma tu pedido para llevar o elige una mesa para comer aquí.</p>
        <?php if ($tipo_servicio_seleccionado === 'domicilio'): ?>
            <div class="order-flow-hint order-flow-hint--delivery">
                <i class="fa-solid fa-truck" aria-hidden="true"></i>
                <span><strong>Recibirás tu pedido a domicilio</strong><small>Agrega tus platillos y luego vuelve al formulario para ingresar la dirección y confirmar.</small></span>
                <a href="domicilio.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Continuar al formulario</a>
                <a class="service-change-link" href="pedir.php?seleccionar_servicio=1"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Cambiar modalidad</a>
            </div>
        <?php else: ?>
            <div class="order-flow-hint">
                <i class="fa-solid <?php echo $tipo_servicio_seleccionado === 'comer_aqui' ? 'fa-chair' : 'fa-bag-shopping'; ?>" aria-hidden="true"></i>
                <span><?php echo $tipo_servicio_seleccionado === 'comer_aqui' ? 'Recibirás tu pedido para comer aquí. Al confirmar podrás seleccionar tu mesa.' : 'Recibirás tu pedido para llevar. Arma tu selección y confirma cuando estés listo.'; ?></span>
                <a class="service-change-link" href="pedir.php?seleccionar_servicio=1"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Cambiar modalidad</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- MENSAJES DE ALERTA -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert-custom <?php echo $tipo_mensaje; ?>">
            <i class="fa-solid <?php echo ($tipo_mensaje === 'error') ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
            <span><?php echo htmlspecialchars($mensaje); ?></span>
        </div>
    <?php endif; ?>

    <!-- PEDIDO RECUPERADO -->
    <?php if ($borrador_pendiente): ?>
        <?php
            $resumen_rec = [];
            $subtotal_rec = 0;
            foreach ($borrador_pendiente['items'] as $it) {
                $resumen_rec[] = intval($it['cantidad'] ?? 1) . ' x ' . ($it['nombre'] ?? 'Platillo');
                $subtotal_rec += floatval($it['precio'] ?? 0) * intval($it['cantidad'] ?? 1);
            }
        ?>
        <div style="background: #eff6ff; border: 1px solid #93c5fd; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px;">
            <div style="font-weight: 700; color: #1e40af; font-size: 16px; margin-bottom: 4px;">
                <i class="fa-solid fa-rotate-left"></i> Pedido recuperado
            </div>
            <div style="font-size: 14px; color: #1f2937;">
                Había un pedido sin terminar (guardado el <?php echo date('d/m/Y H:i', strtotime($borrador_pendiente['actualizado'])); ?>):
                <strong><?php echo htmlspecialchars(implode(', ', $resumen_rec)); ?></strong>
                &middot; Total aprox. LPS <?php echo number_format($subtotal_rec * 1.15, 2); ?>
            </div>
            <div style="font-size: 14px; margin: 6px 0 12px 0;">¿Quieres seguir con este pedido o prefieres ignorarlo?</div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <form method="POST" action="pedir.php" style="margin: 0;">
                    <input type="hidden" name="accion" value="recuperar_borrador">
                    <button type="submit" style="background: #059669; color: #fff; border: 0; padding: 10px 18px; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        <i class="fa-solid fa-check"></i> Seguir con el pedido
                    </button>
                </form>
                <form method="POST" action="pedir.php" style="margin: 0;">
                    <input type="hidden" name="accion" value="descartar_borrador">
                    <button type="submit" style="background: #e5e7eb; color: #111827; border: 0; padding: 10px 18px; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        <i class="fa-solid fa-xmark"></i> Ignorar
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- TICKET GENERADO -->
    <?php if ($ticket_generado): ?>
        <div class="ticket-card">
            <i class="fa-solid fa-ticket fa-3x" style="color: var(--primary-color); margin-bottom: 8px;"></i>
            <h2 style="color: var(--text-main); margin: 4px 0; font-size: 20px;">¡Ticket generado con éxito!</h2>
            <p style="font-size: 14px; color: var(--text-muted); margin: 4px 0;">Cliente: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($nombre_cliente_registrado); ?></strong></p>
            <p style="font-size: 14px; color: var(--text-muted); margin: 4px 0;">
                <?php if ($numero_mesa_ticket !== null): ?>
                    <i class="fa-solid fa-chair" style="color: var(--primary-color);"></i>
                    Mesa: <strong style="color: var(--text-main);"><?php echo intval($numero_mesa_ticket); ?></strong>
                <?php elseif ($tipo_servicio_ticket === 'domicilio'): ?>
                    <i class="fa-solid fa-truck" style="color: var(--primary-color);"></i>
                    <strong style="color: var(--text-main);">Entrega a domicilio</strong>
                <?php else: ?>
                    <i class="fa-solid fa-bag-shopping" style="color: var(--primary-color);"></i>
                    <strong style="color: var(--text-main);">Pedido para llevar</strong>
                <?php endif; ?>
            </p>
            <?php if ($telefono_cliente_ticket !== ''): ?>
                <p style="font-size: 14px; color: var(--text-muted); margin: 4px 0;">
                    Teléfono: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($telefono_cliente_ticket); ?></strong>
                </p>
            <?php endif; ?>
            <?php if ($direccion_entrega_ticket !== ''): ?>
                <p style="font-size: 14px; color: var(--text-muted); margin: 4px 0;">
                    Dirección: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($direccion_entrega_ticket); ?></strong>
                </p>
            <?php endif; ?>
            <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-top: 10px;">Número de Turno</div>
            <div class="ticket-number">#<?php echo $ticket_generado; ?></div>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 18px;">
                <?php echo $tipo_servicio_ticket === 'comer_aqui'
                    ? 'Tu pedido ya fue enviado a cocina. Puedes pagar en caja después.'
                    : 'Pasa a caja para recibir tu pedido y pagar.'; ?>
            </p>
            <a href="pedir.php" class="btn-checkout" style="text-decoration: none; display: inline-flex; justify-content: center;">
                <i class="fa-solid fa-arrow-rotate-left"></i> Hacer un Nuevo Pedido
            </a>
        </div>
    <?php endif; ?>

    <?php if (!$ticket_generado): ?>

        <!-- GRID DE 2 COLUMNAS -->
        <div class="kiosco-main-grid">

            <!-- COLUMNA CENTRAL: MENÚ DE PLATILLOS DISPONIBLES -->
            <div class="menu-container-central">
                <div class="section-heading">
                    <i class="fa-solid fa-utensils" style="color: var(--primary-color);"></i> Menú Disponible
                </div>

                <div class="catalog-filter-panel">
                    <span class="catalog-filter-label">Explora el menú</span>
                    <form method="GET" action="pedir.php" class="catalog-filters" aria-label="Filtrar productos por tipo">
                        <button type="submit" name="categoria" value="platillo" class="catalog-filter <?php echo $categoria_filtro === 'platillo' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $categoria_filtro === 'platillo' ? 'true' : 'false'; ?>">
                            <i class="fa-solid fa-utensils" aria-hidden="true"></i> Platos individuales
                        </button>
                        <button type="submit" name="categoria" value="combo" class="catalog-filter <?php echo $categoria_filtro === 'combo' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $categoria_filtro === 'combo' ? 'true' : 'false'; ?>">
                            <i class="fa-solid fa-burger" aria-hidden="true"></i> Combos
                        </button>
                        <button type="submit" name="categoria" value="bebida" class="catalog-filter <?php echo $categoria_filtro === 'bebida' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $categoria_filtro === 'bebida' ? 'true' : 'false'; ?>">
                            <i class="fa-solid fa-glass-water" aria-hidden="true"></i> Bebidas
                        </button>
                        <?php if ($tipo_servicio_seleccionado === 'comer_aqui'): ?>
                            <button type="submit" name="categoria" value="bebida_alcoholica" class="catalog-filter <?php echo $categoria_filtro === 'bebida_alcoholica' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $categoria_filtro === 'bebida_alcoholica' ? 'true' : 'false'; ?>">
                                <i class="fa-solid fa-wine-glass" aria-hidden="true"></i> Bebidas alcohólicas · 21+
                            </button>
                        <?php endif; ?>
                        <button type="submit" name="categoria" value="promociones" class="catalog-filter <?php echo $categoria_filtro === 'promociones' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $categoria_filtro === 'promociones' ? 'true' : 'false'; ?>">
                            <i class="fa-solid fa-tag" aria-hidden="true"></i> Promociones
                        </button>
                    </form>
                </div>

                <?php if ($mostrar_promociones): ?>
                    <section class="promotions-section" aria-labelledby="promotionsHeading">
                        <div class="promotions-heading">
                            <div>
                                <span class="promotions-eyebrow"><i class="fa-solid fa-sparkles"></i> Ofertas especiales</span>
                                <h2 id="promotionsHeading">Promociones</h2>
                            </div>
                            <span class="promotions-count"><?php echo count($promociones) === 1 ? '1 disponible' : count($promociones) . ' disponibles'; ?></span>
                        </div>
                        <?php if (count($promociones) > 0): ?>
                            <div class="catalog-grid promotions-grid">
                                <?php foreach ($promociones as $p): ?>
                                    <div class="platillo-card promotion-card" data-tipo="<?php echo htmlspecialchars($p['tipo'] ?? 'platillo', ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="promotion-badge"><i class="fa-solid fa-tag"></i> Promoción</span>
                                        <div class="platillo-img-container">
                                            <?php
                                                $img_promocion = trim($p['imagen'] ?? '');
                                                $ruta_promocion = imagen_producto_disponible($img_promocion) ? $img_promocion : 'img/default.svg';
                                            ?>
                                            <img src="<?php echo htmlspecialchars($ruta_promocion); ?>" alt="<?php echo htmlspecialchars($p['nombre'] ?? 'Promoción'); ?>" onerror="this.onerror=null; this.src='img/default.svg';">
                                        </div>
                                        <div class="platillo-info">
                                            <div class="platillo-nombre"><?php echo htmlspecialchars($p['nombre'] ?? ''); ?></div>
                                            <div class="platillo-desc"><?php echo htmlspecialchars($p['descripcion'] ?? 'Sin descripción.'); ?></div>
                                            <?php if (($p['tipo'] ?? '') === 'bebida_alcoholica'): ?>
                                                <small class="alcohol-order-note">21+ · Solo consumo en el restaurante · Presenta identificación. Se agrega 15% de impuesto al total.</small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="platillo-footer orderable-footer">
                                            <div class="platillo-precio-container">
                                                <div class="platillo-precio">LPS <?php echo number_format($p['precio'] ?? 0, 2); ?></div>
                                                <div class="precio-subtext"><?php echo ($p['tipo'] ?? '') === 'bebida_alcoholica' ? 'Precio sin impuesto' : 'Precio de promoción'; ?></div>
                                            </div>
                                            <?php
                                                $disp_promocion = $disponibles[intval($p['id'])] ?? null;
                                                $agotada_promocion = ($disp_promocion !== null && $disp_promocion <= 0);
                                                $max_promocion = ($disp_promocion !== null) ? max(1, min(10, $disp_promocion)) : 10;
                                                $promocion_es_bebida = in_array(strtolower(trim((string) ($p['tipo'] ?? 'platillo'))), ['bebida', 'bebida_alcoholica'], true);
                                            ?>
                                            <?php if ($agotada_promocion): ?>
                                                <div style="color: #c0392b; font-weight: 700; font-size: 13px;">Agotado (sin insumos)</div>
                                            <?php else: ?>
                                                <form method="POST" action="pedir.php" class="add-form order-item-form">
                                                    <input type="hidden" name="accion" value="agregar">
                                                    <input type="hidden" name="categoria" value="<?php echo htmlspecialchars($categoria_filtro, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="id_producto" value="<?php echo intval($p['id']); ?>">
                                                    <?php if (!$promocion_es_bebida): ?>
                                                        <textarea name="especificaciones" rows="2" maxlength="250" class="order-specifications" placeholder="Indicaciones (opcional), ej. sin queso"></textarea>
                                                    <?php endif; ?>
                                                    <div class="order-item-controls">
                                                        <input type="number" name="cantidad" value="1" min="1" max="<?php echo $max_promocion; ?>" class="qty-input">
                                                        <button type="submit" class="btn-add"><i class="fa-solid fa-plus"></i> Agregar</button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-cart">
                                <i class="fa-solid fa-tag fa-2x" style="margin-bottom: 8px;"></i>
                                <p>No hay promociones disponibles por el momento.</p>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <div class="catalog-grid">
                    <?php if (count($platillos) > 0): ?>
                        <?php foreach ($platillos as $p): ?>
                            <div class="platillo-card" data-tipo="<?php echo htmlspecialchars($p['tipo'] ?? 'platillo', ENT_QUOTES, 'UTF-8'); ?>">
                                
                                <!-- 📸 IMAGEN DEL PLATILLO DINÁMICA -->
                                <div class="platillo-img-container">
                                    <?php 
        $img_db = trim($p['imagen'] ?? '');
        $ruta_img = imagen_producto_disponible($img_db) ? $img_db : 'img/default.svg';
    ?>
    <img src="<?php echo htmlspecialchars($ruta_img); ?>" alt="<?php echo htmlspecialchars($p['nombre'] ?? 'Platillo'); ?>" onerror="this.onerror=null; this.src='img/default.svg';">
                                </div>

                                <div class="platillo-info">
                                    <div class="platillo-nombre"><?php echo htmlspecialchars($p['nombre'] ?? ''); ?></div>
                                    <div class="platillo-desc"><?php echo htmlspecialchars($p['descripcion'] ?? 'Sin descripción.'); ?></div>
                                    <?php if (($p['tipo'] ?? '') === 'bebida_alcoholica'): ?>
                                        <small class="alcohol-order-note">21+ · Solo consumo en el restaurante · Presenta identificación. Se agrega 15% de impuesto al total.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="platillo-footer orderable-footer">
                                    <div class="platillo-precio-container">
                                        <div class="platillo-precio">LPS <?php echo number_format($p['precio'] ?? 0, 2); ?></div>
                                        <div class="precio-subtext">Precio sin impuesto</div>
                                    </div>
                                    <?php
                                        $disp = $disponibles[intval($p['id'])] ?? null;
                                        $agotado = ($disp !== null && $disp <= 0);
                                        $max_qty = ($disp !== null) ? max(1, min(10, $disp)) : 10;
                                        $platillo_es_bebida = in_array(strtolower(trim((string) ($p['tipo'] ?? 'platillo'))), ['bebida', 'bebida_alcoholica'], true);
                                    ?>
                                    <?php if ($agotado): ?>
                                        <div style="color: #c0392b; font-weight: 700; font-size: 13px;">Agotado (sin insumos)</div>
                                    <?php else: ?>
                                        <form method="POST" action="pedir.php" class="add-form order-item-form">
                                            <input type="hidden" name="accion" value="agregar">
                                            <input type="hidden" name="categoria" value="<?php echo htmlspecialchars($categoria_filtro, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="id_producto" value="<?php echo intval($p['id']); ?>">
                                            <?php if (!$platillo_es_bebida): ?>
                                                <textarea name="especificaciones" rows="2" maxlength="250" class="order-specifications" placeholder="Indicaciones (opcional), ej. sin queso"></textarea>
                                            <?php endif; ?>
                                            <div class="order-item-controls">
                                                <input type="number" name="cantidad" value="1" min="1" max="<?php echo $max_qty; ?>" class="qty-input">
                                                <button type="submit" class="btn-add">
                                                    <i class="fa-solid fa-plus"></i> Agregar
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($total_platillos === 0): ?>
                        <div class="empty-cart" style="grid-column: 1/-1;">
                            <i class="fa-solid fa-box-open fa-2x" style="margin-bottom: 8px;"></i>
                            <p>No hay platillos registrados en la base de datos.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($categoria_filtro !== 'promociones' && $total_platillos > 0 && count($platillos) === 0): ?>
                    <div class="empty-cart catalog-empty-filter" role="status">
                        <i class="fa-solid fa-filter-circle-xmark fa-2x" style="margin-bottom: 8px;"></i>
                        <p>No hay productos disponibles en esta categoría.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- COLUMNA DERECHA: RESUMEN, IMPUESTOS Y TOTAL -->
            <div class="summary-sidebar">
                <h3 class="summary-title"><i class="fa-solid fa-receipt" style="color: var(--primary-color);"></i> Resumen de Selección</h3>

                <?php if (!empty($_SESSION['carrito'])): ?>
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Platillo</th>
                                <th>Cant</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $subtotal_neto = 0;
                            foreach ($_SESSION['carrito'] as $indice_item => $item):
                                $subtotal = $item['precio'] * $item['cantidad'];
                                $subtotal_neto += $subtotal;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['nombre']); ?></strong>
                                        <?php if (!empty($item['especificaciones'])): ?>
                                            <small class="cart-specifications">Indicaciones: <?php echo htmlspecialchars($item['especificaciones']); ?></small>
                                        <?php endif; ?>
                                        <form method="POST" action="pedir.php" class="remove-item-form">
                                            <input type="hidden" name="accion" value="eliminar_item">
                                            <input type="hidden" name="indice" value="<?php echo intval($indice_item); ?>">
                                            <input type="hidden" name="categoria" value="<?php echo htmlspecialchars($categoria_filtro, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="btn-remove-item" aria-label="Quitar <?php echo htmlspecialchars($item['nombre'], ENT_QUOTES, 'UTF-8'); ?> de la selección">
                                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Eliminar
                                            </button>
                                        </form>
                                    </td>
                                    <td><?php echo $item['cantidad']; ?></td>
                                    <td>LPS <?php echo number_format($subtotal, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- SECCIÓN DE CÁLCULO DE IMPUESTOS (15%) -->
                    <?php
                        $impuesto = $subtotal_neto * 0.15;
                        $gran_total = $subtotal_neto + $impuesto;
                    ?>
                    <div class="summary-calc">
                        <div class="calc-row">
                            <span>Subtotal:</span>
                            <span>LPS <?php echo number_format($subtotal_neto, 2); ?></span>
                        </div>
                        <div class="calc-row">
                            <span>Impuestos (15%):</span>
                            <span>LPS <?php echo number_format($impuesto, 2); ?></span>
                        </div>
                        <div class="summary-total">
                            <span>Total a Pagar:</span>
                            <span class="monto">LPS <?php echo number_format($gran_total, 2); ?></span>
                        </div>
                    </div>

                    <!-- DATOS DE ENTREGA Y CONFIRMACIÓN DEL PEDIDO -->
                    <?php if ($tipo_servicio_seleccionado === 'domicilio'): ?>
                        <a href="domicilio.php" class="btn-checkout" style="display: flex; justify-content: center; align-items: center; gap: 8px; text-decoration: none;">
                            <i class="fa-solid fa-arrow-left"></i> Terminé de elegir
                        </a>
                    <?php else: ?>
                    <form method="POST" action="pedir.php" class="checkout-box" id="checkoutPedido">
                        <input type="hidden" name="accion" value="confirmar_autoservicio">
                        <div class="form-group">
                            <?php if (in_array($tipo_servicio_seleccionado, ['para_llevar', 'comer_aqui'], true)): ?>
                                <label>
                                    <i class="fa-solid fa-utensils" style="color: var(--primary-color);"></i> ¿Cómo recibirás tu pedido?
                                </label>
                                <strong><?php echo $tipo_servicio_seleccionado === 'comer_aqui' ? 'Comer aquí' : 'Para llevar'; ?></strong>
                                <input type="hidden" name="tipo_servicio" id="tipo_servicio" value="<?php echo htmlspecialchars($tipo_servicio_seleccionado, ENT_QUOTES, 'UTF-8'); ?>">
                                <a class="service-change-link" href="pedir.php?seleccionar_servicio=1"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Cambiar cómo recibirlo</a>
                            <?php else: ?>
                                <label for="tipo_servicio">
                                    <i class="fa-solid fa-utensils" style="color: var(--primary-color);"></i> ¿Cómo recibirás tu pedido?
                                </label>
                                <select name="tipo_servicio" id="tipo_servicio" required class="form-input">
                                    <option value="">Selecciona una opción</option>
                                    <option value="para_llevar">Para llevar</option>
                                    <option value="comer_aqui">Comer aquí</option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" id="grupoNumeroMesa" hidden>
                            <label for="numero_mesa">
                                <i class="fa-solid fa-chair" style="color: var(--primary-color);"></i> Elige tu mesa si vas a comer aquí:
                            </label>
                            <select name="numero_mesa" id="numero_mesa" class="form-input">
                                <option value="">Selecciona una mesa (1 al 10)</option>
                                <?php for ($mesa = 1; $mesa <= 10; $mesa++): ?>
                                    <option value="<?php echo $mesa; ?>">Mesa <?php echo $mesa; ?></option>
                                <?php endfor; ?>
                            </select>
                            <small style="display: block; margin-top: 5px; color: var(--text-muted);">Obligatorio para comer aquí.</small>
                        </div>
                        <div class="form-group">
                            <label for="nombre_cliente">
                                <i class="fa-solid fa-user-pen" style="color: var(--primary-color);"></i> Tu Nombre (para Caja):
                            </label>
                            <input type="text" name="nombre_cliente" id="nombre_cliente" required placeholder="Ej. Carlos Mendoza" class="form-input">
                        </div>
                        <?php if ($carrito_tiene_bebida_alcoholica): ?>
                            <div class="form-group age-verification">
                                <label for="fecha_nacimiento">Fecha de nacimiento (obligatoria para bebidas alcohólicas):</label>
                                <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required max="<?php echo $fecha_nacimiento_maxima->format('Y-m-d'); ?>" class="form-input" autocomplete="bday" aria-describedby="aviso-edad aviso-identidad">
                                <small id="aviso-edad">Debes tener 21 años cumplidos. Solo se acepta una fecha de nacimiento del <?php echo $fecha_nacimiento_maxima->format('d/m/Y'); ?> o anterior.</small>
                                <label for="numero_identidad">Número de identidad hondureño (obligatorio):</label>
                                <input type="text" name="numero_identidad" id="numero_identidad" required inputmode="numeric" pattern="(?:[0-9]{13}|[0-9]{4}-[0-9]{4}-[0-9]{5})" minlength="13" maxlength="15" placeholder="0000-0000-00000" class="form-input" autocomplete="off" aria-describedby="aviso-identidad">
                                <small id="aviso-identidad">El año dentro del número de identidad debe coincidir con el año de nacimiento ingresado.</small>
                                <label class="age-verification__confirm">
                                    <input type="checkbox" name="confirmacion_edad" value="1" required>
                                    Confirmo que tengo 21 años o más. Presentaré mi identidad oficial al personal.
                                </label>
                                <small>Las bebidas alcohólicas solo se sirven para consumir dentro del restaurante. Tu fecha de nacimiento y número de identidad no se guardan; el personal verificará el documento original.</small>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn-checkout">
                            <i class="fa-solid fa-print"></i> Generar Ticket para Caja
                        </button>
                    </form>
                    <?php endif; ?>

                    <div>
                        <form method="POST" action="pedir.php">
                            <input type="hidden" name="accion" value="limpiar">
                            <button type="submit" class="btn-clear">
                                <i class="fa-solid fa-trash-can"></i> Vaciar Selección
                            </button>
                        </form>
                    </div>

                <?php else: ?>
                    <div class="empty-cart">
                        <i class="fa-solid fa-basket-shopping fa-2x" style="margin-bottom: 8px; color: #cbd5e1;"></i>
                        <p style="margin: 0;">Selecciona platillos del menú central para agregarlos a tu orden.</p>
                    </div>
                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

</div>

<script>
    const fechaNacimiento = document.getElementById('fecha_nacimiento');
    const numeroIdentidad = document.getElementById('numero_identidad');
    if (fechaNacimiento && numeroIdentidad) {
        const fechaMaximaPermitida = fechaNacimiento.max;
        const validarDatosIdentidad = () => {
            const errorEdad = fechaNacimiento.value && fechaNacimiento.value > fechaMaximaPermitida
                ? 'Debes tener 21 años cumplidos. La fecha debe ser la permitida o anterior.'
                : '';
            fechaNacimiento.setCustomValidity(errorEdad);

            const identidadNormalizada = numeroIdentidad.value.replace(/[\s-]/g, '');
            const anioIdentidad = identidadNormalizada.length === 13
                ? identidadNormalizada.substring(4, 8)
                : '';
            const anioNacimiento = fechaNacimiento.value
                ? fechaNacimiento.value.substring(0, 4)
                : '';
            const errorIdentidad = anioIdentidad && anioNacimiento && anioIdentidad !== anioNacimiento
                ? 'El año de nacimiento de la identidad no coincide con la fecha ingresada.'
                : '';
            numeroIdentidad.setCustomValidity(errorIdentidad);
        };
        fechaNacimiento.addEventListener('input', validarDatosIdentidad);
        fechaNacimiento.addEventListener('change', validarDatosIdentidad);
        numeroIdentidad.addEventListener('input', validarDatosIdentidad);
        numeroIdentidad.addEventListener('change', validarDatosIdentidad);
        validarDatosIdentidad();
    }

    const tipoServicio = document.getElementById('tipo_servicio');
    const grupoNumeroMesa = document.getElementById('grupoNumeroMesa');
    const numeroMesa = document.getElementById('numero_mesa');
    if (tipoServicio && grupoNumeroMesa && numeroMesa) {
        const actualizarDatosServicio = () => {
            const comerAqui = tipoServicio.value === 'comer_aqui';
            numeroMesa.disabled = !comerAqui;
            numeroMesa.required = comerAqui;
            if (!comerAqui) numeroMesa.value = '';
            grupoNumeroMesa.hidden = !comerAqui;
        };

        tipoServicio.addEventListener('change', actualizarDatosServicio);
        actualizarDatosServicio();
    }
</script>

</body>
</html>