<?php # index.page.php
require 'auth.inc.php';       
require 'db_connect.inc.php'; 
require 'php2htm.lib.php';  
require 'menu.inc.php';


// --- HENT DYNAMISKE DATA ---
$current_year = date('Y');

// 1. Omsætning i år (sum af price_each * quantity)
$res_rev = mysqli_query($conn, "SELECT SUM(il.price_each * il.quantity) as total 
                                FROM invoice_lines il 
                                JOIN invoices i ON il.inv_id = i.inv_id 
                                WHERE YEAR(i.inv_date) = '$current_year'");
$row_rev = mysqli_fetch_assoc($res_rev);
$revenue = number_format($row_rev['total'] ?? 0, 2, ',', '.');

// 2. Udestående (alt der ikke er 'paid')
$res_out = mysqli_query($conn, "SELECT SUM(il.price_each * il.quantity) as total 
                                FROM invoice_lines il 
                                JOIN invoices i ON il.inv_id = i.inv_id 
                                WHERE i.inv_status != 'paid'");
$row_out = mysqli_fetch_assoc($res_out);
$outstanding = number_format($row_out['total'] ?? 0, 2, ',', '.');

// 3. Antal kunder totalt
$res_cust = mysqli_query($conn, "SELECT COUNT(*) as total FROM customers");
$row_cust = mysqli_fetch_assoc($res_cust);
$total_customers = $row_cust['total'] ?? 0;

// 4. Seneste 5 fakturaer med beregnet total
$latest_invoices = mysqli_query($conn, "SELECT i.*, c.cust_name, 
                                        (SELECT SUM(price_each * quantity) FROM invoice_lines WHERE inv_id = i.inv_id) as total_amount
                                        FROM invoices i 
                                        LEFT JOIN customers c ON i.cust_id = c.cust_id 
                                        ORDER BY i.inv_date DESC LIMIT 5");

htm_Header(lang('@Overview'));
showMenu();
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
    
    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #3498db;">
        <h4 style="margin: 0; color: #7f8c8d; font-size: 0.9em; text-transform: uppercase;"><?php echo lang('@Revenue This Year'); ?></h4>
        <p style="margin: 10px 0 0; font-size: 1.8em; font-weight: bold; color: #2c3e50;">kr. <?php echo $revenue; ?></p>
    </div>

    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #e74c3c;">
        <h4 style="margin: 0; color: #7f8c8d; font-size: 0.9em; text-transform: uppercase;"><?php echo lang('@Outstanding Invoices'); ?></h4>
        <p style="margin: 10px 0 0; font-size: 1.8em; font-weight: bold; color: #c0392b;">kr. <?php echo $outstanding; ?></p>
    </div>

    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #2ecc71;">
        <h4 style="margin: 0; color: #7f8c8d; font-size: 0.9em; text-transform: uppercase;"><?php echo lang('@Total Customers'); ?></h4>
        <p style="margin: 10px 0 0; font-size: 1.8em; font-weight: bold; color: #27ae60;"><?php echo $total_customers; ?></p>
    </div>

</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
    
    <div>
        <?php htm_Card_(lang('@Latest Invoices')); ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #eee;">
                    <th style="padding: 10px;"><?php echo lang('@Date'); ?></th>
                    <th style="padding: 10px;"><?php echo lang('@Customer'); ?></th>
                    <th style="padding: 10px; text-align: right;"><?php echo lang('@Amount'); ?></th>
                    <th style="padding: 10px; text-align: center;"><?php echo lang('@Status'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php while($inv = mysqli_fetch_assoc($latest_invoices)): ?>
                <tr style="border-bottom: 1px solid #f9f9f9;">
                    <td style="padding: 10px;"><?php echo date('d-m-Y', strtotime($inv['inv_date'])); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($inv['cust_name'] ?? 'System'); ?></td>
                    <td style="padding: 10px; text-align: right;"><?php echo number_format($inv['total_amount'] ?? 0, 2, ',', '.'); ?></td>
                    <td style="padding: 10px; text-align: center;">
                        <span style="background: <?php echo ($inv['inv_status'] == 'paid' ? '#d4edda' : '#f8d7da'); ?>; 
                                     color: <?php echo ($inv['inv_status'] == 'paid' ? '#155724' : '#721c24'); ?>; 
                                     padding: 3px 8px; border-radius: 10px; font-size: 0.8em;">
                            <?php echo lang('@' . ucfirst($inv['inv_status'])); ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php htm_Card_end(); ?>
    </div>

    <div>
        <?php htm_Card_(lang('@Quick Actions')); ?>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <li style="margin-bottom: 10px;">
                <a href="faktura_opret.page.php" style="display: block; background: #f8f9fa; padding: 10px; border-radius: 4px; text-decoration: none; color: #333; border: 1px solid #eee;">➕ <?php echo lang('@Create New Invoice'); ?></a>
            </li>
            <li style="margin-bottom: 10px;">
                <a href="postering.page.php" style="display: block; background: #f8f9fa; padding: 10px; border-radius: 4px; text-decoration: none; color: #333; border: 1px solid #eee;">✍️ <?php echo lang('@Register Expense'); ?></a>
            </li>
        </ul>
        <?php htm_Card_end(); ?>
    </div>

</div>

<?php htm_Footer(); ?>