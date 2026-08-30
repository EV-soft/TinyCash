<?php # /error_log.php v:1.3.0 d:2026-08-30 i:evs
# v1.2.0: manglede ethvert niveau-tjek - enhver logget-ind bruger kunne se
# hele den interne fejllog (filstier, stack traces), i modsætning til
# about.php's tilsvarende "seneste fejl"-visning, som allerede korrekt er
# admin-only. Tilføjet samme tjek. Gennemgang af tilbageværende sider.
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) {
    deny_access_gracefully();
}

htm_Header('@System Logs');
showMenu();

// RETTET: system_errors.log flyttet til inc/data/ (konsolidering af
// installationsspecifikke data) - gammel sti bevaret som fallback.
$log_file = file_exists('inc/data/system_errors.log') ? 'inc/data/system_errors.log' : 'inc/system_errors.log';

// RETTET (§bugs-batch-34-review): læste FØR hele filen ind i hukommelsen
// uafhængigt af dens størrelse - about.php's eget "seneste fejl"-udsnit af
// PRÆCIS samme fil begrænser allerede bevidst til halen (8KB), netop fordi
// en fejllog kan vokse betydeligt over en installations levetid. Uden en
// tilsvarende grænse her kunne selve fejllog-VISNINGEN ende med at udløse
// PHP's eget memory_limit på en installation der har kørt længe med jævnlige
// advarsler/fejl - ironisk nok en fejl i den side der skal vise fejl.
// Viser nu kun halen (seneste ~500KB) med en tydelig note, hvis filen er
// større end det.
$max_read = 500 * 1024;
$log_size = file_exists($log_file) ? filesize($log_file) : 0;

if (file_exists($log_file)) {
    htm_Card_('@System Error Log', 1200);
    if ($log_size > $max_read) {
        htm_Banner('<i class="fa fa-info-circle"></i> ' . sprintf(lang('@This log file is %s - showing only the most recent part below. Check the file directly on the server for the full history.'), number_format($log_size / 1048576, 2, ',', '.') . ' MB'), 'info');
    }
    $fh = @fopen($log_file, 'r');
    $tail = '';
    if ($fh) {
        if ($log_size > $max_read) fseek($fh, -$max_read, SEEK_END);
        $tail = stream_get_contents($fh);
        fclose($fh);
    }
    echo "<pre style='background:#f4f4f4; padding:15px; border-radius:5px; overflow:auto; max-height:500px;'>";
    echo htmlspecialchars($tail);
    echo "</pre>";
    htm_Card_end();
} else {
    echo "<p>" . lang('@No logs found') . "</p>";
}

htm_Footer();
?>