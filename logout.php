<?php # logout.php
// Start sessionen så vi kan lukke den
session_start();

// Fjern alle session-variable
$_SESSION = array();

// Hvis der bruges session-cookies, så slet dem også
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Ødelæg selve sessionen
session_destroy();

// Send brugeren tilbage til login-siden
header("Location: login.page.php");
exit;
?>