<?php

function documentos_personal_definicion() {
    return [
        'documento_cv' => [
            'archivo' => 'documento_cv',
            'etiqueta' => 'CV actualizado o solicitud de empleo firmada',
        ],
        'documento_identidad' => [
            'archivo' => 'documento_identidad',
            'etiqueta' => 'Copia de documento de identidad',
        ],
        'documento_estudios' => [
            'archivo' => 'documento_estudios',
            'etiqueta' => 'Constancias de estudio o títulos',
        ],
        'documento_antecedentes' => [
            'archivo' => 'documento_antecedentes',
            'etiqueta' => 'Certificado de antecedentes penales o policiales',
        ],
    ];
}

function ruta_documentos_personal() {
    $configurada = getenv('PERSONAL_DOCUMENTOS_DIR');
    if ($configurada !== false && trim($configurada) !== '') {
        return rtrim($configurada, DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'documentos_personal_privados';
}

function procesar_documentos_personal(array $archivos, array $existentes = []) {
    $definicion = documentos_personal_definicion();
    $rutas = [];
    $nuevos = [];
    $directorio = ruta_documentos_personal();
    $tipos_permitidos = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $maximo_bytes = 1572864;

    if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento privado de documentos.');
    }
    if (!is_writable($directorio)) {
        throw new RuntimeException('El almacenamiento privado de documentos no permite escritura.');
    }

    try {
        foreach ($definicion as $columna => $documento) {
            $campo = $documento['archivo'];
            $archivo = $archivos[$campo] ?? null;
            $ruta_existente = $existentes[$columna] ?? null;

            if (!is_array($archivo) || !isset($archivo['error'])) {
                throw new RuntimeException('No se recibió el archivo requerido: ' . $documento['etiqueta'] . '.');
            }

            if ((int) $archivo['error'] === UPLOAD_ERR_NO_FILE) {
                if (is_string($ruta_existente) && $ruta_existente !== '') {
                    $rutas[$columna] = $ruta_existente;
                    continue;
                }
                throw new RuntimeException('Debes adjuntar: ' . $documento['etiqueta'] . '.');
            }

            if ((int) $archivo['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('No se pudo recibir: ' . $documento['etiqueta'] . '. Verifica el tamaño del archivo e inténtalo de nuevo.');
            }
            if (!isset($archivo['size']) || (int) $archivo['size'] <= 0 || (int) $archivo['size'] > $maximo_bytes) {
                throw new RuntimeException('Cada documento debe pesar menos de 1.5 MB: ' . $documento['etiqueta'] . '.');
            }
            if (!isset($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name'])) {
                throw new RuntimeException('El archivo recibido no es válido: ' . $documento['etiqueta'] . '.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($archivo['tmp_name']);
            if (!isset($tipos_permitidos[$mime])) {
                throw new RuntimeException('Usa un archivo PDF, JPG o PNG para: ' . $documento['etiqueta'] . '.');
            }

            $nombre_guardado = bin2hex(random_bytes(24)) . '.' . $tipos_permitidos[$mime];
            $destino = $directorio . DIRECTORY_SEPARATOR . $nombre_guardado;
            if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
                throw new RuntimeException('No se pudo guardar: ' . $documento['etiqueta'] . '.');
            }

            $rutas[$columna] = $nombre_guardado;
            $nuevos[] = $nombre_guardado;
        }
    } catch (Throwable $e) {
        eliminar_archivos_personal($nuevos);
        throw $e;
    }

    return ['rutas' => $rutas, 'nuevos' => $nuevos];
}

function eliminar_archivos_personal(array $nombres) {
    $directorio = ruta_documentos_personal();
    foreach ($nombres as $nombre) {
        if (!is_string($nombre) || !preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|png)$/', $nombre)) {
            continue;
        }
        $ruta = $directorio . DIRECTORY_SEPARATOR . $nombre;
        if (is_file($ruta) && !unlink($ruta)) {
            error_log('No se pudo eliminar un documento privado del personal.');
        }
    }
}

function validar_fecha_nacimiento_personal($fecha) {
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return false;
    }

    $zona = new DateTimeZone('America/Tegucigalpa');
    $fecha_objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $zona);
    $errores = DateTimeImmutable::getLastErrors();

    return $fecha_objeto
        && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))
        && $fecha_objeto->format('Y-m-d') === $fecha
        && $fecha_objeto <= new DateTimeImmutable('today', $zona);
}
