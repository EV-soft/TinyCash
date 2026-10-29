<?php # /db-setup/migrate_customer_language.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
// systemet understøtter allerede 13 sprog til selve brugerfladen
// (json-data/languages.json), men en faktura blev altid sendt på
// BOGHOLDERENS eget sprog, aldrig kundens - relevant for udenlandske
// kunder. NULL = brug systemets standardsprog (uændret adfærd). Se
// invoice_view.php/customer_edit.php for selve brugen.
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(customers)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'cust_language') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_language'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'cust_language' findes allerede på customers.\n";
} else {
    $type = DB::is_sqlite() ? 'TEXT' : 'VARCHAR(5)';
    if (DB::query($conn, "ALTER TABLE customers ADD COLUMN cust_language $type")) {
        echo "[OK] Kolonnen 'cust_language' tilføjet til customers.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. NULL/tom = brug systemets standardsprog (uændret adfærd for\n";
echo "eksisterende kunder), indtil de redigeres på customer_edit.php.\n";
?>
