<?php # /about.php v:1.3.0 d:2026-08-30 i:evs
# v1.3.0: viser seneste linje fra inc/system_errors.log i systemstatus, hvis
# den er under 24 timer gammel - kun for admin (level 3). Fuld besked i
# data-hint ved hovering, kort udgave vist inline. Bruger-anmodet.
# v1.4.0: tilføjet webserver-info ($_SERVER['SERVER_SOFTWARE']) i systemstatus.
# Bruger-anmodet.
# v1.4.1: viser "(Apache-kompatibel)" ved LiteSpeed, så det er tydeligt at
# .htaccess-baserede rettelser (Require all denied osv.) også virker der.
require_once __DIR__ . '/inc/auth.inc.php';
require_once __DIR__ . '/inc/db_connect.inc.php';
require_once __DIR__ . '/inc/menu.inc.php';
require_once __DIR__ . '/inc/php2htm.lib.php';

if (!defined('APP_VERSION')) {
    die("Fejl: Kunne ikke indlæse konfiguration (db_connect.inc.php). Tjek stien eller rettigheder.");
}

// --- Effekt-kontrol af .htaccess ---
function check_security_lock() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    // Vi tjekker db_connect.inc.php - den SKAL svare med 403 eller 404 hvis låst
    $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/inc/db_connect.inc.php";
    
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 0.5]]);
    $headers = @get_headers($url, 1, $context);
    
    if ($headers === false) return false;
    $status = $headers[0];
    return (strpos($status, '403') !== false || strpos($status, '404') !== false);
}

$security_ok = check_security_lock();

// --- Seneste fejl fra fejlloggen, hvis den er "frisk" (under 24 timer gammel) ---
// Kun vist for admin (level 3) - loggen kan indeholde interne detaljer
// (SQL, tabelnavne, filstier) der ikke bør vises til almindelige brugere.
$fresh_error = null;
if ((int)($_SESSION['user_level'] ?? 1) >= 3) {
    // RETTET: system_errors.log flyttet til inc/data/ (konsolidering af
    // installationsspecifikke data) - gammel sti bevaret som fallback.
    $error_log_path = __DIR__ . '/inc/data/system_errors.log';
    if (!file_exists($error_log_path)) $error_log_path = __DIR__ . '/inc/system_errors.log';
    if (file_exists($error_log_path) && is_readable($error_log_path)) {
        $max_read = 8192; // læs kun halen af filen, uanset hvor stor den er blevet
        $size = filesize($error_log_path);
        $fh = @fopen($error_log_path, 'r');
        if ($fh) {
            if ($size > $max_read) fseek($fh, -$max_read, SEEK_END);
            $tail = stream_get_contents($fh);
            fclose($fh);
            $lines = array_values(array_filter(array_map('trim', explode("\n", $tail)), fn($l) => $l !== ''));
            if (!empty($lines)) {
                $last_line = end($lines);
                if (preg_match('/^\[(.*?)\]\s*(.*)$/', $last_line, $m)) {
                    $ts = strtotime($m[1]);
                    if ($ts !== false) {
                        $age_hours = (time() - $ts) / 3600;
                        if ($age_hours >= 0 && $age_hours <= 24) {
                            $fresh_error = ['time' => date(CONF_DATE_FORMAT . ' H:i', $ts), 'message' => $m[2]];
                        }
                    }
                }
            }
        }
    }
}

htm_Header('@About TinyCash');
showMenu();

htm_Card_(capt: '@TinyCash Accounting System', wdth: '500'); // fold: kun relevant på sider med flere cards
?>
<div style="text-align: center; font-family: sans-serif; line-height: 1.6;">
    <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 10px;">
        <span style="color:#3498db;">Tiny</span>Cash
    </div>
    <p><strong><?php echo lang('@Version'); ?>:</strong> <?php echo APP_VERSION; ?></p>
    <p><strong><?php echo lang('@Last Updated'); ?>:</strong> <?php echo APP_DATE; ?></p>
    <hr>
    <p><?php echo lang('@© 2026 - Developed with a focus on simplicity and speed.'); ?></p>
    
    <p style="text-align: left; 
              background: rgba(128, 128, 128, 0.1); /* Semi-transparent grå - virker i begge modes */
              padding: 15px; 
              border-radius: 5px; 
              border-left: 4px solid #3498db; 
              color: inherit;"> <strong>TinyCash </strong> 
              <?php echo
                lang('@is a lightweight accounting system designed for small/smaller businesses, that want full control over their own data.').
                '<br>'. lang('@Supports invoicing in foreign currencies with automatic exchange rate lookup and currency gain/loss posting on settlement - the ledger itself is always kept in DKK.');
              ?>
    </p>

    <?php
    // NYT (bruger-anmodet): link til den nye AI-manual (docs/ai_manual.md,
    // se ai_manual.php) - en naturlig placering, da denne side allerede er
    // det sted en bruger går hen for at forstå "hvad er dette program".
    htm_Button(icon: 'fa-robot', labl: '@AI Manual', type: 'info', link: 'ai_manual.php',
        attr: 'data-hint="'.lang('@A comprehensive, AI-readable description of everything TinyCash can do - useful for an AI assistant or support chatbot answering questions about the program.').'" target="_blank"',
        styl: 'margin-bottom:20px; display:inline-block;');
    ?>

    <div style="margin: 25px 0; text-align: left;">
        <h4 style="margin-bottom: 5px;"><?php echo lang('@Developed by:'); ?></h4>
        <p style="margin-top: 0;"><?php echo lang('@EV-soft & AI agents / For your Business'); ?></p>
        
        <h4 style="margin-bottom: 5px;"><?php echo lang('@System status:'); ?></h4>
        <ul style="list-style: none; padding: 0;">
            <li>✅ <?php echo lang('@PHP Version:'); echo ' '.phpversion(); ?></li>
            <li>✅ <?php
                echo lang('@Web Server:');
                $server_software = $_SERVER['SERVER_SOFTWARE'] ?? lang('@Unknown');
                echo ' '.htmlspecialchars($server_software);
                // LiteSpeed er en selvstændig webserver, men bygget som en drop-in-
                // erstatning for Apache: den forstår/håndhæver .htaccess på samme
                // måde (herunder "Require all denied", som flere sikkerhedsrettelser
                // i dette projekt bygger på) - tilføjer en tydelig note, så det ikke
                // fejlagtigt læses som "ingen .htaccess-understøttelse".
                if (stripos($server_software, 'litespeed') !== false) {
                    echo ' <span style="color:var(--text-muted); font-size:0.9em;">('.lang('@Apache-compatible').')</span>';
                }
                ?></li>
            <li>✅
                <?php 
                $db_display = ($db_type === 'sqlite') ? 'SQLite (File-based)' : 'MySQL (Server-based)';
                echo lang('@Database:') . ' ' . $db_display; 
                ?>
            </li>
            <li>
                ✅ <?php echo lang('@Language:'); 
                $current_l = $_SESSION['lang'] ?? 'da';
                
                // Sprog-til-land mapping (nøjagtig kopi fra din inc/menu.inc.php)
                $fMap = [
                    'da' => 'dk', 
                    'en' => 'gb', 
                    'kl' => 'gl', 
                    'se' => 'se', 
                    'no' => 'no'  
                ];
                $fCode = $fMap[$current_l] ?? $current_l;
                
                // Slå navnet op i JSON-filen (nøjagtig kopi fra din inc/menu.inc.php)
                $lang_file = 'json-data/languages.json'; 
                $display_name = strtoupper($current_l); // Fallback
                
                if (file_exists($lang_file)) {
                    $lang_data = json_decode(file_get_contents($lang_file), true);
                    if (!empty($lang_data['language'])) {
                        foreach ($lang_data['language'] as $l) {
                            if ($l['code'] == $current_l) {
                                $display_name = $l['native'];
                                break;
                            }
                        }
                    }
                }
                
                // Udskriv flaget via CSS-klassen og det pæne navn bagefter
                echo '<span class="fi fi-'.$fCode.'" style="margin-right: 5px; display: inline-block;"></span>';
                echo '<span>' . $display_name . '</span>';
                ?>
            </li>

            <li>
                <?php if ($security_ok): ?>
                    <span style="color: #27ae60;">🛡️ </span><?php echo lang('@Security: Core files are locked (.htaccess)'); ?>
                <?php else: ?>
                    <span style="color: #e74c3c;">⚠️ </span><?php echo lang('@Security: Core files are EXPOSED (.htaccess inactive)'); ?>
                <?php endif; ?>
            </li>
            <?php if ((int)($_SESSION['user_level'] ?? 1) >= 3): ?>
            <li>
                <?php if ($fresh_error): ?>
                    <span style="color: #e74c3c;">🐞 </span><?php echo lang('@Recent error:'); ?>
                    <span data-hint="<?php echo htmlspecialchars($fresh_error['message']); ?>" style="cursor:help; text-decoration:underline dotted; text-decoration-color:#e74c3c;">
                        <?php echo htmlspecialchars($fresh_error['time']); ?> — <?php echo htmlspecialchars(mb_strimwidth($fresh_error['message'], 0, 90, '…')); ?>
                    </span>
                <?php else: ?>
                    <span style="color: #27ae60;">✅ </span><?php echo lang('@No errors logged in the last 24 hours'); ?>
                <?php endif; ?>
            </li>
            <?php endif; ?>
        </ul>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
    <small style="color: #9a9d9e;">&copy; <?php echo date('Y'); ?> 𝘓𝘐𝘊𝘌𝘕𝘚𝘌 & 𝘊𝘰𝘱𝘺𝘳𝘪𝘨𝘩𝘵 © EV-soft. All rights reserved.</small>
</div>

<?php 
htm_Card_end();
htm_Footer();
?>
