<?php # /db-setup/migrate_cust_reference.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: tilføjer kunde-reference-feltet til invoices, som nu vises på selve
// fakturaen (under modtager-adressen) og kan gemmes pr. faktura.
echo "Motor: $db_type\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(invoices)");
    if ($res) {
        while ($row = DB::fetch_assoc($res)) {
            if (strtolower($row['name']) === 'cust_reference') { $has_column = true; break; }
        }
    }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM invoices LIKE 'cust_reference'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'cust_reference' findes allerede på invoices.\n";
} else {
    $alter_sql = DB::is_sqlite()
        ? "ALTER TABLE invoices ADD COLUMN cust_reference TEXT"
        : "ALTER TABLE invoices ADD COLUMN cust_reference VARCHAR(100)";
    if (DB::query($conn, $alter_sql)) {
        echo "[OK] Kolonnen 'cust_reference' tilføjet til invoices.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nDu kan nu slette setup/-mappen, når du er færdig med opsætningen.\n";