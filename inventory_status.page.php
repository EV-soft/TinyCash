<?php # /inventory_status.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';      
require_once 'inc/db_connect.inc.php';  
require_once 'inc/menu.inc.php';      

// Hent valuta fra indstillinger (Standard er DKK hvis intet findes)
$settings = get_settings($conn);
$currency = $settings['currency'] ?? 'DKK';

htm_Header(lang('@Inventory Overview'));
showMenu();

htm_Card_(lang('@Product List'), 900);

// Hent produkter - Sorteret efter SKU
$sql = "SELECT * FROM products ORDER BY prod_sku ASC, prod_name ASC";
$res = mysqli_query($conn, $sql);

if (!$res) {
    htm_Alert(lang('@SQL Error') . ": " . mysqli_error($conn), 'error');
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif; margin-bottom: 20px;'>";
    echo "<tr style='background: #f8f9fa; text-align: left;'>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6;'>" . lang('@SKU / ID') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6;'>" . lang('@Product Name') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6; text-align: right;'>" . lang('@Price') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6; text-align: center;'>" . lang('@In Stock') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6; text-align: right;'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    if (mysqli_num_rows($res) == 0) {
        echo "<tr><td colspan='5' style='padding:30px; text-align:center; color:#999;'>" . lang('@No products found in inventory') . "</td></tr>";
    } else {
        while ($row = mysqli_fetch_assoc($res)) {
            $stockValue = (int)($row['prod_stock'] ?? 0);
            $minStock   = (int)($row['prod_min_stock'] ?? 5);
            
            // Brug advarselsfarve hvis under minimumslager
            $stockColor = ($stockValue <= $minStock) ? '#e74c3c' : '#2c3e50';
            $stockWeight = ($stockValue <= $minStock) ? 'bold' : 'normal';
            
            $displayID = !empty($row['prod_sku']) ? $row['prod_sku'] : "ID: ".$row['prod_id'];

            echo "<tr style='border-bottom: 1px solid #eee;'>";
            echo "<td style='padding: 12px; color: #7f8c8d; font-family:monospace;'>" . htmlspecialchars($displayID) . "</td>";
            echo "<td style='padding: 12px; font-weight: 500;'>" . htmlspecialchars($row['prod_name']) . "</td>";
            
            // HER ER RETTELSEN: Vi bruger $currency i stedet for lang()
            echo "<td style='padding: 12px; text-align: right;'>" . number_format($row['prod_price'], 2, ',', '.') . " $currency</td>";
            
            echo "<td style='padding: 12px; text-align: center; color: $stockColor; font-weight: $stockWeight;'>" . $stockValue . "</td>";
            
            echo "<td style='padding: 12px; text-align: right;'>";
            echo "<a href='product_edit.page.php?id=" . $row['prod_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold; margin-right: 15px;' title='".lang('@Edit')."'><i class='fa fa-edit'></i></a> ";
            echo "<a href='product_delete.page.php?id=" . $row['prod_id'] . "' onclick='return confirm(\"".lang('@Are you sure?')."\")' style='color: #e74c3c; text-decoration: none;' title='".lang('@Delete')."'><i class='fa fa-trash'></i></a>";
            echo "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";

    // --- KNAPPER I BUNDEN (Opdateret til dine CSS klasser) ---
    echo "<div style='border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 15px; align-items: center;'>";
        
        echo "<a href='product_new.page.php' class='btn-success' style='width:auto; padding:10px 20px; text-decoration:none;'>";
        echo "<i class='fa fa-plus-circle'></i> " . lang('@Add New Product');
        echo "</a>";

        echo "<a href='inventory_report.page.php' class='btn-primary' style='width:auto; padding:10px 20px; text-decoration:none;'>";
        echo "<i class='fa fa-file-invoice'></i> " . lang('@Stock Report');
        echo "</a>";

        echo "<div style='margin-left: auto;'>";
            echo "<a href='inventory_export.php' style='color: #95a5a6; text-decoration: none; font-size: 0.9em;' title='Download CSV'>";
            echo "<i class='fa fa-download'></i> CSV</a>";
        echo "</div>";

    echo "</div>";
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>