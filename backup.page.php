<?php # backup.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// Kun admins har adgang til backup-funktioner
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

htm_Header(lang('@Backup Management'));
showMenu();

echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 1000px; margin: 0 auto;'>";

    // KORT 1: HURTIG SQL BACKUP
    echo "<div>";
    htm_Card_(lang('@Database Backup'));
    echo "<p style='color:#666; font-size:0.9em;'>" . lang('@Download a complete export of your database tables (.sql).<br><br>') . "</p>";
    echo "<div style='margin-top:20px;'>";
    echo "<a href='export_sql.php' style='display:inline-block; background:#3498db; color:white; padding:12px 20px; text-decoration:none; border-radius:4px; font-weight:bold; width: 90%; text-align:center;'>📥 " . lang('@Download SQL Backup') . "</a>";
    echo "</div>";
    htm_Card_end();
    echo "</div>";

    // KORT 2: FULD PROJEKT BACKUP (Den avancerede vi lavede før)
    echo "<div>";
    htm_Card_(lang('@Full System Backup'));
    echo "<p style='color:#666; font-size:0.9em;'>" . lang('@Create a hybrid backup containing both SQL data and JSON configurations.') . "</p>";
    echo "<div style='margin-top:20px;'>";
    echo "<a href='full_project_backup.php' style='display:inline-block; background:#27ae60; color:white; padding:12px 20px; text-decoration:none; border-radius:4px; font-weight:bold; width: 90%; text-align:center;'>📦 " . lang('@Go to Full Backup') . "</a>";
    echo "</div>";
    htm_Card_end();
    echo "</div>";

    // KORT 3: GENDAN
    echo "<div style='grid-column: span 2;'>";
    htm_Card_(lang('@Restore System'));
    echo "<p style='color:#666; font-size:0.9em;'>" . lang('@Restore your system to a previous state using a backup file.') . "</p>";
    echo "<div style='margin-top:20px; text-align:center;'>";
    echo "<a href='backup_gendan.page.php' style='display:inline-block; background:#e67e22; color:white; padding:12px 40px; text-decoration:none; border-radius:4px; font-weight:bold;'>" . lang('@Open Restore Utility') . "</a>";
    echo "</div>";
    htm_Card_end();
    echo "</div>";

echo "</div>";

htm_Footer();
?>