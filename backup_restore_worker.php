<?php # backup_restore_worker.php v:0.8 d:2026-04-10 i:Gemini m:1
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';

if ($_SESSION['user_role'] !== 'admin') die("Access denied");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['backup_file'])) {
    $tmpFile = $_FILES['backup_file']['tmp_name'];
    $extractDir = 'temp_restore/';
    
    // 1. Opret midlertidig mappe
    if (!is_dir($extractDir)) mkdir($extractDir, 0755);

    // 2. Udpak ZIP
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) === TRUE) {
        $zip->extractTo($extractDir);
        $zip->close();
    } else {
        die(lang('@Error unpacking ZIP.'));
    }

    // 3. Gendan Database (SQL dump)
    $sqlFile = $extractDir . 'database_dump.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        // Multi-query for at køre hele SQL-filen
        if ($conn->multi_query($sql)) {
            while ($conn->next_result()) {;} // Tøm buffer
        }
    }

    // 4. Gendan JSON indstillinger
    if (is_dir($extractDir . 'json-data/')) {
        $jsonFiles = glob($extractDir . 'json-data/*.json');
        foreach ($jsonFiles as $file) {
            copy($file, 'json-data/' . basename($file));
        }
    }

    // 5. Gendan Bilag (uploads)
    if (is_dir($extractDir . 'uploads/')) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir . 'uploads/', RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            $dest = 'uploads/' . ($file->isDir() ? '' : $file->getFilename());
            if ($file->isDir()) {
                if (!is_dir($dest)) mkdir($dest, 0755);
            } else {
                copy($file, $dest);
            }
        }
    }

    // 6. Ryd op i midlertidig mappe
    array_map('unlink', glob("$extractDir/*.*"));
    rmdir($extractDir);

    header("Location: backup_restore.page.php?msg=success");
} else {
    header("Location: backup_restore.page.php?msg=error");
}