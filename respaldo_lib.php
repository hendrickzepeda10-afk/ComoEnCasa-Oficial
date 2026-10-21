<?php
/**
 * Respaldo de la base de datos en un archivo .sql (sin depender de mysqldump).
 */
define('RESPALDO_DIR', __DIR__ . '/respaldos');
define('RESPALDO_MAX_ARCHIVOS', 14);

function preparar_carpeta_respaldos() {
    if (!is_dir(RESPALDO_DIR)) {
        mkdir(RESPALDO_DIR, 0775, true);
    }
    $htaccess = RESPALDO_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
}

/** Crea un respaldo y devuelve el nombre del archivo generado. */
function crear_respaldo(PDO $pdo) {
    preparar_carpeta_respaldos();

    $sql  = "-- Respaldo COMO EN CASA - " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablas as $tabla) {
        $sql .= "DROP TABLE IF EXISTS `$tabla`;\n";
        $crear = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM);
        $sql .= $crear[1] . ";\n\n";

        $filas = $pdo->query("SELECT * FROM `$tabla`");
        while ($fila = $filas->fetch(PDO::FETCH_ASSOC)) {
            $valores = [];
            foreach ($fila as $v) {
                $valores[] = ($v === null) ? 'NULL' : $pdo->quote((string)$v);
            }
            $sql .= "INSERT INTO `$tabla` VALUES (" . implode(',', $valores) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $nombre = 'respaldo_' . date('Ymd_His') . '.sql';
    if (file_put_contents(RESPALDO_DIR . '/' . $nombre, $sql) === false) {
        throw new RuntimeException('No se pudo escribir el archivo de respaldo.');
    }

    // Conservar solo los últimos respaldos
    $archivos = glob(RESPALDO_DIR . '/respaldo_*.sql');
    sort($archivos);
    while (count($archivos) > RESPALDO_MAX_ARCHIVOS) {
        @unlink(array_shift($archivos));
    }

    return $nombre;
}

/** Crea el respaldo del día si todavía no existe. Nunca bloquea el inicio de sesión. */
function respaldo_automatico_si_toca(PDO $pdo) {
    try {
        preparar_carpeta_respaldos();
        $hoy = glob(RESPALDO_DIR . '/respaldo_' . date('Ymd') . '_*.sql');
        if (empty($hoy)) {
            crear_respaldo($pdo);
        }
    } catch (Throwable $e) {
        // Ignorar: el respaldo es adicional al inicio de sesión.
    }
}
