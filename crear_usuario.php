<?php
include 'auth.php';
requerir_rol(['admin']);
include 'conexion.php';
require_once __DIR__ . '/personal_lib.php';

$mensaje = '';
$tipo_mensaje = '';
$usuario_previo = '';
$rol_previo = '';
$nombre_previo = '';
$fecha_nacimiento_previa = '';
$dni_previo = '';
$telefono_previo = '';
$correo_previo = '';
$domicilio_previo = '';
$emergencia_nombre_previo = '';
$emergencia_telefono_previo = '';
$turno_previo = '';
$documentos_nuevos = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));
    $rol = (string) ($_POST['rol'] ?? '');
    $nombre = trim((string) ($_POST['nombre_completo'] ?? ''));
    $dni = trim((string) ($_POST['dni'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));
    $fecha_nacimiento = trim((string) ($_POST['fecha_nacimiento'] ?? ''));
    $correo = trim((string) ($_POST['correo_electronico'] ?? ''));
    $domicilio = trim((string) ($_POST['domicilio'] ?? ''));
    $emergencia_nombre = trim((string) ($_POST['contacto_emergencia_nombre'] ?? ''));
    $emergencia_telefono = trim((string) ($_POST['contacto_emergencia_telefono'] ?? ''));
    $turno = trim((string) ($_POST['turno'] ?? ''));
    $usuario_previo = $usuario;
    $rol_previo = $rol;
    $nombre_previo = $nombre;
    $fecha_nacimiento_previa = $fecha_nacimiento;
    $dni_previo = $dni;
    $telefono_previo = $telefono;
    $correo_previo = $correo;
    $domicilio_previo = $domicilio;
    $emergencia_nombre_previo = $emergencia_nombre;
    $emergencia_telefono_previo = $emergencia_telefono;
    $turno_previo = $turno;

    if (!csrf_peticion_valida()) {
        http_response_code(403);
        $mensaje = "La solicitud expiró o no es válida. Actualiza la página e inténtalo de nuevo.";
        $tipo_mensaje = "error";
    } elseif (
        $usuario === ''
        || $password === ''
        || !in_array($rol, roles_asignables(), true)
        || $nombre === ''
        || $dni === ''
        || $telefono === ''
        || !validar_fecha_nacimiento_personal($fecha_nacimiento)
        || !filter_var($correo, FILTER_VALIDATE_EMAIL)
        || $domicilio === ''
        || $emergencia_nombre === ''
        || $emergencia_telefono === ''
        || !in_array($turno, ['mañana', 'tarde', 'noche'], true)
    ) {
        $mensaje = "Completa todos los datos personales y de emergencia, adjunta los cuatro documentos, elige un rol y asigna un turno válido.";
        $tipo_mensaje = "error";
    } elseif (mb_strlen($usuario, 'UTF-8') > 50) {
        $mensaje = "El nombre de usuario no puede tener más de 50 caracteres.";
        $tipo_mensaje = "error";
    } elseif (mb_strlen($password, 'UTF-8') < 8) {
        $mensaje = "La contraseña debe tener al menos 8 caracteres.";
        $tipo_mensaje = "error";
    } elseif (
        mb_strlen($nombre, 'UTF-8') > 150
        || mb_strlen($dni, 'UTF-8') > 30
        || mb_strlen($telefono, 'UTF-8') > 30
        || mb_strlen($correo, 'UTF-8') > 254
        || mb_strlen($domicilio, 'UTF-8') > 255
        || mb_strlen($emergencia_nombre, 'UTF-8') > 150
        || mb_strlen($emergencia_telefono, 'UTF-8') > 30
    ) {
        $mensaje = "Verifica que los datos personales no excedan sus longitudes máximas.";
        $tipo_mensaje = "error";
    } else {
        try {
            // Verificar si el usuario ya existe
            $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = :usuario LIMIT 1");
            $stmt_check->execute(['usuario' => $usuario]);

            if ($stmt_check->fetch(PDO::FETCH_ASSOC)) {
                $mensaje = "El nombre de usuario ya está registrado. Elige otro.";
                $tipo_mensaje = "error";
            } else {
                $documentos = procesar_documentos_personal($_FILES);
                $documentos_nuevos = $documentos['nuevos'];

                // Encriptar la contraseña de forma segura
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Insertar el nuevo usuario en la base de datos
                $stmt = $pdo->prepare("INSERT INTO usuarios (
                    usuario, password, rol, nombre_completo, fecha_nacimiento, dni, telefono,
                    correo_electronico, domicilio, contacto_emergencia_nombre,
                    contacto_emergencia_telefono, turno, documento_cv, documento_identidad,
                    documento_estudios, documento_antecedentes, estado
                ) VALUES (
                    :usuario, :password, :rol, :nombre_completo, :fecha_nacimiento, :dni, :telefono,
                    :correo_electronico, :domicilio, :contacto_emergencia_nombre,
                    :contacto_emergencia_telefono, :turno, :documento_cv, :documento_identidad,
                    :documento_estudios, :documento_antecedentes, 1
                )");
                $stmt->execute([
                    'usuario' => $usuario,
                    'password' => $password_hash,
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
                ]);

                $mensaje = "¡Usuario creado exitosamente!";
                $tipo_mensaje = "success";
                $usuario_previo = '';
                $rol_previo = '';
                $nombre_previo = '';
                $fecha_nacimiento_previa = '';
                $dni_previo = '';
                $telefono_previo = '';
                $correo_previo = '';
                $domicilio_previo = '';
                $emergencia_nombre_previo = '';
                $emergencia_telefono_previo = '';
                $turno_previo = '';
            }
        } catch (Throwable $e) {
            eliminar_archivos_personal($documentos_nuevos);
            if ($e instanceof PDOException) {
                error_log('No se pudo crear la cuenta: ' . $e->getMessage());
                $mensaje = "No se pudo crear la cuenta. Inténtalo de nuevo.";
            } else {
                $mensaje = $e->getMessage();
            }
            $tipo_mensaje = "error";
        }
    }
}

try {
    $usuarios_existentes = $pdo->query('SELECT usuario FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('No se pudieron consultar nombres de usuario para la sugerencia: ' . $e->getMessage());
    http_response_code(503);
    $usuarios_existentes = [];
    if ($mensaje === '') {
        $mensaje = 'No se pudo preparar una sugerencia de usuario. Inténtalo de nuevo.';
        $tipo_mensaje = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <!-- Incluimos tu barra lateral / header unificado -->
    <?php include 'header.php'; ?>

    <!-- Contenido Principal -->
    <div class="main-content">

        <div class="content-card" style="max-width: 450px; text-align: left;">
            <div style="text-align: center; margin-bottom: 20px;">
                <h1 style="font-size: 24px; margin-bottom: 5px;">Nuevo Usuario</h1>
                <p style="margin: 0; font-size: 14px;">Registra un nuevo acceso para el sistema.</p>
            </div>

            <?php if (!empty($mensaje)): ?>
                <div class="alert-message <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8'); ?>" role="status">
                    <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px;">
                <?php csrf_campo(); ?>
                <div>
                    <label for="rol" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Rol:</label>
                    <select name="rol" id="rol" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; background-color: #fff; box-sizing: border-box; font-size: 14px; outline: none;">
                        <?php foreach (ROLES_ASIGNABLES as $valor => $etiqueta): ?>
                            <option value="<?php echo htmlspecialchars($valor, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($rol_previo === $valor) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label for="nombre_completo" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Nombre completo:</label>
                        <input type="text" name="nombre_completo" id="nombre_completo" required maxlength="150" value="<?php echo htmlspecialchars($nombre_previo, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                    <div>
                        <label for="fecha_nacimiento" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Fecha de nacimiento:</label>
                        <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($fecha_nacimiento_previa, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                    <div>
                        <label for="dni" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Número de identidad:</label>
                        <input type="text" name="dni" id="dni" required maxlength="30" value="<?php echo htmlspecialchars($dni_previo, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                    <div>
                        <label for="telefono" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Teléfono de contacto:</label>
                        <input type="tel" name="telefono" id="telefono" required maxlength="30" value="<?php echo htmlspecialchars($telefono_previo, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                    <div>
                        <label for="correo_electronico" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Correo electrónico:</label>
                        <input type="email" name="correo_electronico" id="correo_electronico" required maxlength="254" value="<?php echo htmlspecialchars($correo_previo, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                    <div>
                        <label for="domicilio" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Dirección de domicilio:</label>
                        <textarea name="domicilio" id="domicilio" required maxlength="255" rows="2" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;"><?php echo htmlspecialchars($domicilio_previo, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div>
                        <label for="contacto_emergencia_nombre" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Contacto de emergencia (nombre):</label>
                        <input type="text" name="contacto_emergencia_nombre" id="contacto_emergencia_nombre" required maxlength="150" value="<?php echo htmlspecialchars($emergencia_nombre_previo, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                    <div>
                        <label for="contacto_emergencia_telefono" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Contacto de emergencia (teléfono):</label>
                        <input type="tel" name="contacto_emergencia_telefono" id="contacto_emergencia_telefono" required maxlength="30" value="<?php echo htmlspecialchars($emergencia_telefono_previo, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box;">
                    </div>
                </div>

                <fieldset style="display: flex; flex-direction: column; gap: 12px; padding: 16px; border: 1px solid #ebdcd0; border-radius: 10px;">
                    <legend style="padding: 0 6px; font-weight: 700;">Documentos obligatorios (PDF, JPG o PNG; máximo 1.5 MB cada uno)</legend>
                    <?php foreach (documentos_personal_definicion() as $campo => $documento): ?>
                        <div>
                            <label for="<?php echo htmlspecialchars($campo, ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php echo htmlspecialchars($documento['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>:
                            </label>
                            <input type="file" name="<?php echo htmlspecialchars($documento['archivo'], ENT_QUOTES, 'UTF-8'); ?>" id="<?php echo htmlspecialchars($campo, ENT_QUOTES, 'UTF-8'); ?>" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" style="width: 100%;">
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <div>
                    <label for="turno" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Turno asignado:</label>
                    <select name="turno" id="turno" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; background-color: #fff; box-sizing: border-box;">
                        <option value="">Selecciona un turno</option>
                        <option value="mañana" <?php echo $turno_previo === 'mañana' ? 'selected' : ''; ?>>Mañana</option>
                        <option value="tarde" <?php echo $turno_previo === 'tarde' ? 'selected' : ''; ?>>Tarde</option>
                        <option value="noche" <?php echo $turno_previo === 'noche' ? 'selected' : ''; ?>>Noche</option>
                    </select>
                </div>

                <fieldset style="display: flex; flex-direction: column; gap: 14px; padding: 16px; border: 1px solid #ebdcd0; border-radius: 10px;">
                    <legend style="padding: 0 6px; font-weight: 700;">Acceso al sistema</legend>
                    <div>
                        <label for="usuario" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Usuario:</label>
                        <input type="text" name="usuario" id="usuario" required maxlength="50" value="<?php echo htmlspecialchars($usuario_previo, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" aria-describedby="usuarioSugerencia" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box; font-size: 14px; outline: none;">
                        <small id="usuarioSugerencia" style="display: block; margin-top: 6px; color: var(--text-muted);">
                            Usuario recomendado según el nombre: <strong id="usuarioRecomendado">Escribe el nombre completo</strong>
                        </small>
                        <button type="button" id="usarUsuarioRecomendado" class="btn-action" style="margin-top: 8px; padding: 8px 12px; border: 0; cursor: pointer;">Usar recomendación</button>
                    </div>
                    <div>
                        <label for="password" style="font-size: 13px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 5px;">Contraseña:</label>
                        <input type="password" name="password" id="password" required minlength="8" autocomplete="new-password" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ebdcd0; box-sizing: border-box; font-size: 14px; outline: none;">
                        <small style="color: var(--text-muted);">Mínimo 8 caracteres.</small>
                    </div>
                </fieldset>

                <button type="submit" class="btn-action" style="justify-content: center; width: 100%; border: none; cursor: pointer; margin-top: 10px;">
                    Registrar Usuario
                </button>
            </form>

            <div style="text-align: center; margin-top: 20px;">
                <a href="gestion_usuarios.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">Ver usuarios registrados</a>
                <span style="color: var(--text-muted);"> · </span>
                <a href="dashboard.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">← Volver al inicio</a>
            </div>
        </div>

    </div>

    <script>
        (function () {
            const nombreInput = document.getElementById('nombre_completo');
            const usuarioInput = document.getElementById('usuario');
            const etiquetaRecomendacion = document.getElementById('usuarioRecomendado');
            const botonRecomendacion = document.getElementById('usarUsuarioRecomendado');
            const usuariosExistentes = new Set(<?php echo json_encode(
                array_map('strtolower', $usuarios_existentes),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            ); ?>);
            let usuarioEditadoManualmente = usuarioInput.value.trim() !== '';
            let sugerenciaActual = '';

            function recomendarUsuario(nombreCompleto) {
                const palabras = nombreCompleto
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .match(/[a-z0-9]+/g) || [];

                if (palabras.length === 0) {
                    return '';
                }

                const base = palabras.length > 1
                    ? palabras[0] + '.' + palabras[palabras.length - 1]
                    : palabras[0];
                let candidato = base.slice(0, 50);
                let consecutivo = 2;

                while (usuariosExistentes.has(candidato.toLowerCase())) {
                    const sufijo = String(consecutivo++);
                    candidato = base.slice(0, 50 - sufijo.length) + sufijo;
                }

                return candidato;
            }

            function actualizarRecomendacion() {
                sugerenciaActual = recomendarUsuario(nombreInput.value.trim());
                etiquetaRecomendacion.textContent = sugerenciaActual || 'Escribe el nombre completo';
                if (!usuarioEditadoManualmente && sugerenciaActual) {
                    usuarioInput.value = sugerenciaActual;
                }
            }

            nombreInput.addEventListener('input', actualizarRecomendacion);
            usuarioInput.addEventListener('input', function () {
                usuarioEditadoManualmente = true;
            });
            botonRecomendacion.addEventListener('click', function () {
                if (sugerenciaActual) {
                    usuarioInput.value = sugerenciaActual;
                    usuarioEditadoManualmente = false;
                    usuarioInput.focus();
                }
            });

            actualizarRecomendacion();
        })();
    </script>
</body>
</html>
