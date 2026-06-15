<?php # inc/auth.inc.php v:0.9.6 d:2026-05-25 i:evs
if (session_status() === PHP_SESSION_NONE) {
    $session_time = 14400; // 14400 * 8;
    ini_set('session.gc_maxlifetime', $session_time);
    session_set_cookie_params($session_time);
    session_start();
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
    }
    $url = parse_url($_SERVER['HTTP_REFERER'] ?? 'index.php');
    $path = $url['path'] ?? 'index.php';
    $query = [];
    if (isset($url['query'])) {
        parse_str($url['query'], $query);
        unset($query['l']); 
    }
    $new_url = $path . (!empty($query) ? '?' . http_build_query($query) : '');
    header("Location: " . $new_url);
    exit;
}

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'da'; 
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
// Hvis vi er på login.php, stopper vi her, så siden ikke fanges i en uendelig løkke
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

if (isset($rLev)) {
    if ($uLev < $rLev) {
        ob_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="max-width:500px; margin:50px auto; padding:20px; border:1px solid #e74c3c; background:#fdf2f2; color:#b91c1c; font-family:sans-serif; border-radius:4px; text-align:center;">';
        echo '<h3>' . lang('Access Denied') . '</h3>';
        echo '<p>' . lang('Your user level is not high enough to view this page.') . '</p>';
        echo '<a href="index.php" style="color:#2563eb; text-decoration:none; font-weight:bold;">' . lang('Go to Frontpage') . '</a>';
        echo '</div>';
        exit;
    }
}