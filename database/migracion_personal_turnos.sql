-- Agrega datos de identificación y turnos para todos los roles, además del rol de limpieza.
-- Ejecutar una sola vez sobre una base de datos existente.
ALTER TABLE `usuarios`
  ADD COLUMN `nombre_completo` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `rol`,
  ADD COLUMN `dni` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `nombre_completo`,
  ADD COLUMN `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `dni`,
  ADD COLUMN `turno` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `telefono`,
  MODIFY COLUMN `rol` enum('admin','gerente_general','cajero','chef','limpieza','mesero','cliente')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
