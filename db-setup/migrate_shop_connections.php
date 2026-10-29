<?php # /db-setup/migrate_shop_connections.php v:1.3.1 d:2026-09-15 i:evs
# Webbutikker: tabellerne shop_connections (en butiks valg) og shop_orders (modtagne ordrer, dubletsikring og
# dagsbogføring). Selve skemaet laves af shop_ensure_schema() (inc/shop.inc.php), samme funktion som nye
# installationer bruger. Idempotent - sikker at køre igen.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/shop.inc.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Motor: $db_type\n\n";
foreach (shop_ensure_schema($conn, DB::is_sqlite()) as $line) echo $line . "\n";

echo "\nFærdig. Webbutikker tilknyttes under System -> Webbutikker (shop_connections.php).\n";
?>
