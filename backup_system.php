<?php # backup_system.php v:1.2.0 d:2026-08-11 i:evs 
# (Cross-engine dump via DB::dump_to_sql())
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// Tjek admin rettigheder
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

$backupDir = 'backups/';
$jsonDir   = 'json-data/';
$zipName   = 'TinyCash_Backup_' . date('Y-m-d_Hi') . '.zip';

if (!is_dir($backupDir)) mkdir($backupDir, 0755);

$zip = new ZipArchive();
if ($zip->open($backupDir . $zipName, ZipArchive::CREATE) !== TRUE) {
    die(lang('@ZIP creation failed'));
}

// 1. SQL DUMP (Indeholder alle data: Kontoplan, brugere, transaktioner og FIRMADATA)
// Bruger den centrale cross-engine dump, så den virker på både MySQL og SQLite -
// samme format som full_project_backup.php, hvilket gør backuppen restore-kompatibel.
$sqlDump = DB::dump_to_sql($conn);
$zip->addFromString('database_dump.sql', $sqlDump);

// 2. SYSTEMFILER (Sprogfiler og andre konfigurationer i JSON)
if (is_dir($jsonDir)) {
    $files = scandir($jsonDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && str_ends_with($file, '.json')) {
            $zip->addFile($jsonDir . $file, 'json-data/' . $file);
        }
    }
}

$zip->close();

// 3. DOWNLOAD & OPRYDNING
if (file_exists($backupDir . $zipName)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($backupDir . $zipName));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    readfile($backupDir . $zipName);
    
    // Slet den midlertidige fil efter download
    unlink($backupDir . $zipName);
    exit;
} else {
    die(lang('@Backup file not found'));
}
