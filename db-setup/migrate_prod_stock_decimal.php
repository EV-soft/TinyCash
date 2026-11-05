<?php # /db-setup/migrate_prod_stock_decimal.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// RETTET (noteret som uløst i [[bugs-batch-51-review]]): lagerantal kan have decimaler (fx 7,5 kg - inventory_actions.php
// læser med parse_dk_number(), og en faktura trækker fx 0,5 fra lageret), men på MySQL er products.prod_stock en INT, så
// decimalerne blev skåret af ved hver gemning/bogføring og lageret gled stille fra virkeligheden. SQLite er upåvirket
// (INTEGER-affinitet gemmer 7.5 som REAL) - kun MySQL ændres. Idempotent: springes over når kolonnen ikke er heltal.
echo "Motor: $db_type\n\n";

if (DB::is_sqlite()) {
    echo "[SPRUNGET OVER] SQLite gemmer allerede decimaler i products.prod_stock - intet at ændre.\n";
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_stock'");
    $col = $res ? DB::fetch_assoc($res) : null;
    if (!$col) {
        echo "[FEJL] Kolonnen products.prod_stock findes ikke.\n";
    } elseif (stripos((string)$col['Type'], 'int') === false) {
        echo "[SPRUNGET OVER] products.prod_stock er allerede " . $col['Type'] . ".\n";
    } elseif (DB::query($conn, "ALTER TABLE products MODIFY prod_stock DECIMAL(15,3) DEFAULT 0")) {
        echo "[OK] products.prod_stock ændret fra " . $col['Type'] . " til DECIMAL(15,3).\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Lagerantal med decimaler (fx 7,5 kg) gemmes nu uden afrunding.\n";
?>
