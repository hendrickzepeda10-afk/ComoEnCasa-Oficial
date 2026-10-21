<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sesion_autenticada = isset($_SESSION['user_id'], $_SESSION['rol']);
$es_visitante = !$sesion_autenticada;
$pagina_actual = basename($_SERVER['PHP_SELF']);

if ($es_visitante && !in_array($pagina_actual, ['inicio.php', 'pedir.php', 'domicilio.php', 'resenas.php'], true)) {
    header("Location: index.php");
    exit();
}

$rol = $sesion_autenticada ? rol_actual() : '';
$enlace_inicio = $es_visitante ? 'inicio.php' : 'dashboard.php';
?>

<!-- =========================================================
     BOTÓN HAMBURGUESA
     ========================================================= -->
<button
    type="button"
    class="sidebar-toggle-btn"
    id="sidebarToggle"
    aria-label="Abrir menú"
    aria-expanded="false"
    aria-controls="sidebarMenu"
>
    <span></span>
    <span></span>
    <span></span>
</button>

<!-- FONDO PARA CERRAR EL MENÚ -->
<div
    class="sidebar-backdrop"
    id="sidebarBackdrop"
    aria-hidden="true"
></div>


<!-- =========================================================
     SIDEBAR
     ========================================================= -->
<aside id="sidebarMenu" class="sidebar">

    <!-- LOGO -->
    <div class="sidebar-brand-container">
        <a href="<?php echo $enlace_inicio; ?>" class="brand-logo-link">
            <img
                src="img/logo_claro.png"
                alt="Como en Casa"
                class="brand-logo-img"
                onerror="this.style.display='none'"
            >
        </a>
    </div>


    <!-- =====================================================
         MENÚ
         ===================================================== -->
    <ul class="sidebar-menu">

        <?php if ($es_visitante): ?>
            <li>
                <a href="inicio.php" class="<?php echo ($pagina_actual === 'inicio.php') ? 'active' : ''; ?>">
                    <span class="text">Inicio</span>
                </a>
            </li>
            <li>
                <a href="pedir.php" class="<?php echo ($pagina_actual === 'pedir.php') ? 'active' : ''; ?>">
                    <span class="text">Pedir Ya</span>
                </a>
            </li>
            <li>
                <a href="domicilio.php" class="<?php echo ($pagina_actual === 'domicilio.php') ? 'active' : ''; ?>">
                    <span class="text">Pedido a domicilio</span>
                </a>
            </li>
            <li>
                <a href="resenas.php" class="<?php echo $pagina_actual === 'resenas.php' ? 'active' : ''; ?>">
                    <span class="text">Reseñas</span>
                </a>
            </li>
            <li>
                <a href="pedir.php?seleccionar_servicio=1">
                    <span class="text">Volver al menú principal</span>
                </a>
            </li>
        <?php else: ?>
            <li>
                <a href="dashboard.php" class="<?php echo ($pagina_actual == 'dashboard.php') ? 'active' : ''; ?>">
                    <span class="text">Inicio</span>
                </a>
            </li>
        <?php endif; ?>

        <!-- =================================================
             CHEF
             ================================================= -->
        <?php if ($rol === 'chef'): ?>

            <li class="menu-section">

                <details class="sidebar-group" open>

                    <summary class="menu-category">
                        Menú
                    </summary>

                    <ul class="submenu">

                        <li>
                            <a
                                href="ordenes.php"
                                class="<?php echo ($pagina_actual == 'ordenes.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Órdenes a Tomar</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="editar_platillo.php"
                                class="<?php echo ($pagina_actual == 'editar_platillo.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Editar Platillos</span>
                            </a>
                        </li>

                    </ul>

                </details>

            </li>


        <!-- =================================================
             CAJERO
             ================================================= -->
        <?php elseif ($rol === 'cajero'): ?>

            <li class="menu-section">

                <details class="sidebar-group" open>

                    <summary class="menu-category">
                        Menú
                    </summary>

                    <ul class="submenu">

                        <li>
                            <a
                                href="caja.php"
                                class="<?php echo ($pagina_actual == 'caja.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Caja</span>
                            </a>
                        </li>

                    </ul>

                </details>

            </li>

        <?php elseif ($rol === 'limpieza'): ?>

            <li class="menu-section">
                <details class="sidebar-group" open>
                    <summary class="menu-category">Personal</summary>
                    <ul class="submenu">
                        <li>
                            <a href="dashboard.php" class="<?php echo ($pagina_actual == 'dashboard.php') ? 'active' : ''; ?>">
                                <span class="text">Inicio</span>
                            </a>
                        </li>
                    </ul>
                </details>
            </li>


        <!-- =================================================
             GERENTE GENERAL
             ================================================= -->
        <?php elseif ($rol === 'gerente_general'): ?>

            <li class="menu-section">

                <details class="sidebar-group" open>

                    <summary class="menu-category">
                        Menú
                    </summary>

                    <ul class="submenu">

                        <li>
                            <a href="insumos.php" class="<?php echo ($pagina_actual == 'insumos.php') ? 'active' : ''; ?>">
                                <span class="text">Insumos</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="reporte_dia.php"
                                class="<?php echo ($pagina_actual == 'reporte_dia.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Registro del Día</span>
                            </a>
                        </li>



                    </ul>

                </details>

            </li>


        <!-- =================================================
             ADMIN
             ================================================= -->
        <?php elseif ($rol === 'admin'): ?>

            <!-- MENÚ GENERAL -->
            <li class="menu-section">

                <details class="sidebar-group" open>

                    <summary class="menu-category">
                        Menú General
                    </summary>

                    <ul class="submenu">

                        <li>
                            <a
                                href="pedir.php"
                                class="<?php echo ($pagina_actual == 'pedir.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Pedir Ya</span>
                            </a>
                        </li>
                        <li>
                            <a
                                href="domicilio.php"
                                class="<?php echo ($pagina_actual == 'domicilio.php') ? 'active' : ''; ?>"
                            >
                                <span class="text">Pedido a domicilio</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="ordenes.php"
                                class="<?php echo ($pagina_actual == 'ordenes.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Órdenes a Tomar</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="editar_platillo.php"
                                class="<?php echo ($pagina_actual == 'editar_platillo.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Editar Platillos</span>
                            </a>
                        </li>

                        <li>
                            <a href="insumos.php" class="<?php echo ($pagina_actual == 'insumos.php') ? 'active' : ''; ?>">
                                <span class="text">Insumos</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="caja.php"
                                class="<?php echo ($pagina_actual == 'caja.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Caja</span>
                            </a>
                        </li>
                        <li>
                            <a
                                href="reporte_dia.php"
                                class="<?php echo ($pagina_actual == 'reporte_dia.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Reporte del Día</span>
                            </a>
                        </li>



                    </ul>

                </details>

            </li>


            <!-- ADMINISTRACIÓN -->
            <li class="menu-section">

                <details class="sidebar-group" open>

                    <summary class="menu-category">
                        Administración
                    </summary>

                    <ul class="submenu">

                        <li>
                            <a
                                href="gestion_usuarios.php"
                                class="<?php echo ($pagina_actual == 'gestion_usuarios.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Gestión de Usuarios</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="crear_usuario.php"
                                class="<?php echo ($pagina_actual == 'crear_usuario.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Crear Usuario</span>
                            </a>
                        </li>

                        <li>
                            <a
                                href="respaldo.php"
                                class="<?php echo ($pagina_actual == 'respaldo.php') ? 'active' : ''; ?>"
                            >

                                <span class="text">Respaldo de Datos</span>
                            </a>
                        </li>

                    </ul>

                </details>

            </li>

        <?php endif; ?>

    </ul>

    <section class="sidebar-qr" aria-label="Código QR para pedir">
        <h2>Escanea para pedir</h2>
        <img src="img/como_en_casa_qr.png" alt="Código QR para abrir la página de pedidos">
    </section>

    <!-- =====================================================
         CERRAR SESIÓN
         ===================================================== -->
    <?php if ($sesion_autenticada): ?>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link">
                <span class="icon">🚪</span>
                <span class="text">Cerrar Sesión</span>
            </a>
        </div>
    <?php endif; ?>

</aside>


<!-- =========================================================
     JAVASCRIPT DEL SIDEBAR
     ========================================================= -->
<script>
(function () {

    const iconoSitio = document.createElement('link');
    iconoSitio.rel = 'icon';
    iconoSitio.type = 'image/x-icon';
    iconoSitio.href = 'img/favicon_como_en_casa.ico?v=2';
    document.head.appendChild(iconoSitio);

    const iconoMovil = document.createElement('link');
    iconoMovil.rel = 'apple-touch-icon';
    iconoMovil.href = 'img/logo_claro.png?v=1';
    document.head.appendChild(iconoMovil);

    function iniciarSidebar() {

        const btn = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebarMenu');
        const backdrop = document.getElementById('sidebarBackdrop');

        if (!btn || !sidebar || !backdrop) {
            console.error('No se encontraron los elementos del sidebar.');
            return;
        }


        /* ================================================
           ABRIR
           ================================================ */
        function abrirSidebar() {

            document.body.classList.add('sidebar-open');

            btn.setAttribute('aria-expanded', 'true');
            btn.setAttribute('aria-label', 'Cerrar menú');

            backdrop.setAttribute('aria-hidden', 'false');
        }


        /* ================================================
           CERRAR
           ================================================ */
        function cerrarSidebar() {

            document.body.classList.remove('sidebar-open');

            btn.setAttribute('aria-expanded', 'false');
            btn.setAttribute('aria-label', 'Abrir menú');

            backdrop.setAttribute('aria-hidden', 'true');
        }


        /* ================================================
           ABRIR / CERRAR CON LA HAMBURGUESA
           ================================================ */
        btn.addEventListener('click', function (event) {

            event.preventDefault();
            event.stopPropagation();

            const abierto =
                document.body.classList.contains('sidebar-open');

            if (abierto) {
                cerrarSidebar();
            } else {
                abrirSidebar();
            }

        });


        /* ================================================
           CERRAR HACIENDO CLIC FUERA
           ================================================ */
        backdrop.addEventListener('click', function (event) {

            event.preventDefault();

            cerrarSidebar();

        });


        /* ================================================
           CERRAR AL SELECCIONAR UNA OPCIÓN
           ================================================ */
        const enlaces = sidebar.querySelectorAll('a');

        enlaces.forEach(function (enlace) {

            enlace.addEventListener('click', function () {

                cerrarSidebar();

            });

        });


        /* ================================================
           CERRAR CON ESC
           ================================================ */
        document.addEventListener('keydown', function (event) {

            if (event.key === 'Escape') {

                cerrarSidebar();

            }

        });


        /* ================================================
           SI PASAMOS A PC, CERRAR EL MENÚ
           ================================================ */
        window.addEventListener('resize', function () {

            if (window.innerWidth > 900) {

                cerrarSidebar();

            }

        });

    }


    /* ====================================================
       INICIAR
       ==================================================== */

    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            iniciarSidebar
        );

    } else {

        iniciarSidebar();

    }

})();
</script>
<script>
window.comoEnCasaFirmaSesionAnterior = <?php
echo json_encode($sesion_pestana_legado_firma ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
</script>
<script src="sesiones_pestanas.js?v=3"></script>