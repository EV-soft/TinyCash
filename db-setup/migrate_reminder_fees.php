<?php # /db-setup/migrate_reminder_fees.php v:1.0.0 d:2026-11-05 i:evs
# Rykkergebyrer bogføres: tabellen invoice_fees (gebyrerne pr. faktura) og gebyrkontoen 1090 "Rykkergebyrer" (momsfri
# indtægt), hvis indstillingen conf_acc_reminder_fee ikke peger på en konto der findes. Se rf_ensure_schema() i
# inc/reminder.inc.php. Idempotent.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/php2htm.lib.php';
require_once __DIR__ . '/../inc/audit.inc.php';
require_once __DIR__ . '/../inc/reminder.inc.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Motor: $db_type\n\n";
foreach (rf_ensure_schema($conn, DB::is_sqlite()) as $line) echo $line . "\n";
