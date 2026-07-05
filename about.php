<?php # about.php v:1.1.0 d:2026-07-05 i:evs
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

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

htm_Header('@About TinyCash');
showMenu();

htm_Card_('@TinyCash Accounting System', '500');
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
              color: inherit;"> <strong>TinyCash </strong> <?php echo lang('@is a lightweight accounting system designed for small/smaller businesses, that want full control over their own data.'); ?><br>
        <?php echo lang('@Currently as a single-currency system'); ?>
    </p>
    <div style="margin: 25px 0; text-align: left;">
        <h4 style="margin-bottom: 5px;"><?php echo lang('@Developed by:'); ?></h4>
        <p style="margin-top: 0;"><?php echo lang('@EV-soft & Gemini / For your Business'); ?></p>
        
        <h4 style="margin-bottom: 5px;"><?php echo lang('@System status:'); ?></h4>
        <ul style="list-style: none; padding: 0;">
            <li>✅ <?php echo lang('@PHP Version:'); echo ' '.phpversion(); ?></li>
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
        </ul>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
    <small style="color: #9a9d9e;">&copy; <?php echo date('Y'); ?> 𝘓𝘐𝘊𝘌𝘕𝘚𝘌 & 𝘊𝘰𝘱𝘺𝘳𝘪𝘨𝘩𝘵 © EV-soft. All rights reserved.</small>
</div>

<?php 
htm_Card_end();
htm_Footer();
?>
