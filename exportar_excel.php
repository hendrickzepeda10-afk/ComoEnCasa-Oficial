<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';
include 'funciones.php';

$hoy = date('Y-m-d');
$desde = fecha_valida($_GET['desde'] ?? ($_GET['fecha'] ?? ''), $hoy);
$hasta = fecha_valida($_GET['hasta'] ?? ($_GET['fecha'] ?? ''), $hoy);
if ($desde > $hasta) {
    list($desde, $hasta) = [$hasta, $desde];
}

try {
    $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE DATE(fecha) BETWEEN ? AND ? ORDER BY fecha DESC");
    $stmt->execute([$desde, $hasta]);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ventas = [];
}

$total_transacciones = count($ventas);
$ingreso_total = 0;
$por_metodo = [];
foreach ($ventas as $v) {
    if ($v['estado'] === 'Completado') {
        $ingreso_total += floatval($v['total']);
        $metodo = !empty($v['metodo_pago']) ? $v['metodo_pago'] : 'Sin método';
        $por_metodo[$metodo] = ($por_metodo[$metodo] ?? 0) + floatval($v['total']);
    }
}

$nombre_archivo = "Corte_Caja_" . $desde . ($desde !== $hasta ? "_al_" . $hasta : "") . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$nombre_archivo\"");
header("Pragma: no-cache");
header("Expires: 0");

$rango = ($desde === $hasta)
    ? date('d/m/Y', strtotime($desde))
    : date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ventas</title>
</head>
<body>

    <table border="1">
        <tr>
            <th colspan="6" style="font-size: 18px; background-color: #065f46; color: #ffffff;">REPORTE DE VENTAS Y CORTE DE CAJA - COMO EN CASA</th>
        </tr>
        <tr>
            <td colspan="6"><strong>Periodo del reporte:</strong> <?php echo $rango; ?></td>
        </tr>
        <tr>
            <td colspan="3"><strong>Total de transacciones:</strong> <?php echo $total_transacciones; ?></td>
            <td colspan="3"><strong>Ingreso total vendido:</strong> LPS <?php echo number_format($ingreso_total, 2); ?></td>
        </tr>
        <?php foreach ($por_metodo as $metodo => $monto): ?>
            <tr>
                <td colspan="3">Ventas con <?php echo htmlspecialchars($metodo); ?></td>
                <td colspan="3">LPS <?php echo number_format($monto, 2); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><td colspan="6"></td></tr>

        <tr>
            <th>ID / Factura</th>
            <th>Cliente</th>
            <th>Método de Pago</th>
            <th>Estado</th>
            <th>Fecha / Hora</th>
            <th>Total Final</th>
        </tr>

        <?php if (count($ventas) > 0): ?>
            <?php foreach($ventas as $row): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?> (<?php echo htmlspecialchars($row['numero_factura'] ?? '') !== '' ? htmlspecialchars($row['numero_factura']) : 'N/A'; ?>)</td>
                    <td><?php echo htmlspecialchars($row['cliente']); ?> (NIF: <?php echo htmlspecialchars($row['cliente_nif'] ?? '') !== '' ? htmlspecialchars($row['cliente_nif']) : 'N/R'; ?>)</td>
                    <td><?php echo htmlspecialchars($row['metodo_pago'] ?? '') !== '' ? htmlspecialchars($row['metodo_pago']) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($row['estado']); ?></td>
                    <td><?php echo date('d/m/Y H:i:s', strtotime($row['fecha'])); ?></td>
                    <td>LPS <?php echo number_format($row['total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" style="text-align: center;">No hay registros para estas fechas.</td>
            </tr>
        <?php endif; ?>
    </table>

</body>
</html>
