<?php # backup_restore_worker.php v:1.1.0 d:2026-07-05 i:evs
set_time_limit(600); // Giver scriptet op til 10 minutter
ini_set('memory_limit', '256M'); // Øger hukommelsen, hvis du har store filer/zip

require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';

if ($_SESSION['user_role'] !== 'admin') die("Access denied");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['backup_file'])) {
    $tmpFile = $_FILES['backup_file']['tmp_name'];
    $extractDir = 'temp_restore/';
    
    if (!is_dir($extractDir)) mkdir($extractDir, 0755);

    $zip = new ZipArchive();
    if ($zip->open($tmpFile) === TRUE) {
        $zip->extractTo($extractDir);
        $zip->close();
    } else {
        header("Location: backup_restore.php?msg=error");
        exit;
    }

    // 3. Gendan Database
    $sqlFile = $extractDir . 'database_dump.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        require 'inc/config.inc.php'; 
        
        try {
            if (DB::is_sqlite()) {
                $pdo_restore = new PDO("sqlite:" . $db_path);
                $pdo_restore->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo_restore->exec("PRAGMA foreign_keys = OFF;");
                $tables = $pdo_restore->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) { $pdo_restore->exec("DROP TABLE IF EXISTS `$table`"); }
                $pdo_restore->exec("PRAGMA foreign_keys = ON;");
                $pdo_restore->exec($sql);
            } else {
                $mysqli_restore = new mysqli($db_host, $db_user, $db_pass, $db_name);
                $mysqli_restore->query("SET FOREIGN_KEY_CHECKS = 0;");
                $result = $mysqli_restore->query("SHOW TABLES;");
                while ($row = $result->fetch_array()) { $mysqli_restore->query("DROP TABLE IF EXISTS " . $row[0]); }
                $mysqli_restore->query("SET FOREIGN_KEY_CHECKS = 1;");
                $mysqli_restore->multi_query($sql);
                while ($mysqli_restore->more_results() && $mysqli_restore->next_result()) {;}
            }
        } catch (Exception $e) {
            error_log("Restore fejl: " . $e->getMessage());
            header("Location: backup_restore.php?msg=error");
            exit;
        }
    }

    // 4. JSON & 5. Uploads (din eksisterende logik her...)
    // ... (resten af din kode til JSON og uploads)

    // 6. Ryd op
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $fileinfo) { $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath()); }
    rmdir($extractDir);

    header("Location: backup_restore.php?msg=success");
    exit;
} else {
    header("Location: backup_restore.php?msg=error");
    exit;
}
?>