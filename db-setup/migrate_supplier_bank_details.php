<?php # /db-setup/migrate_supplier_bank_details.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
// suppliers-tabellen havde INGEN bankoplysninger overhovedet - nødvendigt
// for CSV-eksporten til bankbetaling (se supplier_payment_export.php).
// BEVIDST kun reg.nr./kontonr. som frie tekstfelter, ikke et "certificeret"
// bankfil-format - se supplier_payment_export.php's egen kommentar for
// hvorfor.
echo "Motor: $db_type\n\n";

foreach (['supplier_reg_no' => 10, 'supplier_account_no' => 20] as $col => $len) {
    $has_column = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(suppliers)");
        if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === $col) { $has_column = true; break; } } }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM suppliers LIKE '$col'");
        $has_column = ($res && DB::num_rows($res) > 0);
    }

    if ($has_column) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på suppliers.\n";
    } else {
        $type = DB::is_sqlite() ? 'TEXT' : "VARCHAR($len)";
        if (DB::query($conn, "ALTER TABLE suppliers ADD COLUMN $col $type")) {
            echo "[OK] Kolonnen '$col' tilføjet til suppliers.\n";
        } else {
            echo "[FEJL] " . DB::error($conn) . "\n";
        }
    }
}

echo "\nFærdig. Valgfrie felter - eksisterende leverandører har ingen\n";
echo "bankoplysninger sat, indtil de redigeres på supplier_edit.php.\n";
?>
