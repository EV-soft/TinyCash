<?php # /product_edit.page.php v:0.8.1 d:2026-04-11 i:evs m:1
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php'; 
require 'inc/menu.inc.php';

// 1. Data: Hent varen 
$id = (int)($_GET['id'] ?? 0);
$res = mysqli_query($conn, "SELECT * FROM products WHERE prod_id = $id");
$item = mysqli_fetch_assoc($res);

if (!$item) die(lang('@Product not found'));

htm_Header(lang('@Edit Product'));
showMenu();
?>

<script>
// Lille hjælper til at beregne bruttoprisen med det samme
function updateBrutto() {
    let netto = parseFloat(document.getElementsByName('prod_price')[0].value.replace(',', '.')) || 0;
    let vat = parseFloat(document.getElementsByName('prod_vat_rate')[0].value) || 0;
    let brutto = netto * (1 + (vat / 100));
    document.getElementById('brutto_display').innerText = brutto.toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php
echo "<div style='max-width:500px; margin:0 auto;'>";
    htm_Card_(lang('@Product Details'), '100%');
?>

    <form action="invoice_actions.php?action=update_product" method="POST">
        <input type="hidden" name="prod_id" value="<?php echo $item['prod_id']; ?>">
        
        <div style="display: grid; gap: 10px;">
            <?php 
                htm_InputGroup(
                    icon:  'fa-tag',
                    label: '@Product Name',
                    name:  'prod_name',
                    val:   $item['prod_name'],
                    type:  'text',
                    extra: 'required'
                ); 
            ?>

            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <?php 
                    htm_InputGroup(
                        icon:  'fa-boxes',
                        label: '@In Stock',
                        name:  'prod_stock',
                        val:   $item['prod_stock'],
                        type:  'number'
                    ); 
                    ?>
                </div>
                <div style="flex: 1;">
                    <?php 
                    htm_InputGroup(
                        icon:  'fa-exclamation-triangle',
                        label: '@Min. Level',
                        name:  'prod_min_stock',
                        val:   $item['prod_min_stock'] ?? 5,
                        type:  'number',
                        extra: 'min="0"'
                    ); 
                    ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 10px 0;">

            <div style="display: flex; gap: 15px; align-items: flex-end;">
                <div style="flex: 2;">
                    <?php 
                        htm_InputGroup(
                            icon:  'fa-money-bill-wave',
                            label: lang('@Sales Price') . ' (' . lang('@excl. VAT') . ')',
                            name:  'prod_price',
                            val:   number_format($item['prod_price'], 2, ',', ''),
                            type:  'text',
                            extra: 'required oninput="updateBrutto()" style="font-weight:bold;"'
                        ); 
                    ?>
                </div>
                <div style="flex: 1;">
                    <?php 
                        // Moms-valg (typisk 25, 0 eller fritaget)
                        $vat_opts = ['25' => '25%', '0' => '0%', 'free' => lang('@Exempt')];
                        htm_InputGroup(
                            icon:  'fa-percent',
                            label: '@VAT',
                            name:  'prod_vat_rate',
                            val:   $item['prod_vat_rate'] ?? '25',
                            type:  'select',
                            opt:   $vat_opts,
                            extra: 'onchange="updateBrutto()"'
                        ); 
                    ?>
                </div>
            </div>

            <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; border-left: 4px solid #3498db; font-size: 0.9em;">
                <span style="color: #7f8c8d;"><?php echo lang('@Price incl. VAT'); ?>:</span>
                <span style="float: right; font-weight: bold; color: #2c3e50;">
                    <span id="brutto_display"><?php echo number_format($item['prod_price'] * (1 + ($item['prod_vat_rate'] ?? 25)/100), 2, ',', '.'); ?></span> DKK
                </span>
            </div>

            <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 10px;">
                <button type="submit" class="btn-primary" style="padding:15px; font-size: 1.1em; cursor:pointer;">
                    <i class="fa fa-save"></i> <?php echo lang('@Save Changes'); ?>
                </button>
                <a href="inventory_status.page.php" class="btn-secondary" style="padding:10px; text-decoration:none; text-align:center; font-size:0.9em;">
                    <?php echo lang('@Cancel'); ?>
                </a>
            </div>
        </div>
    </form>

<?php
    htm_Card_end();
echo "</div>";

htm_Footer();
?>