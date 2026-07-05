<?php # /full_project_backup.php v:1.1.0 d:2026-07-05 i:evs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) { 
    die(lang('@Access denied')); 
}

$backupDir = 'backups/';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }

$msg = ""; $err = "";

if (isset($_POST['create_zip'])) {
    $db_suffix = DB::is_sqlite() ? '_sqlite' : '_mysql';
    $zipName = 'FULL_BACKUP_' . date('Y-m-d_H-i-s') . $db_suffix . '.zip';
    $zipPath = $backupDir . $zipName;
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
        
        // 1. GENERER SQL DUMP
        $sqlDump = DB::dump_to_sql($conn);
        $zip->addFromString('database_dump.sql', $sqlDump);

        // 2. PAK JSON-DATA MAPPE
        if (is_dir('json-data/')) {
            foreach (glob('json-data/*.json') as $file) {
                $zip->addFile($file, 'json-data/' . basename($file));
            }
        }

        // 3. PAK UPLOADS MAPPE
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
        
        // --- SKUDSIKKER OPDATERING (REPLACE INTO fjerner dubletter automatisk) ---
        $now = time();
        $key = 'last_backup_time';

        // REPLACE INTO sletter automatisk den gamle række, hvis den findes, og indsætter den nye.
        // Dette virker både i MySQL og SQLite.
        $sql = "REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)";
        DB::prepare_and_execute($conn, $sql, [$key, $now]);
        
        $msg = lang('@Full ZIP-Backup created successfully!') . 
            '<span style="text-align:right; width: 50%; display: inline-block;"> 
            <a href="'. $zipPath . '" style="display:inline-block; background:#16a34a; color:white; 
            padding:8px 15px; text-decoration:none; border-radius:4px; font-weight:bold;" 
            download>💾 ' . lang('@Download archive now') . "</a></span>";
    } else {
        $err = lang('@Error: Could not create ZIP file.');
    }
}

htm_Header('@Full Project Backup');
showMenu();

if ($msg) { htm_Alert($msg, 'success'); }
if ($err) { htm_Alert($err, 'error'); }

htm_Shell_('max-width:600px; margin:20px auto;');
htm_Card_('@Full ZIP-Archive', 600);

echo '<div style="text-align:center; padding:20px;">
        <i class="fa-solid fa-file-zipper" style="font-size:4em; color:#8e44ad; margin-bottom:20px;"></i>
        <p style="color:#666;">'.lang('@This will archive everything: Database, JSON settings, and all uploaded files (receipts & reports).').'</p>
        
        <form method="post" style="margin-top:30px;">
            <button type="submit" name="create_zip" style="background:#8e44ad; color:white; padding:15px 30px; border:none; border-radius:8px; font-size:1.2em; cursor:pointer; font-weight:bold;">
                <i class="fa fa-box-archive"></i> '.lang('@Generate Full Archive').'
            </button>
        </form>
      </div>';
htm_Card_end();
htm_Shell_end();
htm_Footer();
ob_end_flush();
?>