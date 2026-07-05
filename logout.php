<?php # logout.php v:1.1.0 d:2026-07-05 i:evs

session_start();
$_SESSION = array();

// Hvis der bruges session-cookies, så slet dem også
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: login.php");
exit;
?>
