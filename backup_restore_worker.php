<?php # backup_restore_worker.php v:1.2.0 d:2026-08-11 i:evs 
# (Motor-tjek mod cross-engine restore)
set_time_limit(600); // Giver scriptet op til 10 minutter
ini_set('memory_limit', '256M'); // Øger hukommelsen, hvis du har store filer/zip

require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';

if ($_SESSION['user_role'] !== 'admin') die("Access denied");

// Rekursiv sletning af den midlertidige udpakningsmappe. Bruges både ved
// normal afslutning og ved tidligt afbrud (fx motor-mismatch), så vi aldrig
// efterlader en halv-udpakket backup på disken.
function cleanup_extract($dir) {
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
    }
    rmdir($dir);
}

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
    // Genbruger den forbindelse db_connect.inc.php allerede har åbnet ($pdo/$conn,
    // $db_type) - så vi undgår den manglende inc/config.inc.php og rammer altid
    // den motor, der faktisk er aktiv for sessionen.
    global $pdo, $conn, $db_type;
    $sqlFile = $extractDir . 'database_dump.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);

        // MOTOR-TJEK: En dump må kun gendannes på den motor, den blev lavet på.
        // SQLite- og MySQL-dialekterne (citationstegn, AUTO_INCREMENT, backticks
        // osv.) er ikke kompatible, så en cross-engine restore ville droppe alle
        // tabeller og derefter fejle på import - og efterlade databasen tom.
        // DB::dump_to_sql() skriver motoren i header-kommentaren:
        // "-- TinyCash System Dump - Type: <motor> - ...". Kan motoren ikke
        // aflæses (ældre/fremmed dump), springer vi tjekket over og fortsætter.
        $current_engine = DB::is_sqlite() ? 'sqlite' : 'mysql';
        if (preg_match('/Type:\s*(sqlite|mysql)/i', $sql, $m)) {
            $backup_engine = strtolower($m[1]);
            if ($backup_engine !== $current_engine) {
                cleanup_extract($extractDir);
                header("Location: backup_restore.php?msg=engine_mismatch&src=" .
                       urlencode($backup_engine) . "&dst=" . urlencode($current_engine));
                exit;
            }
        }

        try {
            if (DB::is_sqlite()) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("PRAGMA foreign_keys = OFF;");
                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) { $pdo->exec("DROP TABLE IF EXISTS \"$table\""); }
                $pdo->exec($sql);
                $pdo->exec("PRAGMA foreign_keys = ON;");
            } else {
                mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0;");
                $result = mysqli_query($conn, "SHOW TABLES;");
                while ($row = mysqli_fetch_array($result)) { mysqli_query($conn, "DROP TABLE IF EXISTS `" . $row[0] . "`"); }
                mysqli_multi_query($conn, $sql);
                while (mysqli_more_results($conn) && mysqli_next_result($conn)) {;}
                mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1;");
            }
        } catch (Exception $e) {
            error_log("Restore fejl: " . $e->getMessage());
            header("Location: backup_restore.php?msg=error");
            exit;
        }
    }

    // 4. Gendan JSON-data (sprogfiler, konfiguration m.m.)
    $jsonSrc = $extractDir . 'json-data/';
    if (is_dir($jsonSrc)) {
        if (!is_dir('json-data/')) mkdir('json-data/', 0755, true);
        foreach (glob($jsonSrc . '*.json') as $file) {
            copy($file, 'json-data/' . basename($file));
        }
    }

    // 5. Gendan uploads (bilag, kvitteringer, rapporter) - bevarer undermapper
    $uploadSrc = $extractDir . 'uploads/';
    if (is_dir($uploadSrc)) {
        if (!is_dir('uploads/')) mkdir('uploads/', 0755, true);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadSrc, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = 'uploads/' . $items->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getRealPath(), $target);
            }
        }
    }

    // 6. Ryd op
    cleanup_extract($extractDir);

    header("Location: backup_restore.php?msg=success");
    exit;
} else {
    header("Location: backup_restore.php?msg=error");
    exit;
}
?>