<?php # index.page.php v:0.8.0 d:2026-04-10 i:evs m:1
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

htm_Header(lang('@Overview'));
showMenu();

// 1. FETCH DASHBOARD DATA
$current_year = date('Y');

// A: Revenue this year
$rev_res = mysqli_query($conn, "SELECT SUM(quantity * price_each) FROM invoice_lines 
                                INNER JOIN invoices ON invoice_lines.inv_id = invoices.inv_id 
                                WHERE YEAR(invoices.inv_date) = '$current_year'");
$revenue = mysqli_fetch_column($rev_res) ?? 0;

// B: Count of outstanding invoices
$open_res = mysqli_query($conn, "SELECT COUNT(*) FROM invoices WHERE inv_status = 'sent'");
$count_open = mysqli_fetch_column($open_res) ?? 0;

// C: Products below minimum stock
$stock_res = mysqli_query($conn, "SELECT COUNT(*) FROM products WHERE prod_stock <= prod_min_stock");
$count_low_stock = mysqli_fetch_column($stock_res) ?? 0;

// D: Total customers
$cust_res = mysqli_query($conn, "SELECT COUNT(*) FROM customers");
$count_customers = mysqli_fetch_column($cust_res) ?? 0;

?>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div style="background: #2ecc71; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Revenue This Year'); ?></small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;"><?php echo number_format($revenue, 2, ',', '.'); ?> kr.</div>
    </div>
    <div style="background: #3498db; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Outstanding Invoices'); ?></small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;"><?php echo $count_open; ?> stk.</div>
    </div>
    <div style="background: <?php echo ($count_low_stock > 0 ? '#e67e22' : '#95a5a6'); ?>; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@In stock'); ?> (<?php echo lang('@Warning'); ?>)</small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;"><?php echo $count_low_stock; ?> varer</div>
    </div>
    <div style="background: #9b59b6; color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <small style="opacity: 0.9; text-transform: uppercase; font-size: 0.75em; font-weight: bold;"><?php echo lang('@Total Customers'); ?></small>
        <div style="font-size: 1.8em; font-weight: bold; margin-top: 5px;"><?php echo $count_customers; ?></div>
    </div>
</div>
<div style="display: flex; gap: 20px; flex-wrap: wrap;">
    <div style="flex: 2; min-width: 300px;">
        <?php htm_Card_(lang('@Latest Invoices')); ?>
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
            
            // Vi beregner total_amount dynamisk, da kolonnen ikke findes i 'invoices' tabellen
            $sql = "SELECT i.inv_id, i.invoice_no, c.cust_name, 
                    (SELECT SUM(quantity * price_each * (1 + vat_rate / 100)) 
                     FROM invoice_lines 
                     WHERE inv_id = i.inv_id) AS calculated_total
                    FROM invoices i 
                    JOIN customers c ON i.cust_id = c.cust_id 
                    ORDER BY i.inv_id DESC LIMIT 5";

            $latest = mysqli_query($conn, $sql);

            if (!$latest) {
                echo "<tr><td colspan='3'>SQL Error: " . mysqli_error($conn) . "</td></tr>";
            } else {
                while($l = mysqli_fetch_assoc($latest)) {
                    echo "<tr style='border-bottom: 1px solid #f9f9f9;'>";
                    echo "<td style='padding: 10px;'><a href='invoice_view.page.php?id={$l['inv_id']}'>#{$l['invoice_no']}</a></td>";
                    echo "<td style='padding: 10px;'>{$l['cust_name']}</td>";
                    echo "<td style='padding: 10px; text-align: right;'>" . number_format($l['calculated_total'], 2, ',', '.') . " kr.</td>";
                    echo "</tr>";
                }
            }
            ?>
            </tbody>
        </table>
        <p style="margin-top: 15px; font-size: 0.85em;">
            <a href="invoices.page.php" style="color: #3498db; text-decoration: none; font-weight: bold;">
                <i class="fa fa-arrow-right"></i> <?php echo lang('@View all invoices'); ?>
            </a>
        </p>
        <?php htm_Card_end(); ?>
    </div>
    
    <div style="flex: 1; min-width: 250px;">
        <?php htm_Card_(lang('@Quick Actions')); ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="invoice_create.page.php" style="display:flex; align-items:center; gap:10px; padding: 12px; background:#f9f9f9; text-decoration:none; color:#333; border-radius:4px; border-left:4px solid #2ecc71;">
                <i class="fa fa-plus-circle" style="color:#2ecc71;"></i> <?php echo lang('@New Invoice'); ?>
            </a>
            <a href="product_new.page.php" style="display:flex; align-items:center; gap:10px; padding: 12px; background:#f9f9f9; text-decoration:none; color:#333; border-radius:4px; border-left:4px solid #3498db;">
                <i class="fa fa-box-open" style="color:#3498db;"></i> <?php echo lang('@Add New Product'); ?>
            </a>
            <a href="customer_create.page.php" style="display:flex; align-items:center; gap:10px; padding: 12px; background:#f9f9f9; text-decoration:none; color:#333; border-radius:4px; border-left:4px solid #9b59b6;">
                <i class="fa fa-user-plus" style="color:#9b59b6;"></i> <?php echo lang('@Add New Customer'); ?>
            </a>
        </div>
        <?php htm_Card_end(); ?>
    </div>
</div>

<?php htm_Footer(); ?>