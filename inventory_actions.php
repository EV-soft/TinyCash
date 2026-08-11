<?php # /inventory_actions.php v:1.2.0 d:2026-08-11 i:evs 
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php'; 

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: inventory_status.php");
    exit;
}

$action = $_GET['action'] ?? '';
$prod_id = (int)($_POST['prod_id'] ?? 0);

// Forbered data fra formularen (uden prod_vat_rate, da momsen styres via kontoplanen)
$prod_sku       = DB::escape($conn, $_POST['prod_sku'] ?? '');
$prod_name      = DB::escape($conn, $_POST['prod_name'] ?? '');
$prod_stock     = (int)($_POST['prod_stock'] ?? 0);
$prod_min_stock = (int)($_POST['prod_min_stock'] ?? 5);
$prod_price     = (float)str_replace(',', '.', $_POST['prod_price'] ?? 0);
$acc_id         = isset($_POST['acc_id']) ? (int)$_POST['acc_id'] : "NULL";

$success = false;

if ($action === 'create_product') {
    $sql = "INSERT INTO products (prod_sku, prod_name, prod_stock, prod_min_stock, prod_price, acc_id) 
            VALUES ('$prod_sku', '$prod_name', $prod_stock, $prod_min_stock, $prod_price, $acc_id)";
    $success = DB::query($conn, $sql);
} 
elseif ($action === 'update_product' && $prod_id > 0) {
    $sql = "UPDATE products SET 
                prod_sku = '$prod_sku',
                prod_name = '$prod_name', 
                prod_stock = $prod_stock, 
                prod_min_stock = $prod_min_stock, 
                prod_price = $prod_price,
                acc_id = $acc_id
            WHERE prod_id = $prod_id";
    $success = DB::query($conn, $sql);
}

if (!$success) {
    die("SQL Error: " . DB::error($conn));
}

header("Location: inventory_status.php");
exit;
?>