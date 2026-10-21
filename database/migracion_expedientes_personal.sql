-- Agrega datos personales y referencias a documentos privados del personal.
-- Ejecutar una sola vez sobre una base de datos existente.
ALTER TABLE `usuarios`
  ADD COLUMN `fecha_nacimiento` date DEFAULT NULL AFTER `nombre_completo`,
  ADD COLUMN `correo_electronico` varchar(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `telefono`,
  ADD COLUMN `domicilio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `correo_electronico`,
  ADD COLUMN `contacto_emergencia_nombre` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `domicilio`,
  ADD COLUMN `contacto_emergencia_telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `contacto_emergencia_nombre`,
  ADD COLUMN `documento_cv` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `turno`,
  ADD COLUMN `documento_identidad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `documento_cv`,
  ADD COLUMN `documento_estudios` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `documento_identidad`,
  ADD COLUMN `documento_antecedentes` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `documento_estudios`;
