<?php
include 'auth.php';
requerir_rol(['admin', 'cajero', 'gerente_general']);
include 'conexion.php';

$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    http_response_code(404);
    exit('Pedido no encontrado.');
}

// Platillos del pedido (detalle nuevo o, en pedidos antiguos, el texto de cocina)
$items = [];
$stmt_d = $pdo->prepare("SELECT nombre, precio_unitario, cantidad, especificaciones FROM detalle_pedido WHERE pedido_id = ? ORDER BY id");
$stmt_d->execute([$id]);
$items = $stmt_d->fetchAll(PDO::FETCH_ASSOC);

$texto_antiguo = '';
if (count($items) === 0) {
    $stmt_c = $pdo->prepare("SELECT platillos FROM pedidos_chef WHERE pedido_id = ? LIMIT 1");
    $stmt_c->execute([$id]);
    $texto_antiguo = (string)$stmt_c->fetchColumn();
}

// Importes: "total" ya trae el descuento aplicado
$descuento = floatval($p['descuento'] ?? 0);
$total_final = floatval($p['total']);
$total_sin_descuento = $total_final + $descuento;
$subtotal = $total_sin_descuento / 1.15;
$impuesto = $total_sin_descuento - $subtotal;

$estado = $p['estado'];
$metodo = $p['metodo_pago'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $id; ?> - Como en Casa</title>
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.45; color: #29231f; background: #f3f4f6; margin: 0; padding: 24px 12px; }
        .ticket { width: min(100%, 360px); margin: 0 auto; background: #fff; padding: 20px 18px; border-radius: 8px; box-shadow: 0 4px 16px rgba(41,35,31,.08); }
        .centro { text-align: center; }
        .marca { color: #5b514a; font-size: 9px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { color: #29231f; font-size: 19px; letter-spacing: .02em; margin: 4px 0 2px; }
        .subtitulo { color: #76665b; font-size: 11px; }
        .tipo-documento { display: inline-block; margin-top: 9px; padding: 3px 8px; color: #29231f; background: transparent; border: 1px solid #bdb5ae; border-radius: 3px; font-size: 9px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        hr { border: 0; border-top: 1px dashed #d8ccc2; margin: 15px 0; }
        .datos { display: grid; gap: 7px; }
        .dato { display: flex; justify-content: space-between; gap: 12px; }
        .dato span:first-child { color: #76665b; }
        .dato strong { text-align: right; overflow-wrap: anywhere; }
        .dato-factura { margin: 2px 0; padding: 5px 0; color: #29231f; background: transparent; border: 0; border-top: 1px solid #d8ccc2; border-bottom: 1px solid #d8ccc2; }
        .dato-factura span:first-child { color: #29231f; font-weight: 700; }
        .dato-factura strong { font-size: 13px; letter-spacing: .02em; }
        .servicio { margin-top: 3px; padding: 5px 0; color: #29231f; background: transparent; border: 0; border-top: 1px dashed #d8ccc2; border-bottom: 1px dashed #d8ccc2; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 0 0 7px; color: #76665b; font-size: 9px; text-align: left; text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid #eee7e1; }
        td { padding: 8px 0; vertical-align: top; border-bottom: 1px solid #f1ece8; }
        .der { text-align: right; white-space: nowrap; }
        .producto { font-weight: 700; overflow-wrap: anywhere; }
        .detalle-producto { display: block; margin-top: 2px; color: #76665b; font-size: 10px; }
        .totales td { padding: 4px 0; border: 0; }
        .total td { color: #29231f; font-weight: 800; font-size: 14px; border-top: 1px solid #77706a; padding-top: 7px; }
        .pago { padding: 6px 0; background: transparent; border-radius: 0; }
        .pago .dato + .dato { margin-top: 4px; }
        .anulado { border: 2px solid #b91c1c; color: #b91c1c; text-align: center; font-weight: bold; font-size: 16px; padding: 7px; margin: 12px 0; border-radius: 8px; }
        .acciones { text-align: center; margin: 16px auto 0; width: min(100%, 360px); }
        .acciones button { font-family: Arial, sans-serif; padding: 11px 18px; border: 0; border-radius: 9px; cursor: pointer; font-weight: 700; margin: 0 4px; }
        .btn-imprimir { background: #b45309; color: #fff; }
        .btn-cerrar { background: #e5e7eb; color: #29231f; }
        .gracias { color: #76665b; font-size: 11px; }
        .logo { display: block; width: 64px; max-height: 54px; object-fit: contain; margin: 0 auto 5px; }
        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { width: 74mm; margin: 0 auto; padding: 0; background: #fff; }
            .ticket {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 3mm;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                font-size: 12px;
            }
            .acciones { display: none; }
            tr, .pago, .servicio, .dato-factura { break-inside: avoid; }
        }
    </style>
</head>
<body>

    <div class="ticket">
        <div class="centro">
            <img class="logo" src="img/logo_claro.png" alt="Logo Como en Casa">
            <div class="marca">Sabor casero, hecho con cariño</div>
            <h1>COMO EN CASA</h1>
            <div class="subtitulo">Comprobante de venta</div>
            <span class="tipo-documento"><?php echo $estado === 'Completado' ? 'Factura' : 'Comprobante de pedido'; ?></span>
        </div>
        <hr>

        <?php if ($estado === 'Anulado'): ?>
            <div class="anulado">ANULADO</div>
            <?php if (!empty($p['motivo_anulacion'])): ?>
                <div style="font-size: 12px;">Motivo: <?php echo htmlspecialchars($p['motivo_anulacion']); ?></div>
            <?php endif; ?>
        <?php elseif ($estado !== 'Completado'): ?>
            <div class="anulado" style="color:#92400e; border-color:#92400e;">PENDIENTE DE PAGO</div>
        <?php endif; ?>

        <div class="datos">
            <div class="dato"><span>Ticket</span><strong>#<?php echo $id; ?></strong></div>
            <?php if (!empty($p['numero_factura'])): ?>
                <div class="dato dato-factura"><span>Número de factura</span><strong><?php echo htmlspecialchars($p['numero_factura']); ?></strong></div>
            <?php endif; ?>
            <div class="dato"><span>Fecha</span><strong><?php echo date('d/m/Y H:i', strtotime($p['fecha'])); ?></strong></div>
            <div class="dato"><span>Cliente</span><strong><?php echo htmlspecialchars($p['cliente']); ?></strong></div>
            <?php if (!empty($p['cliente_nif'])): ?>
                <div class="dato"><span>DNI/NIF</span><strong><?php echo htmlspecialchars($p['cliente_nif']); ?></strong></div>
            <?php endif; ?>
            <?php if (($p['tipo_servicio'] ?? '') === 'domicilio' && !empty($p['telefono_cliente'])): ?>
                <div class="dato"><span>Teléfono</span><strong><?php echo htmlspecialchars($p['telefono_cliente']); ?></strong></div>
            <?php endif; ?>
            <div class="servicio">
                <?php
                    if (($p['tipo_servicio'] ?? '') === 'domicilio') {
                        echo 'Entrega a domicilio';
                    } elseif (($p['tipo_servicio'] ?? '') === 'comer_aqui' || !empty($p['numero_mesa'])) {
                        echo 'Comer aquí · Mesa ' . intval($p['numero_mesa']);
                    } else {
                        echo 'Pedido para llevar';
                    }
                ?>
            </div>
            <?php if (($p['tipo_servicio'] ?? '') === 'domicilio' && !empty($p['direccion_entrega'])): ?>
                <div class="dato"><span>Dirección</span><strong><?php echo nl2br(htmlspecialchars($p['direccion_entrega'])); ?></strong></div>
            <?php endif; ?>
        </div>
        <hr>

        <table>
            <?php if (count($items) > 0): ?>
                <thead>
                    <tr><th>Descripción</th><th class="der">Importe</th></tr>
                </thead>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <span class="producto"><?php echo htmlspecialchars($it['nombre']); ?></span>
                            <span class="detalle-producto"><?php echo intval($it['cantidad']); ?> × LPS <?php echo number_format($it['precio_unitario'], 2); ?> c/u</span>
                            <?php if (!empty($it['especificaciones'])): ?>
                                <span class="detalle-producto"><strong>Indicaciones:</strong> <?php echo nl2br(htmlspecialchars($it['especificaciones'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="der">LPS <?php echo number_format($it['precio_unitario'] * $it['cantidad'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="2"><?php echo nl2br(htmlspecialchars($texto_antiguo !== '' ? $texto_antiguo : 'Detalle no disponible')); ?></td></tr>
            <?php endif; ?>
        </table>
        <hr>

        <table class="totales">
            <tr><td>Subtotal</td><td class="der">LPS <?php echo number_format($subtotal, 2); ?></td></tr>
            <tr><td>Impuesto (15%)</td><td class="der">LPS <?php echo number_format($impuesto, 2); ?></td></tr>
            <?php if ($descuento > 0): ?>
                <tr><td>Descuento</td><td class="der">-LPS <?php echo number_format($descuento, 2); ?></td></tr>
            <?php endif; ?>
            <tr class="total"><td>TOTAL</td><td class="der">LPS <?php echo number_format($total_final, 2); ?></td></tr>
        </table>

        <?php if ($estado === 'Completado' && $metodo !== ''): ?>
            <hr>
            <div class="pago">
                <div class="dato"><span>Método de pago</span><strong><?php echo htmlspecialchars($metodo); ?></strong></div>
            <?php if ($metodo === 'Efectivo'): ?>
                <div class="dato"><span>Efectivo recibido</span><strong>LPS <?php echo number_format($p['efectivo_recibido'] ?? 0, 2); ?></strong></div>
                <div class="dato"><span>Cambio</span><strong>LPS <?php echo number_format($p['cambio'] ?? 0, 2); ?></strong></div>
            <?php elseif ($metodo === 'Efectivo + Tarjeta'): ?>
                <div class="dato"><span>Parte en efectivo</span><strong>LPS <?php echo number_format($p['efectivo_recibido'] ?? 0, 2); ?></strong></div>
            <?php endif; ?>
            </div>
        <?php endif; ?>

        <hr>
        <div class="centro gracias">¡Gracias por su visita!<br>Esperamos atenderle pronto.</div>
    </div>

    <div class="acciones">
        <button type="button" class="btn-imprimir" onclick="window.print()">Imprimir / Guardar PDF</button>
        <button type="button" class="btn-cerrar" onclick="window.close()">Cerrar</button>
    </div>

    <?php if (!empty($_GET['print'])): ?>
        <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>

    <script>
        window.comoEnCasaFirmaSesionAnterior = <?php
        echo json_encode($sesion_pestana_legado_firma, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;
    </script>
    <script src="sesiones_pestanas.js?v=3"></script>
</body>
</html>
