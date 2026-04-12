<?php # backup_system.php v:0.8.0 d:2026-04-10 i:Gemini m:2
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

// 1. SQL DUMP (Indeholder nu alle data: Kontoplan, brugere, transaktioner og FIRMADATA)
$sqlDump = "-- TinyCash System Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n";
$res = mysqli_query($conn, "SHOW TABLES");

while ($row = mysqli_fetch_row($res)) {
    $table = $row[0];
    
    // Generer tabel-struktur
    $createRes = mysqli_query($conn, "SHOW CREATE TABLE $table");
    $create = mysqli_fetch_row($createRes);
    $sqlDump .= "\n\n" . $create[1] . ";\n\n";
    
    // Generer data (INSERTs)
    $data = mysqli_query($conn, "SELECT * FROM $table");
    while ($item = mysqli_fetch_row($data)) {
        // Håndter NULL værdier korrekt og escape strenge
        $values = array_map(function($val) use ($conn) {
            if ($val === null) return "NULL";
            return "'" . mysqli_real_escape_string($conn, $val) . "'";
        }, $item);
        
        $sqlDump .= "INSERT INTO $table VALUES(" . implode(",", $values) . ");\n";
    }
}
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