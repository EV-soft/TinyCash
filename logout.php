<?php # logout.php v:0.8 d:2026-04-11 i:evs m:1
// Start sessionen så vi kan lukke den
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
header("Location: login.page.php");
exit;
?>