<?php # kunde_detaljer.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

$customer_id = (int)$_GET['id'];

// 1. Data: Hent kundeinfo
$res = mysqli_query($conn, "SELECT * FROM customers WHERE customer_id = $customer_id");
$c = mysqli_fetch_assoc($res);

if (!$c) die(lang('@Customer not found'));

htm_Header(lang('@Customer Details'));
showMenu();

// Knapper til handlinger
echo "<div style='margin-bottom: 20px; display: flex; gap: 10px;'>";
echo "<a href='kunder.page.php' style='background:#95a5a6; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>← " . lang('@Back to List') . "</a>";
echo "<a href='kunde_rediger.page.php?id=$customer_id' style='background:#3498db; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>" . lang('@Edit Customer') . "</a>";
echo "<a href='faktura_opret.page.php?customer_id=$customer_id' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@New Invoice') . "</a>";
echo "</div>";

echo "<div style='display: grid; grid-template-columns: 1fr 2fr; gap: 20px;'>";

    // KUNDEINFO KORT
    echo "<div>";
    htm_Card_(lang('@Contact Information'));
    echo "<p><strong>" . lang('@Customer Name') . ":</strong><br>" . htmlspecialchars($c['customer_name']) . "</p>";
    echo "<p><strong>" . lang('@Contact Person') . ":</strong><br>" . htmlspecialchars($c['contact_person'] ?? '-') . "</p>";
    echo "<p><strong>" . lang('@Email Address') . ":</strong><br>" . htmlspecialchars($c['email'] ?? '-') . "</p>";
    echo "<p><strong>" . lang('@Address') . ":</strong><br>" . htmlspecialchars($c['address']) . "<br>" . $c['zip'] . " " . htmlspecialchars($c['city']) . "</p>";
    echo "<p><strong>" . lang('@CVR Number') . ":</strong><br>" . htmlspecialchars($c['cvr_number'] ?? '-') . "</p>";
    htm_Card_end();
    echo "</div>";

    // FAKTURA HISTORIK KORT
    echo "<div>";
    htm_Card_(lang('@Invoice History'));
    
    $inv_res = mysqli_query($conn, "SELECT * FROM invoices WHERE customer_id = $customer_id ORDER BY invoice_date DESC");
    
    echo "<table style='width:100%; border-collapse: collapse;'>";
    echo "<tr style='text-align: left; border-bottom: 2px solid #eee;'>";
    echo "<th style='padding: 10px;'>" . lang('@No.') . "</th>";
    echo "<th style='padding: 10px;'>" . lang('@Date') . "</th>";
    echo "<th style='padding: 10px; text-align: right;'>" . lang('@Amount') . "</th>";
    echo "<th style='padding: 10px; text-align: center;'>" . lang('@Status') . "</th>";
    echo "</tr>";

    if (mysqli_num_rows($inv_res) == 0) {
        echo "<tr><td colspan='4' style='padding:20px; text-align:center; color:#999;'>" . lang('@No invoices found for this customer') . "</td></tr>";
    } else {
        while ($inv = mysqli_fetch_assoc($inv_res)) {
            $statusLabel = ($inv['status'] == 'paid') ? lang('@Paid') : lang('@Sent');
            $statusColor = ($inv['status'] == 'paid') ? '#27ae60' : '#e67e22';

            echo "<tr style='border-bottom: 1px solid #f9f9f9;'>";
            echo "<td style='padding: 10px;'><a href='faktura_vis.page.php?id=".$inv['invoice_id']."'>" . $inv['invoice_number'] . "</a></td>";
            echo "<td style='padding: 10px;'>" . date('d-m-Y', strtotime($inv['invoice_date'])) . "</td>";
            echo "<td style='padding: 10px; text-align: right;'>" . number_format($inv['total_amount'], 2, ',', '.') . "</td>";
            echo "<td style='padding: 10px; text-align: center; color:$statusColor; font-weight:bold;'>" . $statusLabel . "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    htm_Card_end();
    echo "</div>";

echo "</div>";

htm_Footer();
?>