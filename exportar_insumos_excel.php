<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';

$busqueda = is_string($_GET['buscar'] ?? null) ? trim($_GET['buscar']) : '';
if (mb_strlen($busqueda, 'UTF-8') > 100) {
    http_response_code(400);
    exit('El texto de búsqueda no es válido.');
}

try {
    $sql = 'SELECT id, nombre, cantidad, unidad, stock_minimo, unidad_compra, factor_conversion
            FROM insumos';
    $parametros = [];
    if ($busqueda !== '') {
        $sql .= " WHERE nombre LIKE :buscar ESCAPE '\\\\'";
        $parametros['buscar'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busqueda) . '%';
    }
    $sql .= ' ORDER BY nombre';

    $consulta = $pdo->prepare($sql);
    $consulta->execute($parametros);
    $insumos = $consulta->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudo exportar el inventario de insumos: ' . $e->getMessage());
    http_response_code(503);
    exit('No se pudo generar el inventario de insumos en este momento.');
}

if (!class_exists('ZipArchive')) {
    error_log('No se pudo exportar el inventario: la extensión PHP zip no está habilitada.');
    http_response_code(503);
    exit('No se pudo generar el archivo Excel porque el servidor no tiene habilitado el soporte ZIP.');
}

$xml_texto = static function ($valor) {
    $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $valor);
    return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
};
$celda_texto = static function ($referencia, $valor, $estilo = null) use ($xml_texto) {
    $estilo_xml = $estilo === null ? '' : ' s="' . $estilo . '"';
    return '<c r="' . $referencia . '" t="inlineStr"' . $estilo_xml . '><is><t xml:space="preserve">'
        . $xml_texto($valor)
        . '</t></is></c>';
};
$celda_numero = static function ($referencia, $valor, $estilo = null) {
    $estilo_xml = $estilo === null ? '' : ' s="' . $estilo . '"';
    return '<c r="' . $referencia . '"' . $estilo_xml . '><v>' . $valor . '</v></c>';
};

$encabezados = [
    'ID',
    'Nombre',
    'Existencia actual',
    'Unidad base',
    'Stock mínimo',
    'Estado de stock',
    'Unidad de compra',
    'Contenido por empaque (unidad base)',
];
$columnas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
$filas = [];
$celdas_encabezado = [];
foreach ($encabezados as $indice => $encabezado) {
    $celdas_encabezado[] = $celda_texto($columnas[$indice] . '1', $encabezado, 1);
}
$filas[] = '<row r="1">' . implode('', $celdas_encabezado) . '</row>';

foreach ($insumos as $indice => $insumo) {
    $fila = $indice + 2;
    $cantidad = (float) $insumo['cantidad'];
    $stock_minimo = (float) $insumo['stock_minimo'];
    $celdas = [
        $celda_numero('A' . $fila, (int) $insumo['id']),
        $celda_texto('B' . $fila, $insumo['nombre']),
        $celda_numero('C' . $fila, number_format($cantidad, 2, '.', '')),
        $celda_texto('D' . $fila, $insumo['unidad']),
        $celda_numero('E' . $fila, number_format($stock_minimo, 2, '.', '')),
        $celda_texto('F' . $fila, $cantidad <= $stock_minimo ? 'Stock bajo' : 'Disponible'),
        $celda_texto('G' . $fila, $insumo['unidad_compra'] ?? ''),
        $celda_numero('H' . $fila, number_format((float) $insumo['factor_conversion'], 2, '.', '')),
    ];
    $filas[] = '<row r="' . $fila . '">' . implode('', $celdas) . '</row>';
}

$ultima_fila = count($filas);
$contenido_hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<cols><col min="1" max="1" width="10" customWidth="1"/>'
    . '<col min="2" max="2" width="32" customWidth="1"/>'
    . '<col min="3" max="3" width="20" customWidth="1"/>'
    . '<col min="4" max="4" width="18" customWidth="1"/>'
    . '<col min="5" max="5" width="16" customWidth="1"/>'
    . '<col min="6" max="6" width="20" customWidth="1"/>'
    . '<col min="7" max="7" width="20" customWidth="1"/>'
    . '<col min="8" max="8" width="40" customWidth="1"/></cols>'
    . '<sheetData>' . implode('', $filas) . '</sheetData>'
    . '<autoFilter ref="A1:H' . $ultima_fila . '"/>'
    . '</worksheet>';

$componentes_xlsx = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Inventario" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF065F46"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    'xl/worksheets/sheet1.xml' => $contenido_hoja,
];

$archivo_temporal = tempnam(sys_get_temp_dir(), 'inventario_');
if ($archivo_temporal === false) {
    error_log('No se pudo crear un archivo temporal para exportar el inventario.');
    http_response_code(503);
    exit('No se pudo preparar el archivo Excel del inventario.');
}

try {
    $zip = new ZipArchive();
    if ($zip->open($archivo_temporal, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear el paquete XLSX.');
    }
    foreach ($componentes_xlsx as $ruta => $contenido) {
        if (!$zip->addFromString($ruta, $contenido)) {
            $zip->close();
            throw new RuntimeException('No se pudo agregar contenido al paquete XLSX.');
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('No se pudo finalizar el paquete XLSX.');
    }
    $tamano = filesize($archivo_temporal);
    if ($tamano === false || $tamano === 0) {
        throw new RuntimeException('El archivo XLSX generado está vacío.');
    }
} catch (RuntimeException $e) {
    error_log('No se pudo exportar el inventario de insumos: ' . $e->getMessage());
    if (!unlink($archivo_temporal)) {
        error_log('No se pudo eliminar el archivo temporal del inventario.');
    }
    http_response_code(503);
    exit('No se pudo generar el archivo Excel del inventario en este momento.');
}

$nombre_archivo = 'inventario_insumos_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . $tamano);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (readfile($archivo_temporal) === false) {
    error_log('No se pudo enviar el archivo Excel del inventario.');
}
if (!unlink($archivo_temporal)) {
    error_log('No se pudo eliminar el archivo temporal del inventario.');
}
