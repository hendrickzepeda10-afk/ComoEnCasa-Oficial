<?php
include 'auth.php';
include 'conexion.php';
include 'respaldo_lib.php';

// Si ya hay sesión iniciada, ir directo al dashboard
if (isset($_SESSION['user_id'], $_SESSION['rol'])) {
    header("Location: dashboard.php");
    exit();
}

const LOGIN_MAX_INTENTOS = 5;
const LOGIN_ESPERA_SEGUNDOS = 60;

$error = '';
$sesion_cerrada = isset($_GET['sesion_cerrada']) && $_GET['sesion_cerrada'] === '1';
$sesion_expirada = isset($_GET['sesion_expirada']) && $_GET['sesion_expirada'] === '1';

$intentos = intval($_SESSION['login_intentos'] ?? 0);
$bloqueado_hasta = intval($_SESSION['login_bloqueo'] ?? 0);
$segundos_restantes = max(0, $bloqueado_hasta - time());

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($segundos_restantes > 0) {
        $error = "Demasiados intentos fallidos. Espera $segundos_restantes segundos e inténtalo de nuevo.";
    } elseif (!csrf_peticion_valida()) {
        $error = "La solicitud expiró. Actualiza la página e inténtalo de nuevo.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, usuario, password, rol, estado FROM usuarios WHERE usuario = :usuario LIMIT 1");
            $stmt->execute(['usuario' => $usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('No se pudo consultar la cuenta al iniciar sesión: ' . $e->getMessage());
            $user = null;
            $error = "No se pudo validar el acceso en este momento. Inténtalo de nuevo.";
        }

        if ($error === '') {
            // Solo se acepta contraseña protegida con hash (ya no texto plano)
            $credenciales_ok = $user && password_verify($password, (string) $user['password']);

            if (!$credenciales_ok) {
                // Mismo mensaje para usuario inexistente o contraseña errónea
                $intentos++;
                $_SESSION['login_intentos'] = $intentos;
                if ($intentos >= LOGIN_MAX_INTENTOS) {
                    $_SESSION['login_intentos'] = 0;
                    $_SESSION['login_bloqueo'] = time() + LOGIN_ESPERA_SEGUNDOS;
                    $error = "Demasiados intentos fallidos. Espera " . LOGIN_ESPERA_SEGUNDOS . " segundos e inténtalo de nuevo.";
                } else {
                    $error = "Usuario o contraseña incorrectos.";
                }
            } elseif (rol_es_legacy($user['rol'])) {
                $error = "Esta cuenta ya no tiene acceso al sistema. Los pedidos se hacen sin cuenta desde Pedir Ya; si necesitas acceso de personal, contacta al administrador.";
            } elseif (isset($user['estado']) && $user['estado'] == 0) {
                $error = "Esta cuenta está inhabilitada. Para pedir, entra directamente a Pedir Ya; si necesitas acceso de personal, contacta al administrador.";
            } else {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    try {
                        $upd = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                    } catch (PDOException $e) {
                        error_log('No se pudo actualizar el hash de la contraseña: ' . $e->getMessage());
                    }
                }

                session_regenerate_id(true);
                unset($_SESSION['login_intentos'], $_SESSION['login_bloqueo']);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['usuario'] = $user['usuario'];
                $_SESSION['rol'] = $user['rol'];

                // Respaldo automático diario (una vez al día, al entrar admin o gerente)
                if (in_array(trim($user['rol']), ['admin', 'gerente_general'], true)) {
                    respaldo_automatico_si_toca($pdo);
                }

                header("Location: dashboard.php");
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-logo">
            <img src="img/logo_claro.png" alt="Logo Como en Casa">
        </div>

        <h2>Iniciar Sesión</h2>

        <?php if ($sesion_cerrada): ?>
            <p class="login-aviso login-aviso--ok" role="status">La sesión se cerró correctamente.</p>
        <?php endif; ?>

        <?php if ($sesion_expirada): ?>
            <p class="login-aviso login-aviso--ok" role="status">Tu sesión expiró. Vuelve a iniciar sesión.</p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p class="login-aviso login-aviso--error" role="alert"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="POST" action="">
            <?php csrf_campo(); ?>
            <label for="usuario">Usuario:</label>
            <input type="text" name="usuario" id="usuario" required maxlength="50" autocomplete="username">

            <label for="password">Contraseña:</label>
            <input type="password" name="password" id="password" required autocomplete="current-password">

            <button type="submit">Entrar</button>
        </form>
        <p style="text-align: center; margin-top: 14px;">
            <a href="pedir.php" style="font-size: 13px; font-weight: 600;">← Volver a Pedir Ya sin cuenta</a>
        </p>
    </div>
    <script>
        window.comoEnCasaFirmaSesionAnterior = <?php
        echo json_encode($sesion_pestana_legado_firma, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;
    </script>
    <script src="sesiones_pestanas.js?v=3"></script>
</body>
</html>
