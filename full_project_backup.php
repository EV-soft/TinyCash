<?php # full_project_backup.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php';
require 'menu.inc.php';

// Kun admins må tage fuld backup
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

$backupDir = 'backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$message = "";

if (isset($_POST['create_backup'])) {
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filePath = $backupDir . $filename;
    
    // 1. Start SQL Dump (Her simuleret - du har typisk din mysqldump logik her)
    $sqlDump = "-- TinyCash SQL Dump\n";
    $sqlDump .= "-- " . lang('@Date') . ": " . date('Y-m-d H:i:s') . "\n\n";
    
    // [Din eksisterende SQL-genererings-kode her...]
    // $sqlDump .= generate_sql_dump(); 

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
        $message = lang('@Backup created successfully!') . " ($filename)";
    } else {
        $message = lang('@Error: Could not create backup file.');
    }
}

htm_Header(lang('@Full Project Backup'));
showMenu();

htm_Card_(lang('@Create Complete Backup'));
?>

<div style="max-width: 600px; font-family: sans-serif;">
    <p style="margin-bottom: 20px; color: #666;">
        <?php echo lang('@This will generate a SQL file containing all database tables and all JSON configuration files.'); ?>
    </p>

    <?php if ($message): ?>
        <div style="padding: 15px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form action="" method="post">
        <button type="submit" name="create_backup" style="background:#27ae60; color:white; padding:12px 25px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; font-size: 1.1em;">
            📦 <?php echo lang('@Generate Backup Now'); ?>
        </button>
    </form>

    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
        <a href="backup_gendan.page.php" style="color: #3498db; text-decoration: none; font-weight: bold;">
            <?php echo lang('@Go to Restore Page'); ?> →
        </a>
    </div>
</div>

<?php
htm_Card_end();
htm_Footer();
?>