<?php # /login.php v:1.2.0 d:2026-08-11 i:evs 
# (Tilføjet valgbar database-type: SQLite/MySQL)
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

// --- NYT: LÆS EVT. VALGT DATABASE-TYPE FRA FORMULAREN, FØR db_connect.inc.php
// KØRER. db_connect.inc.php læser $_SESSION['db_type'] og bruger den sektion
// (mysql_config/sqlite_config) i stedet for env.ini's statiske ACTIVE_DB.
// Kun 'mysql'/'sqlite' accepteres - selve forbindelsesoplysningerne kommer
// STADIG udelukkende fra env.ini, brugeren vælger blot hvilken af de to
// allerede konfigurerede sektioner der skal bruges.
if (isset($_POST['db_type']) && in_array($_POST['db_type'], ['mysql', 'sqlite'], true)) {
    $_SESSION['db_type'] = $_POST['db_type'];
}

require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

// Find env-filen (samme logik som db_connect)
$env_paths = ['env.ini', '.env', 'inc/env.ini', 'inc/.env'];
$env_file = '';
foreach ($env_paths as $path) {
    if (file_exists($path)) { $env_file = $path; break; }
}

// Læs konfigurationen
$config = $env_file ? parse_ini_file($env_file, true) : [];

// Tjek om MySQL er opsat
$mysql_configured = (
    !empty($config['mysql_config']['DB_HOST']) && 
    !empty($config['mysql_config']['DB_NAME'])
);

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
            
            // Bevar db_type i sessionen på tværs af $_SESSION-nulstillingen herunder,
            // så resten af sessionen efter login forbliver på samme database.
            $chosen_db_type = $_SESSION['db_type'] ?? $db_type;

            $_SESSION = array();
            $_SESSION['user_id']    = (int)$row['user_id'];
            $_SESSION['user_name']  = (string)$row['username']; 
            $_SESSION['user_level'] = (int)$row['user_level'];
            $_SESSION['user_role']  = (string)$row['user_role'];
            $_SESSION['lang']       = 'da';
            $_SESSION['db_type']    = $chosen_db_type;

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
// Login-siden låses bevidst til light-tema: der findes ingen temavælger her
// (showMenu() vises jo ikke før login), så en tidligere gemt dark/custom-
// cookie ville ellers gøre siden svær at bruge uden mulighed for selv at
// skifte tilbage. Se htm_Header()'s $force_theme-parameter.
htm_Header(lang('@Login'), 1600, true, 'light');

echo '<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh;">';

htm_Card_(
    capt: "TinyCash " . lang('@Login'), 
    wdth: '400', 
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

// --- NYT: DATABASE-TYPE VÆLGER ---
// $db_type er sat af db_connect.inc.php og afspejler den database, siden
// rent faktisk lige har forbundet til (enten fra et tidligere valg i
// sessionen, eller env.ini's ACTIVE_DB som fallback ved en frisk session).
$is_mysql  = ($db_type === 'mysql');
$is_sqlite = ($db_type === 'sqlite');

// ... (din eksisterende kode)

// Logik for MySQL-feltet
$mysql_disabled = $mysql_configured ? '' : 'disabled';
$mysql_style    = $mysql_configured ? 'cursor:pointer;' : 'cursor:not-allowed; opacity: 0.4;';
$mysql_title    = $mysql_configured ? '' : ' title="'.lang('@MySQL is not configured').'"';
if ($mysql_disabled ) $info= '<br>'.lang('@MySQL is not setup (disabled) ');
    
echo '<div style="margin-bottom: 15px; padding: 10px 12px; border: 1px solid var(--border-fieldset, #ccc); border-radius: 8px; background: var(--bg-panel, #f8f9fa);"
      data-hint="'.lang('@SQLite up to 50,000 accounting entries<br>MySQL Many many more...<br>MySQL requires advanced installation').$info.'">';
      
echo '  <div style="font-size: 0.8rem; color: var(--text-muted, #666); margin-bottom: 8px;">
            <i class="fa fa-database" style="margin-right: 5px; color: var(--color-primary);"></i>' . lang('@Database') . '
        </div>';

// SQLite
echo '  <label style="display:inline-flex; align-items:center; gap:5px; margin-right:20px; cursor:pointer; font-size:0.9rem;">
            <input type="radio" name="db_type" value="sqlite" ' . ($is_sqlite ? 'checked' : '') . '> ' . lang('@SQLite (local file)') . '
        </label>';

// MySQL (deaktiveret hvis ikke opsat)
echo '  <label style="display:inline-flex; align-items:center; gap:5px; '.$mysql_style.' font-size:0.9rem;" '.$mysql_title.'>
            <input type="radio" name="db_type" value="mysql" ' . ($is_mysql ? 'checked' : '') . ' ' . $mysql_disabled .'> ' . lang('@MySQL (server)') . '
        </label>';
echo '</div>';
/* 
echo '<div style="margin-bottom: 15px; padding: 10px 12px; border: 1px solid var(--border-fieldset, #ccc); border-radius: 8px; background: var(--bg-panel, #f8f9fa);"
      data-hint="'.lang('@SQLite up to 50,000 accounting entries<br>MySQL Many many more...<br>MySQL requires advanced installation').'">';
echo '  <div style="font-size: 0.8rem; color: var(--text-muted, #666); margin-bottom: 8px;">';
echo '      <i class="fa fa-database" style="margin-right: 5px; color: var(--color-primary);"></i>' . lang('@Database');
echo '  </div>';
echo '  <label style="display:inline-flex; align-items:center; gap:5px; margin-right:20px; cursor:pointer; font-size:0.9rem;">';
echo '      <input type="radio" name="db_type" value="sqlite" ' . ($is_sqlite ? 'checked' : '') . '>';
echo '      ' . lang('@SQLite (local file)');
echo '  </label>';
echo '  <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer; font-size:0.9rem;">';
echo '      <input type="radio" name="db_type" value="mysql" ' . ($is_mysql ? 'checked' : '') . '>';
echo '      ' . lang('@MySQL (server)');
echo '  </label>';
echo '</div>';
 */
htm_nl(1);

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
/* 
echo '<div class="lang-switcher" style="text-align: center; margin-top: 20px; font-family: sans-serif; font-size: 0.9rem;">
    <a href="?l=da"><img src="dk.svg" alt="Dansk"></a>
    <a href="?l=en"><img src="gb.svg" alt="English"></a>
</div>';  */

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
