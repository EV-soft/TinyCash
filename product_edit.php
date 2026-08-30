<?php # /product_edit.php v:1.3.0 d:2026-08-30 i:evs
# fund 3, produkt-/lagergennemgang - se [[inventory-bugs-review]]
# v1.3.0: "Opret variant" foreslog altid samme faste "-2"-SKU-suffiks -
# finder nu det laveste reelt ledige suffiks i stedet.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// Hent globale indstillinger
$s = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

// 1. Data: Hent varen, forbered en kopi, eller opret en tom model
$id = (int)($_GET['id'] ?? 0);
$copy_id = (int)($_GET['copy_id'] ?? 0);

if ($id > 0) {
    // Rediger eksisterende produkt
    $res = DB::query($conn, "SELECT * FROM products WHERE prod_id = $id");
    $item = DB::fetch_assoc($res);
    if (!$item) die(lang('@Product not found'));
    $pageTitle = lang('@Edit Product');
} elseif ($copy_id > 0) {
    // Opret variant: Hent data fra det eksisterende produkt, men nulstil ID'et
    $res = DB::query($conn, "SELECT * FROM products WHERE prod_id = $copy_id");
    $item = DB::fetch_assoc($res);
    if (!$item) die(lang('@Product not found'));
    
    $item['prod_id'] = 0;
    $item['prod_name'] = $item['prod_name'] . ' (Variant)';
    // RETTET (fund 3, produkt-/lagergennemgang [[inventory-bugs-review]]):
    // foreslog altid det samme faste "-2"-suffiks, uanset hvor mange
    // varianter der allerede findes af det samme grundprodukt - to
    // varianter oprettet efter hinanden fik derfor samme foreslåede SKU
    // ("ABC-2" begge gange), og intet i skemaet håndhæver unikke SKU'er.
    // Finder nu det laveste ledige "-N"-suffiks i stedet.
    $base_sku = $item['prod_sku'] ?? '';
    $suffix = 2;
    do {
        $candidate = $base_sku . '-' . $suffix;
        $exists = DB::num_rows(DB::query($conn, "SELECT prod_id FROM products WHERE prod_sku = '" . DB::escape($conn, $candidate) . "'")) > 0;
        $suffix++;
    } while ($exists);
    $item['prod_sku'] = $candidate;
    $pageTitle = lang('@New Variant');
} else {
    // Opret nyt produkt - Standardværdier
    $item = [
        'prod_id'        => 0,
        'prod_sku'       => '',
        'prod_name'      => '',
        'prod_stock'     => 0,
        'prod_min_stock' => 5,
        'prod_price'     => 0,
        'acc_id'         => 0
    ];
    $pageTitle = lang('@New Product');
}

htm_Header($pageTitle);
showMenu();

// Hent konti til kontoplan / momsstyring hvis relevant
$acc_opts = [];
$acc_res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_name ASC");
if ($acc_res) {
    while($acc = DB::fetch_assoc($acc_res)) {
        $acc_opts[$acc['acc_id']] = $acc['acc_name'];
    }
}
?>

<?php
echo "<div style='max-width:600px; margin:20px auto;'>";
    htm_Card_($pageTitle, 600);
?>
    <!-- Action skifter automatisk baseret på om det er et nyt produkt eller opdatering -->
    <form id="prod_form" action="inventory_actions.php?action=<?php echo ($id > 0 ? 'update_product' : 'create_product'); ?>" method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="prod_id" value="<?php echo $item['prod_id']; ?>">
        
        <div style="display: grid; gap: 15px;">
            <?php 
                htm_Field(
                    icon: 'fa-barcode',
                    labl: '@SKU / Item number',
                    name: 'prod_sku',
                    valu: $item['prod_sku'] ?? '',
                    extr: ''
                ); 

                htm_Field(
                    icon: 'fa-tag',
                    labl: '@Product Name',
                    name: 'prod_name',
                    valu: $item['prod_name'],
                    type: 'textarea',
                    extr: 'required autofocus rows="4"'
                ); 
            ?>

            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <?php 
                    htm_Field(
                        icon: 'fa-boxes',
                        labl: '@In Stock',
                        name: 'prod_stock',
                        valu: $item['prod_stock'],
                        type: 'number',
                        extr: 'style="text-align: center;"'
                    ); 
                    ?>
                </div>
                <div style="flex: 1;">
                    <?php 
                    htm_Field(
                        icon: 'fa-exclamation-triangle',
                        labl: '@Min. Level',
                        name: 'prod_min_stock',
                        valu: $item['prod_min_stock'],
                        type: 'number',
                        extr: 'min="0" style="text-align: center;"'
                    ); 
                    ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 5px 0;">

            <div style="display: flex; gap: 15px; align-items: flex-end;">
                <div style="flex: 3;">
                    <?php 
                        htm_Field(
                            icon: 'fa-money-bill-wave',
                            labl: lang('@Sales Price') . ' (' . lang('@excl. VAT') . ')',
                            name: 'prod_price',
                            valu: number_format($item['prod_price'], 2, ',', ''),
                            type: 'text',
                            extr: 'required style="font-weight:bold; text-align: right;"'
                        ); 
                    ?>
                </div>
                <div style="flex: 4;">
                    <?php 
                        htm_Field(
                            icon: 'fa-book',
                            labl: '@Account',
                            name: 'acc_id',
                            valu: $item['acc_id'] ?? 0,
                            type: 'sele',
                            opti: $acc_opts
                        ); 
                    ?>
                </div>
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
                attr: 'form="prod_form" data-hint="'.lang($id > 0 ? '@Save changes to this product' : '@Add this product to the catalog').'"'
            );
        ?>
        <a href="inventory_status.php" style="text-decoration:none;">
            <?php htm_Button(icon: 'fa-times', labl: '@Cancel', type: 'secondary', styl: 'width:100%;', attr: 'data-hint="'.lang('@Discard changes and return to the product catalog').'"'); ?>
        </a>
    </div>
    
<?php
    htm_Card_end();
echo "</div>";

htm_Footer();
?>