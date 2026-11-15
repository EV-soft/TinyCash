<?php # /db-setup/migrate_customer_ean.php v:1.0.0 d:2026-11-05 i:evs
// customers.cust_ean - kundens EAN-/GLN-lokationsnummer. Offentlige myndigheder modtager e-fakturaer (OIOUBL) på
// EAN-nummeret, ikke på CVR; tomt = e-fakturaen adresseres til kundens CVR som hidtil.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

if (db_has_column($conn, 'customers', 'cust_ean')) {
    echo "[SPRUNGET OVER] Kolonnen 'cust_ean' findes allerede på customers.\n";
} elseif (DB::query($conn, "ALTER TABLE customers ADD COLUMN cust_ean " . (DB::is_sqlite() ? 'TEXT' : 'VARCHAR(13)'))) {
    echo "[OK] Kolonnen 'cust_ean' tilføjet til customers.\n";
} else {
    echo "[FEJL] " . DB::error($conn) . "\n";
}
echo "\nFærdig. Udfyld EAN-nummeret på kundekortet (customer_edit.php) for offentlige kunder.\n";
