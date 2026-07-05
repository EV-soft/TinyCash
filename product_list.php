<?php # product_list.php v:1.1.0 d:2026-07-05 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/menu.inc.php';

htm_Header('@Product Catalog');
showMenu();

// 1. Hent data fra databasen med dine specifikke kolonnenavne
$query = "SELECT prod_id, prod_sku, prod_name, prod_stock, prod_min_stock, prod_price FROM products ORDER BY prod_name ASC";
$result = DB::query($conn, $query);

// 2. Definer overskrifter (kun værdierne til din foreach ($head as $labl))
$h = [
    '@ID', 
    '@Product Name', 
    '@SKU/Varenr', 
    '@Price', 
    '@Stock', 
    '@Actions'
];

// 3. Forbered data-rækkerne
$rows = [];
if ($result && DB::num_rows($result) > 0) {
    while ($row = DB::fetch_assoc($result)) {
        // Lager-farve logik
        $stock = $row['prod_stock'] ?? 0;
        $min = $row['prod_min_stock'] ?? 5;
        $color = ($stock <= $min) ? '#e74c3c' : '#27ae60';
        
        // Vi bygger rækken præcis i samme rækkefølge som overskrifterne
        /* $rows[] = [
            'id'    => '#' . $row['prod_id'],
            'name'  => '<b>' . htmlspecialchars($row['prod_name']) . '</b>',
            'sku'   => htmlspecialchars($row['prod_sku'] ?? '-'),
            'price' => number_format($row['prod_price'] ?? 0, 2, ',', '.') . ' kr.',
            'stock' => '<span style="background:'.$color.'; color:white; padding:3px 8px; border-radius:12px; font-size:0.85em;">' . $stock . '</span>',
            'btns'  => htm_Button('@Edit', 'edit_'.$row['prod_id'], 'fa-edit', 'button', 'gray', '90px', 'onclick="window.location.href=\'product_edit.php?id='.$row['prod_id'].'\'"', '@Edit product', false)
        ]; */
        $rows[] = [
                '#' . $row['prod_id'],
                '<b>' . htmlspecialchars($row['prod_name']) . '</b>',
                htmlspecialchars($row['prod_sku'] ?? '-'),
                number_format($row['prod_price'] ?? 0, 2, ',', '.') . ' kr.',
                '<span style="background:'.$color.'; color:white; padding:3px 8px; border-radius:12px; font-size:0.85em;">' . $stock . '</span>',
                htm_Button('@Edit', 'edit_'.$row['prod_id'], 'fa-edit', 'button', 'gray', '90px',
                    'onclick="window.location.href=\'product_edit.php?id='.$row['prod_id'].'\'"',
                    '@Edit product', false)
            ];
    }
}





// 4. Start Card
$tools = htm_Button('@Create New Product', 'new_prod', 'fa-plus', 'button', 'success', 'inline-block', 'onclick="window.location.href=\'product_edit.php?id=0\'"');

htm_Card_('@Product Catalog', 900, '@Manage your stock levels and product details', false, true, $tools);

// 5. Vis tabellen via din htm_Table funktion
// Vi bruger 'prod_table' som navn, så den indbyggede søgning og filterTable() virker sammen
htm_Table($h, $rows, 'prod_table');

htm_Card_end();
htm_Footer();
?>