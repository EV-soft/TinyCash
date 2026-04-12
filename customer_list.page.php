<?php # /customer_list.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

htm_Header(lang('@Customer List'));
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

    echo "<h2 style='margin-bottom: 20px;'>👥 " . lang('@Customer Overview') . "</h2>";

    htm_Card_(lang('@Customer List'), '100%');

    // Hent data
    $res = mysqli_query($conn, "SELECT * FROM customers ORDER BY cust_name ASC");

    if (!$res) {
        // Bruger din nye standardiserede Alert funktion til fejl
        htm_Alert(lang('@SQL Error:') . " " . mysqli_error($conn), 'error');
    } else {
        // Vi bruger en class "std-table" (som bør ligge i dit CSS) for at undgå gentaget style-kode
        echo "<table class='std-table' style='width:100%; border-collapse: collapse;'>";
        echo "<thead>";
            echo "<tr>";
                echo "<th>" . lang('@Customer Name') . "</th>";
                echo "<th>" . lang('@CVR') . "</th>";
                echo "<th>" . lang('@Email') . "</th>";
                echo "<th style='text-align:center;'>" . lang('@Payment Days') . "</th>";
                echo "<th style='text-align:right;'>" . lang('@Actions') . "</th>";
            echo "</tr>";
        echo "</thead>";
        echo "<tbody>";

        if (mysqli_num_rows($res) == 0) {
            echo "<tr><td colspan='5' style='padding:40px; text-align:center; color:#999;'>" . lang('@No customers found') . "</td></tr>";
        } else {
            while ($row = mysqli_fetch_assoc($res)) {
                echo "<tr>";
                    echo "<td style='font-weight:bold;'>" . htmlspecialchars($row['cust_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['cust_cvr'] ?? '-') . "</td>";
                    echo "<td>" . htmlspecialchars($row['cust_email'] ?? '-') . "</td>";
                    echo "<td style='text-align:center;'>" . htmlspecialchars($row['cust_payment_days'] ?? '8') . "</td>";
                    echo "<td style='text-align:right;'>";
                        echo "<a href='customer_edit.page.php?id=" . $row['cust_id'] . "' class='link-edit' title='".lang('@Edit')."'>✏️</a> ";
                        echo "<a href='invoice_create.page.php?cust_id=" . $row['cust_id'] . "' class='link-invoice' style='margin-left:10px;' title='".lang('@New Invoice')."'>📄</a>";
                    echo "</td>";
                echo "</tr>";
            }
        }
        echo "</tbody>";
        echo "</table>";
    }

    // Bund-aktioner
    echo "<div style='margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>";
        echo "<a href='customer_create.page.php' class='btn-success' style='text-decoration:none;'>+ " . lang('@Add New Customer') . "</a>";
    echo "</div>";

    htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush();