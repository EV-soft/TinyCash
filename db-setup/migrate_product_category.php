<?php # /db-setup/migrate_product_category.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 27):
// products-tabellen havde intet kategori-/gruppe-felt overhovedet (kun SKU/
// navn/pris/lager/kostpris) - umuligt at se "salg fordelt på produktkategorier"
// i nogen rapport. Se product_edit.php/inventory_status.php for selve brugen.
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(products)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'prod_category') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_category'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'prod_category' findes allerede på products.\n";
} else {
    $type = DB::is_sqlite() ? 'TEXT' : 'VARCHAR(100)';
    if (DB::query($conn, "ALTER TABLE products ADD COLUMN prod_category $type")) {
        echo "[OK] Kolonnen 'prod_category' tilføjet til products.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Valgfrit tekstfelt - eksisterende produkter har ingen kategori\n";
echo "sat, indtil de redigeres på product_edit.php.\n";
?>
