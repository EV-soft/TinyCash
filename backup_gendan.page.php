<?php # backup_gendan.page.php
require 'auth.inc.php'; 
require 'db_connect.inc.php'; 
require 'php2htm.lib.php'; 
require 'menu.inc.php'; 

$backupDir = 'backups/';
$besked = "";

if (isset($_POST['restore_file'])) {
    $file = $backupDir . basename($_POST['restore_file']);
    
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // --- DEL 1: Gendan JSON Stamdata ---
        preg_match_all('/\/\* JSON_DATA_START:(.*?)\n(.*?)\nJSON_DATA_END \*\//s', $content, $matches);
        
        $json_status = "";
        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $jsonFileName) {
                $jsonData = $matches[2][$index];
                $targetPath = 'json-data/' . trim($jsonFileName);
                
                if (file_put_contents($targetPath, $jsonData)) {
                    $json_status .= "<li>" . lang('@Restored') . ": $jsonFileName</li>";
                }
            }
        }

        // --- DEL 2: Gendan SQL Database ---
        $sqlOnly = preg_replace('/\/\* JSON_DATA_START:.*?JSON_DATA_END \*\//s', '', $content);
        
        if (mysqli_multi_query($conn, $sqlOnly)) {
            do { if ($res = mysqli_store_result($conn)) { mysqli_free_result($res); } } 
            while (mysqli_more_results($conn) && mysqli_next_result($conn));
            
            $besked = "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:4px; margin-bottom:20px;'>
                        <strong>✅ " . lang('@Restore completed successfully!') . "</strong><br>
                        <ul>$json_status</ul>
                        " . lang('@Database updated from') . " " . htmlspecialchars(basename($file)) . "
                       </div>";
        } else {
            $besked = "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:4px; margin-bottom:20px;'>
                        ❌ " . lang('@SQL Error') . ": " . mysqli_error($conn) . "</div>";
        }
    }
}

htm_Header(lang('@Restore System'));
showMenu();
?>

<div style="max-width:900px; margin:20px auto; font-family:sans-serif;">
    <div style="background:white; padding:25px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="color:#2c3e50;">🔄 <?php echo lang('@Restore System (SQL + JSON)'); ?></h2>
        
        <div style="background:#fff3cd; color:#856404; padding:12px; border:1px solid #ffeeba; border-radius:4px; margin-bottom:20px; font-size:0.9em;">
            <strong>⚠️ <?php echo lang('@Warning'); ?>:</strong> <?php echo lang('@This will overwrite your current invoices, customers and system settings (JSON) with data from the selected backup.'); ?>
        </div>

        <?php echo $besked; ?>

        <table style="width:100%; border-collapse:collapse;">
            <tr style="background:#f8f9fa; border-bottom:2px solid #eee;">
                <th style="padding:12px; text-align:left;"><?php echo lang('@Backup File'); ?></th>
                <th style="padding:12px; text-align:left;"><?php echo lang('@Date / Time'); ?></th>
                <th style="padding:12px; text-align:right;"><?php echo lang('@Action'); ?></th>
            </tr>
            <?php
            $files = glob($backupDir . "*.sql");
            if ($files) {
                array_multisort(array_map('filemtime', $files), SORT_DESC, $files);
                
                foreach ($files as $file) {
                    $name = basename($file);
                    $time = date("d. M Y - H:i", filemtime($file));
                    echo "<tr style='border-bottom:1px solid #eee;'>
                            <td style='padding:12px;'>$name</td>
                            <td style='padding:12px; color:#666;'>$time</td>
                            
                            <td style='padding:12px; text-align:right;'>
                                <form method='POST' style='margin:0;' onsubmit='return confirm(\"" . lang('@Are you absolutely sure you want to overwrite the system with this backup?') . "\");'>
                                    <input type='hidden' name='restore_file' value='$name'>
                                    <button type='submit' style='background:#e67e22; color:white; border:none; padding:6px 15px; border-radius:4px; cursor:pointer;'>" . lang('@Restore') . "</button>
                                </form>
                            </td>
                          </tr>";
                }
            } else {
                echo "<tr><td colspan='3' style='padding:20px; text-align:center;'>" . lang('@No archived backups found in /backups/') . "</td></tr>";
            }
            ?>
        </table>
    </div>
</div>

<?php htm_Footer(); ?>