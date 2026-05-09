<?php # /full_project_backup.php v:0.9.0 d:2026-05-08 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// Kun administratorer har adgang til backup-funktioner
if (($_SESSION['user_role'] ?? '') !== 'admin') { die(lang('@Access denied')); }

$backupDir = 'backups/';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }

$msg = ""; $err = "";
if (isset($_POST['create_backup'])) {
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filePath = $backupDir . $filename;
    
    // 1. Start SQL Dump
    $sqlDump = "-- TinyCash SQL Dump\n";
    $sqlDump .= "-- " . lang('@Date') . ": " . date('Y-m-d H:i:s') . "\n\n";

// --- SQL GENERERING START ---

// 1. Hent alle tabeller fra databasen
$tables = [];
$result = mysqli_query($conn, "SHOW TABLES");
while ($row = mysqli_fetch_row($result)) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    // 2. Skab tabel-struktur (CREATE TABLE)
    $res = mysqli_query($conn, "SHOW CREATE TABLE `$table` ");
    $row = mysqli_fetch_row($res);
    
    $sqlDump .= "\n\n-- --------------------------------------------------------\n";
    $sqlDump .= "-- Table structure for: `$table` \n";
    $sqlDump .= "-- --------------------------------------------------------\n\n";
    $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
    $sqlDump .= $row[1] . ";\n\n";

    // 3. Hent data fra tabellen 
    $res = mysqli_query($conn, "SELECT * FROM `$table` ");
    $numFields = mysqli_num_fields($res);
    if (mysqli_num_rows($res) > 0) {
        $sqlDump .= "-- Data for table: `$table` \n";
        while ($row = mysqli_fetch_row($res)) {
            $sqlDump .= "INSERT INTO `$table` VALUES(";
            for ($j = 0; $j < $numFields; $j++) {   // Håndtering af værdier
                if ($row[$j] === null) {
                    $sqlDump .= 'NULL';
                } else {    // Escape data og indpak i citationstegn
                    $val = mysqli_real_escape_string($conn, $row[$j]);
                    $sqlDump .= '"' . $val . '"';
                }
                if ($j < ($numFields - 1)) {
                    $sqlDump .= ',';
                }
            }
            $sqlDump .= ");\n";
        }
    }
}
// --- SQL GENERERING SLUT ---

    // 2. Indlejre JSON filer (Sprog og Indstillinger)
    $jsonFiles = glob('json-data/*.json');
    if ($jsonFiles) {
        foreach ($jsonFiles as $file) {
            $baseName = basename($file);
            $content  = file_get_contents($file);
            $sqlDump .= "\n/* JSON_DATA_START:$baseName\n" . $content . "\nJSON_DATA_END */\n";
        }
    }
    // Gem filen
    if (file_put_contents($filePath, $sqlDump)) {
        $msg = lang('@Backup created successfully!') . " ($filePath)";
    } else {
        $err = lang('@Error: Could not create backup file.');
    }
}

htm_Header('@Full Project Backup');
showMenu();

// Vis beskeder via den nye standardfunktion
htm_Alert($msg, 'success');
htm_Alert($err, 'error');

htm_Card_('@Create Complete Backup', 600);
?>

<div style="font-family: sans-serif;">
    <p style="margin-bottom: 25px; color: #7f8c8d; line-height: 1.6;">
        <?php echo lang('@This will generate a SQL file containing all database tables and all JSON configuration files.'); ?>
    </p>
    <form action="" method="post">
        <button type="submit" name="create_backup" class="btn-success" style="padding:15px; font-size: 1.2em;">
            📦 <?php echo lang('@Generate Backup Now'); ?>
        </button>
    </form>
    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; display: flex; justify-content: space-between;">
        <a href="backup_list.page.php" style="color: #3498db; text-decoration: none; font-weight: bold;">
            📂 <?php echo lang('@View Backup Files'); ?>
        </a>
        <a href="backup_gendan.page.php" style="color: #e67e22; text-decoration: none; font-weight: bold;">
            <?php echo lang('@Go to Restore Page'); ?> →
        </a>
    </div>
</div>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush();
?>