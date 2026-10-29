<?php # /db-setup/migrate_bank_consent_expiry.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): bank_
// connections gemte ALDRIG hvor længe bankens samtykke (consent) er gyldigt,
// selvom eb_start_authorization() (inc/enablebanking.lib.php) allerede
// beregner og sender denne dato til banken ved selve godkendelsen - den blev
// bare aldrig returneret/gemt. En bogholder fik derfor INGEN advarsel før
// den automatiske bankforbindelse stille holdt op med at virke (opdaget
// først når transaktioner manglede). Se bank_integration.php/bank_
// integration_connect.php for selve brugen.
echo "Motor: $db_type\n\n";

$has_table = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='bank_connections'");
    $has_table = ($res && DB::fetch_assoc($res));
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'bank_connections'");
    $has_table = ($res && DB::num_rows($res) > 0);
}
if (!$has_table) {
    echo "[SPRUNGET OVER] Tabellen 'bank_connections' findes ikke endnu - kør migrate_bank_integration.php først.\n";
    exit;
}

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(bank_connections)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'consent_expires_at') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM bank_connections LIKE 'consent_expires_at'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'consent_expires_at' findes allerede på bank_connections.\n";
} else {
    $type = DB::is_sqlite() ? 'TIMESTAMP' : 'DATETIME';
    if (DB::query($conn, "ALTER TABLE bank_connections ADD COLUMN consent_expires_at $type")) {
        echo "[OK] Kolonnen 'consent_expires_at' tilføjet til bank_connections.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Udløbsdatoen sættes automatisk ved NYE bankforbindelser fra nu\n";
echo "af. Allerede eksisterende forbindelser har ingen kendt udløbsdato (stod\n";
echo "aldrig gemt før) - reelt korrigeres det først når de gentilsluttes.\n";
?>
