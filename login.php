<?php # /login.php v:1.0.0 d:2026-06-15 i:evs
ob_start();
session_start();

require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

$error_msg = "";

// 1. Tjek for eksisterende brugere (Setup-tjek)
$check_users = mysqli_query($conn, "SELECT COUNT(*) FROM users");
if ($check_users) {
    $user_count = mysqli_fetch_row($check_users)[0];
    if ($user_count == 0 && file_exists('inc/user_create_admin.php')) {
        require_once 'inc/user_create_admin.php';
        exit;
    }
}

// 2. Håndter Login-post
if (isset($_POST['login'])) {
    $initials = trim($_POST['initials'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'];
    $ua       = $_SERVER['HTTP_USER_AGENT'];

    $stmt = mysqli_prepare($conn, "SELECT user_id, username, password_hash, user_role FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $initials);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($res)) {
        if (password_verify($pass, $row['password_hash'])) {
            // Login godkendt - gem i loggen
            $stmt_log = mysqli_prepare($conn, "INSERT INTO login_log (user_id, logged_username, ip_address, status, user_agent) VALUES (?, ?, ?, 'Success', ?)");
            mysqli_stmt_bind_param($stmt_log, "isss", $row['user_id'], $initials, $ip, $ua);
            mysqli_stmt_execute($stmt_log);
            
            // Start sessionen korrekt
            session_regenerate_id(true);
            $_SESSION['user_id']   = $row['user_id'];
            $_SESSION['user_name'] = $row['username']; 
            $_SESSION['user_role'] = $row['user_role'];

            // Bestem hvor brugeren skal sendes hen (Standard er index.php)
            $destination = 'index.php'; 

            if (isset($_COOKIE['redirect_to']) && !empty($_COOKIE['redirect_to'])) {
                $destination = $_COOKIE['redirect_to'];
                
                // Udregn nøjagtig samme undermappe for at kunne slette cookien i browseren
                $cookie_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
                setcookie('redirect_to', '', time() - 3600, $cookie_path);
            }

            header("Location: " . $destination);
            exit;
        }
    }
    
    // Hvis loginet fejler
    $stmt_fail = mysqli_prepare($conn, "INSERT INTO login_log (logged_username, ip_address, status, user_agent) VALUES (?, ?, 'Failed', ?)");
    mysqli_stmt_bind_param($stmt_fail, "sss", $initials, $ip, $ua);
    mysqli_stmt_execute($stmt_fail);
    
    $error_msg = lang('@Invalid credentials'); 
}

// 3. Generer HTML
htm_Header(lang('@Login'));

echo '<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh;">';

htm_Card_(
    capt: "TinyCash " . lang('@Login'), 
    wdth: '300', 
    form: 'login.php'
);

if ($error_msg) { htm_alert($error_msg, 'error', 300); }

// Input felter
htm_InputGroup(icon: 'fa-user', labl: '@Initials', name: 'initials', extr: 'required autofocus', plho: '@Enter initials...'); 
htm_nl(2);

htm_InputGroup(icon: 'fa-lock', labl: '@Password', name: 'password', type: 'password', extr: 'required style="padding-right: 35px; box-sizing: border-box;"', plho: '••••••••'); 
htm_nl(2);

htm_Button(
    icon: 'fa-sign-in-alt', labl: '@Sign In', type: 'primary', styl: 'width: 100%; padding: 12px; font-size: 1.1em;', attr: 'name="login"', 
    cont: '<div style="margin-top: 25px;"></div>' );

htm_Card_end(); 
echo '</div>'; 
?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var pwdField = document.getElementById('password2');
    if (pwdField) {
        var wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        wrapper.style.display = 'block';
        wrapper.style.width = '100%';
        
        pwdField.parentNode.insertBefore(wrapper, pwdField);
        wrapper.appendChild(pwdField);
        
        var eyeIcon = document.createElement('i');
        eyeIcon.className = 'fas fa-eye';
        eyeIcon.style.position = 'absolute';
        eyeIcon.style.right = '10px';
        eyeIcon.style.top = '50%';
        eyeIcon.style.transform = 'translateY(-50%)';
        eyeIcon.style.cursor = 'pointer';
        eyeIcon.style.color = '#7f8c8d';
        eyeIcon.style.zIndex = '100';
        eyeIcon.style.fontSize = '1rem';
        eyeIcon.title = 'Vis/Skjul adgangskode';
        
        wrapper.appendChild(eyeIcon);
        
        eyeIcon.addEventListener('click', function(e) {
            e.preventDefault();
            if (pwdField.type === 'password') {
                pwdField.type = 'text';
                eyeIcon.className = 'fas fa-eye-slash';
            } else {
                pwdField.type = 'password';
                eyeIcon.className = 'fas fa-eye';
            }
        });
    }
});
</script>

<?php
htm_Footer(); 
?>