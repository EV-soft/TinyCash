<?php # /invoices.page.php v:0.8.0.2 d:2026-04-11 i:evs m:1
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';

htm_Header(lang('@Invoices'));
showMenu();

// --- VIS BESKEDER:
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $color = "#2ecc71"; 
    $text = "";
    if ($msg == 'slettet' || $msg == 'deleted') {
        $text = lang("@Invoice deleted, and stock has been restored.");
        $color = "#e74c3c"; 
    } elseif ($msg == 'success') {
        $text = lang("@Invoice No.") . " " . htmlspecialchars($_GET['no'] ?? '') . " " . lang("@created and stock updated.");
    } elseif ($msg == 'status_opdateret' || $msg == 'status_updated') {
        $text = lang("@Invoice status updated.");
    }
    if ($text) {
        echo "<div style='background: $color; color: white; padding: 15px; margin-bottom: 20px; border-radius: 4px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>";
        echo "✓ " . $text;
        echo "</div>";
    }
}

htm_Card_(lang('@Invoice Overview'), '800');

// SQL: Beregner totalen inkl. moms via subquery
$sql = "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_status, c.cust_name,
        -- Netto (Ekskl. moms)
        (SELECT SUM(quantity * price_each) FROM invoice_lines WHERE inv_id = i.inv_id) AS netto_amount,
        -- Brutto (Inkl. moms)
        (SELECT SUM(quantity * price_each * (1 + line_vat_rate/100)) FROM invoice_lines WHERE inv_id = i.inv_id) AS total_amount
        FROM invoices i 
        LEFT JOIN customers c ON i.cust_id = c.cust_id 
        ORDER BY i.inv_date DESC, i.invoice_no DESC";
        
        
$res = mysqli_query($conn, $sql);

if (!$res) { 
    echo "<div style='color:red; padding:10px;'>" . lang('@SQL Error:') . " " . mysqli_error($conn) . "</div>"; 
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif; margin-bottom: 20px;'>";
    echo "<tr style='background: #f2f2f2; text-align: left;'>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #ddd;'>" . lang('@No.') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #ddd;'>" . lang('@Date') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #ddd;'>" . lang('@Customer') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Total (Incl. VAT)') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #ddd; text-align: center;'>" . lang('@Status') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #ddd; text-align: right;'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    if (mysqli_num_rows($res) == 0) {
        echo "<tr><td colspan='6' style='padding:20px; text-align:center; color:#999;'>" . lang('@No invoices found') . "</td></tr>";
    } else {
        while ($row = mysqli_fetch_assoc($res)) {
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
            echo "<td style='padding: 12px; border-bottom: 1px solid #eee;'>" . ($row['invoice_no'] ?? '---') . "</td>";
            echo "<td style='padding: 12px; border-bottom: 1px solid #eee;'>" . date('d-m-Y', strtotime($row['inv_date'])) . "</td>";
            echo "<td style='padding: 12px; border-bottom: 1px solid #eee; font-weight: bold;'>" . htmlspecialchars($row['cust_name'] ?? lang('@Unknown')) . "</td>";
            // echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>" . number_format($row['total_amount'], 2, ',', '.') . "</td>";
            echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>";
                echo "<span style='font-size: 0.9em; color: #7f8c8d;'>" . number_format($row['netto_amount'], 2, ',', '.') . "</span><br>";
                echo "<strong>" . number_format($row['total_amount'], 2, ',', '.') . "</strong>";
            echo "</td>";
            echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: center;'><span style='$statusStyle'>" . $statusLabel . "</span></td>";
                        echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>";
                echo "<a href='invoice_view.page.php?id=" . $row['inv_id'] . "' style='color: #3498db; text-decoration: none; font-weight: bold; margin-right: 15px;'>" . lang('@View') . "</a>";
                echo "<a href='invoice_lines_edit.page.php?id=" . $row['inv_id'] . "' style='color: #7f8c8d; text-decoration: none; font-weight: bold; margin-right: 15px;'>" . lang('@Edit') . "</a>";
                echo "<a href='invoice_actions.php?action=slet_faktura&id=" . $row['inv_id'] . "' 
                       style='color: #e74c3c; text-decoration: none; font-weight: bold;' 
                       onclick=\"return confirm('" . lang('@Are you sure?') . "');\">" . lang('@Delete') . "</a>";
            echo "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
}

// Den grønne knap er nu flyttet herned, lige før kortet lukker
echo "<div style='margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>";
echo "<a href='invoice_create.page.php' style='display: inline-block; background:#2ecc71; color:white; padding:12px 20px; text-decoration:none; border-radius:4px; font-weight:bold; box-shadow: 0 2px 4px rgba(46, 204, 113, 0.3);'>+ " . lang('@Create New Invoice') . "</a>";
echo "</div>";

htm_Card_end();
htm_Footer();
?>