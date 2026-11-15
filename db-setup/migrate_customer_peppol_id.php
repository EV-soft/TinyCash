<?php # /db-setup/migrate_customer_peppol_id.php v:1.0.0 d:2026-11-09 i:evs
// customers.cust_peppol_id - kundens adresse i Peppol-netværket ("0184:12345678" = dansk CVR, "0088:<EAN>",
// "0007:<svensk org.nr.>" ...). Bruges af Peppol BIS 3-e-fakturaen (inc/peppol.inc.php); tomt = udledes af EAN/CVR.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

if (db_has_column($conn, 'customers', 'cust_peppol_id')) {
    echo "[SPRUNGET OVER] Kolonnen 'cust_peppol_id' findes allerede på customers.\n";
} elseif (DB::query($conn, "ALTER TABLE customers ADD COLUMN cust_peppol_id " . (DB::is_sqlite() ? 'TEXT' : 'VARCHAR(60)'))) {
    echo "[OK] Kolonnen 'cust_peppol_id' tilføjet til customers.\n";
} else {
    echo "[FEJL] " . DB::error($conn) . "\n";
}
echo "\nFærdig. Peppol-ID'et udfyldes på kundekortet (kun nødvendigt hvis det ikke er kundens EAN- eller danske CVR-nummer).\n";
