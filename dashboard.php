<?php
include 'auth.php';

if (!isset($_SESSION['user_id'], $_SESSION['rol'])) {
    header('Location: pedir.php');
    exit();
}
requerir_rol(roles_asignables());

$rol = rol_actual();

// Accesos rápidos por rol: las mismas secciones que muestra el menú lateral.
$accesos_por_rol = [
    'admin' => [
        ['pedir.php', 'fa-cart-shopping', 'Pedir Ya', 'Arma un pedido para llevar o comer aquí.'],
        ['domicilio.php', 'fa-truck', 'Pedido a domicilio', 'Registra la entrega y los datos de contacto.'],
        ['ordenes.php', 'fa-utensils', 'Órdenes a Tomar', 'Estado de preparación en cocina.'],
        ['editar_platillo.php', 'fa-book-open', 'Editar Platillos', 'Menú, precios, combos y bebidas.'],
        ['insumos.php', 'fa-boxes-stacked', 'Insumos', 'Inventario, compras y unidades de empaque.'],
        ['caja.php', 'fa-cash-register', 'Caja', 'Cobros, descuentos y facturación.'],
        ['reporte_dia.php', 'fa-chart-line', 'Reporte del Día', 'Corte de caja y ventas.'],
        ['gestion_usuarios.php', 'fa-users-gear', 'Gestión de Usuarios', 'Accesos y roles del personal.'],
        ['respaldo.php', 'fa-database', 'Respaldo de Datos', 'Copias de seguridad del sistema.'],
    ],
    'gerente_general' => [
        ['reporte_dia.php', 'fa-chart-line', 'Registro del Día', 'Corte de caja y ventas del día.'],
        ['insumos.php', 'fa-boxes-stacked', 'Insumos', 'Consulta y actualiza el inventario.'],
    ],
    'cajero' => [
        ['caja.php', 'fa-cash-register', 'Caja', 'Cobra pedidos y emite facturas.'],
    ],
    'chef' => [
        ['ordenes.php', 'fa-utensils', 'Órdenes a Tomar', 'Platillos por preparar y su estado.'],
        ['editar_platillo.php', 'fa-book-open', 'Editar Platillos', 'Disponibilidad del menú.'],
    ],
    'limpieza' => [],
];

$accesos = $accesos_por_rol[$rol] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inicio - Como en Casa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
</head>
<body>
    <?php include 'header.php'; ?>

    <main class="main-content dashboard-content">
        <section class="content-card welcome-card" aria-labelledby="welcome-title">
            <h1 id="welcome-title">Bienvenido a Como en Casa</h1>
            <p class="welcome-subtitle">
                Sesión de <strong><?php echo htmlspecialchars($_SESSION['usuario'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                · <?php echo htmlspecialchars(rol_etiqueta($rol), ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <?php if ($rol === 'admin'): ?>
                <a href="pedir.php" class="btn-action welcome-cta">🛒 Ir a Pedir Ya <span aria-hidden="true">→</span></a>
            <?php elseif ($rol === 'limpieza'): ?>
                <p class="welcome-subtitle">Cuenta de personal de limpieza. Consulta con administración las tareas y el turno asignados.</p>
            <?php endif; ?>
        </section>

        <?php if ($accesos): ?>
            <section class="quick-access" aria-label="Accesos rápidos">
                <?php foreach ($accesos as $indice => $acceso): ?>
                    <a class="quick-access-card stagger-item" style="animation-name: riseIn;" href="<?php echo htmlspecialchars($acceso[0], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="quick-access-icon"><i class="fa-solid <?php echo htmlspecialchars($acceso[1], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
                        <span class="quick-access-text">
                            <strong><?php echo htmlspecialchars($acceso[2], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <small><?php echo htmlspecialchars($acceso[3], ENT_QUOTES, 'UTF-8'); ?></small>
                        </span>
                        <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
