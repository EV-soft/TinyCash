<?php # /db-setup/migrate_product_sku_unique.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// RETTET (§bugs-batch-23-review): inventory_actions.php's SKU-dublet-tjek
// (tilføjet i [[bugs-batch-22-review]]) er et klassisk check-derefter-
// indsæt-kapløb - intet i databasen forhindrede reelt to næsten-samtidige
// produktoprettelser med samme SKU i begge at bestå SELECT-tjekket, FØR
// nogen af dem nåede at INSERT'e. Uden en rigtig UNIQUE-begrænsning i selve
// databasen er applikationstjekket kun kosmetisk. Denne migration:
//  1) normaliserer tomme SKU'er ('') til NULL - både SQLite og MySQL/InnoDB
//     behandler flere NULL-værdier som INDBYRDES FORSKELLIGE i et UNIQUE-
//     indeks (modsat to tomme strenge, som ville kollidere), så produkter
//     uden SKU stadig frit kan eksistere side om side.
//  2) opretter et rigtigt UNIQUE-indeks på prod_sku.
echo "Motor: $db_type\n";

// --- Trin 1: normalisér tomme SKU'er til NULL ---
$norm_sql = "UPDATE products SET prod_sku = NULL WHERE prod_sku = ''";
if (DB::query($conn, $norm_sql)) {
    echo "[OK] Tomme SKU'er normaliseret til NULL.\n";
} else {
    echo "[FEJL] Kunne ikke normalisere tomme SKU'er: " . DB::error($conn) . "\n";
}

// --- Trin 2: findes indekset allerede? ---
$index_name = 'idx_products_sku_unique';
$has_index = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='index' AND name='$index_name'");
    $has_index = ($res && DB::fetch_assoc($res));
} else {
    $res = DB::query($conn, "SELECT INDEX_NAME FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = '$index_name'");
    $has_index = ($res && DB::fetch_assoc($res));
}

if ($has_index) {
    echo "[SPRUNGET OVER] Indekset '$index_name' findes allerede på products.\n";
} else {
    $idx_sql = "CREATE UNIQUE INDEX $index_name ON products(prod_sku)";
    if (DB::query($conn, $idx_sql)) {
        echo "[OK] Unikt indeks '$index_name' oprettet på products.prod_sku.\n";
    } else {
        // Mest sandsynlige årsag: der findes allerede to eller flere produkter
        // med samme (ikke-tomme) SKU fra FØR dette tjek fandtes overhovedet -
        // indekset kan ikke oprettes oven på eksisterende dubletter. Vis
        // hvilke SKU'er der kolliderer, så en administrator kan rette dem
        // manuelt (fx omdøbe den ene), i stedet for bare at vise en kryptisk
        // SQL-fejl uden nogen vej videre.
        echo "[FEJL] Kunne ikke oprette indekset: " . DB::error($conn) . "\n";
        echo "\nMulig årsag: der findes allerede flere produkter med samme SKU. Kolliderende SKU'er:\n";
        $dup_res = DB::query($conn, "SELECT prod_sku, COUNT(*) AS antal FROM products
                                      WHERE prod_sku IS NOT NULL GROUP BY prod_sku HAVING COUNT(*) > 1");
        if ($dup_res && DB::num_rows($dup_res) > 0) {
            while ($d = DB::fetch_assoc($dup_res)) {
                echo "  - '{$d['prod_sku']}' (" . $d['antal'] . " produkter)\n";
            }
            echo "\nRet disse SKU'er via produktredigering, og kør denne migration igen.\n";
        } else {
            echo "  (ingen fundet - tjek den rå fejlbesked ovenfor)\n";
        }
    }
}

echo "\nDu kan nu slette setup/-mappen, når du er færdig med opsætningen.\n";
