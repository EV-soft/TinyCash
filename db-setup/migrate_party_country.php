<?php # /db-setup/migrate_party_country.php v:1.0.0 d:2026-11-09 i:evs
// Land og momsnummer på kunder og leverandører: customers.cust_country/cust_vat_id, suppliers.country/vat_id.
// Grundlaget for EU-handel (omvendt betalingspligt, EU-salg uden moms, VIES) og andre landepakker.
// Tomt land = samme land som virksomheden (landepakken), så eksisterende kunder/leverandører behøver ingen udfyldning.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

$cols = [
    ['customers', 'cust_country', 2],  ['customers', 'cust_vat_id', 20],
    ['suppliers', 'country', 2],       ['suppliers', 'vat_id', 20],
];
foreach ($cols as [$table, $col, $len]) {
    if (!db_has_table($conn, $table)) {
        echo "[SPRUNGET OVER] Tabellen '$table' findes ikke (modulet er ikke installeret).\n";
    } elseif (db_has_column($conn, $table, $col)) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på $table.\n";
    } elseif (DB::query($conn, "ALTER TABLE $table ADD COLUMN $col " . (DB::is_sqlite() ? 'TEXT' : "VARCHAR($len)"))) {
        echo "[OK] Kolonnen '$col' tilføjet til $table.\n";
    } else {
        echo "[FEJL] $table.$col: " . DB::error($conn) . "\n";
    }
}
echo "\nFærdig. Land og momsnummer udfyldes på kunde- og leverandørkortet (kun nødvendigt for udenlandske).\n";
