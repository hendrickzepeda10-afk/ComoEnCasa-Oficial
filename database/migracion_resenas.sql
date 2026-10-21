-- Agrega el almacenamiento de reseñas de clientes.
-- Ejecutar una vez en la base de datos comoencasa_db existente.
CREATE TABLE IF NOT EXISTS `resenas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comentario` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calificacion` tinyint unsigned NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resenas_creado_en` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
