<?php
include 'auth.php';
requerir_rol(['admin', 'gerente_general']);
include 'conexion.php';
require_once __DIR__ . '/funciones.php';

const TAMANO_MAXIMO_IMPORTACION_INSUMOS = 5242880;
const FILAS_MAXIMAS_IMPORTACION_INSUMOS = 1000;

function fallo_importacion_insumos($mensaje, array $detalles = []) {
    $_SESSION['flash_importacion_insumos'] = [
        'tipo' => 'error',
        'mensaje' => $mensaje,
        'detalles' => $detalles,
    ];
    header('Location: insumos.php#panel-registro');
    exit();
}

function normalizar_encabezado_insumos($valor) {
    $valor = trim((string) $valor);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if ($ascii !== false) {
        $valor = $ascii;
    }
    return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $valor));
}

function normalizar_unidad_base_insumo($valor) {
    $clave = normalizar_encabezado_insumos($valor);
    $equivalencias = [
        'unidad' => 'unidades',
        'unidades' => 'unidades',
        'unid' => 'unidades',
        'pieza' => 'unidades',
        'piezas' => 'unidades',
        'pz' => 'unidades',
        'lb' => 'lb',
        'lbs' => 'lb',
        'libra' => 'lb',
        'libras' => 'lb',
        'kg' => 'kg',
        'kilo' => 'kg',
        'kilos' => 'kg',
        'kilogramo' => 'kg',
        'kilogramos' => 'kg',
        'g' => 'g',
        'gr' => 'g',
        'gramo' => 'g',
        'gramos' => 'g',
        'l' => 'litros',
        'lt' => 'litros',
        'lts' => 'litros',
        'litro' => 'litros',
        'litros' => 'litros',
        'ml' => 'ml',
        'mililitro' => 'ml',
        'mililitros' => 'ml',
    ];

    return $equivalencias[$clave] ?? null;
}

function cantidad_insumo_importacion_valida($valor, $permitir_cero = false) {
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

function leer_archivo_csv_insumos($ruta) {
    $contenido = file_get_contents($ruta);
    if ($contenido === false || $contenido === '') {
        throw new RuntimeException('El archivo está vacío o no se pudo leer.');
    }

    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
    }
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    $primera_linea = strtok($contenido, "\r\n");
    $separadores = [',' => substr_count($primera_linea, ','), ';' => substr_count($primera_linea, ';'), "\t" => substr_count($primera_linea, "\t")];
    arsort($separadores);
    $separador = (string) array_key_first($separadores);

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('No se pudo procesar el archivo.');
    }
    fwrite($stream, $contenido);
    rewind($stream);

    $filas = [];
    $numero_fila = 0;
    while (($fila = fgetcsv($stream, 0, $separador, '"', '')) !== false) {
        $numero_fila++;
        $filas[] = [$numero_fila, $fila];
        if ($numero_fila > FILAS_MAXIMAS_IMPORTACION_INSUMOS + 1) {
            fclose($stream);
            throw new RuntimeException('El archivo supera el máximo de ' . FILAS_MAXIMAS_IMPORTACION_INSUMOS . ' filas de insumos.');
        }
    }
    fclose($stream);
    return $filas;
}

function xml_insumos_desde_zip(ZipArchive $zip, $ruta) {
    $contenido = $zip->getFromName($ruta);
    if (!is_string($contenido) || strlen($contenido) > TAMANO_MAXIMO_IMPORTACION_INSUMOS * 4) {
        throw new RuntimeException('El archivo de Excel contiene una hoja demasiado grande o no válida.');
    }
    $xml = simplexml_load_string($contenido, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer la estructura del archivo Excel.');
    }
    return $xml;
}

function leer_archivo_xlsx_insumos($ruta) {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('El servidor no tiene habilitado el soporte necesario para leer archivos .xlsx.');
    }

    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('El archivo Excel no se pudo abrir. Guarda el documento como .xlsx e inténtalo de nuevo.');
    }

    try {
        $tamano_descomprimido = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $estadisticas = $zip->statIndex($i);
            if ($estadisticas !== false) {
                $tamano_descomprimido += (int) $estadisticas['size'];
                if ($tamano_descomprimido > TAMANO_MAXIMO_IMPORTACION_INSUMOS * 10) {
                    throw new RuntimeException('El contenido del Excel supera el tamaño permitido.');
                }
            }
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relaciones_namespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $paquete_namespace = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $libro = xml_insumos_desde_zip($zip, 'xl/workbook.xml');
        $libro->registerXPathNamespace('main', $namespace);
        $hojas = $libro->xpath('/main:workbook/main:sheets/main:sheet');
        $primera_hoja = $hojas[0] ?? null;
        if (!$primera_hoja) {
            throw new RuntimeException('El archivo Excel no contiene una hoja de cálculo.');
        }

        $relacion_id = (string) $primera_hoja->attributes($relaciones_namespace)['id'];
        $relaciones = xml_insumos_desde_zip($zip, 'xl/_rels/workbook.xml.rels');
        $relaciones->registerXPathNamespace('rel', $paquete_namespace);
        $destino_hoja = '';
        foreach ($relaciones->xpath('/rel:Relationships/rel:Relationship') as $relacion) {
            if ((string) $relacion['Id'] === $relacion_id) {
                $destino_hoja = (string) $relacion['Target'];
                break;
            }
        }
        if ($destino_hoja === '' || strpos($destino_hoja, '..') !== false) {
            throw new RuntimeException('No se encontró la primera hoja del archivo Excel.');
        }
        $ruta_hoja = ltrim($destino_hoja, '/');
        if (strpos($ruta_hoja, 'xl/') !== 0) {
            $ruta_hoja = 'xl/' . $ruta_hoja;
        }
        $hoja = xml_insumos_desde_zip($zip, $ruta_hoja);
        $hoja->registerXPathNamespace('main', $namespace);

        $cadenas_compartidas = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $shared = xml_insumos_desde_zip($zip, 'xl/sharedStrings.xml');
            $shared->registerXPathNamespace('main', $namespace);
            foreach ($shared->xpath('/main:sst/main:si') as $cadena) {
                $texto = '';
                foreach ($cadena->children($namespace) as $parte) {
                    if ($parte->getName() === 't') {
                        $texto .= (string) $parte;
                    } elseif ($parte->getName() === 'r') {
                        $texto .= (string) ($parte->children($namespace)->t ?? '');
                    }
                }
                $cadenas_compartidas[] = $texto;
            }
        }

        $filas = [];
        foreach ($hoja->xpath('/main:worksheet/main:sheetData/main:row') as $fila_xml) {
            $numero_fila = (int) $fila_xml['r'];
            $valores = [];
            $fila_xml->registerXPathNamespace('main', $namespace);
            foreach ($fila_xml->xpath('./main:c') as $celda) {
                $celda->registerXPathNamespace('main', $namespace);
                $referencia = (string) $celda['r'];
                if (!preg_match('/^([A-Z]+)/i', $referencia, $coincidencia)) {
                    continue;
                }
                if (strlen($coincidencia[1]) > 3) {
                    throw new RuntimeException('El archivo Excel contiene una referencia de columna no válida.');
                }
                $columna = 0;
                foreach (str_split(strtoupper($coincidencia[1])) as $letra) {
                    $columna = $columna * 26 + ord($letra) - 64;
                }
                if ($columna < 1 || $columna > 16384) {
                    throw new RuntimeException('El archivo Excel contiene una referencia de columna fuera del límite permitido.');
                }
                $indice = $columna - 1;
                $tipo = (string) $celda['t'];
                $valor = '';
                if ($tipo === 'inlineStr') {
                    $texto_inline = $celda->xpath('./main:is/main:t | ./main:is/main:r/main:t');
                    foreach ($texto_inline as $segmento) {
                        $valor .= (string) $segmento;
                    }
                } else {
                    $celda_valor = $celda->xpath('./main:v');
                    $valor = (string) ($celda_valor[0] ?? '');
                    if ($tipo === 's' && $valor !== '') {
                        $valor = $cadenas_compartidas[(int) $valor] ?? '';
                    }
                }
                $valores[$indice] = $valor;
            }
            if ($valores) {
                $maximo = max(array_keys($valores));
                $fila = array_fill(0, $maximo + 1, '');
                foreach ($valores as $indice => $valor) {
                    $fila[$indice] = $valor;
                }
                $filas[] = [$numero_fila, $fila];
            }
            if (count($filas) > FILAS_MAXIMAS_IMPORTACION_INSUMOS + 1) {
                throw new RuntimeException('El archivo supera el máximo de ' . FILAS_MAXIMAS_IMPORTACION_INSUMOS . ' filas de insumos.');
            }
        }
        return $filas;
    } finally {
        $zip->close();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
if (!csrf_peticion_valida()) {
    fallo_importacion_insumos('La solicitud expiró o no es válida. Actualiza la página e inténtalo de nuevo.');
}

$archivo = $_FILES['archivo_insumos'] ?? null;
if (!is_array($archivo) || !isset($archivo['error']) || (int) $archivo['error'] !== UPLOAD_ERR_OK) {
    fallo_importacion_insumos('Selecciona un archivo .xlsx o .csv válido. Verifica también el límite de tamaño de carga.');
}
if (
    !isset($archivo['tmp_name'], $archivo['size'], $archivo['name'])
    || !is_uploaded_file($archivo['tmp_name'])
    || (int) $archivo['size'] <= 0
    || (int) $archivo['size'] > TAMANO_MAXIMO_IMPORTACION_INSUMOS
) {
    fallo_importacion_insumos('El archivo está vacío, supera los 5 MB o no se recibió correctamente.');
}

$extension = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'csv'], true)) {
    fallo_importacion_insumos('El formato no es compatible. Usa un archivo .xlsx o .csv.');
}

try {
    $filas = $extension === 'xlsx'
        ? leer_archivo_xlsx_insumos($archivo['tmp_name'])
        : leer_archivo_csv_insumos($archivo['tmp_name']);
    if (!$filas) {
        throw new RuntimeException('El archivo no contiene una fila de encabezados.');
    }

    $encabezados = array_shift($filas)[1];
    $columnas_requeridas = [
        'nombre' => 'Nombre',
        'unidadbase' => 'Unidad base',
        'unidaddecompra' => 'Unidad de compra',
        'factordeconversion' => 'Factor de conversion',
        'existenciainicialempaques' => 'Existencia inicial (empaques)',
        'stockminimo' => 'Stock minimo',
    ];
    $indice_columnas = [];
    foreach ($encabezados as $indice => $encabezado) {
        $clave = normalizar_encabezado_insumos($encabezado);
        if (isset($columnas_requeridas[$clave])) {
            $indice_columnas[$clave] = $indice;
        }
    }
    $faltantes = array_diff_key($columnas_requeridas, $indice_columnas);
    if ($faltantes) {
        throw new RuntimeException('Faltan columnas requeridas: ' . implode(', ', array_values($faltantes)) . '. Descarga y usa la plantilla.');
    }

    $unidades_base = ['unidades', 'lb', 'kg', 'g', 'litros', 'ml'];
    $insertar = $pdo->prepare('INSERT INTO insumos (nombre, cantidad, unidad, stock_minimo, unidad_compra, factor_conversion) VALUES (:nombre, :cantidad, :unidad, :stock_minimo, :unidad_compra, :factor_conversion)');
    $buscar = $pdo->prepare('SELECT id FROM insumos WHERE nombre = :nombre LIMIT 1');
    $nombres_archivo = [];
    $importados = 0;
    $duplicados = 0;
    $invalidos = 0;
    $detalles = [];
    $filas_con_datos = 0;

    $pdo->beginTransaction();
    foreach ($filas as [$numero_fila, $valores]) {
        $datos = [];
        foreach ($indice_columnas as $clave => $indice) {
            $datos[$clave] = trim((string) ($valores[$indice] ?? ''));
        }
        if (count(array_filter($datos, static function ($valor) { return $valor !== ''; })) === 0) {
            continue;
        }
        $filas_con_datos++;

        $decimal = static function ($valor) {
            if (strpos($valor, ',') !== false && strpos($valor, '.') === false) {
                return str_replace(',', '.', $valor);
            }
            return $valor;
        };
        $factor = $decimal($datos['factordeconversion']);
        $existencia = $decimal($datos['existenciainicialempaques']);
        $stock_minimo = $decimal($datos['stockminimo']);
        $errores_fila = [];

        if ($datos['nombre'] === '' || mb_strlen($datos['nombre'], 'UTF-8') > 100) {
            $errores_fila[] = 'nombre vacío o supera 100 caracteres';
        }
        $unidad_base = normalizar_unidad_base_insumo($datos['unidadbase']);
        if ($unidad_base === null || !in_array($unidad_base, $unidades_base, true)) {
            $errores_fila[] = 'unidad base "' . $datos['unidadbase'] . '" no reconocida (usa unidades/piezas, lb/libras, kg/kilogramos, g/gramos, litros o ml/mililitros)';
        }
        if ($datos['unidaddecompra'] === '' || mb_strlen($datos['unidaddecompra'], 'UTF-8') > 30) {
            $errores_fila[] = 'unidad de compra vacía o supera 30 caracteres';
        }
        if (!cantidad_insumo_importacion_valida($factor)) {
            $errores_fila[] = 'factor de conversión no válido';
        }
        if (!cantidad_insumo_importacion_valida($existencia, true)) {
            $errores_fila[] = 'existencia inicial no válida';
        }
        if (!cantidad_insumo_importacion_valida($stock_minimo, true)) {
            $errores_fila[] = 'stock mínimo no válido';
        }
        if ($errores_fila) {
            $invalidos++;
            if (count($detalles) < 20) {
                $detalles[] = 'Fila ' . (int) $numero_fila . ': ' . implode('; ', $errores_fila) . '.';
            }
            continue;
        }

        $clave_nombre = mb_strtolower($datos['nombre'], 'UTF-8');
        if (isset($nombres_archivo[$clave_nombre])) {
            $duplicados++;
            if (count($detalles) < 20) {
                $detalles[] = 'Fila ' . (int) $numero_fila . ': el nombre está repetido en el archivo; se omitió.';
            }
            continue;
        }
        $nombres_archivo[$clave_nombre] = true;
        $buscar->execute(['nombre' => $datos['nombre']]);
        if ($buscar->fetchColumn()) {
            $duplicados++;
            if (count($detalles) < 20) {
                $detalles[] = 'Fila ' . (int) $numero_fila . ': "' . $datos['nombre'] . '" ya existe; no se modificó.';
            }
            continue;
        }

        $factor = (float) $factor;
        $existencia = (float) $existencia;
        $stock_minimo = (float) $stock_minimo;
        $existencia_base = round($existencia * $factor, 2);
        if ($existencia_base > 99999999.99) {
            $invalidos++;
            if (count($detalles) < 20) {
                $detalles[] = 'Fila ' . (int) $numero_fila . ': la existencia convertida supera el máximo permitido.';
            }
            continue;
        }

        $insertar->execute([
            'nombre' => $datos['nombre'],
            'cantidad' => $existencia_base,
            'unidad' => $unidad_base,
            'stock_minimo' => $stock_minimo,
            'unidad_compra' => $datos['unidaddecompra'],
            'factor_conversion' => $factor,
        ]);
        $insumo_id = (int) $pdo->lastInsertId();
        if ($existencia > 0) {
            registrar_movimiento(
                $pdo,
                $insumo_id,
                'entrada',
                $existencia_base,
                'Inventario inicial importado: ' . $existencia . ' ' . $datos['unidaddecompra'] . ' × ' . $factor . ' ' . $unidad_base,
                $_SESSION['usuario'] ?? '',
                true
            );
        }
        $importados++;
    }

    if ($filas_con_datos === 0) {
        throw new RuntimeException('No hay filas de insumos para importar.');
    }
    $pdo->commit();

    $mensaje = 'Importación terminada: ' . $importados . ' insumo(s) agregado(s), '
        . $duplicados . ' duplicado(s) omitido(s) y ' . $invalidos . ' fila(s) no válida(s). '
        . 'Los insumos ya registrados no se modifican.';
    $_SESSION['flash_importacion_insumos'] = [
        'tipo' => $importados > 0 ? 'success' : 'error',
        'mensaje' => $mensaje,
        'detalles' => $detalles,
        'detalles_omitidos' => max(0, $duplicados + $invalidos - count($detalles)),
    ];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException) {
        error_log('No se pudo importar el inventario de insumos: ' . $e->getMessage());
        $mensaje = 'Ocurrió un error al guardar los insumos. No se aplicó la importación; revisa el archivo e inténtalo de nuevo.';
    } else {
        $mensaje = $e->getMessage();
    }
    $_SESSION['flash_importacion_insumos'] = [
        'tipo' => 'error',
        'mensaje' => $mensaje,
        'detalles' => [],
    ];
}

header('Location: insumos.php#panel-registro');
exit();
