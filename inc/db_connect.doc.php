# Rename this: db_connect.doc.php to db_connect.inc.php and fillout data and remove this line
<?php # inc/db_connect.inc.php v:0.8.0 d:2026-04-10 i:Gemini m:1
define('APP_VERSION', '0.8.0');
define('APP_DATE', '2026-04-10');

// --- GLOBAL DEBUG CONTROL ---
if (isset($_GET['Debug']) && $_GET['Debug'] === 'true') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo '<div style="background:red; color:white; padding:5px; position:fixed; bottom:0; right:0; z-index:9999; font-size:10px; opacity:0.7;">DEBUG ACTIVE</div>';
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
// ----------------------------

require_once __DIR__ . '/php2htm.lib.php'; 

db_host  = "localhost";
$db_user = "xxxxxx_root";
$db_pass = "zzzzzzzzzzz";
$db_name = "yyyyyyyyyyy_TinyCash";

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    $error_title = lang('@Database Error');
    $error_msg   = lang('@Could not connect to database');
    
    die("<div style='font-family:sans-serif; padding:50px; text-align:center;'>
            <h1 style='color:#e74c3c;'>$error_title</h1>
            <p>$error_msg</p>
            <p style='color:#7f8c8d; font-size:0.9em;'>Tjek venligst dine indstillinger i inc/db_connect.inc.php.</p>
            <hr style='max-width:300px; border:0; border-top:1px solid #eee;'>
            <code style='background:#f4f4f4; padding:5px; border-radius:3px;'>" . mysqli_connect_error() . "</code>
         </div>");
}

mysqli_set_charset($conn, "utf8mb4");

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/system_errors.log'); 

ini_set('display_errors', 0);
error_reporting(E_ALL);