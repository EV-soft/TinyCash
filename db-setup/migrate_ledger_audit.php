<?php # /db-setup/migrate_ledger_audit.php v:1.3.0 d:2026-08-30 i:evs
// auth.inc.php bruger CWD-relative includes; chdir til roden så det virker fra db-setup/.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// Bogføringslov: ledger-linjerne (dobbeltposteringen) mangler registreringsdato
// og hvem der bogførte. Tilføjer created_at + user_id. NB: SQLite tillader IKKE
// DEFAULT CURRENT_TIMESTAMP ved ALTER ADD COLUMN, så created_at tilføjes uden
// default her - selve posteringskoden udfylder begge felter eksplicit ved insert.
echo "Motor: $db_type\n";

$columns = [
    'created_at' => ['sqlite' => 'TIMESTAMP', 'mysql' => 'TIMESTAMP NULL DEFAULT NULL'],
    'user_id'    => ['sqlite' => 'INTEGER',   'mysql' => 'INT DEFAULT NULL'],
];

function ledger_has_column($conn, $col) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(ledger)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === strtolower($col)) return true;
            }
        }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM ledger LIKE '" . DB::escape($conn, $col) . "'");
    return ($res && DB::num_rows($res) > 0);
}

foreach ($columns as $col => $types) {
    if (ledger_has_column($conn, $col)) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på ledger.\n";
        continue;
    }
    $type = DB::is_sqlite() ? $types['sqlite'] : $types['mysql'];
    if (DB::query($conn, "ALTER TABLE ledger ADD COLUMN $col $type")) {
        echo "[OK] Kolonnen '$col' tilføjet til ledger.\n";
    } else {
        echo "[FEJL] $col: " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Nye posteringer får nu registreringsdato + bruger-ID. Slet db-setup/ når opsætningen er fuldført.\n";
