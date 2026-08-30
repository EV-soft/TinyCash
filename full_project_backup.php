<?php # /full_project_backup.php v:1.3.0 d:2026-08-30 i:evs
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
        // RETTET (§bugs-batch-17-review): glob('json-data/*.json') er ikke
        // rekursivt og sprang derfor json-data/languages/ helt over - den
        // mappe indeholder de AI-genererede hjælpesystem-oversættelser
        // (inc/help.lib.php's _help_get_content(), én fil pr. sprog, betalt
        // via OpenAI-kald) - reelt data der kostede penge/tid at generere,
        // ikke bare konfiguration. En gendannelse fra denne backup ville
        // stille miste alle allerede oversatte sprog og skulle regenerere
        // dem (nye OpenAI-kald) i takt med at siderne besøges igen.
        if (is_dir('json-data/')) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator('json-data/', RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($items as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === 'json') {
                    $zip->addFile($item->getRealPath(), 'json-data/' . str_replace('\\', '/', $items->getSubPathName()));
                }
            }
        }

        // 3. PAK UPLOADS MAPPE
        // RETTET (§bugs-batch-13-review): getSubPathName() returnerer stien
        // med OS-NATIVE separator (backslash på Windows) - brugt direkte som
        // ZIP-entry-navn giver fx "uploads/2024\kvittering.pdf". Zip-formatet
        // forventer forward slash som mappe-separator; et bogstaveligt
        // backslash-tegn i entry-navnet virker i praksis kun ved et
        // tilfælde, fordi Windows' egen ZipArchive-udpakning er "venlig" nok
        // til at genkende det alligevel - en udpakning på en rigtig Linux-
        // produktionsserver (langt det mest almindelige for PHP-hosting)
        // ville i stedet skabe ÉN flad fil med et bogstaveligt backslash i
        // navnet, i stedet for den rigtige undermappestruktur. Uploads/ har
        // hidtil altid været flad (ingen undermapper), så fejlen var
        // usynlig her - men den samme kode genbruges lige nedenfor til
        // storage/, som HAR reelle undermapper (einvoices/, voucher_depot/
        // osv.), hvor den blev bekræftet direkte ved en testarkivering.
        if (is_dir('uploads/')) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator('uploads/', RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getRealPath(), 'uploads/' . str_replace('\\', '/', $files->getSubPathName()));
                }
            }
        }

        // 4. PAK STORAGE MAPPE (manglede FØR helt, selvom siden hævder at
        // arkivere "alt") - storage/voucher_depot indeholder allerede
        // scannede/uploadede bilag der endnu ikke er koblet til en bestemt
        // udgift, storage/einvoices og storage/saf-t indeholder arkiverede
        // kopier af udsendte e-fakturaer/SAF-T-eksporter. Fundet ved en
        // backup-/gendannelsesgennemgang.
        if (is_dir('storage/')) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator('storage/', RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getRealPath(), 'storage/' . str_replace('\\', '/', $files->getSubPathName()));
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
            '.csrf_field(false).'
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