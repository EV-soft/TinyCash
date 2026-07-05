<?php # /login.php v:1.1.0 d:2026-07-05 i:evs
ob_start();

// Sørg for at login bruger NØJAGTIG samme navn og parametre som auth.inc.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('TCC_V100_SESSION');
    session_start();
}
/* // --- AUTOLOGIN HACK: Omgår login-check ---
$_SESSION['user_id']    = 1;
$_SESSION['user_name']  = 'Admin';
$_SESSION['user_level'] = 3;
$_SESSION['user_role']  = 'admin';
$_SESSION['lang']       = 'da';
header("Location: index.php");  exit;
 */
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

$error_msg = "";

// 1. Tjek for eksisterende brugere (Setup-tjek)
$check_users = DB::query($conn, "SELECT COUNT(*) FROM users");
if ($check_users) {
    $user_count = DB::fetch_row($check_users)[0];
    if ($user_count == 0 && file_exists('inc/user_create_admin.php')) {
        require_once 'inc/user_create_admin.php';
        exit;
    }
}

// 2. Håndter Login-post (KUN ÉN GANG)
if (isset($_POST['login'])) {
    $initials = trim($_POST['initials'] ?? ''); 
    $pass     = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'];
    $ua       = $_SERVER['HTTP_USER_AGENT'];

    $sql = "SELECT user_id, username, password_hash, user_role, user_level FROM users WHERE username = ?";
    $res = DB::prepare_and_execute($conn, $sql, [$initials]);
    
    // Tjek om vi får et resultat tilbage
    if ($res && $row = DB::fetch_assoc($res)) {
        if (password_verify($pass, $row['password_hash'])) {
            // Log succes
            $log_sql = "INSERT INTO login_log (user_id, logged_username, ip_address, status, user_agent) VALUES (?, ?, ?, 'Success', ?)";
            DB::prepare_and_execute($conn, $log_sql, [$row['user_id'], $initials, $ip, $ua]);
            
            $_SESSION = array();
            $_SESSION['user_id']    = (int)$row['user_id'];
            $_SESSION['user_name']  = (string)$row['username']; 
            $_SESSION['user_level'] = (int)$row['user_level'];
            $_SESSION['user_role']  = (string)$row['user_role'];
            $_SESSION['lang']       = 'da';

            header("Location: index.php");
            exit;
        } else {
            $error_msg = "Forkert adgangskode.";
        }
    } else {
        $error_msg = "Bruger findes ikke.";
    }

    // Log fejl
    $fail_sql = "INSERT INTO login_log (logged_username, ip_address, status, user_agent) VALUES (?, ?, 'Failed', ?)";
    DB::prepare_and_execute($conn, $fail_sql, [$initials, $ip, $ua]);
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
htm_InputGroup(icon: 'fa-user', labl: '@Initials', name: 'initials', extr: 'required autofocus', 
    hint:'@User name', plho: '@Enter initials...'); 
htm_nl(2);

htm_InputGroup(icon: 'fa-lock', labl: '@Password', name: 'password', type: 'password', 
    extr: 'required style="padding-right: 35px; box-sizing: border-box;"', 
    hint:'@User password', plho: '••••••••'); 
htm_nl(2);

htm_Button(
        icon: 'fa-sign-in-alt', labl: '@Sign In', type: 'primary', 
        styl: 'width: 100%; padding: 12px; font-size: 1.1em;', attr: 'name="login"', 
        cont: '<div style="margin-top: 25px;"></div>' 
    );
echo '<div class="lang-switcher" style="text-align: center; margin-top: 20px; font-family: sans-serif; font-size: 0.9rem;">
    <a href="set_lang.php?l=da" style="text-decoration: none; margin: 0 10px; color: #2c3e50;">
        🇩🇰 Dansk
    </a>
    <span style="color: #ccc;">|</span>
    <a href="set_lang.php?l=en" style="text-decoration: none; margin: 0 10px; color: #2c3e50;">
        🇬🇧 English
    </a>
</div>';

htm_Card_end(); 
echo '</div>'; 
?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var pwdField = document.getElementById('password');
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