<?php
include 'auth.php';

function cerrar_sesion_completa($destino) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $destino);
    exit();
}

$sesion_personal_activa = isset($_SESSION['user_id'], $_SESSION['rol']);
cerrar_sesion_completa(
    $sesion_personal_activa
        ? 'login.php?sesion_cerrada=1'
        : 'pedir.php?sesion_cerrada=1'
);