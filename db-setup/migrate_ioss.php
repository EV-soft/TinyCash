<?php # /db-setup/migrate_ioss.php v:1.3.1 d:2026-09-15 i:evs
// IOSS (importordningen): invoices.inv_ioss - 'Y' på en kladde, der er markeret som IOSS-salg (varer sendt fra et land uden
// for EU), modtagerlandet når den er bogført; kreditnotaen arver det. shop_connections.ioss - butikkens ordrer sendes fra
// et land uden for EU. Grundlaget for IOSS-angivelsen (ioss_report.php).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

$cols = [['invoices', 'inv_ioss', DB::is_sqlite() ? 'TEXT' : 'VARCHAR(2)']];
if (db_has_table($conn, 'shop_connections')) $cols[] = ['shop_connections', 'ioss', (DB::is_sqlite() ? 'INTEGER' : 'INT') . ' DEFAULT 0'];
foreach ($cols as [$t, $c, $type]) {
    if (db_has_column($conn, $t, $c)) {
        echo "[SPRUNGET OVER] Kolonnen '$c' findes allerede på $t.\n";
    } elseif (DB::query($conn, "ALTER TABLE $t ADD COLUMN $c $type NULL")) {
        echo "[OK] Kolonnen '$c' tilføjet til $t.\n";
    } else {
        echo "[FEJL] $t.$c: " . DB::error($conn) . "\n";
    }
}
echo "\nFærdig. Indtast IOSS-nummeret under Firmaindstillinger, og vælg IOSS-konti under Auto kontering.\n";
