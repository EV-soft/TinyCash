<?php # /backup.php v:1.2.0 d:2026-08-11 i:evs 
# (Lang vejledning flyttet til hjælpesystemet, vist inline)
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/help.lib.php';

if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) {
    deny_access_gracefully();
}

htm_Header('@Backup Management');
showMenu();

// Find nyeste lokal ZIP-fil til integritets-tjek (uændret logik)
$backup_dir   = __DIR__ . '/backups/';
$latest_file  = null;
$latest_mtime = 0;
if (is_dir($backup_dir)) {
    foreach (glob($backup_dir . '*.zip') as $file) {
        if (filemtime($file) > $latest_mtime) {
            $latest_mtime = filemtime($file);
            $latest_file  = $file;
        }
    }
}

// Genbrugte inline-stilarter (holdt konsistente med resten af filen)
$card  = "background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;";
$cardF = $card . " display: flex; flex-direction: column; justify-content: space-between;";
$h3    = "margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;";
$p     = "color:#64748b; font-size:0.9em; line-height:1.4; margin: 10px 0 20px 0;";
$btn   = "display:block; width:100%; box-sizing:border-box; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;";
$sechd = "color:#1e293b; font-size:1.25em; font-weight:bold; margin: 30px 0 4px 0;";
$secsub= "color:#64748b; font-size:0.9em; line-height:1.4; margin: 0 0 15px 0;";

echo "<div style='max-width: 1000px; margin: 0 auto; font-family: sans-serif; box-sizing: border-box;'>";

    // ══ SEKTION 1: REGNSKABS-BACKUP ═════════════════════════════════════════
    echo "<h2 style='$sechd'>🧾 " . lang('@Accounting Backup') . "</h2>";
    echo "<p style='$secsub'>" . lang('@For the bookkeeper.') . "</p>";

    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 10px;'>";

        // Fuld data-arkiv (primær regnskabs-backup)
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Full Data Archive') . "</h3>";
        echo "<p style='$p'>" . lang('@Compile the database, settings and all uploaded receipts into one ZIP snapshot of your accounting data.') . "</p></div>";
        echo "<a href='full_project_backup.php' style='$btn background:#8e44ad;'>🗄️ " . lang('@Generate & Download ZIP') . "</a>";
        echo "</div>";

        // Rå SQL-eksport
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Database Export') . "</h3>";
        echo "<p style='$p'>" . lang('@Download a raw .sql dump of all tables (structure and data).') . "</p></div>";
        echo "<a href='export_sql.php' style='$btn background:#3498db;'>📥 " . lang('@Export SQL Database') . "</a>";
        echo "</div>";

        // Gendan
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Restore System') . "</h3>";
        echo "<p style='$p'>" . lang('@Restore your system to a previous state using a backup file.') . "</p></div>";
        echo "<a href='backup_restore.php' style='$btn background:#e67e22;'>🔄 " . lang('@Open Restore Utility') . "</a>";
        echo "</div>";

    echo "</div>";

    // ══ SEKTION 2: PROGRAM-BACKUP ═══════════════════════════════════════════
    echo "<h2 style='$sechd'>📦 " . lang('@Program Backup') . "</h2>";
    echo "<p style='$secsub'>" . lang('@For the system administrator.') . "</p>";

    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 10px;'>";

        // System- & konfigurations-backup
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@System Configuration') . "</h3>";
        echo "<p style='$p'>" . lang('@Backup of the database structure and configuration (settings, chart of accounts, language files).') . "</p></div>";
        echo "<a href='backup_system.php' style='$btn background:#27ae60;'>📦 " . lang('@Download Config Backup') . "</a>";
        echo "</div>";

        // Program-backup (kildekode + DB-struktur)
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Program Source Backup') . "</h3>";
        echo "<p style='$p'>" . lang('@Archive the program source code and the database structure before and after an update. Excludes secrets and accounting data.') . "</p></div>";
        echo "<a href='program_backup.php' style='$btn background:#0ea5e9;'>💾 " . lang('@Generate Program Backup') . "</a>";
        echo "</div>";

    echo "</div>";

    // ══ SEKTION 3: INTEGRITET & NYESTE BACKUP ═══════════════════════════════
    echo "<h2 style='$sechd'>🔎 " . lang('@Latest Backup Integrity') . "</h2>";
    echo "<p style='$secsub'>" . lang('@Check the newest archive before you rely on it.') . "</p>";

    echo "<div style='$card margin-bottom: 10px;'>";
    echo "<p style='margin: 0; font-size:0.9em;'>";
    if ($latest_file) {
        if (class_exists('ZipArchive')) { // Sikring mod fatal fejl hvis zip-extension mangler
            $zip = new ZipArchive();
            if ($zip->open($latest_file, ZipArchive::CHECKCONS) === TRUE) {
                $file_md5 = md5_file($latest_file);
                $filename = htmlspecialchars(basename($latest_file), ENT_QUOTES, 'UTF-8');

                echo "<span style='color: #16a34a; font-weight: bold;'>✔️ " . lang('@Verified Valid') . "</span><br>";
                echo "<span style='font-size:0.85em; color:#64748b; display:block; margin: 5px 0;'>" . lang('@File:') . " " . $filename . "</span>";
                echo "<span data-hint='" . lang('@This token verifies the archive on the server is healthy. Compare it to your cloud upload to guarantee 0% data loss.') . "' style='color: #475569; display:block; margin-top:8px; font-size:0.85em; font-family:monospace; background:#f1f5f9; padding:6px; border-radius:3px; cursor:help; border:1px solid #e2e8f0;'>Local MD5: " . $file_md5 . "</span>";

                $zip->close();
            } else {
                echo "<span style='color: #dc2626; font-weight: bold;'>❌ " . lang('@Archive Corrupted') . "</span><br>";
                echo "<span style='font-size:0.85em; color:#dc2626;'>" . lang('@The zip file on the server is broken.') . "</span>";
            }
        } else {
            echo "<span style='color: #e67e22; font-weight: bold;'>⚠️ " . lang('@Cannot Verify') . "</span><br>";
            echo "<span style='font-size:0.85em; color:#475569;'>" . lang('@PHP ZipArchive extension is not enabled on this server.') . "</span>";
        }
    } else {
        echo "<span style='color: #64748b; font-style: italic;'>" . lang('@No archive found. Run a full backup above.') . "</span>";
    }
    echo "</p>";
    echo "</div>";

    // ══ VEJLEDNING (fra hjælpesystemet, vist inline og oversat) ═════════════
    // Al lang prosa - to formål, backupfilers flow, motor-advarsel, test,
    // off-site compliance - bor i json-data/help_system.json (+ _da), så den
    // oversættes ordentligt og undgår extract_translations' 60-tegns-grænse.
    echo "<h2 style='$sechd'>📖 " . lang('@Backup Guidance') . "</h2>";
    echo "<div style='$card margin-bottom: 10px;'>";
    $user_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
    $json_help_content = _help_get_content('backup.php', $user_lang);
    if ($json_help_content) {
        echo "<div style='font-size: 0.9em; color: #2c3e50; line-height:1.6;'>";
        echo $json_help_content;
        echo "</div>";
    } else {
        echo "<p style='color:#7f8c8d; font-style:italic; margin:0;'>" . lang('@Documentation text could not be loaded from help system resource.') . "</p>";
    }
    echo "</div>";

    // TIP i bunden (uændret)
    echo '<div style="margin-top: 30px; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; color: #856404; font-size: 0.9em; margin-bottom: 20px;">';
    echo '<strong>💡 ' . lang('@Tip:') . '</strong> ' . lang('@Regular backups protect your data against server failures. Always store your backup files in a safe place outside the server.');
    echo '</div>';

echo '</div>'; // Container lukkes korrekt HER, før eksterne komponenter loades

htm_HelpSystem();
htm_FloatingActionBar();
htm_Footer();
?>
