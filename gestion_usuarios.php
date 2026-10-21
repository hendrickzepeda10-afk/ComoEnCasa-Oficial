<?php
include 'auth.php';
requerir_rol(['admin']);
include 'conexion.php';
require_once __DIR__ . '/personal_lib.php';

$mensaje = '';
$tipo_mensaje = '';
$csrf = csrf_token();
$roles_validos = roles_asignables();
$acciones_mutables = ['toggle', 'eliminar', 'editar'];
$accion = is_scalar($_POST['accion'] ?? null) ? (string) $_POST['accion'] : '';
$filtro_post = is_scalar($_POST['rol_filtro'] ?? null) ? trim((string) $_POST['rol_filtro']) : '';
if (!in_array($filtro_post, $roles_validos, true)) {
    $filtro_post = '';
}

$csrf_valido = $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !in_array($accion, $acciones_mutables, true)
    || csrf_token_valido($_POST['csrf_token'] ?? null);
if (!$csrf_valido) {
    http_response_code(403);
    $mensaje = 'La solicitud expiró o no es válida. Actualiza la página e inténtalo de nuevo.';
    $tipo_mensaje = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['toggle']) || isset($_GET['eliminar']))) {
    http_response_code(405);
    $mensaje = 'Esta acción debe enviarse desde los controles de la página.';
    $tipo_mensaje = 'error';
}

// 1. Lógica para cambiar el estado (Inhabilitar / Habilitar)
if ($csrf_valido && $_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'toggle') {
    $id_toggle = is_scalar($_POST['id'] ?? null) && ctype_digit((string) $_POST['id'])
        ? intval($_POST['id'])
        : 0;
    if ($id_toggle === intval($_SESSION['user_id'])) {
        header("Location: gestion_usuarios.php?mensaje=propio" . ($filtro_post !== '' ? '&rol=' . urlencode($filtro_post) : ''));
        exit();
    }
    try {
        $stmt_s = $pdo->prepare("SELECT estado FROM usuarios WHERE id = :id");
        $stmt_s->execute(['id' => $id_toggle]);
        $usr = $stmt_s->fetch(PDO::FETCH_ASSOC);
        
        if ($usr) {
            $nuevo_estado = ($usr['estado'] == 1) ? 0 : 1;
            $stmt_upd = $pdo->prepare("UPDATE usuarios SET estado = :estado WHERE id = :id");
            $stmt_upd->execute(['estado' => $nuevo_estado, 'id' => $id_toggle]);
            
            $filtro_redirection = $filtro_post !== '' ? '?rol=' . urlencode($filtro_post) : '';
            header("Location: gestion_usuarios.php" . $filtro_redirection);
            exit();
        }
    } catch (PDOException $e) {
        error_log('No se pudo cambiar el estado de una cuenta: ' . $e->getMessage());
        $mensaje = "No se pudo cambiar el estado de la cuenta. Inténtalo de nuevo.";
        $tipo_mensaje = "error";
    }
}

// 2. Lógica para eliminar un usuario
if ($csrf_valido && $_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar') {
    $id_eliminar = is_scalar($_POST['id'] ?? null) && ctype_digit((string) $_POST['id'])
        ? intval($_POST['id'])
        : 0;
    if ($id_eliminar === intval($_SESSION['user_id'])) {
        header("Location: gestion_usuarios.php?mensaje=propio" . ($filtro_post !== '' ? '&rol=' . urlencode($filtro_post) : ''));
        exit();
    }
    try {
        $stmt_docs = $pdo->prepare("SELECT documento_cv, documento_identidad, documento_estudios, documento_antecedentes FROM usuarios WHERE id = :id");
        $stmt_docs->execute(['id' => $id_eliminar]);
        $documentos_usuario = $stmt_docs->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt_del = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt_del->execute(['id' => $id_eliminar]);
        eliminar_archivos_personal(array_values($documentos_usuario));
        
        $filtro_redirection = $filtro_post !== '' ? '?rol=' . urlencode($filtro_post) : '';
        header("Location: gestion_usuarios.php" . $filtro_redirection . (!empty($filtro_redirection) ? '&' : '?') . 'mensaje=eliminado');
        exit();
    } catch (PDOException $e) {
        error_log('No se pudo eliminar una cuenta: ' . $e->getMessage());
        $mensaje = "No se pudo eliminar la cuenta. Inténtalo de nuevo.";
        $tipo_mensaje = "error";
    }
}

// 3. Lógica para procesar la edición de un usuario
if ($csrf_valido && $_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'editar') {
    $id_editar = is_scalar($_POST['id'] ?? null) && ctype_digit((string) $_POST['id'])
        ? intval($_POST['id'])
        : 0;
    $usuario_ingresado = $_POST['usuario'] ?? '';
    $rol_ingresado = $_POST['rol'] ?? '';
    $password_ingresada = $_POST['password'] ?? '';
    $nombre_ingresado = $_POST['nombre_completo'] ?? '';
    $dni_ingresado = $_POST['dni'] ?? '';
    $telefono_ingresado = $_POST['telefono'] ?? '';
    $fecha_nacimiento_ingresada = $_POST['fecha_nacimiento'] ?? '';
    $correo_ingresado = $_POST['correo_electronico'] ?? '';
    $domicilio_ingresado = $_POST['domicilio'] ?? '';
    $emergencia_nombre_ingresado = $_POST['contacto_emergencia_nombre'] ?? '';
    $emergencia_telefono_ingresado = $_POST['contacto_emergencia_telefono'] ?? '';
    $turno_ingresado = $_POST['turno'] ?? '';
    $usuario = is_scalar($usuario_ingresado) ? trim((string) $usuario_ingresado) : '';
    $rol = is_scalar($rol_ingresado) ? trim((string) $rol_ingresado) : '';
    $password = is_scalar($password_ingresada) ? (string) $password_ingresada : '';
    $nombre = is_scalar($nombre_ingresado) ? trim((string) $nombre_ingresado) : '';
    $dni = is_scalar($dni_ingresado) ? trim((string) $dni_ingresado) : '';
    $telefono = is_scalar($telefono_ingresado) ? trim((string) $telefono_ingresado) : '';
    $fecha_nacimiento = is_scalar($fecha_nacimiento_ingresada) ? trim((string) $fecha_nacimiento_ingresada) : '';
    $correo = is_scalar($correo_ingresado) ? trim((string) $correo_ingresado) : '';
    $domicilio = is_scalar($domicilio_ingresado) ? trim((string) $domicilio_ingresado) : '';
    $emergencia_nombre = is_scalar($emergencia_nombre_ingresado) ? trim((string) $emergencia_nombre_ingresado) : '';
    $emergencia_telefono = is_scalar($emergencia_telefono_ingresado) ? trim((string) $emergencia_telefono_ingresado) : '';
    $turno = is_scalar($turno_ingresado) ? trim((string) $turno_ingresado) : '';
    $documentos_nuevos = [];

    if (
        $id_editar > 0
        && $usuario !== ''
        && mb_strlen($usuario, 'UTF-8') <= 50
        && ($password === '' || mb_strlen($password, 'UTF-8') >= 8)
        && mb_strlen($nombre, 'UTF-8') <= 150
        && mb_strlen($dni, 'UTF-8') <= 30
        && mb_strlen($telefono, 'UTF-8') <= 30
        && mb_strlen($correo, 'UTF-8') <= 254
        && mb_strlen($domicilio, 'UTF-8') <= 255
        && mb_strlen($emergencia_nombre, 'UTF-8') <= 150
        && mb_strlen($emergencia_telefono, 'UTF-8') <= 30
        && in_array($rol, $roles_validos, true)
        && $nombre !== ''
        && $dni !== ''
        && $telefono !== ''
        && validar_fecha_nacimiento_personal($fecha_nacimiento)
        && filter_var($correo, FILTER_VALIDATE_EMAIL)
        && $domicilio !== ''
        && $emergencia_nombre !== ''
        && $emergencia_telefono !== ''
        && in_array($turno, ['mañana', 'tarde', 'noche'], true)
    ) {
        try {
            $stmt_actual = $pdo->prepare("SELECT documento_cv, documento_identidad, documento_estudios, documento_antecedentes FROM usuarios WHERE id = :id");
            $stmt_actual->execute(['id' => $id_editar]);
            $datos_actuales = $stmt_actual->fetch(PDO::FETCH_ASSOC);
            if (!$datos_actuales) {
                throw new RuntimeException('No se encontró la cuenta que intentas actualizar.');
            }
            $documentos = procesar_documentos_personal($_FILES, $datos_actuales);
            $documentos_nuevos = $documentos['nuevos'];

            $perfil = [
                'usuario' => $usuario,
                'rol' => $rol,
                'nombre_completo' => $nombre,
                'fecha_nacimiento' => $fecha_nacimiento,
                'dni' => $dni,
                'telefono' => $telefono,
                'correo_electronico' => $correo,
                'domicilio' => $domicilio,
                'contacto_emergencia_nombre' => $emergencia_nombre,
                'contacto_emergencia_telefono' => $emergencia_telefono,
                'turno' => $turno,
                'documento_cv' => $documentos['rutas']['documento_cv'],
                'documento_identidad' => $documentos['rutas']['documento_identidad'],
                'documento_estudios' => $documentos['rutas']['documento_estudios'],
                'documento_antecedentes' => $documentos['rutas']['documento_antecedentes'],
                'id' => $id_editar
            ];
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET usuario = :usuario, rol = :rol, nombre_completo = :nombre_completo, fecha_nacimiento = :fecha_nacimiento, dni = :dni, telefono = :telefono, correo_electronico = :correo_electronico, domicilio = :domicilio, contacto_emergencia_nombre = :contacto_emergencia_nombre, contacto_emergencia_telefono = :contacto_emergencia_telefono, turno = :turno, documento_cv = :documento_cv, documento_identidad = :documento_identidad, documento_estudios = :documento_estudios, documento_antecedentes = :documento_antecedentes, password = :password WHERE id = :id");
                $stmt->execute($perfil + ['password' => $password_hash]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET usuario = :usuario, rol = :rol, nombre_completo = :nombre_completo, fecha_nacimiento = :fecha_nacimiento, dni = :dni, telefono = :telefono, correo_electronico = :correo_electronico, domicilio = :domicilio, contacto_emergencia_nombre = :contacto_emergencia_nombre, contacto_emergencia_telefono = :contacto_emergencia_telefono, turno = :turno, documento_cv = :documento_cv, documento_identidad = :documento_identidad, documento_estudios = :documento_estudios, documento_antecedentes = :documento_antecedentes WHERE id = :id");
                $stmt->execute($perfil);
            }
            $archivos_reemplazados = [];
            foreach (documentos_personal_definicion() as $columna => $documento) {
                if ($datos_actuales[$columna] !== $documentos['rutas'][$columna]) {
                    $archivos_reemplazados[] = $datos_actuales[$columna];
                }
            }
            eliminar_archivos_personal($archivos_reemplazados);
            
            $filtro_redirection = $filtro_post !== '' ? '&rol=' . urlencode($filtro_post) : '';
            header("Location: gestion_usuarios.php?mensaje=actualizado" . $filtro_redirection);
            exit();

        } catch (Throwable $e) {
            eliminar_archivos_personal($documentos_nuevos);
            if ($e instanceof PDOException) {
                error_log('No se pudo actualizar una cuenta: ' . $e->getMessage());
                $mensaje = "No se pudo actualizar la cuenta. Inténtalo de nuevo.";
            } else {
                $mensaje = $e->getMessage();
            }
            $tipo_mensaje = "error";
        }
    } else {
        $mensaje = "Verifica los datos personales y de emergencia, el turno y que la nueva contraseña tenga al menos 8 caracteres.";
        $tipo_mensaje = "error";
    }
}

// Capturar mensajes por GET
if (isset($_GET['mensaje'])) {
    if ($_GET['mensaje'] == 'actualizado') {
        $mensaje = "¡Usuario actualizado exitosamente!";
        $tipo_mensaje = "success";
    } elseif ($_GET['mensaje'] == 'eliminado') {
        $mensaje = "¡Usuario eliminado exitosamente!";
        $tipo_mensaje = "success";
    } elseif ($_GET['mensaje'] == 'propio') {
        $mensaje = "No puedes inhabilitar ni eliminar tu propio usuario.";
        $tipo_mensaje = "error";
    }
}

// Obtener datos del usuario a editar
$usuario_editar = null;
if (isset($_GET['editar']) && is_scalar($_GET['editar']) && ctype_digit((string) $_GET['editar'])) {
    $id_edit = intval($_GET['editar']);
    $stmt_e = $pdo->prepare("SELECT id, usuario, rol, nombre_completo, fecha_nacimiento, dni, telefono, correo_electronico, domicilio, contacto_emergencia_nombre, contacto_emergencia_telefono, turno, documento_cv, documento_identidad, documento_estudios, documento_antecedentes FROM usuarios WHERE id = :id");
    $stmt_e->execute(['id' => $id_edit]);
    $usuario_editar = $stmt_e->fetch(PDO::FETCH_ASSOC);
}

$usuario_expediente = null;
if (isset($_GET['expediente'])) {
    $id_expediente = is_scalar($_GET['expediente']) && ctype_digit((string) $_GET['expediente'])
        ? intval($_GET['expediente'])
        : 0;
    if ($id_expediente > 0) {
        try {
            $stmt_expediente = $pdo->prepare("SELECT id, usuario, rol, nombre_completo, fecha_nacimiento, dni, telefono, correo_electronico, domicilio, contacto_emergencia_nombre, contacto_emergencia_telefono, turno, documento_cv, documento_identidad, documento_estudios, documento_antecedentes FROM usuarios WHERE id = :id");
            $stmt_expediente->execute(['id' => $id_expediente]);
            $usuario_expediente = $stmt_expediente->fetch(PDO::FETCH_ASSOC);
            if (!$usuario_expediente) {
                $mensaje = 'No se encontró el expediente solicitado.';
                $tipo_mensaje = 'error';
            }
        } catch (PDOException $e) {
            error_log('No se pudo cargar el expediente desde la gestión de usuarios: ' . $e->getMessage());
            http_response_code(503);
            $mensaje = 'No se pudo cargar el expediente. Inténtalo de nuevo.';
            $tipo_mensaje = 'error';
        }
    } else {
        http_response_code(400);
        $mensaje = 'El expediente solicitado no es válido.';
        $tipo_mensaje = 'error';
    }
}

// 4. Capturar el filtro de rol
$filtro_rol = isset($_GET['rol']) && is_scalar($_GET['rol']) ? trim((string) $_GET['rol']) : '';
if (!in_array($filtro_rol, $roles_validos, true)) {
    $filtro_rol = '';
}

try {
    if (!empty($filtro_rol)) {
        $stmt = $pdo->prepare("SELECT id, usuario, rol, nombre_completo, fecha_nacimiento, dni, telefono, correo_electronico, domicilio, contacto_emergencia_nombre, contacto_emergencia_telefono, turno, estado FROM usuarios WHERE rol = :rol ORDER BY id DESC");
        $stmt->execute(['rol' => $filtro_rol]);
    } else {
        $stmt = $pdo->query("SELECT id, usuario, rol, nombre_completo, fecha_nacimiento, dni, telefono, correo_electronico, domicilio, contacto_emergencia_nombre, contacto_emergencia_telefono, turno, estado FROM usuarios ORDER BY id DESC");
    }
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudo cargar la lista de usuarios: ' . $e->getMessage());
    $usuarios = [];
    $mensaje = "No se pudo cargar la lista de usuarios. Verifica que se hayan ejecutado las migraciones de personal y expedientes.";
    $tipo_mensaje = "error";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    
</head>
<body>

    <?php include 'header.php'; ?>

    <div class="main-content wide-page">
        
        <div class="content-card user-management-card wide-page-card" style="text-align: left;">
            
            <div class="header-action-bar">
                <div>
                    <h1 style="font-size: 24px; margin-bottom: 5px;">Gestión de Usuarios</h1>
                    <p style="margin: 0; font-size: 14px;">Filtra y administra los accesos y roles del personal.</p>
                </div>
                <a href="crear_usuario.php" class="btn-action" style="padding: 10px 18px; font-size: 13.5px;">
                    <i class="fa-solid fa-user-plus"></i> Nuevo Usuario
                </a>
            </div>

            <?php if (!empty($mensaje)): ?>
                <div class="alert-message <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8'); ?>" role="status">
                    <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <!-- BARRA DE FILTROS POR ROL (generada desde el catálogo único de roles) -->
            <div class="filter-bar user-role-filters" aria-label="Filtrar usuarios por rol">
                <a href="gestion_usuarios.php" class="filter-btn <?php echo empty($filtro_rol) ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i> Todos
                </a>
                <?php foreach (ROLES_ASIGNABLES as $rol_valor => $rol_etiqueta): ?>
                    <a href="gestion_usuarios.php?rol=<?php echo urlencode($rol_valor); ?>" class="filter-btn <?php echo ($filtro_rol === $rol_valor) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($rol_etiqueta, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($usuario_expediente): ?>
                <?php
                    $rol_expediente = strtolower(trim($usuario_expediente['rol'] ?? ''));
                    if ($rol_expediente === 'gerente') {
                        $rol_expediente = 'gerente_general';
                    }
                ?>
                <section id="expediente" aria-labelledby="titulo-expediente" style="background-color: #fbf8f5; border: 1px solid #ebdcd0; padding: 25px; border-radius: 12px; margin-bottom: 25px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
                        <div>
                            <h2 id="titulo-expediente" style="margin: 0 0 5px; color: var(--accent); font-size: 20px;">
                                <i class="fa-solid fa-folder-open"></i> Expediente de <?php echo htmlspecialchars($usuario_expediente['nombre_completo'] ?: $usuario_expediente['usuario'], ENT_QUOTES, 'UTF-8'); ?>
                            </h2>
                            <p style="margin: 0;">Datos personales, turno y documentos guardados en esta cuenta.</p>
                        </div>
                        <a href="gestion_usuarios.php?editar=<?php echo intval($usuario_expediente['id']); ?><?php echo !empty($filtro_rol) ? '&rol=' . urlencode($filtro_rol) : ''; ?>#formulario-edicion" class="btn-action-table btn-edit">
                            <i class="fa-solid fa-pen"></i> Editar expediente
                        </a>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 20px;">
                        <p><strong>Usuario:</strong> <?php echo htmlspecialchars($usuario_expediente['usuario'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Rol:</strong> <?php echo htmlspecialchars(rol_etiqueta($rol_expediente), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Fecha de nacimiento:</strong> <?php echo htmlspecialchars($usuario_expediente['fecha_nacimiento'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Identidad:</strong> <?php echo htmlspecialchars($usuario_expediente['dni'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($usuario_expediente['telefono'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Correo electrónico:</strong> <?php echo htmlspecialchars($usuario_expediente['correo_electronico'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Domicilio:</strong> <?php echo htmlspecialchars($usuario_expediente['domicilio'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Turno:</strong> <?php echo htmlspecialchars($usuario_expediente['turno'] ? ucfirst($usuario_expediente['turno']) : 'Sin asignar', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Contacto de emergencia:</strong> <?php echo htmlspecialchars($usuario_expediente['contacto_emergencia_nombre'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($usuario_expediente['contacto_emergencia_telefono'] ?: 'Pendiente', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>

                    <div class="personnel-documents" aria-label="Documentos del expediente">
                        <?php foreach (documentos_personal_definicion() as $columna => $documento): ?>
                            <?php if (!empty($usuario_expediente[$columna])): ?>
                                <a href="descargar_documento_personal.php?id=<?php echo intval($usuario_expediente['id']); ?>&tipo=<?php echo urlencode($columna); ?>" class="personnel-document-link">
                                    <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php else: ?>
                                <span class="personnel-document-pending" aria-label="<?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?> pendiente">
                                    <i class="fa-regular fa-file" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>: pendiente
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- FORMULARIO DE EDICIÓN -->
            <?php if ($usuario_editar): ?>
                <div id="formulario-edicion" style="background-color: #fbf8f5; border: 1px solid #ebdcd0; padding: 25px; border-radius: 12px; margin-bottom: 25px;">
                    <h3 style="margin-top: 0; color: var(--accent); font-size: 18px;">
                        <i class="fa-solid fa-user-pen"></i> Editando usuario y expediente: <?php echo htmlspecialchars($usuario_editar['usuario']); ?>
                    </h3>
                    
                    <form method="POST" action="gestion_usuarios.php" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 12px;">
                        <input type="hidden" name="accion" value="editar">
                        <input type="hidden" name="id" value="<?php echo $usuario_editar['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="rol_filtro" value="<?php echo htmlspecialchars($filtro_rol, ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <div>
                            <label for="usuario" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Usuario:</label>
                            <input type="text" name="usuario" id="usuario" value="<?php echo htmlspecialchars($usuario_editar['usuario'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="50" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                        </div>

                        <div>
                            <label for="password" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Nueva Contraseña <span style="font-weight: normal; color: var(--text-muted);">(Déjalo en blanco si no deseas cambiarla):</span></label>
                            <input type="password" name="password" id="password" minlength="8" placeholder="••••••••" autocomplete="new-password" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                        </div>

                        <div>
                            <label for="rol" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Rol:</label>
                            <?php
                                $current_rol = strtolower(trim($usuario_editar['rol'] ?? ''));
                                // 'gerente' es el nombre antiguo de 'gerente_general'.
                                if ($current_rol === 'gerente') {
                                    $current_rol = 'gerente_general';
                                }
                            ?>
                            <select name="rol" id="rol" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; background: #fff;">
                                <?php if (!array_key_exists($current_rol, ROLES_ASIGNABLES)): ?>
                                    <option value="" selected disabled>Selecciona un rol de personal para esta cuenta antigua</option>
                                <?php endif; ?>
                                <?php foreach (ROLES_ASIGNABLES as $rol_valor => $rol_etiqueta): ?>
                                    <option value="<?php echo htmlspecialchars($rol_valor, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($current_rol === $rol_valor) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rol_etiqueta, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (rol_es_legacy($current_rol)): ?>
                                <small style="display: block; margin-top: 5px; color: #b45309;">
                                    Esta cuenta tiene un rol retirado y no puede entrar al sistema. Asígnale un rol de personal para devolverle el acceso.
                                </small>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div>
                                <label for="nombre_completo" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Nombre completo:</label>
                                <input type="text" name="nombre_completo" id="nombre_completo" required maxlength="150" value="<?php echo htmlspecialchars($usuario_editar['nombre_completo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                            <div>
                                <label for="fecha_nacimiento" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Fecha de nacimiento:</label>
                                <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($usuario_editar['fecha_nacimiento'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                            <div>
                                <label for="dni" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Número de identidad:</label>
                                <input type="text" name="dni" id="dni" required maxlength="30" value="<?php echo htmlspecialchars($usuario_editar['dni'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                            <div>
                                <label for="telefono" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Teléfono de contacto:</label>
                                <input type="tel" name="telefono" id="telefono" required maxlength="30" value="<?php echo htmlspecialchars($usuario_editar['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                            <div>
                                <label for="correo_electronico" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Correo electrónico:</label>
                                <input type="email" name="correo_electronico" id="correo_electronico" required maxlength="254" value="<?php echo htmlspecialchars($usuario_editar['correo_electronico'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                            <div>
                                <label for="domicilio" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Dirección de domicilio:</label>
                                <textarea name="domicilio" id="domicilio" required maxlength="255" rows="2" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;"><?php echo htmlspecialchars($usuario_editar['domicilio'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div>
                                <label for="contacto_emergencia_nombre" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Contacto de emergencia (nombre):</label>
                                <input type="text" name="contacto_emergencia_nombre" id="contacto_emergencia_nombre" required maxlength="150" value="<?php echo htmlspecialchars($usuario_editar['contacto_emergencia_nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                            <div>
                                <label for="contacto_emergencia_telefono" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Contacto de emergencia (teléfono):</label>
                                <input type="tel" name="contacto_emergencia_telefono" id="contacto_emergencia_telefono" required maxlength="30" value="<?php echo htmlspecialchars($usuario_editar['contacto_emergencia_telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                            </div>
                        </div>

                        <div>
                            <label for="turno" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Turno asignado:</label>
                            <select name="turno" id="turno" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ebdcd0; background: #fff;">
                                <option value="">Selecciona un turno</option>
                                <option value="mañana" <?php echo ($usuario_editar['turno'] ?? '') === 'mañana' ? 'selected' : ''; ?>>Mañana</option>
                                <option value="tarde" <?php echo ($usuario_editar['turno'] ?? '') === 'tarde' ? 'selected' : ''; ?>>Tarde</option>
                                <option value="noche" <?php echo ($usuario_editar['turno'] ?? '') === 'noche' ? 'selected' : ''; ?>>Noche</option>
                            </select>
                        </div>

                        <fieldset style="display: flex; flex-direction: column; gap: 12px; padding: 16px; border: 1px solid #ebdcd0; border-radius: 10px;">
                            <legend style="padding: 0 6px; font-weight: 700;">Documentación (PDF, JPG o PNG; máximo 1.5 MB cada uno)</legend>
                            <?php foreach (documentos_personal_definicion() as $columna => $documento): ?>
                                <div>
                                    <label for="<?php echo htmlspecialchars($documento['archivo'], ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">
                                        <?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>:
                                    </label>
                                    <?php if (!empty($usuario_editar[$columna])): ?>
                                        <small style="display: block; margin-bottom: 5px;">Documento recibido. Adjunta otro solo si deseas reemplazarlo.</small>
                                    <?php endif; ?>
                                    <input type="file" name="<?php echo htmlspecialchars($documento['archivo'], ENT_QUOTES, 'UTF-8'); ?>" id="<?php echo htmlspecialchars($documento['archivo'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo empty($usuario_editar[$columna]) ? 'required' : ''; ?> accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" style="width: 100%;">
                                </div>
                            <?php endforeach; ?>
                        </fieldset>

                        <div class="report-controls">
                            <button type="submit" class="btn-action" style="padding: 10px 20px; border: none; cursor: pointer;">Guardar Cambios</button>
                            <a href="gestion_usuarios.php" style="background-color: #e5e0db; color: var(--text-main); padding: 10px 20px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center;">Cancelar</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- TABLA DE USUARIOS -->
            <div class="table-container user-table-wrap">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($usuarios) > 0): ?>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td>#<?php echo $u['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if (!empty($u['nombre_completo'])): ?>
                                            <br><small><?php echo htmlspecialchars($u['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($u['dni'])): ?>
                                            <br><small>DNI: <?php echo htmlspecialchars($u['dni'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($u['telefono'])): ?>
                                            <br><small>Tel.: <?php echo htmlspecialchars($u['telefono'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $r = strtolower(trim($u['rol'] ?? ''));
                                            if ($r === 'gerente') {
                                                $r = 'gerente_general';
                                            }
                                            $es_legacy = rol_es_legacy($r);
                                        ?>
                                        <span class="role-pill <?php echo $es_legacy ? 'role-pill--legacy' : ''; ?>">
                                            <?php echo htmlspecialchars(mb_strtoupper(rol_etiqueta($r), 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php $estado = isset($u['estado']) ? $u['estado'] : 1; ?>
                                        <span class="status-pill <?php echo ($estado == 1) ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo ($estado == 1) ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                        <br><small>Turno: <?php echo !empty($u['turno']) ? htmlspecialchars(ucfirst($u['turno']), ENT_QUOTES, 'UTF-8') : 'Sin asignar'; ?></small>
                                    </td>
                                    <td class="user-actions-cell">
                                        <div class="user-actions">
                                            <a href="gestion_usuarios.php?expediente=<?php echo intval($u['id']); ?><?php echo !empty($filtro_rol) ? '&rol=' . urlencode($filtro_rol) : ''; ?>#expediente" class="btn-action-table btn-edit" title="Ver expediente">
                                                <i class="fa-solid fa-folder-open"></i> Expediente
                                            </a>
                                            <a href="gestion_usuarios.php?editar=<?php echo intval($u['id']); ?><?php echo !empty($filtro_rol) ? '&rol='.urlencode($filtro_rol) : ''; ?>#formulario-edicion" class="btn-action-table btn-edit" title="Editar usuario y expediente">
                                                <i class="fa-solid fa-pen"></i> Editar
                                            </a>

                                            <?php if ($estado == 1): ?>
                                                <form method="POST" action="gestion_usuarios.php" onsubmit="return confirm('¿Inhabilitar el acceso de esta cuenta?');" style="margin: 0;">
                                                    <input type="hidden" name="accion" value="toggle">
                                                    <input type="hidden" name="id" value="<?php echo intval($u['id']); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="rol_filtro" value="<?php echo htmlspecialchars($filtro_rol, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn-action-table btn-status-off" title="Inhabilitar acceso">
                                                        <i class="fa-solid fa-ban"></i> Inhabilitar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="gestion_usuarios.php" onsubmit="return confirm('¿Habilitar el acceso de esta cuenta?');" style="margin: 0;">
                                                    <input type="hidden" name="accion" value="toggle">
                                                    <input type="hidden" name="id" value="<?php echo intval($u['id']); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="rol_filtro" value="<?php echo htmlspecialchars($filtro_rol, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn-action-table btn-status-on" title="Habilitar acceso">
                                                        <i class="fa-solid fa-check"></i> Habilitar
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" action="gestion_usuarios.php" onsubmit="return confirm('¿Eliminar permanentemente esta cuenta? Esta acción no se puede deshacer.');" style="margin: 0;">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?php echo intval($u['id']); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="rol_filtro" value="<?php echo htmlspecialchars($filtro_rol, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn-action-table btn-delete-action" title="Eliminar usuario">
                                                    <i class="fa-solid fa-trash"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    No hay usuarios registrados con este rol.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 25px; border-top: 1px solid #ebdcd0; padding-top: 15px;">
                <a href="dashboard.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">← Volver al inicio</a>
            </div>

        </div>

    </div>

</body>
</html>