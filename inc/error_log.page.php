<?php # inc/error_log.page.php v:0.6.2 d:2026-04-03 i:Gemini m:1
// Vi tjekker om vi er logget ind (stien er relativ til roden!)
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

htm_Header(lang('@System Logs'));
showMenu();

htm_Card_(lang('@System Error Log'), 1600);

// STIEN TIL SELVE LOGFILEN
$log_file = 'inc/system_errors.log'; 

if (file_exists($log_file)) {
    echo "<pre style='background:#2c3e50; color:#ecf0f1; padding:15px; border-radius:5px; overflow:auto; max-height:600px; font-family:monospace; font-size:12px;'>";
    echo htmlspecialchars(file_get_contents($log_file));
    echo "</pre>";
    
    // Knap til at slette loggen (valgfrit)
    echo "<form method='post' style='margin-top:10px;'>
            <button type='submit' name='clear_log' class='btn-danger' style='width:auto; padding:5px 15px;'>Tøm Log</button>
          </form>";
} else {
    echo "<p style='color:#7f8c8d; font-style:italic;'>ℹ️ " . lang('@No logs found') . " ($log_file)</p>";
}

// Logik til at tømme loggen
if (isset($_POST['clear_log'])) {
    file_put_contents($log_file, "");
    header("Location: logs.page.php");
    exit;
}

htm_Card_end();
htm_Footer();
?>