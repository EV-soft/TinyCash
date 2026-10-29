<?php # /db-setup/migrate_customer_credit_limit.php v:1.3.1 d:2026-09-15 i:evs
# Migration: customers.credit_limit - valgfri kreditgrænse pr. kunde,
# bruger-anmodet ("kan bogholderen ønske sig mere"). Se customer_edit.php/
# customer_balance_check.php for selve brugen.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(customers)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'credit_limit') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'credit_limit' findes allerede på customers.\n";
} else {
    $type = DB::is_sqlite() ? 'NUMERIC DEFAULT NULL' : 'DECIMAL(12,2) DEFAULT NULL';
    if (DB::query($conn, "ALTER TABLE customers ADD COLUMN credit_limit $type")) {
        echo "[OK] Kolonnen 'credit_limit' tilføjet til customers.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Kreditgrænse kan nu sættes pr. kunde på customer_edit.php.\n";
?>
