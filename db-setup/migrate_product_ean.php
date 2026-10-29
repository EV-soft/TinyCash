<?php # /db-setup/migrate_product_ean.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
// products-tabellen havde intet stregkode-/EAN-felt - mange leverandører/
// fakturaer kræver et EAN-nummer. Samme mønster som prod_category (runde 27).
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(products)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'prod_ean') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_ean'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'prod_ean' findes allerede på products.\n";
} else {
    $type = DB::is_sqlite() ? 'TEXT' : 'VARCHAR(20)';
    if (DB::query($conn, "ALTER TABLE products ADD COLUMN prod_ean $type")) {
        echo "[OK] Kolonnen 'prod_ean' tilføjet til products.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Valgfrit tekstfelt - eksisterende produkter har intet EAN\n";
echo "sat, indtil de redigeres på product_edit.php.\n";
?>
