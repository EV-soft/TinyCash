<?php # /inc/inventory_actions.php v:1.3.0 d:2026-08-30 i:evs
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

// Forbered data ud fra de faktiske felter i din 'products' tabel
$prod_name      = DB::real_escape_string($conn, $_POST['prod_name']);
$prod_stock     = (int)($_POST['prod_stock'] ?? 0);
$prod_min_stock = (int)($_POST['prod_min_stock'] ?? 5);
$prod_price     = parse_dk_number($_POST['prod_price'] ?? 0);
$acc_id         = isset($_POST['acc_id']) ? (int)$_POST['acc_id'] : "NULL"; // Valgfri konto-tilknytning

$success = false;

if ($action === 'create_product') {
    // Bemærk: Ingen prod_vat_rate da feltet ikke eksisterer i din tabel
    $sql = "INSERT INTO products (prod_name, prod_stock, prod_min_stock, prod_price, acc_id) 
            VALUES ('$prod_name', $prod_stock, $prod_min_stock, $prod_price, $acc_id)";
    $success = DB::query($conn, $sql);
} 
elseif ($action === 'update_product' && $prod_id > 0) {
    $sql = "UPDATE products SET 
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