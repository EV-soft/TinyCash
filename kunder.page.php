<?php # kunder.page.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

htm_Header(lang('@Customer List'));
showMenu();

// Knap til at oprette ny kunde
echo "<div style='margin-bottom: 20px;'>";
echo "<a href='kunde_opret.page.php' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@Add New Customer') . "</a>";
echo "</div>";

htm_Card_(lang('@Customer Overview'),'800');

// RETTET SQL: Bruger 'cust_name' i stedet for 'customer_name'
$res = mysqli_query($conn, "SELECT * FROM customers ORDER BY cust_name ASC");

if (!$res) {
    echo "<div style='color:red; padding:10px;'>SQL Fejl: " . mysqli_error($conn) . "</div>";
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif;'>";
    echo "<tr style='background: #f2f2f2; text-align: left;'>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Customer Name') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@CVR') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Email') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Payment Days') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    if (mysqli_num_rows($res) == 0) {
        echo "<tr><td colspan='5' style='padding:20px; text-align:center; color:#999;'>" . lang('@No customers found') . "</td></tr>";
    } else {
        while ($row = mysqli_fetch_assoc($res)) {
            echo "<tr>";
            // RETTET: Bruger feltnavne fra dit blueprint
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; font-weight:bold;'>" . htmlspecialchars($row['cust_name']) . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($row['cust_cvr'] ?? '-') . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($row['cust_email'] ?? '-') . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($row['cust_payment_days'] ?? '8') . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>";
            echo "<a href='kunde_ret.page.php?id=" . $row['cust_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold; margin-right:10px;'>" . lang('@Edit') . "</a>";
            echo "<a href='faktura_opret.page.php?cust_id=" . $row['cust_id'] . "' style='color: #27ae60; text-decoration: none; font-weight: bold;'>" . lang('@New Invoice') . "</a>";
            echo "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
}

htm_Card_end();
htm_Footer();
?>