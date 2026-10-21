-- Permite clasificar productos alcohólicos para limitar su venta al consumo en el restaurante.
ALTER TABLE `platillos`
  MODIFY COLUMN `tipo` enum('platillo','combo','bebida','bebida_alcoholica')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'platillo';
