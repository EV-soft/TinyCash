<?php # /full_project_backup.php v:0.9.5 d:2026-05-10 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

if (($_SESSION['user_role'] ?? '') !== 'admin') { die(lang('@Access denied')); }

$backupDir = 'backups/';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }

$msg = ""; $err = "";

if (isset($_POST['create_zip'])) {
    $zipName = 'FULL_BACKUP_' . date('Y-m-d_H-i-s') . '.zip';
    $zipPath = $backupDir . $zipName;
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
        
        // 1. GENERER SQL DUMP (Samme logik som før)
        $sqlDump = "-- TinyCash SQL Full Dump\n";
        $tables = [];
        $result = mysqli_query($conn, "SHOW TABLES");
        while ($row = mysqli_fetch_row($result)) { $tables[] = $row[0]; }
        foreach ($tables as $table) {
            $res = mysqli_query($conn, "SHOW CREATE TABLE `$table` ");
            $row = mysqli_fetch_row($res);
            $sqlDump .= "\nDROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n";
            $res = mysqli_query($conn, "SELECT * FROM `$table` ");
            while ($row = mysqli_fetch_row($res)) {
                $sqlDump .= "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < mysqli_num_fields($res); $j++) {
                    $sqlDump .= ($row[$j] === null) ? 'NULL' : '"' . mysqli_real_escape_string($conn, $row[$j]) . '"';
                    if ($j < (mysqli_num_fields($res) - 1)) $sqlDump .= ',';
                }
                $sqlDump .= ");\n";
            }
        }
        $zip->addFromString('database_dump.sql', $sqlDump);
        // 2. PAK JSON-DATA MAPPE
        if (is_dir('json-data/')) {
            foreach (glob('json-data/*.json') as $file) {
                $zip->addFile($file, 'json-data/' . basename($file));
            }
        }
        // 3. PAK UPLOADS MAPPE (Inkl. Payout Reports & EXP_ bilag)
        if (is_dir('uploads/')) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator('uploads/', RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getRealPath(), 'uploads/' . $files->getSubPathName());
                }
            }
        }
        $zip->close();
        $msg = lang('@Full ZIP-Backup created successfully!');
    } else {
        $err = lang('@Error: Could not create ZIP file.');
    }
}

htm_Header('@Full Project Backup');
showMenu();

htm_Alert($msg, 'success');
htm_Alert($err, 'error');

htm_Shell_('max-width:600px; margin:20px auto;');
htm_Card_('@Full ZIP-Archive', 600);

echo '<div style="text-align:center; padding:20px;">
        <i class="fa-solid fa-file-zipper" style="font-size:4em; color:#8e44ad; margin-bottom:20px;"></i>
        <p style="color:#666;">'.lang('@This will archive everything: Database, JSON settings, and all uploaded files (receipts & reports).').'</p>
        
        <form method="post" style="margin-top:30px;">
            <button type="submit" name="create_zip" style="background:#8e44ad; color:white; padding:15px 30px; border:none; border-radius:8px; font-size:1.2em; cursor:pointer; font-weight:bold;">
                <i class="fa fa-box-archive"></i> '.lang('@Download Full Archive').'
            </button>
        </form>
      </div>';
htm_Card_end();
htm_Shell_end();
htm_Footer();
ob_end_flush();
?>