<?php # inc/auth.inc.php v:0.8.0 d:2026-04-10 i:Gemini m:1
if (session_status() === PHP_SESSION_NONE) {
    $session_time = 14400*4;
    ini_set('session.gc_maxlifetime', $session_time);
    session_set_cookie_params($session_time);
    session_start();
}

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; 
}

if (!isset($_SESSION['user_id'])) {
    $current_file = basename($_SERVER['PHP_SELF']);
    
    if ($current_file !== 'login.page.php') {
        header('Location: login.page.php');
        exit;
    }
}