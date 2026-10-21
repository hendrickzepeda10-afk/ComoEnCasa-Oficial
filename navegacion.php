<?php
/**
 * Menú único del sistema.
 *
 * Tanto la barra lateral (header.php) como los accesos rápidos del inicio
 * (dashboard.php) se construyen desde aquí, para que agregar o quitar una
 * sección se refleje en los dos lugares sin tener que repetir la lista.
 *
 * Cada elemento es: [archivo, icono, título, descripción].
 */

/** Secciones visibles para quien entra sin cuenta (clientes del kiosco). */
function menu_visitante() {
    return [
        [
            'titulo' => 'Menú',
            'items' => [
                ['inicio.php', 'fa-house', 'Inicio', 'Conoce los favoritos del restaurante.'],
                ['pedir.php', 'fa-utensils', 'Pedir Ya', 'Arma tu pedido para llevar o comer aquí.'],
                ['domicilio.php', 'fa-truck', 'Pedido a domicilio', 'Confirma tu dirección de entrega.'],
            ],
        ],
    ];
}

/** Secciones visibles para cada rol del personal. */
function menu_por_rol($rol) {
    $menus = [
        'admin' => [
            [
                'titulo' => 'Menú General',
                'items' => [
                    ['pedir.php', 'fa-cart-shopping', 'Pedir Ya', 'Arma un pedido para llevar o comer aquí.'],
                    ['domicilio.php', 'fa-truck', 'Pedido a domicilio', 'Registra la entrega y los datos de contacto.'],
                    ['ordenes.php', 'fa-utensils', 'Órdenes a Tomar', 'Estado de preparación en cocina.'],
                    ['editar_platillo.php', 'fa-book-open', 'Editar Platillos', 'Menú, precios, combos y bebidas.'],
                    ['insumos.php', 'fa-boxes-stacked', 'Insumos', 'Inventario, compras y unidades de empaque.'],
                    ['caja.php', 'fa-cash-register', 'Caja', 'Cobros, descuentos y facturación.'],
                    ['reporte_dia.php', 'fa-chart-line', 'Reporte del Día', 'Corte de caja y ventas.'],
                ],
            ],
            [
                'titulo' => 'Administración',
                'items' => [
                    ['gestion_usuarios.php', 'fa-users-gear', 'Gestión de Usuarios', 'Accesos y roles del personal.'],
                    ['crear_usuario.php', 'fa-user-plus', 'Crear Usuario', 'Registra un acceso nuevo.'],
                    ['respaldo.php', 'fa-database', 'Respaldo de Datos', 'Copias de seguridad del sistema.'],
                ],
            ],
        ],
        'gerente_general' => [
            [
                'titulo' => 'Menú',
                'items' => [
                    ['reporte_dia.php', 'fa-chart-line', 'Registro del Día', 'Corte de caja y ventas del día.'],
                    ['insumos.php', 'fa-boxes-stacked', 'Insumos', 'Consulta y actualiza el inventario.'],
                ],
            ],
        ],
        'cajero' => [
            [
                'titulo' => 'Menú',
                'items' => [
                    ['caja.php', 'fa-cash-register', 'Caja', 'Cobra pedidos y emite facturas.'],
                ],
            ],
        ],
        'chef' => [
            [
                'titulo' => 'Menú',
                'items' => [
                    ['ordenes.php', 'fa-utensils', 'Órdenes a Tomar', 'Platillos por preparar y su estado.'],
                    ['editar_platillo.php', 'fa-book-open', 'Editar Platillos', 'Disponibilidad del menú.'],
                ],
            ],
        ],
        'limpieza' => [
            [
                'titulo' => 'Personal',
                'items' => [
                    ['dashboard.php', 'fa-broom', 'Inicio', 'Consulta con administración las tareas asignadas.'],
                ],
            ],
        ],
    ];

    return $menus[strtolower(trim((string) $rol))] ?? [];
}

/** Devuelve todos los elementos de un menú en una sola lista. */
function menu_items_planos(array $secciones) {
    $items = [];
    foreach ($secciones as $seccion) {
        foreach ($seccion['items'] as $item) {
            $items[] = $item;
        }
    }
    return $items;
}

/** ¿Es esta la página que se está viendo? */
function menu_es_pagina_actual($archivo, $pagina_actual) {
    return basename((string) $archivo) === $pagina_actual;
}
