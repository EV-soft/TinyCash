<?php # /db-setup/migrate_auto_backup_indexes.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// RETTET (bruger-rapporteret: langsom sidevisning ved sideskift på en
// installation): inc/auto_backup.inc.php's auto_backup_check() kører på
// HVER sidevisning (via htm_Footer()) og udfører - når 7+ dage er gået -
// op til 3 COUNT(*)-forespørgsler mod audit_log/expenses/journal for at
// afgøre om noget er ændret siden sidste backup (invoices/transactions har
// ingen created_at-kolonne og rammes derfor slet ikke af disse forespørgsler
// - kun de tre nedenfor er reelt relevante). Ingen af de tre havde noget
// indeks på den filtrerede kolonne, så hver forespørgsel var en fuld
// tabel-scanning - værre jo mere bogføringshistorik installationen har.
// Selve gentagelses-fejlen (manglende tidsstempel-gemning ved fejlende
// backup) er rettet direkte i inc/auto_backup.inc.php - dette indeks er et
// ekstra lag, så selv det legitime tjek hver 7. dag er hurtigt, og så andre
// nuværende/fremtidige forespørgsler mod de samme kolonner også får gavn.
echo "Motor: $db_type\n";

$indexes = [
    ['name' => 'idx_audit_log_date',  'table' => 'audit_log', 'column' => 'log_date'],
    ['name' => 'idx_expenses_created', 'table' => 'expenses',  'column' => 'created_at'],
    ['name' => 'idx_journal_created',  'table' => 'journal',   'column' => 'created_at'],
];

foreach ($indexes as $idx) {
    $name  = $idx['name'];
    $table = $idx['table'];
    $col   = $idx['column'];

    $has_index = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='index' AND name='$name'");
        $has_index = ($res && DB::fetch_assoc($res));
    } else {
        $res = DB::query($conn, "SELECT INDEX_NAME FROM information_schema.STATISTICS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$name'");
        $has_index = ($res && DB::fetch_assoc($res));
    }

    if ($has_index) {
        echo "[SPRUNGET OVER] Indekset '$name' findes allerede på $table.\n";
        continue;
    }

    if (DB::query($conn, "CREATE INDEX $name ON $table($col)")) {
        echo "[OK] Indeks '$name' oprettet på $table.$col.\n";
    } else {
        echo "[FEJL] Kunne ikke oprette indekset '$name': " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig.\n";
