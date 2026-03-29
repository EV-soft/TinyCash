<?php # auth.inc.php

// 1. Session konfiguration
$session_levetid = 7200;
ini_set('session.gc_maxlifetime', $session_levetid);
session_set_cookie_params($session_levetid);

// 2. Start sessionen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Sæt standardsprog (hvis det ikke allerede er sat)
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'da'; 
}

// 4. Login tjek
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.page.php') {
    header('Location: login.page.php');
    exit;
}
?>