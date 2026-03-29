<?php # lager_indhold.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

htm_Header(lang('@Inventory Overview'));
showMenu();

// Knapper til handlinger
echo "<div style='margin-bottom: 20px; display: flex; gap: 10px;'>";
echo "<a href='lager_ny.page.php' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@Add New Product') . "</a>";
echo "<a href='lager_status.page.php' style='background:#3498db; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>" . lang('@Stock Report') . "</a>";
echo "</div>";

htm_Card_(lang('@Product List'));

// Hent produkter
$sql = "SELECT * FROM products ORDER BY prod_name ASC";
$res = mysqli_query($conn, $sql);

echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif;'>";
echo "<tr style='background: #f2f2f2; text-align: left;'>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@SKU / ID') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Product Name') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Price') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: center;'>" . lang('@In Stock') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Actions') . "</th>";
echo "</tr>";

if (mysqli_num_rows($res) == 0) {
    echo "<tr><td colspan='5' style='padding:20px; text-align:center; color:#999;'>" . lang('@No products found in inventory') . "</td></tr>";
} else {
    while ($row = mysqli_fetch_assoc($res)) {
        // Lager-advarsel farve
        $stockColor = ($row['stock_qty'] <= 5) ? '#e74c3c' : '#2c3e50';
        $stockWeight = ($row['stock_qty'] <= 5) ? 'bold' : 'normal';

        echo "<tr style='border-bottom: 1px solid #eee;'>";
        echo "<td style='padding: 10px; color: #7f8c8d;'>" . htmlspecialchars($row['prod_sku'] ?? $row['prod_id']) . "</td>";
        echo "<td style='padding: 10px; font-weight: bold;'>" . htmlspecialchars($row['prod_name']) . "</td>";
        echo "<td style='padding: 10px; text-align: right;'>" . number_format($row['prod_price'], 2, ',', '.') . "</td>";
        echo "<td style='padding: 10px; text-align: center; color: $stockColor; font-weight: $stockWeight;'>" . $row['stock_qty'] . "</td>";
        echo "<td style='padding: 10px; text-align: right;'>";
        echo "<a href='lager_rediger.page.php?id=" . $row['prod_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold; margin-right: 10px;'>" . lang('@Edit') . "</a>";
        echo "<a href='lager_slet.php?id=" . $row['prod_id'] . "' onclick='return confirm(\"".lang('@Are you sure?')."\")' style='color: #e74c3c; text-decoration: none; font-size: 0.9em;'>" . lang('@Delete') . "</a>";
        echo "</td>";
        echo "</tr>";
    }
}
echo "</table>";

htm_Card_end();
htm_Footer();
?>