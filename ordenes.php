<?php
include 'auth.php';
requerir_rol(['chef', 'admin']);
include 'conexion.php';
include 'funciones.php';

// Cambio de estado de una orden (y descuento de inventario al entregar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chef_id'], $_POST['nuevo_estado'])) {
    $chef_id = intval($_POST['chef_id']);
    $nuevo_estado = $_POST['nuevo_estado'];

    $estados_validos = ['En espera', 'Listo', 'Entregado'];
    if (in_array($nuevo_estado, $estados_validos, true)) {
        try {
            $pdo->beginTransaction();

            $stmt_check = $pdo->prepare("SELECT estado, pedido_id, platillos, inventario_descontado FROM pedidos_chef WHERE id = ? FOR UPDATE");
            $stmt_check->execute([$chef_id]);
            $orden = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($orden) {
                $stmt_update = $pdo->prepare("UPDATE pedidos_chef SET estado = ? WHERE id = ?");
                $stmt_update->execute([$nuevo_estado, $chef_id]);

                // Descontar solo la primera vez que se entrega (evita descontar dos veces)
                if ($nuevo_estado === 'Entregado' && intval($orden['inventario_descontado']) === 0) {
                    descontar_inventario($pdo, $orden, $_SESSION['usuario'] ?? '');
                    $stmt_flag = $pdo->prepare("UPDATE pedidos_chef SET inventario_descontado = 1 WHERE id = ?");
                    $stmt_flag->execute([$chef_id]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_cocina'] = "No se pudo actualizar la orden. Intenta de nuevo.";
        }
    }
    header("Location: ordenes.php");
    exit();
}

$flash = $_SESSION['flash_cocina'] ?? '';
unset($_SESSION['flash_cocina']);

try {
    $ordenes_chef = consultar_ordenes_cocina($pdo);
} catch (PDOException $e) {
    $ordenes_chef = [];
}
$firma_inicial = firma_ordenes_cocina($ordenes_chef);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Órdenes de Cocina - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
   
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include('header.php'); ?>

    <div class="chef-page-wrapper">
        <div class="chef-header-banner">
            <h1><i class="fa-solid fa-utensils"></i> Órdenes de Cocina (Chef)</h1>
            <p>Visualiza en tiempo real los platillos solicitados por los clientes y actualiza su estado de preparación.</p>
            <p style="margin-top: 8px; font-size: 12.5px;">
                La pantalla se actualiza sola cada 10 segundos.
                <button type="button" id="btnSonido" style="margin-left: 8px; padding: 4px 12px; border-radius: 20px; border: 1px solid #d97706; background: #fff; color: #92400e; cursor: pointer; font-size: 12.5px; font-weight: 600;">🔕 Activar sonido</button>
            </p>
        </div>

        <?php if ($flash !== ''): ?>
            <div style="background:#fdeede; border:1px solid #f5b7b1; color:#c0392b; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-weight:600;">
                <?php echo htmlspecialchars($flash); ?>
            </div>
        <?php endif; ?>

        <div class="ordenes-grid">
            <?php if (count($ordenes_chef) > 0): ?>
                <?php foreach($ordenes_chef as $row): ?>
                    <?php 
                        $estado = $row['estado'];
                        $clase_estado = 'estado-en-espera';
                        if ($estado == 'Listo') $clase_estado = 'estado-listo';
                        elseif ($estado == 'Entregado') $clase_estado = 'estado-entregado';
                    ?>
                    <div class="orden-card <?php echo $clase_estado; ?>">
                        <div>
                            <div class="orden-card-header">
                                <strong style="font-size: 15px; color: #1f2937;">Ticket #<?php echo $row['pedido_id'] ?? $row['id']; ?></strong>
                                <span style="font-size: 12px; color: #6b7280;"><i class="fa-regular fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($row['fecha'])); ?></span>
                            </div>

                            <?php if (($row['tipo_servicio'] ?? '') === 'comer_aqui'): ?>
                                <p style="margin: 0 0 10px; color: #92400e; font-weight: 600;">
                                    <i class="fa-solid fa-chair"></i>
                                    Comer aquí<?php echo !empty($row['numero_mesa']) ? ' · Mesa ' . intval($row['numero_mesa']) : ''; ?>
                                    <?php if (($row['estado_pago'] ?? '') === 'Pendiente'): ?>
                                        <span> · Pago después de comer</span>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            
                            <p style="margin: 0 0 10px 0;"><strong>Cliente:</strong> <span class="badge-cliente"><?php echo htmlspecialchars($row['cliente']); ?></span></p>
                            
                            <div class="platillo-box">
                                <span style="font-size: 11px; color: #b45309; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 4px;">Qué debe cocinar:</span>
                                <div style="font-weight: 600; color: #1f2937; font-size: 14.5px; line-height: 1.4;">
                                    <?php echo nl2br(htmlspecialchars($row['platillos'])); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Selector para cambiar estado en tiempo real -->
                        <form action="ordenes.php" method="POST">
                            <input type="hidden" name="chef_id" value="<?php echo $row['id']; ?>">
                            <label style="display:block; margin-top:8px; font-size: 12.5px; color: #4b5563;"><strong>Estado actual:</strong></label>
                            <select name="nuevo_estado" class="select-estado" onchange="this.form.submit()">
                                <option value="En espera" <?php echo ($estado == 'En espera') ? 'selected' : ''; ?>>⏳ En espera</option>
                                <option value="Listo" <?php echo ($estado == 'Listo') ? 'selected' : ''; ?>>✅ Listo</option>
                                <option value="Entregado" <?php echo ($estado == 'Entregado') ? 'selected' : ''; ?>>🚀 Entregado</option>
                            </select>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-kitchen">
                    <i class="fa-solid fa-mug-hot fa-3x" style="margin-bottom: 12px; color: #cbd5e1;"></i>
                    <p style="margin: 0; font-size: 15px; font-weight: 500;">No hay órdenes pendientes en la cocina en este momento.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function () {
        var actual = <?php echo json_encode($firma_inicial); ?>;
        var btn = document.getElementById('btnSonido');
        var sonido = localStorage.getItem('cocina_sonido') === '1';
        var audioCtx = null;

        function pintarBoton() {
            btn.textContent = sonido ? '🔔 Sonido activado' : '🔕 Activar sonido';
        }

        function beep() {
            try {
                audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
                if (audioCtx.state === 'suspended') { audioCtx.resume(); }
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.frequency.value = 880;
                gain.gain.value = 0.15;
                osc.start();
                setTimeout(function () { osc.stop(); }, 350);
            } catch (e) {}
        }

        btn.addEventListener('click', function () {
            sonido = !sonido;
            localStorage.setItem('cocina_sonido', sonido ? '1' : '0');
            pintarBoton();
            if (sonido) { beep(); }   // este clic habilita el audio del navegador
        });
        pintarBoton();

        function refrescar() {
            // No interrumpir si el chef está eligiendo un estado
            if (document.activeElement && document.activeElement.tagName === 'SELECT') { return; }

            fetch('ordenes_estado.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || d.error || d.firma === actual.firma) { return; }
                    return fetch('ordenes.php', { credentials: 'same-origin', cache: 'no-store' })
                        .then(function (r) { return r.text(); })
                        .then(function (html) {
                            var doc = new DOMParser().parseFromString(html, 'text/html');
                            var nuevo = doc.querySelector('.ordenes-grid');
                            var grid = document.querySelector('.ordenes-grid');
                            if (nuevo && grid) {
                                grid.innerHTML = nuevo.innerHTML;
                                if (sonido && d.en_espera > actual.en_espera) { beep(); }
                                actual = d;
                            }
                        });
                })
                .catch(function () {});
        }

        setInterval(refrescar, 10000);
    })();
    </script>

</body>
</html>
