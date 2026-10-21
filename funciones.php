<?php
/**
 * Funciones compartidas: inventario, disponibilidad de platillos, cocina y fechas.
 */

/** Comprueba la existencia de una imagen usando una ruta del proyecto, no el directorio de trabajo de PHP. */
function imagen_producto_disponible($ruta) {
    if (!is_string($ruta) || $ruta === '') {
        return false;
    }

    $ruta = str_replace('\\', '/', $ruta);
    if (strpos($ruta, 'img/') !== 0 || preg_match('~(^|/)\.\.?(/|$)~', $ruta)) {
        return false;
    }

    $ruta_local = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
    return is_file($ruta_local);
}

/** Registra un movimiento. Las operaciones de inventario pueden exigir que el historial también se guarde. */
function registrar_movimiento(PDO $pdo, $insumo_id, $tipo, $cantidad, $nota = '', $usuario = '', $estricto = false) {
    try {
        $stmt = $pdo->prepare("INSERT INTO movimientos_insumos (insumo_id, tipo, cantidad, nota, usuario, fecha) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$insumo_id, $tipo, $cantidad, $nota, $usuario, date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        error_log('No se pudo registrar un movimiento de inventario: ' . $e->getMessage());
        if ($estricto) {
            throw $e;
        }
    }
}

/**
 * Cuántas porciones se pueden preparar de cada platillo según el stock actual.
 * Devuelve [platillo_id => porciones]. Los platillos sin receta no aparecen (sin límite).
 */
function porciones_disponibles(PDO $pdo) {
    $res = [];
    $sql = "SELECT r.platillo_id, MIN(FLOOR(i.cantidad / r.cantidad_necesaria)) AS max_porciones
            FROM recetas r
            JOIN insumos i ON i.id = r.insumo_id
            WHERE r.cantidad_necesaria > 0
            GROUP BY r.platillo_id";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $res[intval($row['platillo_id'])] = max(0, intval($row['max_porciones']));
    }
    return $res;
}

/**
 * Descuenta del inventario los insumos de un pedido entregado.
 * Usa la tabla detalle_pedido; si el pedido es antiguo (sin detalle), interpreta el texto "2x Pollo".
 */
function descontar_inventario(PDO $pdo, array $orden, $usuario = '') {
    $pedido_id = intval($orden['pedido_id'] ?? 0);
    $consumos = [];

    $stmt = $pdo->prepare("SELECT r.insumo_id, SUM(r.cantidad_necesaria * d.cantidad) AS total
                           FROM detalle_pedido d
                           JOIN recetas r ON r.platillo_id = d.platillo_id
                           WHERE d.pedido_id = ?
                           GROUP BY r.insumo_id");
    $stmt->execute([$pedido_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $consumos[intval($row['insumo_id'])] = floatval($row['total']);
    }

    // Pedidos anteriores a la mejora: no tienen detalle_pedido
    $stmt_det = $pdo->prepare("SELECT COUNT(*) FROM detalle_pedido WHERE pedido_id = ?");
    $stmt_det->execute([$pedido_id]);
    if (intval($stmt_det->fetchColumn()) === 0) {
        $texto = $orden['platillos'] ?? '';
        $platillos = $pdo->query("SELECT id, nombre FROM platillos")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($platillos as $p) {
            if ($p['nombre'] === '' || stripos($texto, $p['nombre']) === false) {
                continue;
            }
            $cantidad = 1;
            if (preg_match('/(\d+)\s*x\s*' . preg_quote($p['nombre'], '/') . '/i', $texto, $m)) {
                $cantidad = intval($m[1]);
            }
            $stmt_r = $pdo->prepare("SELECT insumo_id, cantidad_necesaria FROM recetas WHERE platillo_id = ?");
            $stmt_r->execute([$p['id']]);
            foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $iid = intval($item['insumo_id']);
                $consumos[$iid] = ($consumos[$iid] ?? 0) + floatval($item['cantidad_necesaria']) * $cantidad;
            }
        }
    }

    foreach ($consumos as $insumo_id => $total) {
        if ($total <= 0) {
            continue;
        }
        $upd = $pdo->prepare("UPDATE insumos SET cantidad = GREATEST(cantidad - ?, 0) WHERE id = ?");
        $upd->execute([$total, $insumo_id]);
        registrar_movimiento($pdo, $insumo_id, 'salida', $total, 'Pedido #' . $pedido_id . ' entregado', $usuario);
    }
}

/** Muestra en cocina lo pagado y lo de comer aquí mientras espera el pago o la entrega. */
function consultar_ordenes_cocina(PDO $pdo) {
    $sql = "SELECT c.*, p.tipo_servicio, p.numero_mesa, p.estado AS estado_pago FROM pedidos_chef c
            JOIN pedidos p ON c.pedido_id = p.id
            WHERE (
                (p.estado = 'Completado' AND (DATE(p.fecha) = CURDATE() OR c.estado <> 'Entregado'))
                OR (p.tipo_servicio = 'comer_aqui' AND p.estado = 'Pendiente' AND c.estado <> 'Entregado')
            )
            ORDER BY FIELD(c.estado, 'En espera', 'Listo', 'Entregado'), c.id DESC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Huella de las órdenes para detectar cambios sin recargar toda la página. */
function firma_ordenes_cocina(array $ordenes) {
    $en_espera = 0;
    $partes = [];
    foreach ($ordenes as $o) {
        if ($o['estado'] === 'En espera') {
            $en_espera++;
        }
        $partes[] = $o['id'] . ':' . $o['estado'];
    }
    sort($partes);
    return [
        'firma'     => md5(implode('|', $partes)),
        'en_espera' => $en_espera,
        'total'     => count($ordenes),
    ];
}

/** Devuelve la fecha (Y-m-d) si es válida; si no, la de respaldo. */
function fecha_valida($valor, $respaldo) {
    if (is_string($valor) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $valor;
    }
    return $respaldo;
}


/* ------------------------------------------------------------------
 * Pedido en curso (borrador): se guarda en la base de datos cada vez que cambia,
 * para poder recuperarlo si se va la luz o se cierra el navegador.
 * Se identifica por usuario + dispositivo (cookie duradera), así dos equipos
 * con el mismo usuario no se mezclan.
 * ------------------------------------------------------------------ */

/** Identificador anónimo del dispositivo; crea la cookie si no existe (llamar antes de imprimir HTML). */
function token_dispositivo() {
    $t = $_COOKIE['ce_dispositivo'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
        $t = bin2hex(random_bytes(16));
        setcookie('ce_dispositivo', $t, time() + 60 * 60 * 24 * 365, '/', '', false, true);
        $_COOKIE['ce_dispositivo'] = $t;
    }
    return $t;
}

function borrar_borrador(PDO $pdo, $usuario_id, $token) {
    try {
        $stmt = $pdo->prepare("DELETE FROM pedido_borrador WHERE usuario_id = ? AND dispositivo = ?");
        $stmt->execute([$usuario_id, $token]);
    } catch (PDOException $e) {
        // Si la tabla aún no existe, el programa sigue funcionando sin recuperación.
    }
}

function guardar_borrador(PDO $pdo, $usuario_id, $token, array $carrito) {
    if (empty($carrito)) {
        borrar_borrador($pdo, $usuario_id, $token);
        return;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO pedido_borrador (usuario_id, dispositivo, carrito, actualizado)
                               VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE carrito = VALUES(carrito), actualizado = VALUES(actualizado)");
        $stmt->execute([$usuario_id, $token, json_encode(array_values($carrito), JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
    }
}

/** Devuelve ['items' => [...], 'actualizado' => 'Y-m-d H:i:s'] o null. Los borradores de más de 24 h se descartan. */
function obtener_borrador(PDO $pdo, $usuario_id, $token) {
    try {
        $stmt = $pdo->prepare("SELECT carrito, actualizado FROM pedido_borrador WHERE usuario_id = ? AND dispositivo = ?");
        $stmt->execute([$usuario_id, $token]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            return null;
        }
        if (strtotime($fila['actualizado']) < time() - 86400) {
            borrar_borrador($pdo, $usuario_id, $token);
            return null;
        }
        $items = json_decode($fila['carrito'], true);
        if (!is_array($items) || count($items) === 0) {
            return null;
        }
        return ['items' => $items, 'actualizado' => $fila['actualizado']];
    } catch (PDOException $e) {
        return null;
    }
}
