<?php # /inc/program_backup.lib.php v:1.3.0 d:2026-08-30 i:evs
# backup-manifest-dato brugte hårdkodet d.m.Y i stedet for CONF_DATE_FORMAT
# Program-backup: zipper selve programkoden (PHP m.m.) + DB-struktur, så en
# programopdatering kan rulles tilbage. Indeholder BEVIDST hverken env.ini
# (hemmeligheder), regnskabsdata (uploads/, data/, storage/) eller tidligere
# backups. Genbruges af program_backup.php (manuel) og auto_backup.inc.php
# (foldet ind i den samlede ugentlige backup).
# v1.3.0: '.claude', 'ai_export' og 'sessions_tmp' tilføjet til udelukkelses-
# listen - se program_backup_excluded_dirs().

// Projektroden, normaliseret til '/' så Windows og Linux behandles ens.
function program_backup_root() {
    return str_replace('\\', '/', realpath(__DIR__ . '/..'));
}

// Mapper der aldrig medtages (hemmeligheder, regnskabsdata, tidligere backups).
// UDVIDET 2026-08-19 (fundet ved en backup-/gendannelsesgennemgang): '.claude'
// (Claude Codes egne sessions-/konfigurationsfiler), 'ai_export' (ophobede
// AI-samtaleeksporter - kan blive store og indeholder ikke programkode) og
// 'sessions_tmp' (PHP-sessionsfiler - kan indeholde andre brugeres session-
// data, som ikke bør havne i en ekstern backup-mail) blev før pakket ind i
// HVER ENESTE program-backup, inkl. den ugentlige automatiske, uden grund.
function program_backup_excluded_dirs() {
    return ['backups', 'uploads', 'storage', 'data', 'temp_restore', 'temp', 'tmp', 'cache', '.git', 'node_modules', 'vendor', '.claude', 'ai_export', 'sessions_tmp'];
}

// Filnavne/endelser der aldrig medtages.
function program_backup_excluded_file($basename) {
    $lower = strtolower($basename);
    // KRITISK: env.ini indeholder DB-, mail- og OpenAI-hemmeligheder.
    if (in_array($lower, ['env.ini', '.env', '.htpasswd'], true)) return true;
    $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    if (in_array($ext, ['sqlite', 'sqlite3', 'db', 'log', 'zip', 'enc'], true)) return true;
    return false;
}

// Tilføjer programkode + schema.sql til en eksisterende ZIP under valgfrit prefix.
// Returnerer antal tilføjede filer.
function program_backup_add_to_zip(ZipArchive $zip, $conn, $prefix = '') {
    $root     = program_backup_root();
    $excl_dir = array_flip(program_backup_excluded_dirs());
    $count    = 0;

    $dirIt  = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
    // Prune: sti ned i undermapper undtagen de udelukkede (så vi aldrig
    // rører uploads/, data/, backups/ osv. - hverken indhold eller ydeevne).
    $filter = new RecursiveCallbackFilterIterator($dirIt, function ($current) use ($excl_dir) {
        if ($current->isDir()) {
            return !isset($excl_dir[$current->getFilename()]);
        }
        return true;
    });
    $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if (program_backup_excluded_file($file->getFilename())) continue;

        $abs = str_replace('\\', '/', $file->getPathname());
        $rel = ltrim(substr($abs, strlen($root)), '/');
        if ($rel === '') continue;
        $zip->addFile($abs, $prefix . $rel);
        $count++;
    }

    // DB-STRUKTUR (ingen data)
    $zip->addFromString($prefix . 'schema.sql', DB::dump_schema($conn));
    $count++;

    // Manifest
    $zip->addFromString($prefix . 'program_backup_info.txt',
        "TinyCash Program Backup\n" .
        "Dato:    " . date(CONF_DATE_FORMAT . ' H:i:s') . "\n" .
        "Version: " . (defined('APP_VERSION') ? APP_VERSION : '?') . "\n" .
        "Motor:   " . (DB::is_sqlite() ? 'SQLITE' : 'MYSQL') . "\n" .
        "Filer:   " . $count . "\n" .
        "NB: Indeholder IKKE env.ini, regnskabsdata (uploads/, data/, storage/) eller tidligere backups.\n"
    );

    return $count;
}

// Bygger en selvstændig program-backup ZIP i backups/.
// Returnerer ['path' => relativ sti, 'name' => filnavn] eller false.
function program_backup_build($conn) {
    if (!class_exists('ZipArchive')) return false;

    $root      = program_backup_root();
    $backupDir = $root . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $engine = DB::is_sqlite() ? 'sqlite' : 'mysql';
    $name   = 'PROGRAM_BACKUP_' . date('Y-m-d_H-i-s') . '_' . $engine . '.zip';
    $path   = $backupDir . $name;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    program_backup_add_to_zip($zip, $conn, '');
    $zip->close();

    return ['path' => 'backups/' . $name, 'name' => $name];
}
?>
