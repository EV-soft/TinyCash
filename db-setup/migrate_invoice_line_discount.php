<?php # /db-setup/migrate_invoice_line_discount.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): der fandtes
// slet ingen rabatfunktion - hverken på faktura- eller tilbudslinjer. Eneste
// måde at give en kunde en lavere pris i dag var at redigere enhedsprisen
// direkte, hvilket skjuler at der er givet en rabat (kunden ser bare en
// lavere pris på selve fakturaen, ikke "10% rabat"). Tilføjer et valgfrit
// procent-rabat-felt pr. linje. Rabatten trækkes fra NETTOBELØBET før moms
// beregnes (standardpraksis) - se invoice_dkk_totals() (inc/db_connect.inc.php)
// for den ENESTE autoritative formel, og dens egen kommentar for den fulde
// liste af øvrige steder der measurely selv genberegner en linjetotal og
// derfor også er rettet i samme runde.
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(invoice_lines)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'discount_pct') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM invoice_lines LIKE 'discount_pct'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'discount_pct' findes allerede på invoice_lines.\n";
} else {
    $type = DB::is_sqlite() ? 'NUMERIC DEFAULT 0' : 'DECIMAL(5,2) DEFAULT 0';
    if (DB::query($conn, "ALTER TABLE invoice_lines ADD COLUMN discount_pct $type")) {
        echo "[OK] Kolonnen 'discount_pct' tilføjet til invoice_lines.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Rabat kan nu angives pr. fakturalinje på invoice_edit.php.\n";
?>
