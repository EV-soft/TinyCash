<?php # kontoplan.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

htm_Header(lang('@Chart of Accounts'));
showMenu();

// Knap til at oprette ny konto
echo "<div style='margin-bottom: 20px;'>";
echo "<a href='konto_ny.page.php' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@Add New Account') . "</a>";
echo "</div>";

htm_Card_(lang('@Accounts & VAT Settings'),'800');

$res = mysqli_query($conn, "SELECT * FROM accounts ORDER BY acc_id ASC");

echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif;'>";
echo "<tr style='background: #f2f2f2; text-align: left;'>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; width: 80px;'>" . lang('@Account No.') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Account Name') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@VAT Code') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Type') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Balance') . "</th>";
echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: center;'>" . lang('@Actions') . "</th>";
echo "</tr>";

while ($row = mysqli_fetch_assoc($res)) {
    // Simpel logik til at farve kontotyper
    $typeLabel = ($row['acc_id'] < 3000) ? lang('@Result') : lang('@Balance Sheet');
    $typeColor = ($row['acc_id'] < 3000) ? '#e67e22' : '#3498db';

    echo "<tr>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee; font-weight:bold;'>" . $row['acc_id'] . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($row['acc_name']) . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'><span style='background:#eee; padding:2px 6px; border-radius:3px; font-size:0.9em;'>" . ($row['vat_code'] ?? 'N/A') . "</span></td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee; color:$typeColor; font-size:0.85em; font-weight:bold;'>" . $typeLabel . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>" . number_format($row['current_balance'] ?? 0, 2, ',', '.') . "</td>";
    echo "<td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'>";
    echo "<a href='konto_ret.page.php?id=" . $row['acc_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold;'>" . lang('@Edit') . "</a>";
    echo "</td>";
    echo "</tr>";
}
echo "</table>";

htm_Card_end();
htm_Footer();
?>