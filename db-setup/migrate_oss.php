<?php # /db-setup/migrate_oss.php v:1.3.1 d:2026-09-15 i:evs
// invoices.inv_oss - modtagerlandet (ISO-kode) når fakturaen er et EU-OSS-salg til en privat kunde i et andet EU-land.
// Sættes ved bogføringen (inc/invoice_post.inc.php); kreditnotaen arver det. Grundlaget for OSS-angivelsen (oss_report.php).
// invoices.inv_oss_off - 1 = salget til en sådan kunde er IKKE OSS: en almindelig ydelse, der beskattes i sælgerens eget
// land (momssystemdirektivet art. 45, fx konsulenttimer). Valgt pr. faktura.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

$cols = [['inv_oss', DB::is_sqlite() ? 'TEXT NULL' : 'VARCHAR(2) NULL'], ['inv_oss_off', (DB::is_sqlite() ? 'INTEGER' : 'INT') . ' DEFAULT 0']];
foreach ($cols as [$c, $type]) {
    if (db_has_column($conn, 'invoices', $c)) {
        echo "[SPRUNGET OVER] Kolonnen '$c' findes allerede på invoices.\n";
    } elseif (DB::query($conn, "ALTER TABLE invoices ADD COLUMN $c $type")) {
        echo "[OK] Kolonnen '$c' tilføjet til invoices.\n";
    } else {
        echo "[FEJL] $c: " . DB::error($conn) . "\n";
    }
}
echo "\nFærdig. Slå OSS til under Firmaindstillinger, og vælg OSS-konti under Auto kontering.\n";
