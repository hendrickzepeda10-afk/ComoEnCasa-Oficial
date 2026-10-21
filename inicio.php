<?php
include 'auth.php';

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

$mas_pedidos = [];
$bebidas_disponibles = [];
$hay_historial_popular = false;
$error_menu = false;

try {
    $columna_imagen = $pdo->query("SHOW COLUMNS FROM platillos LIKE 'imagen'")->fetch(PDO::FETCH_ASSOC);
    $seleccion_imagen = $columna_imagen ? 'platillos.imagen' : "''";

    $sql = "SELECT
                platillos.id,
                platillos.nombre,
                COALESCE(platillos.descripcion, '') AS descripcion,
                platillos.precio,
                COALESCE(ventas.unidades_vendidas, 0) AS unidades_vendidas,
                $seleccion_imagen AS imagen
            FROM platillos
            LEFT JOIN (
                SELECT detalle_pedido.platillo_id, SUM(detalle_pedido.cantidad) AS unidades_vendidas
                FROM detalle_pedido
                INNER JOIN pedidos ON pedidos.id = detalle_pedido.pedido_id
                WHERE pedidos.estado = 'Completado'
                GROUP BY detalle_pedido.platillo_id
            ) AS ventas ON ventas.platillo_id = platillos.id
            WHERE platillos.estado = 'activo'
              AND (platillos.tipo IS NULL OR platillos.tipo NOT IN ('bebida', 'bebida_alcoholica'))
            ORDER BY COALESCE(ventas.unidades_vendidas, 0) DESC, platillos.es_promocion DESC, platillos.nombre ASC
            LIMIT 16";
    $mas_pedidos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $hay_historial_popular = !empty($mas_pedidos)
        && intval($mas_pedidos[0]['unidades_vendidas']) > 0;

    $sql_bebidas = "SELECT
                        platillos.id,
                        platillos.nombre,
                        COALESCE(platillos.descripcion, '') AS descripcion,
                        platillos.precio,
                        $seleccion_imagen AS imagen
                    FROM platillos
                    WHERE platillos.estado = 'activo'
                      AND platillos.tipo = 'bebida'
                    ORDER BY platillos.es_promocion DESC, platillos.nombre ASC
                    LIMIT 12";
    $bebidas_disponibles = $pdo->query($sql_bebidas)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudieron cargar los productos más pedidos: ' . $e->getMessage());
    $error_menu = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conoce los favoritos de Como en Casa y arma tu pedido para disfrutar en casa o en el restaurante.">
    <title>Como en Casa - Inicio</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-icono-portada">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="customer-home">
    <?php include 'header.php'; ?>

    <main class="customer-home-main">
        <section class="customer-hero" aria-labelledby="customerHomeTitle">
            <div class="customer-hero-copy">
                <span class="customer-eyebrow"><i class="fa-solid fa-heart" aria-hidden="true"></i> Hecho para disfrutar</span>
                <h1 id="customerHomeTitle">El sabor de sentirte <span>en casa.</span></h1>
                <p>Descubre los favoritos de nuestros clientes y elige cómo quieres disfrutar tu próximo pedido.</p>
                <a class="customer-primary-cta" href="pedir.php?seleccionar_servicio=1">
                    <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                    Empezar mi pedido
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
                <div class="customer-service-note">
                    <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                    A domicilio
                    <span aria-hidden="true">·</span>
                    Para llevar
                    <span aria-hidden="true">·</span>
                    Comer aquí
                </div>
            </div>
            <div class="customer-hero-art" aria-hidden="true">
                <div class="customer-hero-orbit customer-hero-orbit--outer"></div>
                <div class="customer-hero-orbit customer-hero-orbit--inner"></div>
                <div class="customer-hero-logo">
                    <img src="img/logo_claro.png" alt="">
                </div>
                <span class="customer-hero-stamp">
                    <span class="customer-hero-stamp-icon"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                    <span class="customer-hero-stamp-text">Rico, fresco y hecho con cariño</span>
                </span>
            </div>
        </section>

        <section class="customer-popular" aria-labelledby="popularHeading">
            <div class="customer-section-heading">
                <div>
                    <span class="customer-eyebrow"><i class="fa-solid fa-fire" aria-hidden="true"></i> Los favoritos</span>
                    <h2 id="popularHeading"><?php echo $hay_historial_popular ? 'Lo más pedido' : 'Platillos recomendados'; ?></h2>
                    <p><?php echo $hay_historial_popular ? 'Los platillos que nuestros clientes eligen una y otra vez.' : 'Mira algunas opciones de nuestro menú y encuentra tu nuevo favorito.'; ?></p>
                </div>
                <a class="customer-text-link" href="pedir.php">
                    Ver menú completo <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <?php if ($error_menu): ?>
                <div class="customer-empty-state" role="status">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <p>No pudimos cargar el menú en este momento. Intenta iniciar tu pedido más tarde.</p>
                </div>
            <?php elseif ($mas_pedidos): ?>
                <div class="customer-popular-grid">
                    <?php foreach ($mas_pedidos as $platillo): ?>
                        <?php
                            $imagen = imagen_producto_disponible($platillo['imagen'] ?? '')
                                ? $platillo['imagen']
                                : 'img/default.svg';
                        ?>
                        <article class="customer-popular-card">
                            <a class="customer-popular-image" href="pedir.php" aria-label="Ver menú para pedir <?php echo htmlspecialchars($platillo['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
                                <img src="<?php echo htmlspecialchars($imagen, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                                <span><i class="fa-solid fa-star" aria-hidden="true"></i> Recomendado</span>
                            </a>
                            <div class="customer-popular-content">
                                <h3><?php echo htmlspecialchars($platillo['nombre'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p><?php echo htmlspecialchars(trim((string) $platillo['descripcion']) ?: 'Una de las opciones favoritas de nuestros clientes.', ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="customer-popular-footer">
                                    <strong>LPS <?php echo number_format((float) $platillo['precio'], 2); ?></strong>
                                    <a href="pedir.php" aria-label="Pedir <?php echo htmlspecialchars($platillo['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
                                        Pedir <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="customer-empty-state">
                    <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>
                    <p>Pronto verás aquí los platillos más pedidos. Mientras tanto, explora el menú y encuentra tu favorito.</p>
                    <a href="pedir.php">Explorar el menú <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            <?php endif; ?>
        </section>

        <section class="customer-popular customer-drinks" aria-labelledby="drinksHeading">
            <div class="customer-section-heading">
                <div>
                    <span class="customer-eyebrow"><i class="fa-solid fa-glass-water" aria-hidden="true"></i> Para acompañar</span>
                    <h2 id="drinksHeading">Bebidas disponibles</h2>
                    <p>Opciones refrescantes disponibles en nuestro menú.</p>
                </div>
                <a class="customer-text-link" href="pedir.php?categoria=bebida">
                    Ver bebidas <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <?php if ($error_menu): ?>
                <div class="customer-empty-state" role="status">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <p>No pudimos cargar las bebidas en este momento. Intenta iniciar tu pedido más tarde.</p>
                </div>
            <?php elseif ($bebidas_disponibles): ?>
                <div class="customer-popular-grid customer-drinks-grid">
                    <?php foreach ($bebidas_disponibles as $bebida): ?>
                        <?php
                            $imagen_bebida = imagen_producto_disponible($bebida['imagen'] ?? '')
                                ? $bebida['imagen']
                                : 'img/default.svg';
                        ?>
                        <article class="customer-popular-card customer-drink-card">
                            <a class="customer-popular-image" href="pedir.php?categoria=bebida" aria-label="Ver menú de bebidas">
                                <img src="<?php echo htmlspecialchars($imagen_bebida, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                                <span><i class="fa-solid fa-glass-water" aria-hidden="true"></i> Disponible</span>
                            </a>
                            <div class="customer-popular-content">
                                <h3><?php echo htmlspecialchars($bebida['nombre'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p><?php echo htmlspecialchars(trim((string) $bebida['descripcion']) ?: 'Una bebida para acompañar tu comida.', ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="customer-popular-footer">
                                    <strong>LPS <?php echo number_format((float) $bebida['precio'], 2); ?></strong>
                                    <a href="pedir.php?categoria=bebida" aria-label="Ver bebidas disponibles">
                                        Ver menú <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="customer-empty-state">
                    <i class="fa-solid fa-glass-water" aria-hidden="true"></i>
                    <p>Por el momento no hay bebidas disponibles.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="customer-order-banner" aria-label="Iniciar pedido">
            <div>
                <span class="customer-eyebrow">¿Ya se te antojó?</span>
                <h2>Tu próximo favorito está a un pedido de distancia.</h2>
            </div>
            <a class="customer-primary-cta" href="pedir.php?seleccionar_servicio=1">
                Elegir cómo recibirlo <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </section>
    </main>
</body>
</html>
