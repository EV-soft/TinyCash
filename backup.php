<?php # /backup.php v:1.0.0 d:2026-06-15 i:evs
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php'; 
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; 
require_once 'inc/help.lib.php';

/* if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully(); // Kalder den centrale funktion fra auth.inc.php
} */
if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) {
    deny_access_gracefully(); 
}

htm_Header('@Backup Management');
showMenu();

echo "<div style='max-width: 1000px; margin: 0 auto; font-family: sans-serif; box-sizing: border-box;'>";

    // 1. RÆKKE: DE TRE DOWNLOAD-KORT
    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;'>";
        // Kort 1: Database
        echo "<div style='background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;'>";
        echo "<div><h3 style='margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;'>" . lang('@Database Export') . "</h3>";
        echo "<p style='color:#64748b; font-size:0.9em; line-height:1.4; margin: 10px 0 20px 0;'>" . lang('@Generate and download a complete export of your database tables (.sql).') . "</p></div>";
        echo "<a href='export_sql.php' style='display:block; width:100%; box-sizing:border-box; background:#3498db; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>📥 " . lang('@Export SQL Database') . "</a>";
        echo "</div>";

        // Kort 2: System & Config
        echo "<div style='background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;'>";
        echo "<div><h3 style='margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;'>" . lang('@System Configuration') . "</h3>";
        echo "<p style='color:#64748b; font-size:0.9em; line-height:1.4; margin: 10px 0 20px 0;'> " . lang('@Backup containing system settings, core profiles and configurations.') . "</p></div>";
        echo "<a href='backup_system.php' style='display:block; width:100%; box-sizing:border-box; background:#27ae60; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>📦 " . lang('@Download Config Backup') . "</a>";
        echo "</div>";

        // Kort 3: Fuld ZIP
        echo "<div style='background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;'>";
        echo "<div><h3 style='margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;'>" . lang('@Build Full Archive') . "</h3>";
        echo "<p style='color:#64748b; font-size:0.9em; line-height:1.4; margin: 10px 0 20px 0;'> " . lang('@Compile all system files, settings, and uploaded receipts into a new ZIP archive.') . "</p></div>";
        echo "<a href='full_project_backup.php' style='display:block; width:100%; box-sizing:border-box; background:#8e44ad; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>🗄️ " . lang('@Generate & Download ZIP') . "</a>";
        echo "</div>";
    echo "</div>";

    // Find nyeste lokal fil til integritets-tjek
    $backup_dir = __DIR__ . '/backups/';
    $latest_file = null;
    $latest_mtime = 0;

    if (is_dir($backup_dir)) {
        foreach (glob($backup_dir . '*.zip') as $file) {
            if (filemtime($file) > $latest_mtime) {
                $latest_mtime = filemtime($file);
                $latest_file = $file;
            }
        }
    }

    // 2. RÆKKE: GENDAN, INTEGRITETSTJEK OG INTEGRERET COMPLIANCE-VEJLEDNING
    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;'>";
        
        // Venstre kolonne: Gendan + Nyeste Backup Info
        echo "<div style='display: flex; flex-direction: column; gap: 20px;'>";
            
            // Gendan Værktøj
            echo "<div style='background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;'>";
            echo "<h3 style='margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;'>" . lang('@Restore System') . "</h3>";
            echo "<p style='color:#64748b; font-size:0.9em; line-height:1.4; margin: 10px 0 20px 0;'>" . lang('@Restore your system to a previous state using a backup file.') . "</p>";
            echo "<a href='backup_restore.php' style='display:block; width:100%; box-sizing:border-box; background:#e67e22; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>🔄 " . lang('@Open Restore Utility') . "</a>";
            echo "</div>";
            
            // Integritetsstatus
            echo "<div style='background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;'>";
            echo "<h3 style='margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;'>" . lang('@Latest Backup Integrity') . "</h3>";
            echo "<p style='margin: 10px 0 0 0; font-size:0.9em;'>";
            
            if ($latest_file) {
                if (class_exists('ZipArchive')) { // Sikring mod fatal fejl hvis zip-extension mangler
                    $zip = new ZipArchive();
                    if ($zip->open($latest_file, ZipArchive::CHECKCONS) === TRUE) {
                        $file_md5 = md5_file($latest_file);
                        $filename = htmlspecialchars(basename($latest_file), ENT_QUOTES, 'UTF-8');
                        
                        echo "<span style='color: #16a34a; font-weight: bold;'>✔️ Verified Valid</span><br>";
                        echo "<span style='font-size:0.85em; color:#64748b; display:block; margin: 5px 0;'>Fil: " . $filename . "</span>";
                        echo "<span data-hint='" . lang('@This token verifies the archive on the server is healthy. Compare it to your cloud upload to guarantee 0% data loss.') . "' style='color: #475569; display:block; margin-top:8px; font-size:0.85em; font-family:monospace; background:#f1f5f9; padding:6px; border-radius:3px; cursor:help; border:1px solid #e2e8f0;'>Local MD5: " . $file_md5 . "</span>";
                        
                        $zip->close();
                    } else {
                        echo "<span style='color: #dc2626; font-weight: bold;'>❌ Archive Corrupted</span><br>";
                        echo "<span style='font-size:0.85em; color:#dc2626;'>The zip file on the server is broken.</span>";
                    }
                } else {
                    echo "<span style='color: #e67e22; font-weight: bold;'>⚠️ Cannot Verify</span><br>";
                    echo "<span style='font-size:0.85em; color:#475569;'>PHP ZipArchive extension is not enabled on this server.</span>";
                }
            } else {
                echo "<span style='color: #64748b; font-style: italic;'>No archive found. Run a full backup above.</span>";
            }
            echo "</p>";
            echo "</div>";
        echo "</div>";

        // Højre kolonne: Den dynamiske Off-site Compliance vejledning
        echo "<div style='grid-column: span 2; background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;'>";
        echo "<h3 style='margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;'>" . lang('@Off-site Compliance Documentation') . "</h3>";
        
        $user_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da'; 
        $json_help_content = _help_get_content('backup.php', $user_lang);
        if ($json_help_content) {
            echo "<div style='font-size: 0.9em; color: #2c3e50; line-height:1.5;'>";
            echo $json_help_content;
            echo "</div>";
        } else {
            echo "<p style='color:#7f8c8d; font-style:italic; margin-top:10px;'>Documentation text could not be loaded from help system resource.</p>";
        }
        echo "</div>";

    echo "</div>";

    // TIP i bunden
    echo '<div style="margin-top: 30px; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; color: #856404; font-size: 0.9em; margin-bottom: 20px;">';
    echo '<strong>💡 ' . lang('@Tip:') . '</strong> ' . lang('@Regular backups protect your data against server failures. Always store your backup files in a safe place outside the server.');
    echo '</div>';

echo '</div>'; // Container lukkes korrekt HER, før eksterne komponenter loades

htm_HelpSystem();
htm_FloatingActionBar();
htm_Footer();
?>
