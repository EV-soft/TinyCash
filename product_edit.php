<?php # /product_edit.php v:1.0.1 d:2026-05-03 i:Gemini m:1
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/menu.inc.php';

// Hent globale indstillinger
$s = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

// 1. Data: Hent varen eller forbered en tom model
$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    // Rediger eksisterende produkt
    $res = mysqli_query($conn, "SELECT * FROM products WHERE prod_id = $id");
    $item = mysqli_fetch_assoc($res);
    if (!$item) die(lang('@Product not found'));
} else {
    // Opret nyt produkt - Standardværdier
    $item = [
        'prod_id'       => 0,
        'prod_name'     => '',
        'prod_stock'    => 0,
        'prod_min_stock' => 5,
        'prod_price'    => 0,
        'prod_vat_rate' => '25'
    ];
}

$pageTitle = ($id > 0) ? lang('@Edit Product') : lang('@New Product');
htm_Header($pageTitle);
showMenu();
?>

<script>
function updateBrutto() {
    let netto = parseFloat(document.getElementsByName('prod_price')[0].value.replace(',', '.')) || 0;
    let vatVal = document.getElementsByName('prod_vat_rate')[0].value;
    let vat = (vatVal === 'free') ? 0 : parseFloat(vatVal) || 0;
    
    let brutto = netto * (1 + (vat / 100));
    document.getElementById('brutto_display').innerText = brutto.toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php
echo "<div style='max-width:500px; margin:20px auto;'>";
    htm_Card_($pageTitle, 500);
?>
    <!-- Action skifter automatisk baseret på ID -->
    <form id="prod_form" action="inventory_actions.php?action=<?php echo ($id > 0 ? 'update_product' : 'create_product'); ?>" method="POST">
        <input type="hidden" name="prod_id" value="<?php echo $item['prod_id']; ?>">
        
        <div style="display: grid; gap: 15px;">
            <?php 
                htm_InputGroup(
                    icon: 'fa-tag',
                    labl: '@Product Name',
                    name: 'prod_name',
                    valu: $item['prod_name'],
                    extr: 'required autofocus'
                ); 
            ?>

            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <?php 
                    htm_InputGroup(
                        icon: 'fa-boxes',
                        labl: '@In Stock',
                        name: 'prod_stock',
                        valu: $item['prod_stock'],
                        type: 'number'
                    ); 
                    ?>
                </div>
                <div style="flex: 1;">
                    <?php 
                    htm_InputGroup(
                        icon: 'fa-exclamation-triangle',
                        labl: '@Min. Level',
                        name: 'prod_min_stock',
                        valu: $item['prod_min_stock'],
                        type: 'number',
                        extr: 'min="0"'
                    ); 
                    ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 5px 0;">

            <div style="display: flex; gap: 15px; align-items: flex-end;">
                <div style="flex: 2;">
                    <?php 
                        htm_InputGroup(
                            icon: 'fa-money-bill-wave',
                            labl: lang('@Sales Price') . ' (' . lang('@excl. VAT') . ')',
                            name: 'prod_price',
                            valu: number_format($item['prod_price'], 2, ',', ''),
                            type: 'text',
                            extr: 'required oninput="updateBrutto()" style="font-weight:bold;"'
                        ); 
                    ?>
                </div>
                <div style="flex: 1;">
                    <?php 
                        $vat_opts = ['25' => '25%', '0' => '0%', 'free' => lang('@Exempt')];
                        htm_InputGroup(
                            icon: 'fa-percent',
                            labl: '@VAT',
                            name: 'prod_vat_rate',
                            valu: $item['prod_vat_rate'],
                            type: 'sele',
                            opti: $vat_opts,
                            extr: 'onchange="updateBrutto()"'
                        ); 
                    ?>
                </div>
            </div>

            <div style="background: #f8f9fa; padding: 12px; border-radius: 4px; border-left: 4px solid #3498db; font-size: 0.95em;">
                <span style="color: #7f8c8d;"><?php echo lang('@Price incl. VAT'); ?>:</span>
                <span style="float: right; font-weight: bold; color: #2c3e50;">
                    <span id="brutto_display">
                        <?php 
                        $current_vat = ($item['prod_vat_rate'] == 'free') ? 0 : ($item['prod_vat_rate'] ?? 25);
                        echo number_format($item['prod_price'] * (1 + $current_vat/100), 2, ',', '.'); 
                        ?>
                    </span> <?php echo $cur; ?>
                </span>
            </div>
        </div>
    </form>
    <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 10px;">
        <?php 
            htm_Button(
                icon: 'fa-save', 
                labl: ($id > 0 ? '@Save Changes' : '@Create Product'), 
                type: 'success', 
                styl: 'padding:12px; font-size:1.1em;',
                attr: 'form="prod_form"'
            ); 
        ?>
        <a href="inventory_status.php" style="text-decoration:none;">
            <?php htm_Button(icon: 'fa-times', labl: '@Cancel', type: 'secondary', styl: 'width:100%;'); ?>
        </a>
    </div>
    
<?php
    htm_Card_end();
echo "</div>";

htm_Footer();
?>