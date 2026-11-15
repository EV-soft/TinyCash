<?php # /db-setup/migrate_stock.php v:1.0.0 d:2026-11-05 i:evs
# Varemodtagelse og lageroptælling: tabellen stock_movements og varelagerkontoen 8150 "Varelager" (aktiv), hvis
# indstillingen conf_acc_inventory ikke peger på en konto der findes. Se stock_ensure_schema() i inc/stock.inc.php.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/stock.inc.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Motor: $db_type\n\n";
foreach (stock_ensure_schema($conn, DB::is_sqlite()) as $line) echo $line . "\n";
