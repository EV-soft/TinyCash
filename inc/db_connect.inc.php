<?php # inc/db_connect.inc.php v:1.3.1 d:2026-06-12 i:gemini ok
define('APP_VERSION', '0.9.3');
define('APP_DATE', '2026-05-23');

// --- 1. SIKKER SESSIONSSTART & SPROGVÆLGER-LOGIK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = strtolower($_GET['lang']);
    
    // Fjern ?lang= parameteren fra URL'en og genindlæs siden rent
    $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: " . $clean_url);
    exit;
}

// --- 2. GLOBAL DEBUG CONTROL ---
if (isset($_GET['Debug']) && $_GET['Debug'] === 'true') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    $GLOBAL_DEBUG_ALERT = '<div style="background:red; color:white; padding:5px; position:fixed; bottom:0; right:0; z-index:9999; font-size:10px; opacity:0.7;">DEBUG ACTIVE</div>';
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// --- 3. DYNAMISK KONFIGURATIONSLÆSER ---
$env_file = null;
$SøgeStier = [
    __DIR__ . '/env.ini',
    __DIR__ . '/.env',
    __DIR__ . '/../env.ini',
    __DIR__ . '/../.env'
];

foreach ($SøgeStier as $sti) {
    if (file_exists($sti)) {
        $env_file = $sti;
        break;
    }
}

if ($env_file === null) {
    die("<div style='font-family:sans-serif; padding:50px; text-align:center;'>
            <h1 style='color:#e67e22;'>Konfigurationsfejl</h1>
            <p>Hverken <code>env.ini</code> eller <code>.env</code> kunne findes i dine mapper.</p>
         </div>");
}

$lines = @file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines !== false) {
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"' ");
            
            if ($key !== '') {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// --- 4. HENT PARAMETRE FRA MILJØET ---
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_user = $_ENV['DB_USER'] ?? '';
$db_pass = $_ENV['DB_PASS'] ?? '';
$db_name = $_ENV['DB_NAME'] ?? '';

$apiKey = $_ENV['OPENAI_API_KEY'] ?? '';

// --- 5. OPRET FORBINDELSEN TIL DATABASEN ---
$conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("<div style='font-family:sans-serif; padding:50px; text-align:center;'>
            <h1 style='color:#e74c3c;'>Database Error</h1>
            <p>Could not connect to database.</p>
            <hr style='max-width:300px; border:0; border-top:1px solid #eee;'>
            <code style='background:#f4f4f4; padding:5px; border-radius:3px;'>" . mysqli_connect_error() . "</code>
         </div>");
}

if (!function_exists('mysqli_fetch_column')) {
    function mysqli_fetch_column($result, $column = 0) {
        if ($result && $row = mysqli_fetch_row($result)) {
            return $row[$column];
        }
        return null;
    }
}

mysqli_set_charset($conn, "utf8mb4");

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/system_errors.log'); 

function get_settings($conn) {
    $settings = [];
    $sql = "SELECT setting_key, setting_value FROM settings";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        mysqli_free_result($res);
    }
    return $settings;
}

// --- 6. INDSTILLINGER OG SPROG-FALLBACK ---
$global_settings = get_settings($conn);
$current_date_format = (!empty($global_settings['date_format'])) ? $global_settings['date_format'] : 'd.m.Y';
define('CONF_DATE_FORMAT', $current_date_format);

// Sikring mod at databasen overskriver det manuelle sprogvalg i sessionen
if (!isset($_SESSION['lang']) && !empty($global_settings['language'])) {
    $_SESSION['lang'] = strtolower($global_settings['language']);
}
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'da';
}

// --- 7. URL-KONTROL TIL MAILHENTNING ---
if (isset($_GET['CheckMail']) && $_GET['CheckMail'] === 'true') {
    $sync_script = __DIR__ . '/depot_sync.php';
    if (file_exists($sync_script)) {
        require_once $sync_script;
        echo "<div style='background:#2ecc71; color:white; padding:10px; text-align:center; font-family:sans-serif;'>E-mail synkronisering fuldført!</div>";
    } else {
        echo "<div style='background:#e74c3c; color:white; padding:10px; text-align:center; font-family:sans-serif;'>Fejl: depot_sync.php blev ikke fundet i inc/ mappen.</div>";
    }
}

function is_date_locked($conn, $date) {
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM settings WHERE setting_key = 'accounting_lock_date' LIMIT 1");
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $lock_date = $row['setting_value'];
            if (!empty($lock_date) && strtotime($date) <= strtotime($lock_date)) {
                return true; // Datoen er låst!
            }
        }
        mysqli_stmt_close($stmt);
    }
    return false;
}
?>