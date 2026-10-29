<?php # /db-setup/migrate_account_status.php v:1.3.1 d:2026-09-15 i:evs
# Kontoplanens synlighed: accounts.acc_status (aktiv / dæmpet / slukket) - alle nuværende konti bliver aktive.
# Selve kolonnen laves af acc_ensure_schema() (inc/accounts.inc.php), samme funktion som nye installationer
# bruger. Erhvervsstyrelsens standardkontoplan importeres bagefter fra kontoplan-siden (chart_of_accounts.php).
# Idempotent - sikker at køre igen.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/accounts.inc.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Motor: $db_type\n\n";
foreach (acc_ensure_schema($conn, DB::is_sqlite()) as $line) echo $line . "\n";

echo "\nFærdig. Erhvervsstyrelsens standardkontoplan kan nu importeres på kontoplan-siden (Kontoplan).\n";
?>
