<?php # /db-setup/migrate_invoice_layouts.php v:1.3.1 d:2026-09-15 i:evs
# Flere navngivne fakturalayouts: tabellerne invoice_layouts/invoice_layout_blocks,
# customers.cust_layout_id og invoices.inv_layout_id - det hidtidige ene layout
# (layout_settings) flyttes over som "Standard". Selve skemaet laves af
# il_ensure_schema() (inc/invoice_layouts.inc.php), samme funktion som nye
# installationer bruger. Idempotent - sikker at køre igen.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/invoice_layouts.inc.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Motor: $db_type\n\n";
foreach (il_ensure_schema($conn, DB::is_sqlite()) as $line) echo $line . "\n";

echo "\nFærdig. Layouts vælges og redigeres på fakturasiden (Design Mode), pr. kunde\n";
echo "på customer_edit.php og pr. faktura på invoice_edit.php.\n";
?>
