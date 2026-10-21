<?php
include 'auth.php';
requerir_rol(['admin', 'cajero']);
include 'conexion.php';

$mensaje = $_SESSION['mensaje'] ?? '';
$tipo_mensaje = $_SESSION['tipo_mensaje'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']);

function volver_a_caja($texto, $tipo) {
    $_SESSION['mensaje'] = $texto;
    $_SESSION['tipo_mensaje'] = $tipo;
    header("Location: caja.php");
    exit();
}

// Número de factura correlativo por año
function generarNumeroFactura($pdo) {
    $anioActual = date('Y');
    $stmt = $pdo->prepare("SELECT numero_factura FROM pedidos WHERE numero_factura LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["$anioActual-%"]);
    $ultimaFactura = $stmt->fetch(PDO::FETCH_ASSOC);

    $correlativo = ($ultimaFactura && !empty($ultimaFactura['numero_factura']))
        ? intval(explode('-', $ultimaFactura['numero_factura'])[1]) + 1
        : 1;

    return $anioActual . '-' . str_pad($correlativo, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ------------------------------------------------------------
    // COBRAR Y FACTURAR
    // ------------------------------------------------------------
    if ($accion === 'procesar_cobro') {
        $id_pedido = intval($_POST['id_pedido'] ?? 0);
        $metodo_pago = $_POST['metodo_pago'] ?? '';

        if (!in_array($metodo_pago, ['Efectivo', 'Tarjeta', 'Efectivo + Tarjeta'], true)) {
            volver_a_caja("Error: método de pago no válido.", "error");
        }

        // El monto se lee de la base de datos (nunca del formulario)
        $stmt_p = $pdo->prepare("SELECT * FROM pedidos WHERE id = ? AND estado = 'Pendiente'");
        $stmt_p->execute([$id_pedido]);
        $pedido = $stmt_p->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) {
            volver_a_caja("Error: el pedido ya no está pendiente de cobro.", "error");
        }
        $total = floatval($pedido['total']);

        $cliente_nombre = trim($_POST['cliente_nombre'] ?? '');
        $cliente_nif = trim($_POST['cliente_nif'] ?? '');
        $tipo_descuento_especial = $_POST['tipo_descuento_especial'] ?? 'ninguno';

        if ($cliente_nombre === '') {
            volver_a_caja("Error: el nombre del cliente es obligatorio.", "error");
        }
        if ($tipo_descuento_especial !== 'ninguno' && $cliente_nif === '') {
            volver_a_caja("Error: El DNI es obligatorio al aplicar un descuento especial.", "error");
        }

        $porcentaje_descuento = 0;
        if ($tipo_descuento_especial === 'tercera_edad') {
            $porcentaje_descuento = 15;
        } elseif ($tipo_descuento_especial === 'cuarta_edad') {
            $porcentaje_descuento = 20;
        } elseif ($tipo_descuento_especial === 'discapacitado') {
            $porcentaje_descuento = 15;
        }

        $base_sin_impuesto = $total > 0 ? $total / 1.15 : 0;
        $descuento = round($base_sin_impuesto * ($porcentaje_descuento / 100), 2);
        $importe_final = round(max(0, $total - $descuento), 2);

        // Montos según el método de pago
        $efectivo_recibido = round(max(0, floatval($_POST['efectivo_recibido'] ?? 0)), 2);
        $cambio = 0.0;
        $monto_tarjeta = 0.0;

        if ($metodo_pago === 'Efectivo') {
            if ($efectivo_recibido < $importe_final) {
                volver_a_caja("Error: Monto insuficiente. Faltan LPS " . number_format($importe_final - $efectivo_recibido, 2) . ".", "error");
            }
            $cambio = round($efectivo_recibido - $importe_final, 2);
        } elseif ($metodo_pago === 'Efectivo + Tarjeta') {
            if ($efectivo_recibido <= 0 || $efectivo_recibido >= $importe_final) {
                volver_a_caja("Error: en pago mixto el efectivo debe ser mayor a 0 y menor al total. El resto se cobra con tarjeta.", "error");
            }
            $monto_tarjeta = round($importe_final - $efectivo_recibido, 2);
        } else { // Tarjeta
            $efectivo_recibido = 0.0;
            $monto_tarjeta = $importe_final;
        }

        // Solo se guarda una referencia mínima del cobro; no se registra al titular.
        $referencia_tarjeta = '';
        if ($metodo_pago !== 'Efectivo') {
            $ultimos4 = preg_replace('/\D/', '', $_POST['tarjeta_ultimos4'] ?? '');
            $autorizacion = trim($_POST['tarjeta_autorizacion'] ?? '');

            if (strlen($ultimos4) !== 4) {
                volver_a_caja("Error: indica los últimos 4 dígitos de la tarjeta.", "error");
            }
            $referencia_tarjeta = "Tarj: **** **** **** $ultimos4 | Cargo: LPS " . number_format($monto_tarjeta, 2);
            if ($autorizacion !== '') {
                $referencia_tarjeta .= " | Aut: $autorizacion";
            }
        }

        try {
            $pdo->beginTransaction();

            $numero_factura = generarNumeroFactura($pdo);
            $fecha_actual = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("UPDATE pedidos SET
                estado = 'Completado', cliente = ?, cliente_nif = ?, numero_factura = ?,
                metodo_pago = ?, efectivo_recibido = ?, cambio = ?, referencia_tarjeta = ?,
                descuento = ?, total = ?, fecha = ?
                WHERE id = ? AND estado = 'Pendiente'");
            $stmt->execute([
                $cliente_nombre, $cliente_nif, $numero_factura,
                $metodo_pago, $efectivo_recibido, $cambio, $referencia_tarjeta,
                $descuento, $importe_final, $fecha_actual, $id_pedido
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception("El pedido ya fue procesado por otra persona.");
            }

            $pdo->commit();

            volver_a_caja(
                "Factura <strong>" . htmlspecialchars($numero_factura) . "</strong> emitida y enviada a la cocina correctamente. "
                . "<a href=\"ticket.php?id=$id_pedido&print=1\" target=\"_blank\" style=\"font-weight:700; text-decoration:underline;\">Imprimir ticket</a>",
                "success"
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            volver_a_caja("No se pudo procesar el cobro. Revisa el pedido e intenta de nuevo.", "error");
        }
    }

    // ------------------------------------------------------------
    // ANULAR PEDIDO
    // ------------------------------------------------------------
    if ($accion === 'anular_pedido') {
        $id_pedido = intval($_POST['id_pedido'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');

        if (strlen($motivo) < 3) {
            volver_a_caja("Error: escribe el motivo de la anulación.", "error");
        }

        $stmt_p = $pdo->prepare("SELECT id, estado FROM pedidos WHERE id = ?");
        $stmt_p->execute([$id_pedido]);
        $pedido = $stmt_p->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            volver_a_caja("Error: pedido no encontrado.", "error");
        }
        if ($pedido['estado'] === 'Anulado') {
            volver_a_caja("Ese pedido ya estaba anulado.", "error");
        }
        if ($pedido['estado'] === 'Completado' && rol_actual() !== 'admin') {
            volver_a_caja("Solo el administrador puede anular un pedido que ya fue cobrado.", "error");
        }

        try {
            $stmt = $pdo->prepare("UPDATE pedidos SET estado = 'Anulado', motivo_anulacion = ?, anulado_por = ?, fecha_anulacion = ? WHERE id = ?");
            $stmt->execute([substr($motivo, 0, 255), $_SESSION['usuario'] ?? '', date('Y-m-d H:i:s'), $id_pedido]);
            volver_a_caja("Pedido #$id_pedido anulado.", "success");
        } catch (PDOException $e) {
            volver_a_caja("No se pudo anular el pedido. ¿Ya ejecutaste la migración de la base de datos?", "error");
        }
    }

    header("Location: caja.php");
    exit();
}

// Pedidos: los de HOY y los que siguen pendientes de cobro (de cualquier día).
// El historial completo está en "Reporte del Día".
try {
    $stmt_pedidos = $pdo->query("
        SELECT p.*, c.platillos AS detalle_platillos
        FROM pedidos p
        LEFT JOIN pedidos_chef c ON c.pedido_id = p.id
        WHERE DATE(p.fecha) = CURDATE() OR p.estado = 'Pendiente'
        ORDER BY FIELD(p.estado, 'Pendiente', 'Completado', 'Anulado'), p.id DESC
    ");
    $pedidos = $stmt_pedidos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pedidos = [];
}
$es_admin = (rol_actual() === 'admin');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Caja y Facturación</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include 'header.php'; ?>

    <div class="caja-page-wrapper">
        <div class="caja-header">
            <h1><i class="fa-solid fa-cash-register"></i> Panel de Caja</h1>
            <p>Control de cobros, descuentos especiales automáticos, datos de tarjeta y facturación.</p>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert-custom <?php echo $tipo_mensaje; ?>">
                <i class="fa-solid <?php echo ($tipo_mensaje === 'error') ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                <span><?php echo $mensaje; ?></span>
            </div>
        <?php endif; ?>

        <div class="pedidos-card">
            <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-list-check" style="color: #059669;"></i> Pedidos Registrados
            </h3>

            <div class="table-container">
                <?php if (count($pedidos) > 0): ?>
                    <table class="caja-table">
                        <thead>
                            <tr>
                                <th>Factura / Ticket</th>
                                <th>Cliente</th>
                                <th>Mesa / Tipo</th>
                                <th>Total Final</th>
                                <th>Estado</th>
                                <th>Método Pago</th>
                                <th>Fecha / Hora</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidos as $ped): ?>
                                <?php $anulado = ($ped['estado'] === 'Anulado'); ?>
                                <tr<?php echo $anulado ? ' style="opacity: .6;"' : ''; ?>>
                                    <td>
                                        <strong>#<?php echo $ped['id']; ?></strong><br>
                                        <span style="font-size: 11.5px; color: var(--primary-color); font-weight: 600;"><?php echo htmlspecialchars($ped['numero_factura'] ?? '') !== '' ? htmlspecialchars($ped['numero_factura']) : 'Sin emitir'; ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($ped['cliente']); ?><br>
                                        <span style="font-size: 11px; color: var(--text-muted);"><?php echo !empty($ped['cliente_nif']) ? 'DNI: ' . htmlspecialchars($ped['cliente_nif']) : 'DNI no requerido'; ?></span>
                                        <?php if (($ped['tipo_servicio'] ?? '') === 'domicilio' && !empty($ped['telefono_cliente'])): ?>
                                            <br><span style="font-size: 11px; color: var(--text-muted);">Tel: <?php echo htmlspecialchars($ped['telefono_cliente']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($ped['tipo_servicio'] ?? '') === 'domicilio'): ?>
                                            <span class="badge pendiente"><i class="fa-solid fa-truck"></i> Domicilio</span>
                                            <?php if (!empty($ped['direccion_entrega'])): ?>
                                                <span style="display: block; max-width: 220px; margin-top: 4px; font-size: 11px; color: var(--text-muted); white-space: normal;"><?php echo htmlspecialchars($ped['direccion_entrega']); ?></span>
                                            <?php endif; ?>
                                        <?php elseif (($ped['tipo_servicio'] ?? '') === 'comer_aqui' || !empty($ped['numero_mesa'])): ?>
                                            <span class="badge pendiente"><i class="fa-solid fa-chair"></i> Mesa <?php echo intval($ped['numero_mesa']); ?></span>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: var(--text-muted);">Para llevar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong style="color: var(--primary-color);<?php echo $anulado ? ' text-decoration: line-through;' : ''; ?>">LPS <?php echo number_format($ped['total'], 2); ?></strong></td>
                                    <td>
                                        <?php if ($ped['estado'] == 'Pendiente'): ?>
                                            <span class="badge pendiente"><i class="fa-solid fa-clock"></i> Pendiente</span>
                                        <?php elseif ($anulado): ?>
                                            <span class="badge" style="background: #fee2e2; color: #b91c1c;" title="<?php echo htmlspecialchars($ped['motivo_anulacion'] ?? ''); ?>"><i class="fa-solid fa-ban"></i> Anulado</span>
                                        <?php else: ?>
                                            <span class="badge completado"><i class="fa-solid fa-check"></i> Completado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($ped['metodo_pago'])): ?>
                                            <span style="font-size: 12.5px; font-weight: 600; color: #047857;"><?php echo htmlspecialchars($ped['metodo_pago']); ?></span>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: var(--text-muted);">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($ped['fecha'])); ?></td>
                                    <td>
                                        <?php if ($ped['estado'] == 'Pendiente'): ?>
                                            <button type="button" class="btn-accion" onclick="abrirModalCobro(<?php echo $ped['id']; ?>, <?php echo floatval($ped['total']); ?>, <?php echo htmlspecialchars(json_encode($ped['cliente']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($ped['detalle_platillos'] ?? ''), ENT_QUOTES); ?>)">
                                                <i class="fa-solid fa-file-invoice-dollar"></i> Cobrar y Facturar
                                            </button>
                                            <button type="button" onclick="abrirModalAnular(<?php echo $ped['id']; ?>)" style="margin-top: 5px; padding: 6px 10px; border: 1px solid #b91c1c; background: #fff; color: #b91c1c; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                                <i class="fa-solid fa-ban"></i> Anular
                                            </button>
                                        <?php elseif ($ped['estado'] == 'Completado'): ?>
                                            <a href="ticket.php?id=<?php echo $ped['id']; ?>" target="_blank" style="font-size: 12.5px; font-weight: 600; color: #047857; text-decoration: none;">
                                                <i class="fa-solid fa-print"></i> Ticket
                                            </a>
                                            <?php if ($es_admin): ?>
                                                <br>
                                                <button type="button" onclick="abrirModalAnular(<?php echo $ped['id']; ?>)" style="margin-top: 5px; padding: 4px 9px; border: 1px solid #b91c1c; background: #fff; color: #b91c1c; border-radius: 8px; cursor: pointer; font-size: 11.5px; font-weight: 600;">
                                                    Anular
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: var(--text-muted);">Anulado</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-receipt fa-3x" style="margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p style="margin: 0; font-size: 14px;">No hay pedidos de hoy ni pendientes de cobro.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL DE COBRO Y FACTURACIÓN -->
    <div id="modalCobro" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cobrar Pedido <span id="modalIdPedidoText"></span></h3>
                <button type="button" class="close-modal" onclick="cerrarModalCobro()">&times;</button>
            </div>
            
            <form method="POST" action="caja.php" onsubmit="return validarFormularioCobro()">
                <input type="hidden" name="accion" value="procesar_cobro">
                <input type="hidden" name="id_pedido" id="inputModalIdPedido">

                <div style="background: #fdf8f6; border: 1px solid #fde68a; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                    <span class="caja-order-details-label" style="font-size: 11px; color: #b45309; text-transform: uppercase; font-weight: bold; display: block; margin-bottom: 3px;">Lo que ordenó el cliente:</span>
                    <strong id="modalDetallePlatilloText" style="color: #1f2937; font-size: 14.5px;">Detalles no disponibles</strong>
                </div>

                <div class="caja-payment-summary" style="background: #f8fafc; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 13.5px;">
                    <div>Monto Inicial del Pedido: <strong style="color: var(--primary-color);" id="modalTotalText"></strong></div>
                    <div class="caja-payment-summary-note" style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">Número de factura y fecha/hora automáticos al procesar.</div>
                </div>

                <div class="form-group">
                    <label for="cliente_nombre">Nombre del Cliente <span style="color: red;">*</span>:</label>
                    <input type="text" name="cliente_nombre" id="cliente_nombre" class="form-control" required>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="cliente_nif" id="labelDni">DNI / NIF (Opcional):</label>
                        <input type="text" name="cliente_nif" id="cliente_nif" class="form-control" placeholder="Ej. 12345678X">
                    </div>
                    <div class="form-group">
                        <label for="tipo_descuento_especial">Descuento Especial:</label>
                        <select name="tipo_descuento_especial" id="tipo_descuento_especial" class="form-control" onchange="aplicarDescuentoEspecial()">
                            <option value="ninguno">Ninguno / General (0%)</option>
                            <option value="tercera_edad">Tercera Edad (15%)</option>
                            <option value="cuarta_edad">Cuarta Edad (20%)</option>
                            <option value="discapacitado">Discapacitado (15%)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="descuento">Monto de Descuento Aplicado (LPS):</label>
                    <input type="number" step="0.01" id="descuento" class="form-control" value="0.00" readonly style="background-color: #f1f5f9; cursor: not-allowed;">
                </div>

                <div class="caja-total-summary" style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px; border-radius: 6px; margin: 10px 0; font-size: 13.5px;">
                    <div>Total Final a Pagar: <strong id="textoImporteFinal" style="color: #047857; font-size: 16px;">LPS 0.00</strong></div>
                </div>

                <div class="form-group">
                    <label for="metodo_pago">Método de Pago:</label>
                    <select name="metodo_pago" id="metodo_pago" class="form-control" required onchange="cambiarMetodoPago()">
                        <option value="Efectivo">Efectivo</option>
                        <option value="Tarjeta">Tarjeta</option>
                        <option value="Efectivo + Tarjeta">Efectivo + Tarjeta (Mixto)</option>
                    </select>
                </div>

                <div id="seccionEfectivo" class="form-group">
                    <label for="efectivo_recibido">Efectivo Recibido ($):</label>
                    <input type="number" step="0.01" min="0" name="efectivo_recibido" id="efectivo_recibido" class="form-control" placeholder="0.00" oninput="calcularCambio()">
                    <div style="font-size: 12px; margin-top: 4px;" id="contenedorCambioText"></div>
                </div>

                <div id="seccionTarjeta" class="card-box" style="display: none;">
                    <h4 class="caja-card-summary-heading" style="margin: 0 0 4px 0; font-size: 13.5px; color: var(--primary-color);"><i class="fa-solid fa-credit-card"></i> Datos del cobro con tarjeta</h4>
                    <p class="caja-card-summary-note" style="margin: 0 0 10px 0; font-size: 11.5px; color: var(--text-muted);">Cobra la tarjeta en el datáfono. Aquí solo se registra la referencia; nunca se guarda el número completo, el CVV ni el PIN.</p>

                    <div class="grid-2">
                        <div class="form-group">
                            <label for="tarjeta_ultimos4">Últimos 4 dígitos:</label>
                            <input type="text" name="tarjeta_ultimos4" id="tarjeta_ultimos4" class="form-control" placeholder="1234" maxlength="4" inputmode="numeric" pattern="\d{4}">
                        </div>
                        <div class="form-group">
                            <label for="tarjeta_autorizacion">N.º de autorización (opcional):</label>
                            <input type="text" name="tarjeta_autorizacion" id="tarjeta_autorizacion" class="form-control" placeholder="Del voucher" maxlength="30">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-accion" style="width: 100%; justify-content: center; padding: 10px; margin-top: 15px;">
                    <i class="fa-solid fa-file-circle-check"></i> Confirmar Cobro y Facturar
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL PARA ANULAR UN PEDIDO -->
    <div id="modalAnular" class="modal-overlay">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h3>Anular Pedido <span id="anularIdText"></span></h3>
                <button type="button" class="close-modal" onclick="cerrarModalAnular()">&times;</button>
            </div>
            <form method="POST" action="caja.php">
                <input type="hidden" name="accion" value="anular_pedido">
                <input type="hidden" name="id_pedido" id="anularIdInput">
                <p class="caja-cancel-warning" style="font-size: 13px; color: var(--text-muted); margin-top: 0;">Esta acción no se puede deshacer. Indica el motivo:</p>
                <div class="form-group">
                    <textarea name="motivo" id="anularMotivo" class="form-control" rows="3" required minlength="3" maxlength="200" placeholder="Ej. El cliente canceló el pedido"></textarea>
                </div>
                <button type="submit" class="btn-accion" style="width: 100%; justify-content: center; padding: 10px; margin-top: 10px; background: #b91c1c;">
                    <i class="fa-solid fa-ban"></i> Confirmar anulación
                </button>
            </form>
        </div>
    </div>

    <script>
        let montoTotalActual = 0;

        function importeFinal() {
            const descuento = parseFloat(document.getElementById('descuento').value) || 0;
            return Math.max(0, montoTotalActual - descuento);
        }

        function abrirModalCobro(id, total, cliente, detallePlatillo) {
            montoTotalActual = total;
            document.getElementById('inputModalIdPedido').value = id;
            document.getElementById('modalIdPedidoText').innerText = '#' + id;
            document.getElementById('cliente_nombre').value = cliente;
            document.getElementById('modalTotalText').innerText = 'LPS ' + total.toFixed(2);
            document.getElementById('modalDetallePlatilloText').innerText = detallePlatillo || 'Detalles no disponibles';
            document.getElementById('cliente_nif').value = '';
            document.getElementById('tipo_descuento_especial').value = 'ninguno';
            document.getElementById('descuento').value = '0.00';
            document.getElementById('efectivo_recibido').value = '';
            document.getElementById('tarjeta_ultimos4').value = '';
            document.getElementById('tarjeta_autorizacion').value = '';
            document.getElementById('metodo_pago').value = 'Efectivo';
            aplicarDescuentoEspecial();
            cambiarMetodoPago();
            document.getElementById('modalCobro').style.display = 'flex';
        }

        function cerrarModalCobro() {
            document.getElementById('modalCobro').style.display = 'none';
        }

        function abrirModalAnular(id) {
            document.getElementById('anularIdInput').value = id;
            document.getElementById('anularIdText').innerText = '#' + id;
            document.getElementById('anularMotivo').value = '';
            document.getElementById('modalAnular').style.display = 'flex';
        }

        function cerrarModalAnular() {
            document.getElementById('modalAnular').style.display = 'none';
        }

        function aplicarDescuentoEspecial() {
            const tipo = document.getElementById('tipo_descuento_especial').value;
            const dniInput = document.getElementById('cliente_nif');
            const labelDni = document.getElementById('labelDni');
            let porcentaje = 0;

            if (tipo !== 'ninguno') {
                dniInput.required = true;
                labelDni.innerHTML = 'DNI / NIF <span style="color: red;">*</span> (Obligatorio por descuento):';
                if (tipo === 'tercera_edad') porcentaje = 15;
                else if (tipo === 'cuarta_edad') porcentaje = 20;
                else if (tipo === 'discapacitado') porcentaje = 15;
            } else {
                dniInput.required = false;
                labelDni.innerHTML = 'DNI / NIF (Opcional):';
            }

            const baseSinImpuesto = montoTotalActual > 0 ? montoTotalActual / 1.15 : 0;
            const descuentoCalculado = Math.round(baseSinImpuesto * (porcentaje / 100) * 100) / 100;
            document.getElementById('descuento').value = descuentoCalculado.toFixed(2);
            document.getElementById('textoImporteFinal').innerText = 'LPS ' + importeFinal().toFixed(2);
            calcularCambio();
        }

        function cambiarMetodoPago() {
            const metodo = document.getElementById('metodo_pago').value;
            const usaTarjeta = (metodo !== 'Efectivo');

            document.getElementById('tarjeta_ultimos4').required = usaTarjeta;
            document.getElementById('efectivo_recibido').required = (metodo !== 'Tarjeta');
            document.getElementById('seccionEfectivo').style.display = (metodo === 'Tarjeta') ? 'none' : 'block';
            document.getElementById('seccionTarjeta').style.display = usaTarjeta ? 'block' : 'none';
            calcularCambio();
        }

        function calcularCambio() {
            const metodo = document.getElementById('metodo_pago').value;
            const recibido = parseFloat(document.getElementById('efectivo_recibido').value) || 0;
            const total = importeFinal();
            const cont = document.getElementById('contenedorCambioText');

            if (metodo === 'Efectivo') {
                const cambio = recibido - total;
                if (cambio < -0.004) {
                    cont.innerHTML = '<span style="color: #dc2626; font-weight: bold;"><i class="fa-solid fa-triangle-exclamation"></i> Monto insuficiente. Faltan LPS ' + Math.abs(cambio).toFixed(2) + '</span>';
                } else {
                    cont.innerHTML = 'Cambio: <strong style="color: #047857;">LPS ' + Math.max(0, cambio).toFixed(2) + '</strong>';
                }
            } else if (metodo === 'Efectivo + Tarjeta') {
                if (recibido <= 0 || recibido >= total) {
                    cont.innerHTML = '<span style="color: #dc2626; font-weight: bold;">En pago mixto el efectivo debe ser mayor a 0 y menor al total.</span>';
                } else {
                    cont.innerHTML = 'Resto a cobrar con tarjeta: <strong style="color: #047857;">LPS ' + (total - recibido).toFixed(2) + '</strong>';
                }
            } else {
                cont.innerHTML = '';
            }
        }

        function validarFormularioCobro() {
            const metodo = document.getElementById('metodo_pago').value;
            const recibido = parseFloat(document.getElementById('efectivo_recibido').value) || 0;
            const total = importeFinal();

            if (metodo === 'Efectivo' && recibido < total - 0.004) {
                alert("Monto insuficiente. El efectivo recibido es menor al total a pagar.");
                return false;
            }
            if (metodo === 'Efectivo + Tarjeta' && (recibido <= 0 || recibido >= total)) {
                alert("En pago mixto el efectivo debe ser mayor a 0 y menor al total. El resto se cobra con tarjeta.");
                return false;
            }
            if (metodo !== 'Efectivo' && !/^\d{4}$/.test(document.getElementById('tarjeta_ultimos4').value.trim())) {
                alert("Indica los últimos 4 dígitos de la tarjeta.");
                return false;
            }
            return true;
        }
    </script>

</body>
</html>
