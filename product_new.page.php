<?php # /product_new.page.php v:0.8.1 d:2026-04-11 i:Gemini m:3
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$msg = ""; $err = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_product'])) {
    
    // 1. Forbered data
    $sku   = mysqli_real_escape_string($conn, $_POST['product_sku']);
    $name  = mysqli_real_escape_string($conn, $_POST['product_name']);
    $price = (float)str_replace(',', '.', $_POST['product_price']);
    $acc_id = (int)$_POST['vat_code']; 

    if (empty($sku) || empty($name)) {
        $err = lang('@SKU and Name are required');
    } else {
        $sql = "INSERT INTO products (prod_sku, prod_name, prod_price, acc_id) 
                VALUES ('$sku', '$name', '$price', '$acc_id')";

        try {
            if (mysqli_query($conn, $sql)) {
                header("Location: inventory_status.page.php?msg=product_created");
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            // OVERSAT: Databasefejl-besked
            $err = lang('@Database error:') . " " . $e->getMessage();
        }
    }
}

htm_Header(lang('@Create New Product'));
showMenu();

// Fejlvisning benytter nu den oversatte $err
if($err) htm_Alert($err, 'error');

echo "<div style='max-width:700px; margin:0 auto;'>";
    htm_Card_(lang('@Product Details'), '100%');
?>

<form method="post">
    <div style="display: grid; gap: 10px;">
        <div style="display: flex; gap: 15px;">
            <div style="flex: 1;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-barcode',
                    label: lang('@Product SKU'),
                    name:  'product_sku',
                    type:  'text',
                    extra: 'required autofocus'
                ); 
                ?>
            </div>
            <div style="flex: 2;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-tag',
                    label: lang('@Product Name'),
                    name:  'product_name',
                    type:  'text',
                    extra: 'required'
                ); 
                ?>
            </div>
        </div>
        <div style="display: flex; gap: 15px;">
            <div style="flex: 1;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-money-bill-wave',
                    label: lang('@Sales Price'),
                    name:  'product_price',
                    val:   '0,00',
                    type:  'text',
                    extra: 'required'
                ); 
                ?>
            </div>
            <div style="flex: 1;">
                <?php 
                $vat_options = getVatOptionsFromDB($conn);
                htm_InputGroup(
                    icon:  'fa-university',
                    label: lang('@Account / VAT'),
                    name:  'vat_code',
                    type:  'select',
                    opt:   $vat_options,
                    extra: 'required'
                ); 
                ?>
            </div>
        </div>
    </div>
    <div style="margin-top:30px; display:flex; gap:10px;">
        <button type="submit" name="create_product" class="btn-primary" style="flex:2; padding:15px; font-size: 1.1em; cursor:pointer;">
            <i class="fa fa-plus-circle"></i> <?php echo lang('@Create Product'); ?>
        </button>
        <a href="inventory_status.page.php" class="btn-secondary" style="flex:1; text-decoration:none; display:flex; align-items:center; justify-content:center; font-weight:bold;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php 
    htm_Card_end();
echo "</div>";

htm_Footer(); 
ob_end_flush();
?>