<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';
require_once __DIR__ . '/funciones.php';

$unidades_base = ['unidades', 'lb', 'kg', 'g', 'litros', 'ml'];
$etiquetas_unidad_base = [
    'unidades' => 'unidades',
    'lb' => 'lb (libras)',
    'kg' => 'kg (kilogramos)',
    'g' => 'g (gramos)',
    'litros' => 'litros',
    'ml' => 'ml (mililitros)',
];
$mensaje = '';
$tipo_mensaje = '';
$insumo_editar = null;
$flash_importacion = $_SESSION['flash_importacion_insumos'] ?? null;
unset($_SESSION['flash_importacion_insumos']);

function cantidad_insumo_valida($valor, $permitir_cero = false) {
    if (!is_scalar($valor)) {
        return false;
    }

    $texto = trim((string) $valor);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $texto)) {
        return false;
    }

    $cantidad = (float) $texto;
    return is_finite($cantidad)
        && ($permitir_cero ? $cantidad >= 0 : $cantidad > 0)
        && $cantidad <= 99999999.99;
}

function texto_insumo_formulario($campo) {
    return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
    if (!csrf_peticion_valida()) {
        http_response_code(403);
        $mensaje = 'La solicitud expiró o no es válida. Actualiza la página e inténtalo de nuevo.';
        $tipo_mensaje = 'error';
    } else {
        try {
            if ($accion === 'crear') {
                $nombre = texto_insumo_formulario('nombre');
                $unidad_base = texto_insumo_formulario('unidad');
                $unidad_compra = texto_insumo_formulario('unidad_compra');
                $factor = $_POST['factor_conversion'] ?? '';
                $stock_inicial = $_POST['stock_inicial'] ?? '';
                $stock_minimo = $_POST['stock_minimo'] ?? '';

                if (
                    $nombre === ''
                    || mb_strlen($nombre, 'UTF-8') > 100
                    || !in_array($unidad_base, $unidades_base, true)
                    || $unidad_compra === ''
                    || mb_strlen($unidad_compra, 'UTF-8') > 30
                    || !cantidad_insumo_valida($factor)
                    || !cantidad_insumo_valida($stock_inicial, true)
                    || !cantidad_insumo_valida($stock_minimo, true)
                ) {
                    throw new RuntimeException('Revisa el nombre, las unidades y las cantidades del insumo.');
                }

                $factor = (float) $factor;
                $stock_inicial = (float) $stock_inicial;
                $stock_minimo = (float) $stock_minimo;
                $existencia_base = round($stock_inicial * $factor, 2);
                if ($existencia_base > 99999999.99) {
                    throw new RuntimeException('La existencia inicial supera el máximo permitido.');
                }

                $pdo->beginTransaction();
                $buscar = $pdo->prepare('SELECT id FROM insumos WHERE nombre = :nombre LIMIT 1');
                $buscar->execute(['nombre' => $nombre]);
                if ($buscar->fetchColumn()) {
                    throw new RuntimeException('Ya existe un insumo con ese nombre.');
                }

                $insertar = $pdo->prepare('INSERT INTO insumos (nombre, cantidad, unidad, stock_minimo, unidad_compra, factor_conversion) VALUES (:nombre, :cantidad, :unidad, :stock_minimo, :unidad_compra, :factor_conversion)');
                $insertar->execute([
                    'nombre' => $nombre,
                    'cantidad' => $existencia_base,
                    'unidad' => $unidad_base,
                    'stock_minimo' => $stock_minimo,
                    'unidad_compra' => $unidad_compra,
                    'factor_conversion' => $factor,
                ]);
                $insumo_id = (int) $pdo->lastInsertId();

                if ($stock_inicial > 0) {
                    registrar_movimiento(
                        $pdo,
                        $insumo_id,
                        'entrada',
                        $existencia_base,
                        'Inventario inicial: ' . $stock_inicial . ' ' . $unidad_compra . ' × ' . $factor . ' ' . $unidad_base,
                        $_SESSION['usuario'] ?? '',
                        true
                    );
                }
                $pdo->commit();
                header('Location: insumos.php?mensaje=creado');
                exit();
            }

            if ($accion === 'entrada') {
                $insumo_id = filter_var($_POST['insumo_id'] ?? null, FILTER_VALIDATE_INT);
                $cantidad_paquetes = $_POST['cantidad_paquetes'] ?? '';
                if (!$insumo_id || !cantidad_insumo_valida($cantidad_paquetes)) {
                    throw new RuntimeException('Indica una cantidad de compra válida.');
                }

                $pdo->beginTransaction();
                $buscar = $pdo->prepare('SELECT id, nombre, unidad, unidad_compra, factor_conversion FROM insumos WHERE id = :id FOR UPDATE');
                $buscar->execute(['id' => $insumo_id]);
                $insumo = $buscar->fetch(PDO::FETCH_ASSOC);
                if (!$insumo) {
                    throw new RuntimeException('No se encontró el insumo seleccionado.');
                }
                $cantidad_paquetes = (float) $cantidad_paquetes;
                $factor = (float) $insumo['factor_conversion'];
                $cantidad_base = round($cantidad_paquetes * $factor, 2);
                if ($cantidad_base <= 0 || $cantidad_base > 99999999.99) {
                    throw new RuntimeException('La compra convertida supera el máximo permitido.');
                }

                $actualizar = $pdo->prepare('UPDATE insumos SET cantidad = cantidad + :cantidad WHERE id = :id');
                $actualizar->execute(['cantidad' => $cantidad_base, 'id' => $insumo_id]);
                registrar_movimiento(
                    $pdo,
                    $insumo_id,
                    'entrada',
                    $cantidad_base,
                    'Compra: ' . $cantidad_paquetes . ' ' . $insumo['unidad_compra'] . ' × ' . $factor . ' ' . $insumo['unidad'],
                    $_SESSION['usuario'] ?? '',
                    true
                );
                $pdo->commit();
                header('Location: insumos.php?mensaje=entrada');
                exit();
            }

            if ($accion === 'actualizar') {
                $insumo_id = filter_var($_POST['insumo_id'] ?? null, FILTER_VALIDATE_INT);
                $nombre = texto_insumo_formulario('nombre');
                $unidad_compra = texto_insumo_formulario('unidad_compra');
                $factor = $_POST['factor_conversion'] ?? '';
                $stock_minimo = $_POST['stock_minimo'] ?? '';
                if (
                    !$insumo_id
                    || $nombre === ''
                    || mb_strlen($nombre, 'UTF-8') > 100
                    || $unidad_compra === ''
                    || mb_strlen($unidad_compra, 'UTF-8') > 30
                    || !cantidad_insumo_valida($factor)
                    || !cantidad_insumo_valida($stock_minimo, true)
                ) {
                    throw new RuntimeException('Revisa el nombre, la unidad de compra y las cantidades.');
                }

                $pdo->beginTransaction();
                $buscar = $pdo->prepare('SELECT id FROM insumos WHERE id = :id FOR UPDATE');
                $buscar->execute(['id' => $insumo_id]);
                if (!$buscar->fetchColumn()) {
                    throw new RuntimeException('No se encontró el insumo que deseas editar.');
                }
                $duplicado = $pdo->prepare('SELECT id FROM insumos WHERE nombre = :nombre AND id <> :id LIMIT 1');
                $duplicado->execute(['nombre' => $nombre, 'id' => $insumo_id]);
                if ($duplicado->fetchColumn()) {
                    throw new RuntimeException('Ya existe otro insumo con ese nombre.');
                }

                $actualizar = $pdo->prepare('UPDATE insumos SET nombre = :nombre, unidad_compra = :unidad_compra, factor_conversion = :factor_conversion, stock_minimo = :stock_minimo WHERE id = :id');
                $actualizar->execute([
                    'nombre' => $nombre,
                    'unidad_compra' => $unidad_compra,
                    'factor_conversion' => (float) $factor,
                    'stock_minimo' => (float) $stock_minimo,
                    'id' => $insumo_id,
                ]);
                $pdo->commit();
                header('Location: insumos.php?mensaje=actualizado');
                exit();
            }

            if ($accion === 'eliminar') {
                $insumo_id = filter_var($_POST['insumo_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$insumo_id) {
                    throw new RuntimeException('Selecciona un insumo válido para eliminar.');
                }

                $pdo->beginTransaction();
                $buscar = $pdo->prepare('SELECT id FROM insumos WHERE id = :id FOR UPDATE');
                $buscar->execute(['id' => $insumo_id]);
                if (!$buscar->fetchColumn()) {
                    throw new RuntimeException('No se encontró el insumo que deseas eliminar.');
                }

                $eliminar_recetas = $pdo->prepare('DELETE FROM recetas WHERE insumo_id = :id');
                $eliminar_recetas->execute(['id' => $insumo_id]);
                $eliminar_movimientos = $pdo->prepare('DELETE FROM movimientos_insumos WHERE insumo_id = :id');
                $eliminar_movimientos->execute(['id' => $insumo_id]);
                $eliminar = $pdo->prepare('DELETE FROM insumos WHERE id = :id');
                $eliminar->execute(['id' => $insumo_id]);
                $pdo->commit();
                header('Location: insumos.php?mensaje=eliminado');
                exit();
            }

            throw new RuntimeException('La acción solicitada no es válida.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException) {
                error_log('No se pudo actualizar el inventario de insumos: ' . $e->getMessage());
                $mensaje = 'No se pudo guardar el cambio de inventario. Inténtalo de nuevo.';
            } else {
                $mensaje = $e->getMessage();
            }
            $tipo_mensaje = 'error';
        }
    }
}

if (isset($_GET['mensaje'])) {
    $mensajes = [
        'creado' => 'El insumo quedó registrado.',
        'entrada' => 'La compra se agregó al inventario.',
        'actualizado' => 'Los datos del insumo se actualizaron.',
        'eliminado' => 'El insumo y sus datos relacionados se eliminaron correctamente.',
    ];
    if (is_string($_GET['mensaje']) && isset($mensajes[$_GET['mensaje']])) {
        $mensaje = $mensajes[$_GET['mensaje']];
        $tipo_mensaje = 'success';
    }
}
if (is_array($flash_importacion) && is_string($flash_importacion['mensaje'] ?? null)) {
    $mensaje = $flash_importacion['mensaje'];
    $tipo_mensaje = is_string($flash_importacion['tipo'] ?? null) ? $flash_importacion['tipo'] : 'error';
}

$id_editar = filter_var($_GET['editar'] ?? null, FILTER_VALIDATE_INT);
try {
    if ($id_editar) {
        $stmt_editar = $pdo->prepare('SELECT * FROM insumos WHERE id = :id');
        $stmt_editar->execute(['id' => $id_editar]);
        $insumo_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $insumos = $pdo->query('SELECT i.*, (SELECT COUNT(*) FROM recetas r WHERE r.insumo_id = i.id) AS recetas_asociadas FROM insumos i ORDER BY i.nombre')->fetchAll(PDO::FETCH_ASSOC);
    $platillos = $pdo->query('SELECT id, nombre, tipo FROM platillos ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    $movimientos = $pdo->query('SELECT m.*, i.nombre, i.unidad FROM movimientos_insumos m JOIN insumos i ON i.id = m.insumo_id ORDER BY m.fecha DESC, m.id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudo cargar el inventario: ' . $e->getMessage());
    http_response_code(503);
    $mensaje = 'No se pudo cargar el inventario. Verifica que ejecutaste database/migracion_insumos_conversion.sql.';
    $tipo_mensaje = 'error';
    $insumos = [];
    $platillos = [];
    $movimientos = [];
}

$busqueda_insumo = is_string($_GET['buscar'] ?? null) ? trim($_GET['buscar']) : '';
$total_insumos = count($insumos);
$insumos_stock_bajo = count(array_filter($insumos, static function ($insumo) {
    return (float) $insumo['cantidad'] <= (float) $insumo['stock_minimo'];
}));
if ($busqueda_insumo !== '') {
    $insumos = array_values(array_filter($insumos, static function ($insumo) use ($busqueda_insumo) {
        return mb_stripos($insumo['nombre'], $busqueda_insumo, 0, 'UTF-8') !== false;
    }));
}

$csrf = csrf_token();
$panel_inicial = $insumo_editar ? 'registro' : 'existencias';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insumos e Inventario - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .inventory-page { max-width: 1680px !important; text-align: left !important; }
        .inventory-page-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; margin-bottom: 22px; }
        .inventory-page-header h1 { margin: 0 0 6px; }
        .inventory-page-header p { margin: 0; }
        .inventory-link { min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; border: 0; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 700; color: #fff; background: #087f5b; }
        .inventory-link:hover { background: #066c4d; }
        .inventory-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 22px; }
        .inventory-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 9px; min-width: 0; padding: 16px 18px; border: 1px solid #eee4da; border-radius: 14px; background: linear-gradient(135deg, #fff, #fffaf5); text-align: center; }
        .inventory-stat-icon { display: grid; width: 44px; height: 44px; flex: 0 0 auto; place-items: center; border-radius: 12px; background: #f8eee5; color: #b8663d; font-size: 18px; }
        .inventory-stat strong, .inventory-stat span { display: block; }
        .inventory-stat strong { color: #392c24; font-size: 22px; line-height: 1.2; }
        .inventory-stat span { margin-top: 3px; color: var(--text-muted); font-size: 13px; }
        .inventory-tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 16px; padding: 7px; border: 1px solid #eee4da; border-radius: 14px; background: #f8f4ef; }
        .inventory-tab { display: flex; min-height: 48px; align-items: center; justify-content: center; gap: 9px; padding: 10px 14px; border: 1px solid transparent; border-radius: 10px; color: #65564b; font-weight: 700; text-align: center; text-decoration: none; transition: background .18s ease, color .18s ease, box-shadow .18s ease; }
        .inventory-tab:hover { background: #fff; color: #8a4b12; }
        .inventory-tab[aria-selected="true"] { background: #fff; border-color: #eadbc9; color: #8a4b12; box-shadow: 0 3px 9px rgba(73, 51, 34, .08); }
        .inventory-panel { min-width: 0; padding: clamp(18px, 2.2vw, 28px); border: 1px solid #ebdfd4; border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(43, 33, 28, .045); }
        .inventory-panel h2 { margin: 0 0 7px; color: #392c24; font-size: 24px; }
        .inventory-panel-heading { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 18px; }
        .inventory-panel-heading p, .inventory-hint { margin: 0; line-height: 1.5; color: var(--text-muted); }
        .inventory-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .inventory-form label { display: block; margin-bottom: 7px; color: #493a30; font-weight: 700; }
        .inventory-form input, .inventory-form select { width: 100%; min-height: 48px; padding: 10px 12px; border: 1px solid #e4d9cf; border-radius: 9px; background: #fff; }
        .inventory-form input:focus, .inventory-form select:focus { outline: 3px solid rgba(196, 111, 66, .16); border-color: #c46f42; }
        .inventory-form .inventory-note { margin: 7px 0 0; }
        .inventory-form-submit { grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; padding-top: 2px; }
        .inventory-form-submit .btn-action { min-height: 48px; justify-content: center; padding: 10px 20px; border: 0; cursor: pointer; }
        .inventory-import { display: grid; gap: 16px; margin-top: 24px; padding: 20px; border: 1px solid #eee4da; border-radius: 14px; background: linear-gradient(145deg, #fffdfb, #fff8f1); }
        .inventory-import-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; }
        .inventory-import-header h3 { margin: 0 0 5px; color: #392c24; font-size: 19px; }
        .inventory-import-header p { max-width: 760px; margin: 0; color: var(--text-muted); line-height: 1.5; }
        .inventory-import-template { flex: 0 0 auto; min-height: 42px; padding: 9px 13px; background: #087f5b; }
        .inventory-import-template:hover { background: #066c4d; }
        .inventory-import-form { display: grid; gap: 9px; }
        .inventory-import-controls { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: stretch; gap: 12px; }
        .inventory-file-input { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; clip-path: inset(50%); border: 0; }
        .inventory-file-picker { display: flex; min-width: 0; min-height: 54px; align-items: center; gap: 12px; padding: 9px 12px; border: 1px dashed #d9c9ba; border-radius: 10px; background: #fff; cursor: pointer; transition: border-color .18s ease, background .18s ease, box-shadow .18s ease; }
        .inventory-file-input:focus + .inventory-file-picker, .inventory-file-picker:hover { border-color: #c46f42; background: #fffaf6; box-shadow: 0 0 0 3px rgba(196, 111, 66, .1); }
        .inventory-file-icon { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border-radius: 9px; background: #f8eee5; color: #b8663d; }
        .inventory-file-copy { display: grid; min-width: 0; gap: 2px; }
        .inventory-file-copy strong { color: #493a30; font-size: 14px; }
        .inventory-file-name { overflow: hidden; color: var(--text-muted); font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }
        .inventory-file-browse { margin-left: auto; padding: 7px 11px; border-radius: 7px; background: #f6f1eb; color: #65564b; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .inventory-import-submit { min-height: 54px; justify-content: center; padding: 10px 17px; border: 0; cursor: pointer; white-space: nowrap; }
        .inventory-import-note { display: flex; align-items: flex-start; gap: 7px; margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.5; }
        .inventory-import-note i { margin-top: 3px; color: #9a745c; }
        .inventory-import-details { margin: 0; padding-left: 22px; color: #6b5141; font-size: 14px; }
        .inventory-import-details li + li { margin-top: 4px; }
        .inventory-note { font-size: 14px; color: var(--text-muted); }
        .inventory-table-wrap { max-height: min(62vh, 680px); overflow: auto; border: 1px solid #eee4da; border-radius: 12px; }
        .inventory-table { width: 100%; min-width: 900px; border-collapse: separate; border-spacing: 0; }
        .inventory-table th, .inventory-table td { padding: 13px 14px; text-align: left; vertical-align: middle; border-bottom: 1px solid #eee7e1; }
        .inventory-table th { position: sticky; top: 0; z-index: 1; background: #f8f4ef; color: #5b4634; font-size: 13px; }
        .inventory-table tbody tr:last-child td { border-bottom: 0; }
        .inventory-table tbody tr:hover { background: #fffaf5; }
        .inventory-stock-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(330px, 100%), 1fr)); gap: 14px; max-height: min(62vh, 680px); overflow-y: auto; padding: 2px; }
        .inventory-search { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; }
        .inventory-search input { flex: 1 1 220px; min-width: 0; min-height: 42px; padding: 9px 12px; border: 1px solid #e4d9cf; border-radius: 9px; }
        .inventory-search button { min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 14px; border: 0; border-radius: 9px; cursor: pointer; color: #fff; background: #087f5b; font-weight: 700; }
        .inventory-search button:hover { background: #066c4d; }
        .inventory-stock-card { display: grid; gap: 14px; min-width: 0; padding: 17px; border: 1px solid #eee4da; border-radius: 13px; background: linear-gradient(145deg, #fff, #fffdfb); transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
        .inventory-stock-card:hover { transform: translateY(-1px); border-color: #dfc8b5; box-shadow: 0 6px 16px rgba(73, 51, 34, .07); }
        .inventory-stock-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .inventory-stock-card h3 { min-width: 0; margin: 0; color: #392c24; font-size: 17px; overflow-wrap: anywhere; }
        .inventory-stock-amount { flex: 0 0 auto; color: #047857; font-size: 17px; font-weight: 800; text-align: right; }
        .inventory-stock-amount.inventory-low { color: #b91c1c; }
        .inventory-stock-amount small { display: block; margin-top: 4px; font-size: 12px; font-weight: 700; }
        .inventory-stock-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .inventory-stock-detail { min-width: 0; padding: 9px 10px; border-radius: 9px; background: #f8f5f1; }
        .inventory-stock-detail span, .inventory-stock-detail strong { display: block; overflow-wrap: anywhere; }
        .inventory-stock-detail span { margin-bottom: 4px; color: var(--text-muted); font-size: 12px; }
        .inventory-stock-detail strong { color: #4c4037; font-size: 13px; }
        .inventory-stock-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding-top: 12px; border-top: 1px solid #eee7e1; }
        .inventory-stock-actions form { display: flex; flex: 1 1 190px; gap: 7px; align-items: center; margin: 0; }
        .inventory-stock-actions input { width: 90px; min-width: 0; min-height: 40px; padding: 7px 9px; border: 1px solid #e4d9cf; border-radius: 8px; }
        .inventory-stock-actions button, .inventory-stock-actions .inventory-link { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 11px; border: 0; border-radius: 8px; cursor: pointer; text-decoration: none; font-weight: 700; color: #fff; background: #c46f42; }
        .inventory-stock-actions .inventory-edit { background: #4b5563; }
        .inventory-stock-actions .inventory-delete-form { flex: 0 1 auto; }
        .inventory-stock-actions .inventory-delete { background: #b91c1c; }
        .inventory-stock-empty { grid-column: 1 / -1; padding: 40px 20px; border: 1px dashed #e5d7ca; border-radius: 12px; color: var(--text-muted); text-align: center; }
        .inventory-low { color: #b91c1c; font-weight: 800; }
        .inventory-low-label { display: inline-block; margin-top: 4px; padding: 3px 7px; border-radius: 999px; background: #fff0ef; font-size: 12px; }
        .inventory-recipe-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(230px, 100%), 1fr)); gap: 12px; max-height: min(62vh, 680px); overflow-y: auto; padding: 2px; }
        .inventory-recipe-link { display: flex; min-height: 58px; align-items: center; gap: 11px; padding: 12px 14px; border: 1px solid #eadfd6; border-radius: 11px; background: #fffaf6; color: #514237; font-weight: 700; text-decoration: none; transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .inventory-recipe-link i { color: #b8663d; font-size: 17px; }
        .inventory-recipe-link:hover { transform: translateY(-1px); border-color: #d6a783; box-shadow: 0 5px 14px rgba(73, 51, 34, .08); }
        .inventory-table--movements { min-width: 900px; }
        .inventory-tabs-ready [data-inventory-panel][hidden] { display: none !important; }
        @media (max-width: 1050px) { .inventory-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .inventory-summary { grid-template-columns: 1fr; } .inventory-tabs { grid-template-columns: repeat(2, minmax(0, 1fr)); } .inventory-panel-heading { align-items: flex-start; flex-direction: column; } }
        @media (max-width: 760px) { .inventory-import-header { align-items: flex-start; flex-direction: column; } }
        @media (max-width: 600px) { .inventory-panel { padding: 17px; } .inventory-form { grid-template-columns: 1fr; gap: 14px; } .inventory-form-submit { grid-column: auto; align-items: stretch; flex-direction: column; } .inventory-form-submit .btn-action { width: 100%; } .inventory-stock-actions form { flex-basis: 100%; } .inventory-stock-actions input { flex: 1; } .inventory-table-wrap { max-height: 65vh; } .inventory-import { padding: 16px; } .inventory-import-template { width: 100%; } .inventory-import-controls { grid-template-columns: 1fr; } .inventory-import-submit { width: 100%; } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <section class="content-card inventory-page">
            <div class="inventory-page-header">
                <div>
                    <h1>Insumos e Inventario</h1>
                    <p>Controla existencias, compras y recetas desde un solo lugar.</p>
                </div>
                <a class="inventory-link" href="#panel-recetas" data-inventory-tab-link="recetas"><i class="fa-solid fa-utensils" aria-hidden="true"></i> Configurar recetas</a>
            </div>

            <?php if ($mensaje !== ''): ?>
                <div class="alert-message <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8'); ?>" role="status">
                    <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php if (is_array($flash_importacion) && !empty($flash_importacion['detalles'])): ?>
                    <ul class="inventory-import-details" role="status">
                        <?php foreach ($flash_importacion['detalles'] as $detalle): ?>
                            <li><?php echo htmlspecialchars((string) $detalle, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                        <?php if (!empty($flash_importacion['detalles_omitidos'])): ?>
                            <li>Y <?php echo (int) $flash_importacion['detalles_omitidos']; ?> fila(s) más omitida(s).</li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <div class="inventory-summary" aria-label="Resumen del inventario">
                <div class="inventory-stat">
                    <span class="inventory-stat-icon"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></span>
                    <div><strong><?php echo $total_insumos; ?></strong><span>Insumos registrados</span></div>
                </div>
                <div class="inventory-stat">
                    <span class="inventory-stat-icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                    <div><strong><?php echo $insumos_stock_bajo; ?></strong><span>Con stock bajo</span></div>
                </div>
                <div class="inventory-stat">
                    <span class="inventory-stat-icon"><i class="fa-solid fa-utensils" aria-hidden="true"></i></span>
                    <div><strong><?php echo count($platillos); ?></strong><span>Platillos para configurar</span></div>
                </div>
            </div>

            <nav class="inventory-tabs" role="tablist" aria-label="Secciones del inventario">
                <a class="inventory-tab" id="tab-existencias" href="#panel-existencias" role="tab" aria-controls="panel-existencias" aria-selected="<?php echo $panel_inicial === 'existencias' ? 'true' : 'false'; ?>" data-inventory-tab="existencias">
                    <i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Existencias
                </a>
                <a class="inventory-tab" id="tab-registro" href="#panel-registro" role="tab" aria-controls="panel-registro" aria-selected="<?php echo $panel_inicial === 'registro' ? 'true' : 'false'; ?>" data-inventory-tab="registro">
                    <i class="fa-solid fa-circle-plus" aria-hidden="true"></i> <?php echo $insumo_editar ? 'Editar insumo' : 'Registrar insumo'; ?>
                </a>
                <a class="inventory-tab" id="tab-recetas" href="#panel-recetas" role="tab" aria-controls="panel-recetas" aria-selected="false" data-inventory-tab="recetas">
                    <i class="fa-solid fa-utensils" aria-hidden="true"></i> Recetas
                </a>
                <a class="inventory-tab" id="tab-movimientos" href="#panel-movimientos" role="tab" aria-controls="panel-movimientos" aria-selected="false" data-inventory-tab="movimientos">
                    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Movimientos
                </a>
            </nav>

            <section class="inventory-panel" id="panel-registro" role="tabpanel" aria-labelledby="tab-registro" data-inventory-panel="registro">
                <div class="inventory-panel-heading">
                    <div>
                        <h2><?php echo $insumo_editar ? 'Editar insumo' : 'Registrar insumo'; ?></h2>
                        <p class="inventory-hint">Indica cuánto contiene cada empaque; el inventario y las recetas usarán la unidad base.</p>
                    </div>
                </div>
                <form method="POST" class="inventory-form">
                        <?php csrf_campo(); ?>
                        <input type="hidden" name="accion" value="<?php echo $insumo_editar ? 'actualizar' : 'crear'; ?>">
                        <?php if ($insumo_editar): ?>
                            <input type="hidden" name="insumo_id" value="<?php echo (int) $insumo_editar['id']; ?>">
                        <?php endif; ?>
                        <div>
                            <label for="nombre">Nombre del insumo</label>
                            <input id="nombre" name="nombre" type="text" maxlength="100" required value="<?php echo htmlspecialchars($insumo_editar['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <?php if (!$insumo_editar): ?>
                            <div>
                                <label for="unidad">Unidad base (para inventario y recetas)</label>
                                <select id="unidad" name="unidad" required>
                                    <?php foreach ($unidades_base as $unidad): ?>
                                        <option value="<?php echo htmlspecialchars($unidad, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($etiquetas_unidad_base[$unidad], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div>
                                <label>Unidad base (no se puede cambiar)</label>
                                <input type="text" value="<?php echo htmlspecialchars($insumo_editar['unidad'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                <p class="inventory-note">Así las recetas guardadas y el stock existente siguen usando la misma unidad.</p>
                            </div>
                        <?php endif; ?>
                        <div>
                            <label for="unidad_compra">Unidad de compra o empaque</label>
                            <input id="unidad_compra" name="unidad_compra" type="text" list="unidadesCompra" maxlength="30" required value="<?php echo htmlspecialchars($insumo_editar['unidad_compra'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. caja, saco, bolsa">
                            <datalist id="unidadesCompra">
                                <option value="caja"><option value="saco"><option value="bolsa"><option value="paquete"><option value="botella"><option value="lata"><option value="unidad">
                            </datalist>
                        </div>
                        <div>
                            <label for="factor_conversion">¿Cuántas unidades base contiene un empaque?</label>
                            <input id="factor_conversion" name="factor_conversion" type="number" min="0.01" max="99999999.99" step="0.01" required value="<?php echo htmlspecialchars((string) ($insumo_editar['factor_conversion'] ?? '1.00'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($insumo_editar): ?>
                                <p class="inventory-note">La equivalencia nueva se aplicará a las próximas compras; no cambia el stock ni las recetas ya guardadas.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="stock_minimo">Aviso de stock bajo (en <?php echo htmlspecialchars($insumo_editar['unidad'] ?? 'unidad base', ENT_QUOTES, 'UTF-8'); ?>)</label>
                            <input id="stock_minimo" name="stock_minimo" type="number" min="0" max="99999999.99" step="0.01" required value="<?php echo htmlspecialchars((string) ($insumo_editar['stock_minimo'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <?php if (!$insumo_editar): ?>
                            <div>
                                <label for="stock_inicial">Existencia inicial (número de empaques)</label>
                                <input id="stock_inicial" name="stock_inicial" type="number" min="0" max="99999999.99" step="0.01" required value="0">
                            </div>
                        <?php endif; ?>
                    <div class="inventory-form-submit">
                        <button type="submit" class="btn-action">
                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                            <?php echo $insumo_editar ? 'Guardar cambios' : 'Registrar insumo'; ?>
                        </button>
                        <?php if ($insumo_editar): ?>
                            <a href="insumos.php" class="inventory-note">Cancelar edición</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if (!$insumo_editar): ?>
                    <section class="inventory-import" aria-labelledby="inventory-import-title">
                        <div class="inventory-import-header">
                            <div>
                                <h3 id="inventory-import-title"><i class="fa-solid fa-file-import" aria-hidden="true"></i> Importar insumos desde Excel</h3>
                                <p>Agrega varios insumos de una vez. Descarga la plantilla y completa una fila por insumo; los nombres ya registrados se omiten sin modificar sus existencias.</p>
                            </div>
                            <a class="inventory-link inventory-import-template" href="descargar_plantilla_insumos.php"><i class="fa-solid fa-download" aria-hidden="true"></i> Descargar plantilla</a>
                        </div>
                        <form method="POST" action="importar_insumos_excel.php" enctype="multipart/form-data" class="inventory-import-form">
                            <?php csrf_campo(); ?>
                            <div class="inventory-import-controls">
                                <input class="inventory-file-input" id="archivo-insumos" name="archivo_insumos" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" aria-describedby="inventory-import-note" required>
                                <label class="inventory-file-picker" for="archivo-insumos">
                                    <span class="inventory-file-icon"><i class="fa-solid fa-file-excel" aria-hidden="true"></i></span>
                                    <span class="inventory-file-copy">
                                        <strong>Selecciona tu archivo</strong>
                                        <span class="inventory-file-name" id="archivo-insumos-nombre" aria-live="polite">Excel (.xlsx) o CSV (.csv)</span>
                                    </span>
                                    <span class="inventory-file-browse">Elegir archivo</span>
                                </label>
                                <button type="submit" class="btn-action inventory-import-submit"><i class="fa-solid fa-file-import" aria-hidden="true"></i> Importar insumos</button>
                            </div>
                            <p class="inventory-import-note" id="inventory-import-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Formatos .xlsx y .csv · Máximo 5 MB y 1,000 filas · La existencia inicial se captura en empaques; las unidades base válidas son unidades, lb, kg, g, litros y ml.</span></p>
                        </form>
                    </section>
                <?php endif; ?>
            </section>

            <section class="inventory-panel" id="panel-existencias" role="tabpanel" aria-labelledby="tab-existencias" data-inventory-panel="existencias">
                <div class="inventory-panel-heading">
                    <div>
                        <h2>Existencias</h2>
                        <p>El stock se muestra en unidad base; las compras se convierten automáticamente.</p>
                    </div>
                    <a
                        class="inventory-link"
                        href="exportar_insumos_excel.php<?php echo $busqueda_insumo !== '' ? '?buscar=' . rawurlencode($busqueda_insumo) : ''; ?>"
                    >
                        <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i>
                        Descargar inventario Excel
                    </a>
                </div>
                <form method="GET" action="insumos.php#panel-existencias" class="inventory-search" role="search">
                    <label class="sr-only" for="buscar-insumo">Buscar insumo por nombre</label>
                    <input id="buscar-insumo" name="buscar" type="search" value="<?php echo htmlspecialchars($busqueda_insumo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nombre del insumo">
                    <button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar insumo</button>
                    <?php if ($busqueda_insumo !== ''): ?>
                        <a class="inventory-link inventory-edit" href="insumos.php#panel-existencias">Limpiar búsqueda</a>
                    <?php endif; ?>
                </form>
                <div class="inventory-stock-grid">
                    <?php foreach ($insumos as $insumo): ?>
                        <?php $bajo = (float) $insumo['cantidad'] <= (float) $insumo['stock_minimo']; ?>
                        <article class="inventory-stock-card">
                            <div class="inventory-stock-card-head">
                                <h3><?php echo htmlspecialchars($insumo['nombre'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <div class="inventory-stock-amount <?php echo $bajo ? 'inventory-low' : ''; ?>">
                                    <?php echo number_format((float) $insumo['cantidad'], 2); ?>
                                    <small><?php echo htmlspecialchars($insumo['unidad'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php if ($bajo): ?><small class="inventory-low-label">Stock bajo</small><?php endif; ?>
                                </div>
                            </div>
                            <div class="inventory-stock-details">
                                <div class="inventory-stock-detail">
                                    <span>Empaque</span>
                                    <strong>1 <?php echo htmlspecialchars($insumo['unidad_compra'], ENT_QUOTES, 'UTF-8'); ?> = <?php echo number_format((float) $insumo['factor_conversion'], 2); ?> <?php echo htmlspecialchars($insumo['unidad'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                                <div class="inventory-stock-detail">
                                    <span>Stock mínimo</span>
                                    <strong><?php echo number_format((float) $insumo['stock_minimo'], 2); ?> <?php echo htmlspecialchars($insumo['unidad'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                                <div class="inventory-stock-detail">
                                    <span>Recetas asociadas</span>
                                    <strong><?php echo (int) $insumo['recetas_asociadas']; ?> platillo(s)</strong>
                                </div>
                            </div>
                            <div class="inventory-stock-actions">
                                <form method="POST">
                                    <?php csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="entrada">
                                    <input type="hidden" name="insumo_id" value="<?php echo (int) $insumo['id']; ?>">
                                    <label class="sr-only" for="entrada-<?php echo (int) $insumo['id']; ?>">Empaques recibidos</label>
                                    <input id="entrada-<?php echo (int) $insumo['id']; ?>" name="cantidad_paquetes" type="number" min="0.01" step="0.01" placeholder="Cantidad" required>
                                    <button type="submit" title="Agregar compra"><i class="fa-solid fa-plus" aria-hidden="true"></i> Entrada</button>
                                </form>
                                <a class="inventory-link inventory-edit" href="insumos.php?editar=<?php echo (int) $insumo['id']; ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar</a>
                                <form method="POST" class="inventory-delete-form" onsubmit="return confirm('¿Eliminar este insumo? También se borrarán sus recetas asociadas y sus movimientos del historial. Esta acción no se puede deshacer.');">
                                    <?php csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="insumo_id" value="<?php echo (int) $insumo['id']; ?>">
                                    <button type="submit" class="inventory-delete" title="Eliminar insumo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$insumos): ?>
                        <div class="inventory-stock-empty"><?php echo $busqueda_insumo !== '' ? 'No se encontraron insumos con ese nombre.' : 'Todavía no hay insumos registrados. Usa la pestaña «Registrar insumo» para comenzar.'; ?></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="inventory-panel" id="panel-recetas" role="tabpanel" aria-labelledby="tab-recetas" data-inventory-panel="recetas">
                <div class="inventory-panel-heading">
                    <div>
                        <h2>Recetas por platillo</h2>
                        <p>Selecciona un platillo para definir cuánto inventario consume cada porción.</p>
                    </div>
                </div>
                <div class="inventory-recipe-grid">
                    <?php foreach ($platillos as $platillo): ?>
                        <a class="inventory-recipe-link" href="recetas_platillo.php?id=<?php echo (int) $platillo['id']; ?>">
                            <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($platillo['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$platillos): ?>
                        <p class="inventory-note">No hay platillos registrados para configurar.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="inventory-panel" id="panel-movimientos" role="tabpanel" aria-labelledby="tab-movimientos" data-inventory-panel="movimientos">
                <div class="inventory-panel-heading">
                    <div>
                        <h2>Últimos movimientos</h2>
                        <p>Consulta las entradas y consumos recientes del inventario.</p>
                    </div>
                </div>
                <div class="inventory-table-wrap">
                    <table class="users-table inventory-table inventory-table--movements">
                                <thead><tr><th>Fecha</th><th>Insumo</th><th>Tipo</th><th>Cantidad base</th><th>Detalle</th><th>Usuario</th></tr></thead>
                                <tbody>
                                    <?php foreach ($movimientos as $movimiento): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($movimiento['fecha'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($movimiento['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($movimiento['tipo']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo number_format((float) $movimiento['cantidad'], 2); ?> <?php echo htmlspecialchars($movimiento['unidad'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($movimiento['nota'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($movimiento['usuario'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$movimientos): ?>
                                        <tr><td colspan="6">Todavía no hay movimientos registrados.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                </div>
            </section>
        </section>
    </main>
    <script>
        (() => {
            const page = document.querySelector('.inventory-page');
            const tabs = Array.from(document.querySelectorAll('[data-inventory-tab]'));
            const panels = Array.from(document.querySelectorAll('[data-inventory-panel]'));
            if (!page || tabs.length === 0 || panels.length === 0) return;

            const initialPanel = <?php echo json_encode($panel_inicial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const activatePanel = (name, updateHash) => {
                const activeTab = tabs.find(tab => tab.dataset.inventoryTab === name);
                const activePanel = panels.find(panel => panel.dataset.inventoryPanel === name);
                if (!activeTab || !activePanel) return;

                tabs.forEach(tab => {
                    const selected = tab === activeTab;
                    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                    tab.tabIndex = selected ? 0 : -1;
                });

                panels.forEach(panel => {
                    const selected = panel === activePanel;
                    panel.hidden = !selected;
                    panel.setAttribute('aria-hidden', selected ? 'false' : 'true');
                    panel.querySelectorAll('input, select, textarea, button').forEach(control => {
                        if (!Object.prototype.hasOwnProperty.call(control.dataset, 'inventoryWasDisabled')) {
                            control.dataset.inventoryWasDisabled = control.disabled ? 'true' : 'false';
                        }
                        control.disabled = !selected || control.dataset.inventoryWasDisabled === 'true';
                    });
                });

                if (updateHash) history.replaceState(null, '', '#' + activePanel.id);
            };

            const hashPanel = panels.find(panel => '#' + panel.id === window.location.hash);
            page.classList.add('inventory-tabs-ready');
            activatePanel(hashPanel ? hashPanel.dataset.inventoryPanel : initialPanel, false);

            tabs.forEach(tab => {
                tab.addEventListener('click', event => {
                    event.preventDefault();
                    activatePanel(tab.dataset.inventoryTab, true);
                });
                tab.addEventListener('keydown', event => {
                    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
                    event.preventDefault();
                    const direction = event.key === 'ArrowRight' ? 1 : -1;
                    const nextTab = tabs[(tabs.indexOf(tab) + direction + tabs.length) % tabs.length];
                    nextTab.focus();
                    activatePanel(nextTab.dataset.inventoryTab, true);
                });
            });

            document.querySelectorAll('[data-inventory-tab-link]').forEach(link => {
                link.addEventListener('click', event => {
                    event.preventDefault();
                    activatePanel(link.dataset.inventoryTabLink, true);
                });
            });
        })();
    </script>
    <script>
        (() => {
            const input = document.getElementById('archivo-insumos');
            const fileName = document.getElementById('archivo-insumos-nombre');
            if (!input || !fileName) return;

            input.addEventListener('change', () => {
                fileName.textContent = input.files && input.files.length
                    ? input.files[0].name
                    : 'Excel (.xlsx) o CSV (.csv)';
            });
        })();
    </script>
</body>
</html>
