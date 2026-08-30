<?php # /db-setup/migrate_invoice_currency.php v:1.3.0 d:2026-08-30 i:evs
// auth.inc.php (samt menu/php2htm) bruger CWD-relative includes som 'inc/...'.
// Køres scriptet fra db-setup/ er CWD = db-setup/, så de includes fejler (500).
// Skift til projektroden, så alt resolver præcis som fra en rod-side.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// RETTELSE: migration_currency.sql tilføjede orig_currency + exch_rate til
// expenses og invoice_lines, men GLEMTE invoices-tabellen - selvom
// invoice_edit.php INSERT'er begge felter direkte på invoices. Uden dem fejler
// gemning af en ny/opdateret faktura tavst (INSERT returnerer false, men siden
// redirecter alligevel som "saved"). Dette script tilføjer de to kolonner,
// idempotent og på både SQLite og MySQL.
echo "Motor: $db_type\n";

$columns = [
    'orig_currency' => ['sqlite' => 'VARCHAR(3) DEFAULT NULL', 'mysql' => 'VARCHAR(3) DEFAULT NULL'],
    'exch_rate'     => ['sqlite' => 'NUMERIC DEFAULT NULL',     'mysql' => 'NUMERIC DEFAULT NULL'],
];

function invoices_has_column($conn, $col) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(invoices)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === strtolower($col)) return true;
            }
        }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM invoices LIKE '" . DB::escape($conn, $col) . "'");
    return ($res && DB::num_rows($res) > 0);
}

foreach ($columns as $col => $types) {
    if (invoices_has_column($conn, $col)) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på invoices.\n";
        continue;
    }
    $type = DB::is_sqlite() ? $types['sqlite'] : $types['mysql'];
    if (DB::query($conn, "ALTER TABLE invoices ADD COLUMN $col $type")) {
        echo "[OK] Kolonnen '$col' tilføjet til invoices.\n";
    } else {
        echo "[FEJL] $col: " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Prøv at oprette en ny faktura igen. Slet setup/db-setup-mappen når opsætningen er fuldført.\n";
