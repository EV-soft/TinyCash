<?php # /product_edit.php v:1.3.1 d:2026-09-15 i:evs
# fund 3, produkt-/lagergennemgang - se [[inventory-bugs-review]]
# v1.3.0: "Opret variant" foreslog altid samme faste "-2"-SKU-suffiks -
# finder nu det laveste reelt ledige suffiks i stedet.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/field_visibility.inc.php';

// Hent globale indstillinger
$s = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$fv_overrides = get_field_visibility_overrides($conn);
$fv_is_admin  = (($_SESSION['user_role'] ?? '') === 'admin');
$fv_uLev      = (int)($_SESSION['user_level'] ?? 1);

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
        'acc_id'         => 0,
        'prod_cost_price' => null,
    ];
    $pageTitle = lang('@New Product');
}

htm_Header($pageTitle);
showMenu();

// Hent konti til kontoplan / momsstyring hvis relevant
$acc_opts = [];
require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$acc_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE " . acc_visible_sql($conn, '', [(int)($item['acc_id'] ?? 0)]) . " ORDER BY " . acc_order_sql($conn) . "acc_name ASC");
if ($acc_res) {
    while($acc = DB::fetch_assoc($acc_res)) {
        $acc_opts[$acc['acc_id']] = acc_label($acc['acc_name'], $acc['acc_status'] ?? null);
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

            <?php
            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
            // 27): products havde intet kategori-/gruppefelt overhovedet -
            // umuligt at se "salg fordelt på kategorier" nogen steder. Rent
            // frit tekstfelt (ikke en separat tabel/dropdown - datalist'en
            // her giver blot forslag ud fra allerede brugte kategorier, uden
            // at LÅSE brugeren til kun dem), samme "stille degradering hvis
            // ikke migreret endnu"-mønster som kostprisen nedenfor.
            $has_category_col = false;
            if (DB::is_sqlite()) {
                $catc = DB::query($conn, "PRAGMA table_info(products)");
                if ($catc) { while ($catr = DB::fetch_assoc($catc)) { if ($catr['name'] === 'prod_category') { $has_category_col = true; break; } } }
            } else {
                $catc = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_category'");
                $has_category_col = ($catc && DB::num_rows($catc) > 0);
            }
            if ($has_category_col):
                $cat_res = DB::query($conn, "SELECT DISTINCT prod_category FROM products WHERE prod_category IS NOT NULL AND prod_category != '' ORDER BY prod_category ASC");
                $existing_cats = [];
                if ($cat_res) { while ($catr = DB::fetch_assoc($cat_res)) { $existing_cats[] = $catr['prod_category']; } }
            ?>
                <datalist id="prod_category_list">
                    <?php foreach ($existing_cats as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?>
                </datalist>
                <?php
                htm_Field(
                    icon: 'fa-layer-group',
                    labl: '@Category',
                    name: 'prod_category',
                    valu: $item['prod_category'] ?? '',
                    type: 'text',
                    extr: 'list="prod_category_list" placeholder="' . lang('@Optional') . '"'
                );
            endif;

            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
            // 31): products havde intet stregkode-/EAN-felt - mange
            // leverandører/fakturaer kræver et EAN-nummer. Samme "stille
            // degradering hvis ikke migreret endnu"-mønster som kategorien
            // ovenfor.
            $has_ean_col = false;
            if (DB::is_sqlite()) {
                $eanc = DB::query($conn, "PRAGMA table_info(products)");
                if ($eanc) { while ($eanr = DB::fetch_assoc($eanc)) { if ($eanr['name'] === 'prod_ean') { $has_ean_col = true; break; } } }
            } else {
                $eanc = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_ean'");
                $has_ean_col = ($eanc && DB::num_rows($eanc) > 0);
            }
            if ($has_ean_col):
                htm_Field(
                    icon: 'fa-barcode',
                    labl: '@EAN / Barcode',
                    name: 'prod_ean',
                    valu: $item['prod_ean'] ?? '',
                    type: 'text',
                    extr: 'placeholder="' . lang('@Optional') . '"'
                );
            endif; ?>

            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <?php
                    htm_Field(
                        icon: 'fa-boxes',
                        labl: '@In Stock',
                        name: 'prod_stock',
                        valu: $item['prod_stock'],
                        type: 'number',
                        extr: 'step="any" style="text-align: center;"'
                    ); 
                    // Beholdningen da formularen blev åbnet - se inventory_actions.php (kun en ÆNDRING af feltet skrives)
                    echo '<input type="hidden" name="prod_stock_orig" value="' . htmlspecialchars((string)$item['prod_stock']) . '">';
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
                            extr: 'required id="prod_price_field" oninput="updateMargin()" style="font-weight:bold; text-align: right;"'
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

            <?php
            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): products
            // havde en salgspris, men ingen kostpris - ingen måde at se avance/
            // dækningsbidrag pr. produkt. Rent informativ (påvirker ikke
            // fakturering/moms), samme "stille degradering hvis ikke migreret
            // endnu"-mønster som andre nyere kolonner - viser kun feltet hvis
            // db-setup/migrate_product_cost_price.php er kørt.
            $has_cost_col = false;
            if (DB::is_sqlite()) {
                $cc = DB::query($conn, "PRAGMA table_info(products)");
                if ($cc) { while ($ccr = DB::fetch_assoc($cc)) { if ($ccr['name'] === 'prod_cost_price') { $has_cost_col = true; break; } } }
            } else {
                $cc = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_cost_price'");
                $has_cost_col = ($cc && DB::num_rows($cc) > 0);
            }
            if ($has_cost_col):
            ?>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div style="flex: 3;">
                    <?php
                        render_hideable_field($fv_overrides, 'product_edit.prod_cost_price', $fv_is_admin, $fv_uLev, function() use ($item) {
                            htm_Field(
                                icon: 'fa-tags',
                                labl: '@Cost Price',
                                name: 'prod_cost_price',
                                valu: isset($item['prod_cost_price']) && $item['prod_cost_price'] !== null ? number_format((float)$item['prod_cost_price'], 2, ',', '') : '',
                                type: 'text',
                                extr: 'id="prod_cost_field" oninput="updateMargin()" style="text-align: right;" placeholder="' . lang('@Optional') . '"'
                            );
                        }, true); // has_own_box: htm_Field() tegner allerede sin egen fieldset-kant
                    ?>
                </div>
                <div style="flex: 4; font-size:0.85em; color:var(--text-muted);" id="margin_display"></div>
            </div>
            <?php endif; ?>
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

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): der findes et
// "bedst sælgende produkter"-overblik (sales_hub.php, runde 13), men ingen
// måde at se salgshistorikken for ÉT bestemt produkt direkte fra selve
// produktkortet - nyttigt ved prisjusteringer eller lagerplanlægning.
// Ekskluderer kladder, samme "en kladde er endnu ikke et reelt salg"-
// princip som resten af appens omsætnings-relaterede rapporter.
if ($id > 0) {
    // RETTET: linjetotalen så bort fra linjens rabat og fra fakturaens valutakurs - en EUR-faktura talte med
    // sit EUR-beløb som var det kroner, og en rabatlinje med fuld pris (samme regel som invoice_dkk_totals())
    $ph_disc = invoice_discount_sql($conn, 'il.');
    $sales_res = DB::query($conn, "
        SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_status, c.cust_name,
               il.quantity, il.price_each,
               (il.quantity * il.price_each $ph_disc * (100 + il.line_vat_rate) / 100.0 * COALESCE(NULLIF(i.exch_rate, 0), 1)) AS line_total
        FROM invoice_lines il
        JOIN invoices i ON il.inv_id = i.inv_id
        JOIN customers c ON i.cust_id = c.cust_id
        WHERE il.prod_id = $id AND LOWER(i.inv_status) != 'draft'
        ORDER BY i.inv_date DESC
        LIMIT 200");

    $sales_rows = [];
    $total_qty = 0.0; $total_rev = 0.0;
    while ($sr = DB::fetch_assoc($sales_res)) {
        $total_qty += (float)$sr['quantity'];
        $total_rev += (float)$sr['line_total'];
        $sales_rows[] = [
            '<a href="invoice_view.php?id=' . (int)$sr['inv_id'] . '">#' . str_pad($sr['invoice_no'], 6, "0", STR_PAD_LEFT) . '</a>',
            date(CONF_DATE_FORMAT, strtotime($sr['inv_date'])),
            htmlspecialchars($sr['cust_name']),
            number_format((float)$sr['quantity'], 2, ',', '.'),
            number_format((float)$sr['price_each'], 2, ',', '.'),
            number_format((float)$sr['line_total'], 2, ',', '.') . ' ' . $cur,
        ];
    }

    if (!empty($sales_rows)) {
        echo "<div style='max-width:900px; margin:20px auto;'>";
        htm_Card_(capt: '@Sales History', wdth: 900);
        echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">'
            . sprintf(lang('@%s units sold for a total of %s across %d invoice line(s).'), number_format($total_qty, 2, ',', '.'), number_format($total_rev, 2, ',', '.') . ' ' . $cur, count($sales_rows))
            . '</p>';
        htm_Table(
            ['@Invoice', '@Date', '@Customer', '@Qty', '@Price', '@Line Total'],
            $sales_rows, 'prod_sales_tbl', 100, '', true,
            ['', '', '', 'text-align:right;', 'text-align:right;', 'text-align:right;'],
            '500px', 'produkt_salgshistorik.csv'
        );
        htm_Card_end();
        echo "</div>";
    }
}
?>
<script>
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): live avance-/
// dækningsbidrags-visning mens man udfylder salgs-/kostpris - kun til
// visning, sendes ikke med i formularen.
function updateMargin() {
    var priceField = document.getElementById('prod_price_field');
    var costField = document.getElementById('prod_cost_field');
    var box = document.getElementById('margin_display');
    if (!priceField || !costField || !box) return;
    var price = parseFloat((priceField.value || '0').toString().replace(',', '.')) || 0;
    var cost = parseFloat((costField.value || '').toString().replace(',', '.'));
    if (!costField.value || isNaN(cost) || price <= 0) { box.innerHTML = ''; return; }
    var margin = price - cost;
    var pct = price > 0 ? (margin / price * 100) : 0;
    var color = margin >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
    box.innerHTML = '<span style="color:' + color + '; font-weight:bold;">' + margin.toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2})
        + ' ' + <?php echo json_encode($cur); ?> + ' (' + pct.toFixed(1) + '%)</span> ' + <?php echo json_encode(lang('@margin')); ?>;
}
document.addEventListener('DOMContentLoaded', updateMargin);
</script>
<?php
htm_Footer();
?>