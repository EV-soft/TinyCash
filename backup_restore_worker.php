<?php # /backup_restore_worker.php v:1.3.0 d:2026-08-30 i:evs
# Modtager en uploadet backup-ZIP fra backup_restore.php, pakker den ud og
# gendanner database + json-data/ + uploads/ fra den. Kun admin. Afviser en
# cross-engine restore (motoren i dumpens header-kommentar skal matche den
# aktive motor), og en ZIP der reelt mangler database_dump.sql (fx en
# ugyldig/korrupt upload) - i begge tilfælde uden at røre den eksisterende
# database. Gemmer altid en sikkerhedskopi af DEN NUVÆRENDE database til
# backups/ FØR den destruktive DROP TABLE + import, og logger selve
# gendannelsen til backups/restore_log.txt (en fil, ikke DB - at logge til
# audit_log-tabellen ville være selvmodsigende, da gendannelsen netop
# overskriver den tabel).
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

    // KRITISK: hvis den uploadede ZIP mangler database_dump.sql (forkert/
    // gammel/korrupt fil - brugeren kan uploade HVILKEN SOM HELST zip, ikke
    // kun ægte TinyCash-backups), sprang hele database-gendannelsen FØR
    // stiltiende over uden varsel - trin 4/5/6 (json-data/uploads) fortsatte
    // alligevel, og siden endte på msg=success, som om HELE gendannelsen
    // lykkedes. Databasen var reelt slet ikke rørt, men brugeren fik besked
    // om at gendannelsen var fuldført. Fundet ved en backup-/
    // gendannelsesgennemgang.
    if (!file_exists($sqlFile)) {
        cleanup_extract($extractDir);
        header("Location: backup_restore.php?msg=missing_dump");
        exit;
    }

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

        // SIKKERHEDSKOPI: gem en frisk dump af DEN NUVÆRENDE database FØR den
        // destruktive gendannelse (DROP TABLE + import) går i gang. Uden dette
        // var der ingen vej tilbage, hvis selve importen fejlede halvvejs (fx
        // en korrupt upload, eller en fremmed/ukendt dump uden motor-mærke der
        // glider forbi tjekket ovenfor) - originaldata var på det tidspunkt
        // allerede droppet. Fundet ved en backup-/gendannelsesgennemgang.
        $safety_dir  = 'backups/';
        if (!is_dir($safety_dir)) mkdir($safety_dir, 0755, true);
        $safety_file = $safety_dir . 'pre_restore_safety_' . date('Y-m-d_His') . '.sql';
        $safety_ok   = @file_put_contents($safety_file, DB::dump_to_sql($conn)) !== false;
        if (!$safety_ok) {
            // Kan vi ikke garantere en sikkerhedskopi, er det tryggere at
            // afbryde gendannelsen end at risikere data uden nogen vej tilbage.
            cleanup_extract($extractDir);
            header("Location: backup_restore.php?msg=safety_backup_failed");
            exit;
        }

        // REVISIONSSPOR (2026-08-20): en gendannelse overskriver hele
        // databasen, så at logge til audit_log-tabellen ville være
        // selvmodsigende - selve handlingen ville overskrive loggen med
        // indholdet fra backuppen. Logges derfor til en almindelig fil i
        // stedet, som IKKE berøres af selve database-gendannelsen, og som
        // derfor overlever handlingen den er et spor af. Fundet under en
        // revisionsspor-gennemgang.
        $restore_log_line = '[' . date('Y-m-d H:i:s') . '] '
            . lang('@Restore initiated by') . ' ' . ($_SESSION['user_name'] ?? '#' . ($_SESSION['user_id'] ?? 0))
            . ' - ' . lang('@Uploaded file:') . ' ' . basename($_FILES['backup_file']['name'] ?? '?')
            . ' - ' . lang('@Safety backup saved as:') . ' ' . basename($safety_file) . "\n";
        @file_put_contents($safety_dir . 'restore_log.txt', $restore_log_line, FILE_APPEND | LOCK_EX);

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
            // RETTET (§bugs-batch-14-review): manglede cleanup_extract() her -
            // en fejlet import (fx et korrupt/delvist dump) efterlod den
            // fuldt udpakkede temp_restore/-mappe (ukrypteret database_dump.sql
            // + uploads/ + storage/ fra den uploadede backup) liggende på
            // disken på ubestemt tid, aldrig ryddet op. Parret med den
            // manglende temp_restore/.htaccess (nu tilføjet) var dette reelt
            // en fuld, uautentificeret kopi af hele databasen efterladt et
            // web-tilgængeligt sted efter enhver fejlet gendannelse.
            error_log("Restore fejl: " . $e->getMessage());
            cleanup_extract($extractDir);
            header("Location: backup_restore.php?msg=error");
            exit;
        }

    // 4. Gendan JSON-data (sprogfiler, konfiguration m.m.)
    // RETTET (§bugs-batch-17-review): glob(...'*.json') er ikke rekursivt og
    // gendannede derfor aldrig json-data/languages/ (AI-genererede
    // hjælpesystem-oversættelser) - samme mangel som på selve backup-siden
    // (full_project_backup.php/backup_system.php, nu også rettet der).
    $jsonSrc = $extractDir . 'json-data/';
    if (is_dir($jsonSrc)) {
        if (!is_dir('json-data/')) mkdir('json-data/', 0755, true);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($jsonSrc, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = 'json-data/' . str_replace('\\', '/', $items->getSubPathName());
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } elseif (strtolower($item->getExtension()) === 'json') {
                copy($item->getRealPath(), $target);
            }
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

    // 5b. Gendan storage (voucher_depot/einvoices/saf-t) - manglede FØR helt,
    // parret med den tilsvarende manglende sikkerhedskopiering i
    // full_project_backup.php. Samme mønster som uploads/ ovenfor.
    $storageSrc = $extractDir . 'storage/';
    if (is_dir($storageSrc)) {
        if (!is_dir('storage/')) mkdir('storage/', 0755, true);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageSrc, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = 'storage/' . $items->getSubPathName();
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