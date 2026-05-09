<?php # inc/auth.inc.php v:0.9.1 d:2026-05-07 i:evs
if (session_status() === PHP_SESSION_NONE) {
    $session_time = 14400 * 8;
    ini_set('session.gc_maxlifetime', $session_time);
    session_set_cookie_params($session_time);
    session_start();
}

// --- SPROG-HÅNDTERING START ---
// 1. Tjek om der anmodes om et nyt sprog via URL (?l=xx)
if (isset($_GET['l'])) {
    $requested_lang = strtolower($_GET['l']);
    if (preg_match('/^[a-z]{2}$/', $requested_lang)) {
        $_SESSION['lang'] = $requested_lang;
    }
    // 2. Send brugeren tilbage til den side de kom fra uden l-parameteren
    $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    // Fjern l=xx fra referer hvis den findes for at undgå loops
    $redirect_to = preg_replace('/([?&])l=[^&]+(&|$)/', '$1', $referer);
    $redirect_to = rtrim($redirect_to, '?&');
    header("Location: " . $redirect_to);
    exit;
}

// 3. Sæt standardsprog hvis sessionen er helt tom
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'da'; 
}
// --- SPROG-HÅNDTERING SLUT ---
if (!isset($_SESSION['user_id'])) {
    $current_file = basename($_SERVER['PHP_SELF']);
    if ($current_file !== 'login.php') {
        header('Location: login.php');
        exit;
    }
}