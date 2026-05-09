<?php # /backup_list.page.php v:0.9.1 d:2026-05-08 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

if (($_SESSION['user_role'] ?? '') !== 'admin') { die(lang('@Access denied')); }

$backupDir = 'backups/';
$msg = ""; $err = "";

// 1. Håndter sletning af backup
if (isset($_GET['delete'])) {
    $fileToDelete = basename($_GET['delete']); // basename for sikkerhed
    $path = $backupDir . $fileToDelete;
    
    if (file_exists($path) && is_file($path)) {
        if (unlink($path)) {
            $msg = lang('@Backup file deleted.');
        } else {
            $err = lang('@Error: Could not delete file.');
        }
    }
}

htm_Header('@Backup Files');
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";
    echo "<h2 style='margin-bottom: 20px;'>📂 " . lang('@Manage Backups') . "</h2>";
    htm_Alert($msg, 'success');
    htm_Alert($err, 'error')
    htm_Card_('@Stored Backups', '100%');

    // Hent filer og sorter efter nyeste først
    $files = glob($backupDir . "*.sql");
    array_multisort(array_map('filemtime', $files), SORT_DESC, $files);

    echo "<table class='std-table' style='width:100%; border-collapse: collapse;'>";
    echo "<thead>
            <tr>
                <th>" . lang('@Filename') . "</th>
                <th>" . lang('@Date') . "</th>
                <th style='text-align:center;'>" . lang('@Size') . "</th>
                <th style='text-align:right;'>" . lang('@Actions') . "</th>
            </tr>
          </thead>
          <tbody>";
    if (empty($files)) {
        echo "<tr><td colspan='4' style='padding:40px; text-align:center; color:#999;'>" . lang('@No backups found') . "</td></tr>";
    } else {
        foreach ($files as $file) {
            $filename = basename($file);
            $date = date("Y-m-d H:i:s", filemtime($file));
            $size = round(filesize($file) / 1024, 2) . " KB";
            echo "<tr>";
                echo "<td style='font-weight:bold;'>$filename</td>";
                echo "<td>$date</td>";
                echo "<td style='text-align:center;'>$size</td>";
                echo "<td style='text-align:right;'>";
                    // Download (direkte link til filen)
                    echo "<a href='$file' download class='link-invoice' style='margin-right:15px;' title='".
                          lang('@Download')."'>📥</a>";
                    // Slet (med bekræftelse)
                    echo "<a href='?delete=$filename' onclick='return confirm(\"".
                          lang('@Are you sure?')."\")' style='text-decoration:none; font-size:1.2em;' title='".
                          lang('@Delete')."'>🗑️</a>";
                echo "</td>";
            echo "</tr>";
        }
    }
    echo "</tbody></table>";

    // Knap til at oprette ny backup
    echo "<div style='margin-top:25px; border-top:1px solid #eee; padding-top:20px; display:flex; gap:15px;'>";
        echo "<a href='full_project_backup.php' class='btn-success' style='flex:1; text-align:center; text-decoration:none;'>+ " . lang('@Create New Backup') . "</a>";
        echo "<a href='backup_gendan.page.php' class='btn-primary' style='flex:1; text-align:center; text-decoration:none;'>🔄 " . lang('@Restore Backup') . "</a>";
    echo "</div>";
    htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush();