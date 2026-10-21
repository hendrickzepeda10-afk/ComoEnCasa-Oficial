<?php
/**
 * Control de acceso por rol y utilidades de sesión.
 *
 * Uso, al inicio de cada página (antes de imprimir cualquier HTML):
 *     include 'auth.php';
 *     requerir_rol(['admin', 'cajero']);
 */
$sesion_pestana_id = $_GET['__tab'] ?? $_POST['__tab'] ?? '';
$sesion_pestana_id = is_string($sesion_pestana_id)
    && preg_match('/^[a-f0-9]{64}$/', $sesion_pestana_id)
    ? $sesion_pestana_id
    : '';
$sesion_pestana_padre = $_GET['__tab_parent'] ?? $_POST['__tab_parent'] ?? '';
$nombre_sesion_predeterminado = session_name();
$sesion_pestana_legado_firma = '';

function nombre_sesion_pestana($id) {
    return 'CECTAB' . hash('sha256', $id);
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($sesion_pestana_id !== '') {
        $nombre_sesion_pestana_actual = nombre_sesion_pestana($sesion_pestana_id);
        $datos_sesion_padre = null;
        $nombre_sesion_padre = '';

        if (
            is_string($sesion_pestana_padre)
            && preg_match('/^legacy\.([a-f0-9]{64})$/', $sesion_pestana_padre, $coincidencias)
        ) {
            $nombre_sesion_padre = $nombre_sesion_predeterminado;
        } elseif (is_string($sesion_pestana_padre) && preg_match('/^[a-f0-9]{64}$/', $sesion_pestana_padre)) {
            $nombre_sesion_padre = nombre_sesion_pestana($sesion_pestana_padre);
        }

        if (
            $nombre_sesion_padre !== ''
            && !isset($_COOKIE[$nombre_sesion_pestana_actual])
            && isset($_COOKIE[$nombre_sesion_padre])
        ) {
            session_name($nombre_sesion_padre);
            session_start();
            $sesion_padre_es_legada = $nombre_sesion_padre === $nombre_sesion_predeterminado;
            if (
                !$sesion_padre_es_legada
                || hash_equals(
                    hash_hmac('sha256', 'migrar-sesion-legada', session_id()),
                    $coincidencias[1] ?? ''
                )
            ) {
                $datos_sesion_padre = $_SESSION;
            }
            session_write_close();
            session_id('');
            $_SESSION = [];
        }

        session_name($nombre_sesion_pestana_actual);
        session_start();
        if ($datos_sesion_padre !== null && !$_SESSION) {
            $_SESSION = $datos_sesion_padre;
        }
    } else {
        session_start();
        if ($nombre_sesion_predeterminado === session_name()) {
            $sesion_pestana_legado_firma = hash_hmac(
                'sha256',
                'migrar-sesion-legada',
                session_id()
            );
        }
    }
}

if ($sesion_pestana_id !== '' && !headers_sent()) {
    header_register_callback(static function () use ($sesion_pestana_id) {
        foreach (headers_list() as $encabezado) {
            if (stripos($encabezado, 'Location:') !== 0) {
                continue;
            }
            $ubicacion = trim(substr($encabezado, strlen('Location:')));
            if (preg_match('/(?:\?|&)__tab=[a-f0-9]{64}(?:&|#|$)/', $ubicacion)) {
                continue;
            }

            $fragmento = '';
            $posicion_fragmento = strpos($ubicacion, '#');
            if ($posicion_fragmento !== false) {
                $fragmento = substr($ubicacion, $posicion_fragmento);
                $ubicacion = substr($ubicacion, 0, $posicion_fragmento);
            }
            $separador = strpos($ubicacion, '?') === false ? '?' : '&';
            $ubicacion .= $separador . '__tab=' . rawurlencode($sesion_pestana_id);
            $estado_http = http_response_code();
            if (!is_int($estado_http) || $estado_http < 300 || $estado_http >= 400) {
                $estado_http = 302;
            }
            header_remove('Location');
            header('Location: ' . $ubicacion . $fragmento, true, $estado_http);
            break;
        }
    });
}

/* ------------------------------------------------------------------
 * Catálogo único de roles. Todas las páginas (crear usuario, gestión de
 * usuarios, menús y permisos) deben leer estas listas para no quedar
 * desincronizadas con el ENUM de la base de datos.
 * ------------------------------------------------------------------ */

/** Roles que el administrador puede asignar a una cuenta nueva o existente. */
const ROLES_ASIGNABLES = [
    'admin' => 'Administrador',
    'gerente_general' => 'Gerente General',
    'cajero' => 'Cajero',
    'chef' => 'Chef',
    'limpieza' => 'Personal de Limpieza',
];

/** Roles antiguos que ya no dan acceso, pero que pueden seguir guardados. */
const ROLES_LEGACY = [
    'mesero' => 'Rol retirado (mesero)',
    'cliente' => 'Rol retirado (cliente)',
];

function roles_asignables() {
    return array_keys(ROLES_ASIGNABLES);
}

function rol_es_legacy($rol) {
    return array_key_exists(strtolower(trim((string) $rol)), ROLES_LEGACY);
}

/** Nombre legible de un rol, para mostrarlo en pantalla. */
function rol_etiqueta($rol) {
    $clave = strtolower(trim((string) $rol));
    if (isset(ROLES_ASIGNABLES[$clave])) {
        return ROLES_ASIGNABLES[$clave];
    }
    if (isset(ROLES_LEGACY[$clave])) {
        return ROLES_LEGACY[$clave];
    }
    return $clave === '' ? 'Sin rol' : ucfirst($clave);
}

/** Cierra la sesión de una cuenta cuyo rol ya no da acceso al sistema. */
function descartar_sesion_sin_acceso() {
    unset($_SESSION['user_id'], $_SESSION['rol'], $_SESSION['usuario']);
    session_regenerate_id(true);
}

if (isset($_SESSION['user_id'], $_SESSION['rol']) && rol_es_legacy($_SESSION['rol'])) {
    descartar_sesion_sin_acceso();
}

function rol_actual() {
    return trim($_SESSION['rol'] ?? '');
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_token_valido($token) {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Imprime el campo oculto con el token; se usa dentro de cada formulario POST. */
function csrf_campo() {
    echo '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Comprueba el token de un POST. Devuelve true si la petición es legítima. */
function csrf_peticion_valida() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }

    return csrf_token_valido($_POST['csrf_token'] ?? null);
}

/** Página mínima de "sin permiso"; evita redirigir en bucle a dashboard.php. */
function detener_por_permiso() {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Sin permiso - Como en Casa</title>'
        . '<link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia"></head><body>'
        . '<div class="main-content"><div class="content-card" style="max-width: 420px;">'
        . '<h1 style="font-size: 22px;">Esta cuenta no tiene acceso</h1>'
        . '<p style="font-size: 14px;">Tu cuenta no tiene permiso para esta sección. '
        . 'Pide al administrador que revise tu rol.</p>'
        . '<a href="logout.php" class="btn-action" style="text-decoration: none;">Cerrar sesión</a>'
        . '</div></div>'
        . '<script>window.comoEnCasaFirmaSesionAnterior = '
        . json_encode($sesion_pestana_legado_firma, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script><script src="sesiones_pestanas.js?v=3"></script>'
        . '</body></html>';
    exit();
}

function requerir_rol(array $roles_permitidos, $como_json = false) {
    if (!isset($_SESSION['user_id'], $_SESSION['rol'])) {
        if ($como_json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'sesion']);
            exit();
        }
        header('Location: login.php');
        exit();
    }

    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        require_once __DIR__ . '/conexion.php';
    }

    try {
        $stmt = $pdo->prepare("SELECT usuario, rol, estado FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([intval($_SESSION['user_id'])]);
        $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('No se pudo validar la sesión: ' . $e->getMessage());
        http_response_code(503);
        if ($como_json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'validacion_sesion']);
        } else {
            echo 'No se pudo validar el acceso en este momento. Inténtalo de nuevo.';
        }
        exit();
    }

    if (
        !$cuenta
        || (isset($cuenta['estado']) && (int) $cuenta['estado'] === 0)
        || trim((string) $cuenta['rol']) !== rol_actual()
        || rol_es_legacy($cuenta['rol'])
    ) {
        descartar_sesion_sin_acceso();
        if ($como_json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'sesion_expirada']);
        } else {
            header('Location: login.php?sesion_expirada=1');
        }
        exit();
    }
    $_SESSION['usuario'] = $cuenta['usuario'];

    if (!in_array(rol_actual(), $roles_permitidos, true)) {
        if ($como_json) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'permiso']);
            exit();
        }
        // Si la página sin permiso ya es el inicio, redirigir crearía un bucle.
        if (basename($_SERVER['PHP_SELF'] ?? '') === 'dashboard.php') {
            detener_por_permiso();
        }
        header('Location: dashboard.php');
        exit();
    }
}
