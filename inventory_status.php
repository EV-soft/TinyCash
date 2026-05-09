<?php # inventory_status.php v:0.9.0 d:2026-05-08 i:evs
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$s = get_settings($conn); 
$cur = $s['currency'] ?? 'DKK';

htm_Header("@Inventory Status", 800);
showMenu();

// Knapper til top-højre
$tools = htm_Button('fa-plus', ' '.lang('@New Product'), 'success', 'product_new.page.php', 'padding:4px 8px; margin-right:5px;', '', '', false);
$tools .= htm_Button('fa-file-csv', ' CSV', 'info', 'inventory_export.php', 'padding:4px 8px;', 'target="_blank"', '', false);

htm_Card_("@Inventory Status", "100%", "", "inv_card", true, $tools);

$data = [];
$res = mysqli_query($conn, "SELECT prod_id, prod_sku, prod_name, prod_stock, prod_min_stock, prod_price FROM products ORDER BY prod_name ASC");

if ($res && mysqli_num_rows($res) > 0) {
    while($r = mysqli_fetch_assoc($res)) {
        $stk = (int)($r['prod_stock'] ?? 0);
        $min = (int)($r['prod_min_stock'] ?? 5);
        
        $sku = !empty($r['prod_sku']) ? $r['prod_sku'] : $r['prod_id'];
        $name = $r['prod_name'];
        
        // Pris formateres med valuta
        $prc = number_format($r['prod_price'], 2, ',', '.') . " $cur";
        
        // Lagerstatus
        $display_stock = $stk; 

        // Action knapper
        $btns = '<a href="product_edit.php?id='.$r['prod_id'].'" class="btn-icon bg-edit"><i class="fa fa-pencil"></i></a>';
        
        $data[] = [$sku, $name, $prc, $display_stock, $btns];
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
htm_Table($headers, $data, 'inv_table', 50);

htm_Card_end();
htm_Footer();
?>