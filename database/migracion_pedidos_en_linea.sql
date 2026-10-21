-- Agrega información de contacto y modalidad para pedidos en línea existentes.
ALTER TABLE `pedidos`
  ADD COLUMN `telefono_cliente` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `cliente`,
  ADD COLUMN `tipo_servicio` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `telefono_cliente`,
  ADD COLUMN `direccion_entrega` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `tipo_servicio`;
