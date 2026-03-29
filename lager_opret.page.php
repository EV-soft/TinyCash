<?php # lager_opret.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

$message = "";

// 1. Logik: Gem ny vare
if (isset($_POST['create_product'])) {
    $sku    = mysqli_real_escape_string($conn, $_POST['prod_sku']);
    $name   = mysqli_real_escape_string($conn, $_POST['prod_name']);
    $price  = (float)str_replace(',', '.', $_POST['prod_price']);
    $stock  = (int)$_POST['stock_qty'];
    $desc   = mysqli_real_escape_string($conn, $_POST['prod_description']);

    $sql = "INSERT INTO products (prod_sku, prod_name, prod_price, stock_qty, prod_description) 
            VALUES ('$sku', '$name', $price, $stock, '$desc')";

    if (mysqli_query($conn, $sql)) {
        header("Location: lager_indhold.page.php?msg=created");
        exit;
    } else {
        $message = "<div style='color:red; margin-bottom:15px;'>" . lang('@Error creating product') . ": " . mysqli_error($conn) . "</div>";
    }
}

htm_Header(lang('@Add New Product'));
showMenu();

htm_Card_(lang('@Product Details'));
echo $message;
?>

<form action="" method="post" style="max-width: 600px; font-family: sans-serif;">
    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@SKU / Product Number'); ?>:</label>
        <input type="text" name="prod_sku" placeholder="f.eks. VARE-100" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Product Name'); ?>:</label>
        <input type="text" name="prod_name" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="display:flex; gap:10px; margin-bottom: 15px;">
        <div style="flex:1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Sales Price'); ?>:</label>
            <input type="text" name="prod_price" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div style="flex:1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Initial Stock'); ?>:</label>
            <input type="number" name="stock_qty" value="0" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Description'); ?>:</label>
        <textarea name="prod_description" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"></textarea>
    </div>

    <div style="display: flex; gap: 10px;">
        <button type="submit" name="create_product" style="background:#2ecc71; color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">
            ✅ <?php echo lang('@Create Product'); ?>
        </button>
        <a href="lager_indhold.page.php" style="background:#95a5a6; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>