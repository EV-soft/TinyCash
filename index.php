<?php # index.php v:1.0.0 d:2026-06-15 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header('@Overview');
showMenu();

$current_year = date('Y');

// A: Omsætning i år
$rev_res = mysqli_query($conn, "SELECT SUM(quantity * price_each) FROM invoice_lines 
                                INNER JOIN invoices ON invoice_lines.inv_id = invoices.inv_id 
                                WHERE YEAR(invoices.inv_date) = '$current_year'");
$revenue = $rev_res ? (mysqli_fetch_column($rev_res) ?: 0) : false;

// B: Antal ubetalte fakturaer
$open_res = mysqli_query($conn, "SELECT COUNT(*) FROM invoices WHERE inv_status = 'SENT'");
$count_open = $open_res ? (mysqli_fetch_column($open_res) ?: 0) : false;

// C: Lav lagerbeholdning
$stock_res = mysqli_query($conn, "SELECT COUNT(*) FROM products WHERE prod_stock <= prod_min_stock");
$count_low_stock = $stock_res ? (mysqli_fetch_column($stock_res) ?: 0) : false;

// D: Total kunder
$cust_res = mysqli_query($conn, "SELECT COUNT(*) FROM customers");
$count_customers = $cust_res ? (mysqli_fetch_column($cust_res) ?: 0) : false;
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div style="background: #2ecc71; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Revenue This Year'); ?></small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;">
            <?php 
            if ($revenue === false) {
                echo '<span style="font-size:0.6em; color:#ffdddd;">⚠️ DB Error</span>';
            } else {
                echo number_format($revenue, 2, ',', '.') . ' kr.'; 
            }
            ?>
        </div>
    </div>

    <div style="background: #3498db; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Outstanding Invoices'); ?></small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;">
            <?php 
            if ($count_open === false) {
                echo '<span style="font-size:0.6em; color:#ffdddd;">⚠️ DB Error</span>';
            } else {
                echo $count_open . ' stk.'; 
            }
            ?>
        </div>
    </div>

    <div style="background: <?php echo ($count_low_stock > 0 ? '#e67e22' : '#95a5a6'); ?>; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Out of stock'); ?> (<?php echo lang('@Warning'); ?>)</small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;">
            <?php 
            if ($count_low_stock === false) {
                echo '<span style="font-size:0.6em; color:#ffdddd;">⚠️ DB Error</span>';
            } else {
                echo $count_low_stock . ' varer'; 
            }
            ?>
        </div>
    </div>

    <div style="background: #9b59b6; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Total Customers'); ?></small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;">
            <?php 
            if ($count_customers === false) {
                echo '<span style="font-size:0.6em; color:#ffdddd;">⚠️ DB Error</span>';
            } else {
                echo $count_customers; 
            }
            ?>
        </div>
    </div>
</div>

<div style="display: flex; gap: 20px; flex-wrap: wrap;">
    <div style="flex: 2; min-width: 300px;">
        <?php htm_Card_('@Latest Invoices'); ?>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #eee;">
                    <th style="padding: 10px;">ID</th>
                    <th style="padding: 10px;"><?php echo lang('@Customer'); ?></th>
                    <th style="padding: 10px; text-align: right;"><?php echo lang('@Amount'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sql = "SELECT i.inv_id, i.invoice_no, c.cust_name, 
                    (SELECT SUM(quantity * price_each * (1 + line_vat_rate / 100)) 
                     FROM invoice_lines 
                     WHERE inv_id = i.inv_id) AS calculated_total
                    FROM invoices i 
                    JOIN customers c ON i.cust_id = c.cust_id 
                    ORDER BY i.inv_id DESC LIMIT 5";

            $latest = mysqli_query($conn, $sql);

            if (!$latest) {
                // Rød fejlmelding hvis selve SQL-kaldet fejler (f.eks. manglende tabel)
                echo "<tr><td colspan='3' style='padding: 15px; color: #e74c3c; font-weight: bold; background: #fadbd8; border-radius: 4px;'>❌ SQL Error: " . htmlspecialchars(mysqli_error($conn)) . "</td></tr>";
            } elseif (mysqli_num_rows($latest) === 0) {
                // Venlig besked hvis databasen virker, men er tom
                echo "<tr><td colspan='3' style='padding: 15px; color: #7f8c8d; font-style: italic; text-align: center;'>" . lang('@No invoices found') . "</td></tr>";
            } else {
                while($l = mysqli_fetch_assoc($latest)) {
                    $total = $l['calculated_total'] ?: 0;
                    echo "<tr style='border-bottom: 1px solid #f9f9f9;'>";
                    echo "<td style='padding: 10px;'><a href='invoice_edit.php?id={$l['inv_id']}'>#{$l['invoice_no']}</a></td>";
                    echo "<td style='padding: 10px;'>{$l['cust_name']}</td>";
                    echo "<td style='padding: 10px; text-align: right;'>" . number_format($total, 2, ',', '.') . " kr.</td>";
                    echo "</tr>";                               
                }
            }
            ?>
            </tbody>
        </table>
        <p style="margin-top: 15px; font-size: 0.85em;">
            <a href="sales_hub.php" style="color: #3498db; text-decoration: none; font-weight: bold;">
                <i class="fa fa-arrow-right"></i> <?php echo lang('@View all invoices'); ?>
            </a>
        </p>
        <?php htm_Card_end(); ?>
    </div>
    
    <div style="flex: 1; min-width: 250px;">
        <?php htm_Card_('@Quick Actions'); ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="invoice_edit.php?id=0" style="display:flex; align-items:center; gap:10px; padding: 12px; background:#f9f9f9; text-decoration:none; color:#333; border-radius:4px; border-left:4px solid #2ecc71;">
                <i class="fa fa-plus-circle" style="color:#2ecc71;"></i> <?php echo lang('@New Invoice'); ?>
            </a>
            <a href="product_edit.php?id=0" style="display:flex; align-items:center; gap:10px; padding: 12px; background:#f9f9f9; text-decoration:none; color:#333; border-radius:4px; border-left:4px solid #3498db;">
                <i class="fa fa-box-open" style="color:#3498db;"></i> <?php echo lang('@Add New Product'); ?>
            </a>
            <a href="customer_edit.php?id=0" style="display:flex; align-items:center; gap:10px; padding: 12px; background:#f9f9f9; text-decoration:none; color:#333; border-radius:4px; border-left:4px solid #9b59b6;">
                <i class="fa fa-user-plus" style="color:#9b59b6;"></i> <?php echo lang('@Add New Customer'); ?>
            </a>
        </div>
        <?php htm_Card_end(); ?>
    </div>
</div>

<?php htm_Footer(); ?>