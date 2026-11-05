<?php # /inventory_status.php v:1.3.2 d:2026-09-15 i:evs
# 5 fund ved en produkt-/lagergennemgang - se [[inventory-bugs-review]]
# v1.3.0: (1) KRITISK - produktnavn blev skrevet råt/uescaped ind i tabellen
# (lagret XSS, bekræftet live med <script>alert(1)</script>) - product_list.php
# escaper allerede samme felt korrekt, kun denne søsterside manglede det.
# (2) Der var slet ingen måde at slette et produkt på nogen steder i UI'et -
# den eneste sletningslogik (invoice_actions.php?action=delete_product) er
# helt ukoblet/ureferet. Tilføjet en rigtig Slet-knap her (bekræftelse +
# tjek for brug på fakturalinjer, samme mønster som customer_edit.php).
# (4) "Opret variant"-knappens hint var hårdkodet dansk (manglede
# @-præfikset), oversatte derfor aldrig - rettet til en rigtig lang()-nøgle.
# (5) CSV-eksporten hed altid bogstaveligt "test.csv" - en glemt
# placeholder-værdi, rettet til et rigtigt filnavn.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$s = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header("@Inventory Status", 800);
showMenu();


// Knapper til top-højre
$tools = htm_Button('fa-plus', ' '.lang('@New Product'), 'success', 'product_edit.php', 'padding:4px 8px; margin-right:5px;', 'data-hint="'.lang('@Create a new product').'"', '', false);
$tools .= htm_Button('fa-file-csv', ' CSV', 'info', 'inventory_export.php', 'padding:4px 8px;', 'target="_blank" data-hint="'.lang('@Export the inventory list as CSV').'"', '', false);

htm_Card_("@Inventory Status", "100%", "", "inv_card", true, $tools);

// NYT: ingen side i systemet viste hvilke produkter der reelt sælger mest - relevant for genbestilling og
// prisbeslutninger. Krediterede linjer (negativ mængde, se invoice_credit.php) trækker automatisk fra igen, så en
// krediteret ordre ikke tæller som et reelt salg.
// NYT: viser bruttoavance pr. produkt her også, når kostpris er udfyldt - samme "stille degradering hvis ikke
// migreret endnu"-mønster.
$has_cost_col = db_has_column($conn, 'products', 'prod_cost_price');
$cost_select = $has_cost_col ? ", SUM(il.quantity * COALESCE(p.prod_cost_price, 0)) AS cost_total, MAX(CASE WHEN p.prod_cost_price IS NULL THEN 1 ELSE 0 END) AS cost_unknown" : "";
// RETTET: omsætningen så bort fra linjerabatten og fakturaens valutakurs (en EUR-linje talte som kroner) -
// så omsætning og bruttoavance (mod kostprisen i kroner) blev for høje / forkerte
$is_rev = "il.quantity * il.price_each " . invoice_discount_sql($conn, 'il.') . " * COALESCE(NULLIF(i.exch_rate, 0), 1)";
$top_res = DB::query($conn, "
    SELECT il.prod_id, p.prod_name,
           SUM(il.quantity) AS qty_sold,
           SUM($is_rev) AS revenue_excl_vat
           $cost_select
    FROM invoice_lines il
    JOIN invoices i ON il.inv_id = i.inv_id
    JOIN products p ON il.prod_id = p.prod_id
    WHERE il.prod_id > 0 AND LOWER(i.inv_status) != 'draft'
    GROUP BY il.prod_id, p.prod_name
    HAVING SUM(il.quantity) > 0
    ORDER BY revenue_excl_vat DESC
    LIMIT 10");
$top_rows = [];
while ($tr = DB::fetch_assoc($top_res)) {
    $row = [
        htmlspecialchars($tr['prod_name']),
        fmt_dk((float)$tr['qty_sold'], 0),
        fmt_dk((float)$tr['revenue_excl_vat']) . ' ' . $cur,
    ];
    if ($has_cost_col) {
        $row[] = !empty($tr['cost_unknown'])
            ? '<span style="color:var(--text-muted);">' . lang('@Unknown') . '</span>'
            : fmt_dk((float)$tr['revenue_excl_vat'] - (float)$tr['cost_total']) . ' ' . $cur;
    }
    $top_rows[] = $row;
}
if (!empty($top_rows)) {
    echo '<details style="margin-bottom:15px;"><summary style="cursor:pointer; font-weight:bold; color:var(--text-muted); user-select:none; list-style:none; display:flex; align-items:center; gap:6px;">'
        . '<i class="fa-solid fa-ranking-star" style="color:var(--color-primary);"></i> ' . lang('@Best-selling Products (Top 10 by Revenue)') . '</summary>';
    echo '<div style="margin-top:8px;">';
    $top_headers = ['@Product', '@Qty Sold', '@Revenue (excl. VAT)'];
    $top_cols = ['', 'width:100px; text-align:right;', 'width:150px; text-align:right;'];
    if ($has_cost_col) { $top_headers[] = '@Gross Margin'; $top_cols[] = 'width:150px; text-align:right;'; }
    htm_Table($top_headers, $top_rows, 'topProdTbl', 10, '', true, $top_cols);
    echo '</div></details>';
}

// NYT: samme "gruppér salg"-idé som udgiftsfordeling-pr.-konto, blot på salgssiden - kun muligt nu hvor
// products.prod_category findes. Samme "ekskludér kladder"-princip som bedst-sælgende-produkter-panelet ovenfor.
$has_category_col = db_has_column($conn, 'products', 'prod_category');
if ($has_category_col) {
    $cat_res = DB::query($conn, "
        SELECT p.prod_category AS cat_name,
               SUM($is_rev) AS revenue_excl_vat
        FROM invoice_lines il
        JOIN invoices i ON il.inv_id = i.inv_id
        JOIN products p ON il.prod_id = p.prod_id
        WHERE il.prod_id > 0 AND LOWER(i.inv_status) != 'draft'
        GROUP BY p.prod_category
        HAVING SUM($is_rev) != 0
        ORDER BY revenue_excl_vat DESC");
    $cat_rows = [];
    $cat_grand = 0.0;
    $cat_tmp = [];
    if ($cat_res) {
        while ($cr = DB::fetch_assoc($cat_res)) { $cat_tmp[] = $cr; $cat_grand += (float)$cr['revenue_excl_vat']; }
    }
    foreach ($cat_tmp as $cr) {
        $pct = $cat_grand > 0 ? ((float)$cr['revenue_excl_vat'] / $cat_grand * 100) : 0;
        $cat_rows[] = [
            htmlspecialchars($cr['cat_name'] !== null && $cr['cat_name'] !== '' ? $cr['cat_name'] : lang('@Uncategorized')),
            fmt_dk((float)$cr['revenue_excl_vat']) . ' ' . $cur,
            fmt_dk($pct, 1) . ' %',
        ];
    }
    if (!empty($cat_rows)) {
        echo '<details style="margin-bottom:15px;"><summary style="cursor:pointer; font-weight:bold; color:var(--text-muted); user-select:none; list-style:none; display:flex; align-items:center; gap:6px;">'
            . '<i class="fa-solid fa-layer-group" style="color:var(--color-primary);"></i> ' . lang('@Sales by Category') . '</summary>';
        echo '<div style="margin-top:8px;">';
        htm_Table(['@Category', '@Revenue (excl. VAT)', '@Share'], $cat_rows, 'catSalesTbl', 20, '', true, ['', 'width:150px; text-align:right;', 'width:100px; text-align:right;']);
        echo '</div></details>';
    }
}

$data = [];
$res = DB::query($conn, "SELECT prod_id, prod_sku, prod_name, prod_stock, prod_min_stock, prod_price FROM products ORDER BY prod_name ASC");

if ($res && DB::num_rows($res) > 0) {
    while($r = DB::fetch_assoc($res)) {
        $stk = (float)($r['prod_stock'] ?? 0);      // RETTET: (int) skar decimaler af (fx 7,5 kg -> 7)
        $stk_txt = rtrim(rtrim(fmt_dk($stk, 3), '0'), ',');
        $min = (float)($r['prod_min_stock'] ?? 5);
        
        $sku = !empty($r['prod_sku']) ? $r['prod_sku'] : $r['prod_id'];
        // RETTET: produktnavnet blev før skrevet direkte ind i markup'en - htm_Table escaper IKKE cellernes
        // indhold selv (den understøtter bevidst rå HTML i celler, fx knapper), så det er hvert kaldested der selv
        // skal escape ren tekst. product_list.php gjorde det allerede korrekt for samme felt.
        $name = htmlspecialchars($r['prod_name']);

        // Pris formateres med valuta
        $prc = fmt_dk($r['prod_price']) . " $cur";

        // Lagerstatus
        // RETTET: $min (prod_min_stock) blev beregnet men ALDRIG reelt brugt til noget - hverken her eller nogen
        // andre steder i appen. Lagertallet vises nu i rødt/fed med et advarselsikon, når beholdningen er nået ned
        // på eller under grænsen - samme idé som index.php's nye "Lavt lager"-tæller nedenfor, som bruger nøjagtig
        // samme sammenligning.
        $display_stock = ($min > 0 && $stk <= $min)      // (minimum 0 = ingen grænse - se index.php)
            ? '<strong style="color:var(--color-danger);"><i class="fa-solid fa-triangle-exclamation"></i> ' . $stk_txt . '</strong>'
            : $stk_txt;

        // Action knapper inkl. ny variant-knap
        $btns = htm_ActionButtons([
            ['icon' => 'fa-pencil', 'link' => 'product_edit.php?id='.$r['prod_id'], 'hint' => '@Edit', 'type' => 'primary'],
            // RETTET (fund 4): hint manglede @-præfikset og var derfor
            // hårdkodet dansk uanset valgt sprog - se lang()'s konvention.
            ['icon' => 'fa-copy', 'link' => 'product_edit.php?copy_id='.$r['prod_id'], 'hint' => '@Create variant', 'type' => 'success'],
            // NYT: der var ingen måde at slette et produkt på nogen steder i UI'et - den eneste eksisterende
            // sletningslogik (invoice_actions.php?action=delete_product) er helt ukoblet. Genbruger samme tjek
            // (blokerer sletning hvis produktet er brugt på en fakturalinje) i den aktivt vedligeholdte
            // inventory_actions.php i stedet.
            ['icon' => 'fa-trash', 'link' => 'inventory_actions.php?action=delete_product&id='.$r['prod_id'], 'hint' => '@Delete', 'type' => 'danger', 'confirm' => '@Are you sure you want to delete this product? This cannot be undone.'],
        ], false);

        $data[] = [$sku,
            '<span class="truncate" onclick="toggleCell(this.parentElement)" title="'.lang('@Click to toggle view').'">'.$name.'</span>',
            $prc,
            $display_stock,
            $btns];
    }
}

// Simple overskrifter (Biblioteket oversætter dem selv og tilføjer sortering)
$headers = [
    '@SKU', 
    '@Product', 
    '@Price', 
    '@Stock', 
    '@Action'
];

// Generer tabellen (Kun ét kald her)
// RETTET (fund 5): eksportfilnavnet var en glemt placeholder ("test.csv").
htm_Table($headers, $data, 'inv_table', 50, vhgh:'350px', expo:'lagerstatus.csv');

htm_Card_end();
htm_Footer();
?>