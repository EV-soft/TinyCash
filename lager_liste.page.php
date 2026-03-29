<?php # lager_liste.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php';
require 'menu.inc.php';

htm_Header(lang('@Inventory list'));
showMenu();

// Knap til at oprette ny vare
echo "<div style='margin-bottom: 20px;'>";
echo "<a href='vare_ny.page.php' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@Add new product') . "</a>";
echo "</div>";

htm_Card_(lang('@Inventory list'));

$res = mysqli_query($conn, "SELECT * FROM products ORDER BY prod_name ASC");

echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif;'>";
echo "<tr style='background: #f2f2f2; text-align: left;'>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@SKU') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Product name') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Price') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@In stock') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Actions') . "</th>";
echo "</tr>";

while ($row = mysqli_fetch_assoc($res)) {
    echo "<tr>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($row['prod_sku']) . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($row['prod_name']) . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . number_format($row['prod_price'], 2, ',', '.') . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . $row['prod_stock'] . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>";
    echo "<a href='vare_ret.page.php?id=" . $row['prod_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold;'>" . lang('@Edit') . "</a>";
    echo "</td>";
    echo "</tr>";
}
echo "</table>";

htm_Card_end();
htm_Footer();
?>