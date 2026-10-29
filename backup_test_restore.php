<?php # /backup_test_restore.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 30):
# backup_restore_worker.php's gendannelse er reelt DESTRUKTIV på den rigtige
# database (DROP TABLE + import, se dens egen kommentar) - der fandtes
# ingen måde at bekræfte at en backup-fil rent faktisk KAN gendannes, uden
# at være klar til at overskrive alt. Denne side importerer i stedet den
# uploadede dump til en HELT NY, midlertidig SQLite-fil (en separat PDO-
# forbindelse, aldrig $conn/$pdo/$db) og verificerer den - rører ALDRIG den
# rigtige database. KUN SQLite: en MySQL-testgendannelse ville kræve
# CREATE DATABASE-rettigheder, som den almindelige forbindelseskonto ikke
# nødvendigvis har på delt hosting - vises i stedet en tydelig, ærlig
# "ikke understøttet"-besked (samme "aldrig lad som om noget virker, når
# det ikke gør"-princip som resten af sessionen).
set_time_limit(300);
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}

function btr_cleanup($dir) {
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    // RETTET (selv-fanget under live-test): @ foran unlink()/rmdir() - en
    // enkelt fil Windows endnu ikke har frigivet sin lås på (fx SQLite's
    // egen -journal-fil, lige efter forbindelsen er lukket) må ikke kunne
    // stoppe HELE den rekursive oprydning halvvejs og efterlade resten af
    // den midlertidige mappe liggende.
    foreach ($files as $fileinfo) {
        $fileinfo->isDir() ? @rmdir($fileinfo->getRealPath()) : @unlink($fileinfo->getRealPath());
    }
    rmdir($dir);
}

$result = null; // ['ok' => bool, 'message' => string, 'tables' => [[name, count], ...]]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if (!DB::is_sqlite()) {
        $result = ['ok' => false, 'message' => lang('@Test restore is only supported for SQLite installations - a MySQL test would require CREATE DATABASE privileges the normal connection account may not have.')];
    } else {
        $extractDir = 'temp_test_restore/';
        if (!is_dir($extractDir)) mkdir($extractDir, 0755);

        $zip = new ZipArchive();
        if ($zip->open($_FILES['backup_file']['tmp_name']) !== TRUE) {
            $result = ['ok' => false, 'message' => lang('@Could not open the uploaded file as a ZIP archive.')];
        } else {
            $zip->extractTo($extractDir);
            $zip->close();
            $sqlFile = $extractDir . 'database_dump.sql';

            if (!file_exists($sqlFile)) {
                $result = ['ok' => false, 'message' => lang('@This ZIP does not contain a database_dump.sql - it may not be a valid TinyCash backup.')];
                btr_cleanup($extractDir);
            } else {
                $sql = file_get_contents($sqlFile);
                if (preg_match('/Type:\s*(sqlite|mysql)/i', $sql, $m) && strtolower($m[1]) !== 'sqlite') {
                    $result = ['ok' => false, 'message' => sprintf(lang('@This backup was made on a %s database - a test restore of a non-SQLite backup is not supported.'), strtoupper($m[1]))];
                    btr_cleanup($extractDir);
                } else {
                    $testDbPath = $extractDir . 'test_restore_' . date('Y-m-d_His') . '.sqlite';
                    try {
                        $testPdo = new PDO('sqlite:' . $testDbPath);
                        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        // RETTET (selv-fanget under live-test): uden en eksplicit
                        // transaktion udfører SQLite en fsync-tung commit PR.
                        // enkelt INSERT i dumpet - et dump med blot nogle få
                        // hundrede rækker tog over 80 sekunder at importere. En
                        // engangs BEGIN/COMMIT omkring HELE importen gør det til
                        // én samlet commit i stedet, konsekvent hurtigere.
                        $testPdo->exec('PRAGMA synchronous = OFF');
                        $testPdo->beginTransaction();
                        $testPdo->exec($sql);
                        $testPdo->commit();

                        // Stikprøve på nogle af de vigtigste tabeller - bekræfter
                        // at importen reelt skabte brugbare, forespørgelige tabeller
                        // med data, ikke bare at exec() ikke kastede en fejl.
                        $check_tables = ['customers', 'invoices', 'expenses', 'journal', 'ledger', 'settings', 'users'];
                        $tables = [];
                        foreach ($check_tables as $t) {
                            try {
                                $cnt = $testPdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
                                $tables[] = [$t, (int)$cnt, true];
                            } catch (Exception $e) {
                                $tables[] = [$t, null, false];
                            }
                        }
                        $missing = array_filter($tables, fn($t) => !$t[2]);
                        $testPdo = null; // luk forbindelsen før vi sletter filen

                        if (!empty($missing)) {
                            $result = ['ok' => false, 'message' => sprintf(lang('@The dump imported without a fatal error, but %d expected table(s) could not be queried afterward - see details below.'), count($missing)), 'tables' => $tables];
                        } else {
                            $result = ['ok' => true, 'message' => lang('@This backup imported successfully into a temporary, isolated database and all core tables are queryable. Your live database was never touched.'), 'tables' => $tables];
                        }
                    } catch (Exception $e) {
                        $result = ['ok' => false, 'message' => lang('@The dump failed to import:') . ' ' . $e->getMessage()];
                    }
                    btr_cleanup($extractDir);
                }
            }
        }
    }
}

htm_Header('@Backup Test Restore');
showMenu();

echo "<div style='max-width:700px; margin:0 auto; padding:10px;'>";
htm_Card_('@Backup Test Restore', 700);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">'
    . lang('@Upload a TinyCash backup ZIP to verify it can actually be restored, WITHOUT touching your live database - the dump is imported into a temporary, isolated file that is deleted immediately afterward.')
    . '</p>';

if (!DB::is_sqlite()) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@Test restore is only supported for SQLite installations - a MySQL test would require CREATE DATABASE privileges the normal connection account may not have.'), 'warning');
} else {
    echo '<form method="post" enctype="multipart/form-data">';
    csrf_field();
    echo '<input type="file" name="backup_file" accept=".zip" required style="margin-bottom:12px;">';
    htm_Button(icon: 'fa-vial', labl: '@Test This Backup', type: 'primary', attr: 'data-hint="'.lang('@Verify this backup file without touching the live database').'"');
    echo '</form>';
}

if ($result !== null) {
    htm_Alert($result['message'], $result['ok'] ? 'success' : 'error', 700, true);
    if (!empty($result['tables'])) {
        $rows = [];
        foreach ($result['tables'] as [$name, $count, $ok]) {
            $rows[] = [
                '<span style="font-family:monospace;">' . htmlspecialchars($name) . '</span>',
                $ok ? number_format((int)$count, 0, ',', '.') : '<span style="color:var(--color-danger);">' . lang('@Missing') . '</span>',
            ];
        }
        htm_Table(['@Table', '@Row Count'], $rows, 'btrTbl', 20);
    }
}

htm_Card_end();
echo "</div>";
htm_Footer();
