<?php # /backup_system.php v:1.3.0 d:2026-08-30 i:evs
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
// RETTET (§bugs-batch-17-review): scandir() lister kun json-data/ selv, ikke
// dens undermapper - json-data/languages/ (de AI-genererede hjælpesystem-
// oversættelser, én pr. sprog) blev derfor aldrig taget med her, selvom
// denne sides egen beskrivelse ("language files") netop lover dem.
if (is_dir($jsonDir)) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($jsonDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'json') {
            $zip->addFile($item->getRealPath(), 'json-data/' . str_replace('\\', '/', $items->getSubPathName()));
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
