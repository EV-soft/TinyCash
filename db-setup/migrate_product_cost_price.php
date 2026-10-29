<?php # /db-setup/migrate_product_cost_price.php v:1.0.0 d:2026-09-15 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): products havde en
// salgspris (prod_price), men ingen kostpris - der var derfor ingen måde at
// se avance/dækningsbidrag pr. produkt, hverken på selve produktet eller i
// [[bookkeeper-wishlist-round13]]'s "bedst sælgende produkter"-panel. Rent
// informativ tilføjelse - påvirker IKKE fakturering, momsberegning eller
// bogføring, kun visning. Valgfri (NULL = ukendt/ikke udfyldt), så
// eksisterende produkter ikke pludselig viser en falsk 0-kr-kostpris.
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(products)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'prod_cost_price') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_cost_price'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'prod_cost_price' findes allerede på products.\n";
} else {
    $type = DB::is_sqlite() ? 'REAL' : 'DECIMAL(10,2)';
    if (DB::query($conn, "ALTER TABLE products ADD COLUMN prod_cost_price $type")) {
        echo "[OK] Kolonnen 'prod_cost_price' tilføjet til products.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Kostpris kan nu udfyldes pr. produkt på product_edit.php.\n";
?>
