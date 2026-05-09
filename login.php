<?php # /login.php v:0.8.5 d:2026-04-25 i:evs m:1 ok
ob_start();
session_start();

require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

$error_msg = "";

// 1. Tjek for eksisterende brugere (Setup-tjek)
$check_users = mysqli_query($conn, "SELECT COUNT(*) FROM users");
if ($check_users) {
    $user_count = mysqli_fetch_row($check_users)[0];
    if ($user_count == 0 && file_exists('user_create_admin.php')) {
        header("Location: user_create_admin.php");
        exit;
    }
}

// 2. Håndter Login-post
if (isset($_POST['login'])) {
    $initials = trim($_POST['initials'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'];
    $ua       = $_SERVER['HTTP_USER_AGENT'];

    // Sikkerhed: Prepared Statements i stedet for mysqli_real_escape_string
    $stmt = mysqli_prepare($conn, "SELECT user_id, username, password_hash, user_role FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $initials);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($res)) {
        if (password_verify($pass, $row['password_hash'])) {
            // SUCCESS
            $stmt_log = mysqli_prepare($conn, "INSERT INTO login_log (user_id, logged_username, ip_address, status, user_agent) VALUES (?, ?, ?, 'Success', ?)");
            mysqli_stmt_bind_param($stmt_log, "isss", $row['user_id'], $initials, $ip, $ua);
            mysqli_stmt_execute($stmt_log);
            // Sikkerhed: Regenerer session ID for at undgå hijacking
            session_regenerate_id(true);
            $_SESSION['user_id']   = $row['user_id'];
            $_SESSION['user_name'] = $row['username']; 
            $_SESSION['user_role'] = $row['user_role'];
            header("Location: index.php");
            exit;
        }
    }
    // FAILED: Log fejlen og sæt en generisk besked (beskyttelse mod user enumeration)
    $stmt_fail = mysqli_prepare($conn, "INSERT INTO login_log (logged_username, ip_address, status, user_agent) VALUES (?, ?, 'Failed', ?)");
    mysqli_stmt_bind_param($stmt_fail, "sss", $initials, $ip, $ua);
    mysqli_stmt_execute($stmt_fail);
    
    $error_msg = lang('@Invalid credentials'); 
}

// 3. Generer HTML
htm_Header(lang('@Login'));

// Centrerings-wrapper
echo '<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh;">';

// Her benytter vi, at htm_Card_ selv kan åbne en form (form: 'login-form')
htm_Card_(
    capt: "TinyCash " . lang('@Login'), 
    wdth: '300', 
    form: 'login.php' // Biblioteket laver automatisk <form method='post'>
);

if ($error_msg) { htm_alert($error_msg, 'error', 300); }    // Brug din htm_alert funktion til fejlbeskeder

// Input felter via biblioteket
htm_InputGroup(
    icon: 'fa-user', labl: '@Initials', name: 'initials', extr: 'required autofocus', plho: '@Enter initials...'); 
htm_nl(2);
htm_InputGroup(
    icon: 'fa-lock', labl: '@Password', name: 'password', type: 'password', extr: 'required', plho: '••••••••'); 
htm_nl(2);
htm_Button(
    icon: 'fa-sign-in-alt', labl: '@Sign In', type: 'primary', styl: 'width: 100%; padding: 12px; font-size: 1.1em;', attr: 'name="login"', 
    cont: '<div style="margin-top: 25px;"></div>' );

htm_Card_end(); // Lukker både boks og form automatisk jf. dit lib
echo '</div>'; 

htm_Footer(); 
?>