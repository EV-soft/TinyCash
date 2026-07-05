<?php # inc/auth.inc.php v:1.1.0 d:2026-07-02 i:evs
require_once 'inc/php2htm.lib.php'; 

if (session_status() === PHP_SESSION_NONE) {
    $session_time = 14400; // 4 timer
    ini_set('session.gc_maxlifetime', $session_time);
    
    // Tving et unikt sessionsnavn specifikt til dit PHP 8-miljø
    // Dette klipper ALT samarbejde med de gamle, defekte sessionsfiler på serveren!
    session_name('TCC_V100_SESSION'); 

    session_set_cookie_params([
        'lifetime' => $session_time,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
    echo "";
}

// 1. --- HÅNDTER NIVEAUSKIFT FRA L-INDIKATOR (Kører ALTID) ---
if (isset($_GET['set_level'])) {
    $requested_level = (int)$_GET['set_level'];
    if ($requested_level >= 1 && $requested_level <= 3) {
        $_SESSION['user_level'] = $requested_level;
    }
}

// 2. --- SPROG-HÅNDTERING (Kører ALTID) ---
if (isset($_GET['l'])) {
    $requested_lang = strtolower($_GET['l']);
    if (preg_match('/^[a-z]{2}$/', $requested_lang)) {
        $_SESSION['lang'] = $requested_lang;
        
        // Opdater proglang med det samme, så biblioteket forstår det
        if ($requested_lang === 'en') {
            $_SESSION['proglang'] = 'en : English';
        } else {
            $_SESSION['proglang'] = 'da : Dansk';
        }
    }
}

// Fallback hvis sessionen ikke er sat endnu
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'da'; 
}
if (!isset($_SESSION['proglang'])) {
    $_SESSION['proglang'] = 'da : Dansk';
}

// Hent den aktuelle sti med det samme
$current_url = $_SERVER['REQUEST_URI'];

// 3. --- AKTIV HARD TIMEOUT KONTROL (Kører kun hvis logget ind og ikke på login.php) ---
$max_inactivity = 43200; // 12 timers inaktivitet tilladt inden hårdt udsmid
if (isset($_SESSION['user_id']) && strpos($current_url, 'login.php') === false) {
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];
        
        if ($inactive_time > $max_inactivity) {
            // Tving udsmidning
            $_SESSION = array();
            session_destroy();
            
            // Gem arbejdssiden i cookien inden vi dør
            if (!empty($current_url)) {
                $cookie_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
                setcookie('redirect_to', $current_url, time() + 3600, $cookie_path);
            }
            header("Location: login.php");
            exit;
        }
    }
    $_SESSION['last_activity'] = time(); // Opdater aktivitet ved gyldigt klik
}

// 4. --- LOGIN-KONTROL ---
if (strpos($current_url, 'login.php') !== false) {
    return; 
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
    if (!empty($current_url)) {
        $cookie_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
        setcookie('redirect_to', $current_url, time() + 3600, $cookie_path);
    }    
    header("Location: login.php");
    exit;
}

// 5. --- BRUGER-NIVEAU KONTROL ---
$uLev = $_SESSION['user_level'] ?? 2;

if (isset($rLev) && $uLev < $rLev) {
    deny_access_gracefully();
}

// Central funktion til at afvise adgang med bevaret menu og design
function deny_access_gracefully() {
    if (ob_get_length()) ob_clean();
    
    if (!function_exists('showMenu')) {
        require_once 'inc/menu.inc.php';
    }
    
    htm_Header(lang('Access Denied'));
    showMenu();
    
    htm_Card_(lang('Access Denied'), 600, '', 'access_error', false);
    echo '<div style="text-align:center; padding:20px;">';
    echo '<i class="fa fa-exclamation-triangle" style="font-size:48px; color:#e74c3c; margin-bottom:15px;"></i>';
    echo '<p style="font-size:1.2em; color:#555; margin-bottom:25px;">' . lang('Your user level is not high enough to view this page.') . '</p>';
    htm_Button('fa-arrow-left', 'Back to Dashboard', 'primary', 'sales_hub.php', '', '', '', true);
    echo '</div>';
    htm_Card_end();
    
    htm_Footer();
    exit;
}
