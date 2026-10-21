-- Retira el acceso de cuentas antiguas de cliente sin borrarlas ni darles
-- acceso operativo. El administrador puede reasignarlas desde Gestión de Usuarios.
UPDATE `usuarios`
SET `rol` = 'mesero', `estado` = 0
WHERE `rol` = 'cliente';

ALTER TABLE `usuarios`
  MODIFY COLUMN `rol` enum('admin','gerente_general','cajero','chef','limpieza','mesero')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
