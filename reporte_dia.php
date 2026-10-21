<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';
include 'funciones.php';

// Rango de fechas (por defecto, hoy)
$hoy = date('Y-m-d');
$desde = fecha_valida($_GET['desde'] ?? ($_GET['fecha'] ?? ''), $hoy);
$hasta = fecha_valida($_GET['hasta'] ?? ($_GET['fecha'] ?? ''), $hoy);
if ($desde > $hasta) {
    list($desde, $hasta) = [$hasta, $desde];
}

$ventas = [];
$por_metodo = [];
$mas_vendidos = [];
$total_completadas = 0;
$ingreso_total = 0;
$total_pendientes = 0;
$monto_pendientes = 0;
$total_anulados = 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE DATE(fecha) BETWEEN ? AND ? ORDER BY fecha DESC");
    $stmt->execute([$desde, $hasta]);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ventas as $v) {
        if ($v['estado'] === 'Completado') {
            $total_completadas++;
            $ingreso_total += floatval($v['total']);
            $metodo = !empty($v['metodo_pago']) ? $v['metodo_pago'] : 'Sin método';
            if (!isset($por_metodo[$metodo])) {
                $por_metodo[$metodo] = ['n' => 0, 'monto' => 0];
            }
            $por_metodo[$metodo]['n']++;
            $por_metodo[$metodo]['monto'] += floatval($v['total']);
        } elseif ($v['estado'] === 'Anulado') {
            $total_anulados++;
        } else {
            $total_pendientes++;
            $monto_pendientes += floatval($v['total']);
        }
    }

    $stmt_top = $pdo->prepare("SELECT d.nombre, SUM(d.cantidad) AS unidades, SUM(d.cantidad * d.precio_unitario) AS monto
                               FROM detalle_pedido d
                               JOIN pedidos p ON p.id = d.pedido_id
                               WHERE p.estado = 'Completado' AND DATE(p.fecha) BETWEEN ? AND ?
                               GROUP BY d.platillo_id, d.nombre
                               ORDER BY unidades DESC, monto DESC
                               LIMIT 10");
    $stmt_top->execute([$desde, $hasta]);
    $mas_vendidos = $stmt_top->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Si falta la tabla detalle_pedido (migración pendiente) solo se omite el ranking
}

$etiqueta_rango = ($desde === $hasta)
    ? date('d/m/Y', strtotime($desde))
    : date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Ventas - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include('header.php'); ?>

    <div class="report-wrapper">
        <div class="report-header">
            <div>
                <h1><i class="fa-solid fa-chart-line" style="color: #3b82f6;"></i> Corte de Caja y Ventas</h1>
                <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 14px;">Monitorea los ingresos y transacciones del <?php echo $etiqueta_rango; ?>.</p>
            </div>

            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <form method="GET" class="filter-form" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <label style="font-size: 13px;">Desde <input type="date" name="desde" value="<?php echo $desde; ?>"></label>
                    <label style="font-size: 13px;">Hasta <input type="date" name="hasta" value="<?php echo $hasta; ?>"></label>
                    <button type="submit" class="btn-filtrar"><i class="fa-solid fa-filter"></i> Consultar</button>
                </form>

                <a href="exportar_excel.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>" class="btn-excel">
                    <i class="fa-solid fa-file-excel"></i> Excel
                </a>
            </div>
        </div>

        <!-- Tarjetas de resumen -->
        <div class="metrics-grid">
            <div class="metric-card">
                <h3>Ventas Completadas (Tickets)</h3>
                <p class="value"><?php echo $total_completadas; ?></p>
            </div>
            <div class="metric-card total">
                <h3>Ingreso Total Vendido</h3>
                <p class="value" style="color: #059669;">LPS <?php echo number_format($ingreso_total, 2); ?></p>
            </div>
            <div class="metric-card">
                <h3>Pendientes de Cobro</h3>
                <p class="value"><?php echo $total_pendientes; ?> <span style="font-size: 13px; color: #6b7280; font-weight: 500;">(LPS <?php echo number_format($monto_pendientes, 2); ?>)</span></p>
            </div>
            <div class="metric-card">
                <h3>Pedidos Anulados</h3>
                <p class="value"><?php echo $total_anulados; ?></p>
            </div>
        </div>

        <!-- Ventas por método de pago -->
        <section class="report-panel">
            <h3 class="report-panel-title">
                <i class="fa-solid fa-wallet"></i> Ventas por método de pago
            </h3>
            <?php if (count($por_metodo) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Método</th><th>Tickets</th><th style="text-align: right;">Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($por_metodo as $metodo => $d): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($metodo); ?></td>
                                    <td><?php echo $d['n']; ?></td>
                                    <td style="text-align: right; font-weight: 600;">LPS <?php echo number_format($d['monto'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="report-empty-state">Sin ventas completadas en el rango seleccionado.</p>
            <?php endif; ?>
        </section>

        <!-- Platillos más vendidos -->
        <section class="report-panel">
            <h3 class="report-panel-title">
                <i class="fa-solid fa-ranking-star"></i> Platillos más vendidos
            </h3>
            <?php if (count($mas_vendidos) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>#</th><th>Platillo</th><th>Unidades</th><th style="text-align: right;">Monto (sin descuentos)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mas_vendidos as $i => $m): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($m['nombre']); ?></td>
                                    <td><?php echo intval($m['unidades']); ?></td>
                                    <td style="text-align: right; font-weight: 600;">LPS <?php echo number_format($m['monto'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="report-empty-state">Sin datos en este rango. El ranking incluye los pedidos hechos después de activar el detalle de pedidos.</p>
            <?php endif; ?>
        </section>

        <!-- Detalle de transacciones -->
        <section class="report-panel">
            <h3 class="report-panel-title">
                <i class="fa-solid fa-receipt"></i> Detalle de Facturas del <?php echo $etiqueta_rango; ?>
            </h3>
            
            <?php if (count($ventas) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Factura / Ticket</th>
                                <th>Cliente</th>
                                <th>Método Pago</th>
                                <th>Estado</th>
                                <th>Fecha / Hora</th>
                                <th style="text-align: right;">Total Final</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($ventas as $row): ?>
                                <tr>
                                    <td><strong>#<?php echo $row['id']; ?></strong> <span style="font-size: 11px; color: #6b7280; display: block;"><?php echo htmlspecialchars($row['numero_factura'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['cliente']); ?> <span style="font-size: 11px; color: #6b7280; display: block;">NIF: <?php echo htmlspecialchars($row['cliente_nif'] ?? '') !== '' ? htmlspecialchars($row['cliente_nif']) : 'N/R'; ?></span></td>
                                    <td><?php echo htmlspecialchars($row['metodo_pago'] ?? '') !== '' ? htmlspecialchars($row['metodo_pago']) : '-'; ?></td>
                                    <td>
                                        <?php if ($row['estado'] === 'Completado'): ?>
                                            <span class="badge-estado badge-completado">Completado</span>
                                        <?php elseif ($row['estado'] === 'Anulado'): ?>
                                            <span class="badge-estado" style="background: #fee2e2; color: #b91c1c;" title="<?php echo htmlspecialchars($row['motivo_anulacion'] ?? ''); ?>">Anulado</span>
                                        <?php else: ?>
                                            <span class="badge-estado badge-pendiente"><?php echo htmlspecialchars($row['estado']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($row['fecha'])); ?></td>
                                    <td style="text-align: right; font-weight: 600; <?php echo ($row['estado'] === 'Anulado') ? 'color: #9ca3af; text-decoration: line-through;' : 'color: #059669;'; ?>">LPS <?php echo number_format($row['total'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="report-empty-state report-empty-state-large">
                    <i class="fa-regular fa-folder-open fa-3x" style="margin-bottom: 10px;"></i>
                    <p style="margin: 0; font-size: 15px;">No se registraron ventas para las fechas seleccionadas.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        window.comoEnCasaFirmaSesionAnterior = <?php
        echo json_encode($sesion_pestana_legado_firma, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;
    </script>
    <script src="sesiones_pestanas.js?v=3"></script>
</body>
</html>