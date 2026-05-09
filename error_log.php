<?php # error_log.page.php  v:0.9.1 d:2026-05-08 i:evs
ob_start();
require_once 'inc/auth.inc.php';      
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/menu.inc.php';      

htm_Header('@System Logs');
showMenu();

$log_file = 'inc/system_errors.log';

if (file_exists($log_file)) {
    htm_Card_('@System Error Log', 1200);
    echo "<pre style='background:#f4f4f4; padding:15px; border-radius:5px; overflow:auto; max-height:500px;'>";
    echo htmlspecialchars(file_get_contents($log_file));
    echo "</pre>";
    htm_Card_end();
} else {
    echo "<p>" . lang('@No logs found') . "</p>";
}

htm_Footer();
?>