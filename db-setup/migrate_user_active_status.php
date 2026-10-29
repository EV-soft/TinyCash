<?php # /db-setup/migrate_user_active_status.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION: Deaktiver brugerkonto (bruger-anmodet, "kan bogholderen ønske
// sig mere"). Der var ingen is_active-mekanisme på users overhovedet - en
// administrator kunne kun SLETTE en bruger helt (mister revisionssporets
// tilknytning, og er ofte slet ikke muligt - user_actions.php blokerer
// allerede sletning af enhver bruger der er refereret i ledger/audit_log/
// expenses/invoice_payments/time_entries) eller ændre kodeordet (blokerer
// kun NYE logins - en allerede aktiv session forbliver fuldt gyldig, da
// inc/auth.inc.php aldrig genslår brugeren op i databasen efter selve
// login-øjeblikket). Standard er 1 (aktiv) for alle eksisterende brugere -
// ingen bliver låst ude ved at køre denne migration.
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(users)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'is_active') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'is_active' findes allerede på users.\n";
} else {
    $type = DB::is_sqlite() ? 'INTEGER NOT NULL DEFAULT 1' : 'TINYINT(1) NOT NULL DEFAULT 1';
    if (DB::query($conn, "ALTER TABLE users ADD COLUMN is_active $type")) {
        echo "[OK] Kolonnen 'is_active' tilføjet til users (alle eksisterende brugere sat til aktiv).\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. En admin kan nu deaktivere en brugerkonto på user_edit.php -\n";
echo "blokerer både nye logins og logger den bruger øjeblikkeligt ud af en\n";
echo "allerede aktiv session (tjekket ved hver sideindlæsning, se inc/auth.inc.php).\n";
?>
