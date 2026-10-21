<?php
include 'auth.php';

if (isset($_SESSION['user_id'], $_SESSION['rol']) && rol_actual() !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mensaje = $_SESSION['mensaje'] ?? '';
$tipo_mensaje = $_SESSION['tipo_mensaje'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']);

$ticket_generado = $_SESSION['ticket_generado'] ?? null;
$nombre_cliente = $_SESSION['nombre_cliente_registrado'] ?? '';
$telefono_cliente = $_SESSION['telefono_cliente_ticket'] ?? '';
$direccion_entrega = $_SESSION['direccion_entrega_ticket'] ?? '';
unset(
    $_SESSION['ticket_generado'],
    $_SESSION['nombre_cliente_registrado'],
    $_SESSION['telefono_cliente_ticket'],
    $_SESSION['direccion_entrega_ticket'],
    $_SESSION['numero_mesa_ticket'],
    $_SESSION['tipo_servicio_ticket']
);

$subtotal = 0;
foreach ($_SESSION['carrito'] ?? [] as $item) {
    $subtotal += floatval($item['precio'] ?? 0) * intval($item['cantidad'] ?? 0);
}
$total = round($subtotal * 1.15, 2);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido a domicilio - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php include 'header.php'; ?>

<main class="kiosco-page-wrapper">
    <section class="kiosco-header">
        <h1><i class="fa-solid fa-truck"></i> Pedido a domicilio</h1>
        <p>Completa tus datos para confirmar la entrega a domicilio.</p>
        <aside class="order-flow-hint order-flow-hint--delivery" aria-label="Cómo agregar platillos al pedido">
            <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>
            <span>
                <strong>¿Aún no eliges tus platillos?</strong>
                <small>Ve al menú y, al terminar tu selección, vuelve aquí para confirmar el domicilio.</small>
            </span>
            <a href="pedir.php?servicio=domicilio"><i class="fa-solid fa-utensils" aria-hidden="true"></i> Ir a Pedir Ya</a>
        </aside>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert-custom <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8'); ?>" role="status">
            <i class="fa-solid <?php echo $tipo_mensaje === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
            <span><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($ticket_generado): ?>
        <section class="ticket-card">
            <i class="fa-solid fa-circle-check fa-3x" style="color: var(--success); margin-bottom: 8px;"></i>
            <h2 style="color: var(--text-main); margin: 4px 0; font-size: 20px;">¡Pedido a domicilio recibido!</h2>
            <p style="font-size: 14px; color: var(--text-muted);">
                Cliente: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($nombre_cliente, ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <p style="font-size: 14px; color: var(--text-muted);">
                Teléfono: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($telefono_cliente, ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <p style="font-size: 14px; color: var(--text-muted);">
                Dirección: <strong style="color: var(--text-main);"><?php echo nl2br(htmlspecialchars($direccion_entrega, ENT_QUOTES, 'UTF-8')); ?></strong>
            </p>
            <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-top: 10px;">Número de pedido</div>
            <div class="ticket-number">#<?php echo intval($ticket_generado); ?></div>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 18px;">El restaurante se comunicará contigo para coordinar la entrega y el pago.</p>
            <a href="pedir.php" class="btn-checkout" style="text-decoration: none;">
                <i class="fa-solid fa-arrow-rotate-left"></i> Hacer otro pedido
            </a>
        </section>
    <?php elseif (empty($_SESSION['carrito'])): ?>
        <section class="empty-cart">
            <i class="fa-solid fa-basket-shopping fa-2x" style="margin-bottom: 8px; color: var(--text-muted);"></i>
            <p>No tienes platillos en tu pedido todavía.</p>
            <a href="pedir.php?servicio=domicilio" class="btn-checkout" style="max-width: 280px; margin: 12px auto 0; text-decoration: none;">
                <i class="fa-solid fa-utensils"></i> Elegir platillos
            </a>
        </section>
    <?php else: ?>
        <div class="summary-sidebar" style="max-width: 760px; margin: 0 auto;">
            <h2 class="summary-title"><i class="fa-solid fa-receipt" style="color: var(--primary-color);"></i> Tu pedido</h2>
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Platillo</th>
                        <th>Cant.</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($_SESSION['carrito'] as $item): ?>
                        <?php $subtotal_item = floatval($item['precio'] ?? 0) * intval($item['cantidad'] ?? 0); ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['nombre'] ?? 'Platillo', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($item['especificaciones'])): ?>
                                    <small class="cart-specifications">Indicaciones: <?php echo htmlspecialchars($item['especificaciones'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo intval($item['cantidad'] ?? 0); ?></td>
                            <td>LPS <?php echo number_format($subtotal_item, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="summary-calc">
                <div class="calc-row">
                    <span>Subtotal:</span>
                    <span>LPS <?php echo number_format($subtotal, 2); ?></span>
                </div>
                <div class="calc-row">
                    <span>Impuestos (15%):</span>
                    <span>LPS <?php echo number_format($total - $subtotal, 2); ?></span>
                </div>
                <div class="summary-total">
                    <span>Total:</span>
                    <span class="monto">LPS <?php echo number_format($total, 2); ?></span>
                </div>
            </div>

            <form method="POST" action="pedir.php" class="checkout-box">
                <input type="hidden" name="accion" value="confirmar_autoservicio">
                <input type="hidden" name="origen_checkout" value="domicilio">
                <input type="hidden" name="tipo_servicio" value="domicilio">

                <div class="form-group">
                    <label for="nombre_cliente">
                        <i class="fa-solid fa-user-pen" style="color: var(--primary-color);"></i> Nombre:
                    </label>
                    <input type="text" name="nombre_cliente" id="nombre_cliente" required maxlength="100" placeholder="Ej. Carlos Mendoza" class="form-input" autocomplete="name">
                </div>
                <div class="form-group">
                    <label for="telefono_cliente">
                        <i class="fa-solid fa-phone" style="color: var(--primary-color);"></i> Teléfono de contacto:
                    </label>
                    <input type="tel" name="telefono_cliente" id="telefono_cliente" required minlength="7" maxlength="20" pattern="[0-9+(). -]{7,20}" placeholder="Ej. 9999-9999" class="form-input" autocomplete="tel">
                </div>
                <div class="form-group">
                    <label for="direccion_entrega">
                        <i class="fa-solid fa-location-dot" style="color: var(--primary-color);"></i> Dirección de entrega:
                    </label>
                    <textarea name="direccion_entrega" id="direccion_entrega" required maxlength="255" rows="3" class="form-input" placeholder="Colonia, calle, número de casa y referencias"></textarea>
                </div>
                <button type="submit" class="btn-checkout">
                    <i class="fa-solid fa-truck"></i> Confirmar pedido a domicilio
                </button>
            </form>
        </div>
    <?php endif; ?>
</main>

</body>
</html>
