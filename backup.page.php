<?php # backup.page.php v:0.8 d:2026-04-10 i:Gemini m:2
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php'; 
require 'inc/menu.inc.php';

// Sikkerhedstjek:
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

htm_Header(lang('@Backup Management'));
showMenu();

echo "<div style='max-width: 1000px; margin: 0 auto; font-family: sans-serif;'>";

    // GRID LAYOUT FOR BACKUP KORT
    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;'>";

        // KORT 1: SQL DATABASE (Hurtig & vigtig)
        echo "<div>";
        htm_Card_(lang('@Database Backup'));
        echo "<p style='color:#666; font-size:0.9em; min-height:50px;'>" . lang('@Download a complete export of your database tables (.sql).') . "</p>";
        echo "<a href='export_sql.php' style='display:block; background:#3498db; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>📥 " . lang('@Download SQL Backup') . "</a>";
        htm_Card_end();
        echo "</div>";

        // KORT 2: SYSTEM & KONFIGURATION (SQL + JSON)
        echo "<div>";
        htm_Card_(lang('@System & Config Backup'));
        echo "<p style='color:#666; font-size:0.9em; min-height:20px;'>" . lang('@Backup containing SQL data.') . "</p>";
        echo "<a href='backup_system.php' style='display:block; background:#27ae60; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>📦 " . lang('@Download System Backup') . "</a>";
        htm_Card_end();
        echo "</div>";

        // KORT 3: FULD PROJEKT BACKUP (Alt inkl. Bilag)
        echo "<div>";
        htm_Card_(lang('@Full ZIP-Backup'));
        echo "<p style='color:#666; font-size:0.9em; min-height:50px;'>" . lang('@Archive all data, settings, and uploaded receipts into one large ZIP file.') . "</p>";
        echo "<a href='full_project_backup.php' style='display:block; background:#8e44ad; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>🗄️ " . lang('@Download Full Archive') . "</a>";
        htm_Card_end();
        echo "</div>";

        // KORT 4: GENDANNELSE
        echo "<div>";
        htm_Card_(lang('@Restore System'));
        echo "<p style='color:#666; font-size:0.9em; min-height:50px;'>" . lang('@Restore your system to a previous state using a backup file.') . "</p>";
        echo "<a href='backup_restore.page.php' style='display:block; background:#e67e22; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;'>🔄 " . lang('@Open Restore Utility') . "</a>";
        htm_Card_end();
        echo "</div>";

    echo "</div>"; // Slut på grid

    // EKSTRA INFO
    echo '<div style="margin-top: 30px; padding: 20px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; color: #856404;">';
    echo '<strong>💡 '. lang('@Tip:').'</strong> ' . lang('@Regular backups protect your data against server failures. Always store your backup files in a safe place outside the server.');
    echo '</div>';

echo '</div>';

htm_Footer();
?>