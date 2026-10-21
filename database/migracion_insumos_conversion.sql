-- Agrega empaque y factor de conversión para inventario y recetas.
-- Ejecutar una sola vez en una base de datos existente.
ALTER TABLE `insumos`
  ADD COLUMN `unidad_compra` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `unidad`,
  ADD COLUMN `factor_conversion` decimal(12,2) NOT NULL DEFAULT '1.00' AFTER `unidad_compra`;

-- Los insumos existentes conservan su unidad de stock/receta como empaque base.
UPDATE `insumos`
SET `unidad_compra` = `unidad`
WHERE `unidad_compra` IS NULL OR `unidad_compra` = '';

-- La deducción y el registro del pedido se realizan en una sola transacción.
ALTER TABLE `pedidos_chef` ENGINE=InnoDB;
