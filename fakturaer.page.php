<?php # fakturaer.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

htm_Header(lang('@Invoices'));
showMenu();

// Knap til at oprette ny faktura
echo "<div style='margin-bottom: 20px;'>";
echo "<a href='faktura_opret.page.php' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@Create New Invoice') . "</a>";
echo "</div>";

htm_Card_(lang('@Invoice Overview'),'800');

// RETTET SQL: Bruger nu de korrekte navne fra dit blueprint
// Bemærk: 'total_amount' findes ikke i din faktura-tabel, så vi beregner den via en subquery
$sql = "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_status, c.cust_name,
        (SELECT SUM(quantity * price_each * (1 + vat_rate/100)) FROM invoice_lines WHERE inv_id = i.inv_id) AS total_amount
        FROM invoices i 
        LEFT JOIN customers c ON i.cust_id = c.cust_id 
        ORDER BY i.inv_date DESC, i.invoice_no DESC";

$res = mysqli_query($conn, $sql);

if (!$res) {
    echo "<div style='color:red; padding:10px;'>SQL Fejl: " . mysqli_error($conn) . "</div>";
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif;'>";
    echo "<tr style='background: #f2f2f2; text-align: left;'>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@No.') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Date') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd;'>" . lang('@Customer') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Amount') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: center;'>" . lang('@Status') . "</th>";
    echo "<th style='padding: 10px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    if (mysqli_num_rows($res) == 0) {
        echo "<tr><td colspan='6' style='padding:20px; text-align:center; color:#999;'>" . lang('@No invoices found') . "</td></tr>";
    } else {
        while ($row = mysqli_fetch_assoc($res)) {
            // Status farve-logik - rettet til at bruge 'inv_status'
            $statusStyle = "padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold;";
            if ($row['inv_status'] == 'paid') {
                $statusLabel = lang('@Paid');
                $statusStyle .= " background: #d4edda; color: #155724;";
            } elseif ($row['inv_status'] == 'draft') {
                $statusLabel = lang('@Draft');
                $statusStyle .= " background: #e9ecef; color: #495057;";
            } else {
                $statusLabel = lang('@Sent');
                $statusStyle .= " background: #fff3cd; color: #856404;";
            }

            echo "<tr>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . ($row['invoice_no'] ?? '---') . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>" . date('d-m-Y', strtotime($row['inv_date'])) . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;'>" . htmlspecialchars($row['cust_name'] ?? lang('@Unknown')) . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>" . number_format($row['total_amount'], 2, ',', '.') . "</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'><span style='$statusStyle'>" . $statusLabel . "</span></td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>";
            echo "<a href='faktura_vis.page.php?id=" . $row['inv_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold; margin-right: 10px;'>" . lang('@View') . "</a>";
            echo "<a href='faktura_linjer_rediger.page.php?id=" . $row['inv_id'] . "' style='color: #7f8c8d; text-decoration: none; font-weight: bold;'>" . lang('@Edit') . "</a>";
            echo "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
}

htm_Card_end();
htm_Footer();
?>