<?php # /invoice_view.page.php v:0.8.0 d:2026-04-11 i:evs m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Hent indstillinger
$f = [];
$res_set = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while($s_row = mysqli_fetch_assoc($res_set)) {
    $f[$s_row['setting_key']] = $s_row['setting_value'];
}

// Hent faktura- og kundedata
$sql = "SELECT i.*, c.cust_name, c.cust_address, c.cust_cvr 
        FROM invoices i 
        JOIN customers c ON i.cust_id = c.cust_id 
        WHERE i.inv_id = $inv_id";
$res = mysqli_query($conn, $sql);
$inv = mysqli_fetch_assoc($res);

if (!$inv) {
    htm_Header(lang('@Error'));
    showMenu();
    echo "<div style='text-align:center; padding:50px;'><h2>❌ " . lang('@Invoice not found') . "</h2></div>";
    htm_Footer();
    exit;
}

htm_Header(lang('@View Invoice') . " #" . $inv['invoice_no']);
showMenu();
?>

<div style="max-width: 850px; margin: 20px auto; background: white; padding: 40px; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); font-family: sans-serif;">
    
    <div style="display: flex; justify-content: space-between; border-bottom: 2px solid #f4f4f4; padding-bottom: 20px; margin-bottom: 30px; align-items: flex-start;">
        <div>
            <span style="font-size: 28px; font-weight: bold; color: #2c3e50;"><?php echo strtoupper(lang('@INVOICE')); ?></span><br>
            <small style="color: #7f8c8d;">#<?php echo $inv['invoice_no']; ?></small>
            <div style="margin-top: 25px;">
                <div style="background: #fcfcfc; padding: 15px; border: 1px solid #f0f0f0; border-radius: 4px; min-width: 250px;">
                    <strong><?php echo lang('@Bill To'); ?>:</strong><br>
                    <strong><?php echo htmlspecialchars($inv['cust_name']); ?></strong><br>
                    <?php echo nl2br(htmlspecialchars($inv['cust_address'])); ?><br>
                    <?php if(!empty($inv['cust_cvr'])) echo "CVR: " . htmlspecialchars($inv['cust_cvr']); ?>
                </div>
            </div>
        </div>
        <div style="text-align: right;">
            <?php if (!empty($f['logo_url'])): ?>
                <img src="<?php echo htmlspecialchars($f['logo_url']); ?>" style="max-height: 70px; max-width: 250px; margin-bottom: 10px;" alt="Logo"><br>
            <?php endif; ?>
            <strong><?php echo htmlspecialchars($f['company_name'] ?? 'System'); ?></strong><br>
            <span style="font-size: 0.9em; color: #666;">
                <?php echo nl2br(htmlspecialchars($f['company_address'] ?? '')); ?><br>
                CVR: <?php echo htmlspecialchars($f['company_cvr'] ?? ''); ?>
            </span>
            <table style="margin-top: 20px; width: 100%; font-size: 0.9em;">
                <tr><td style="text-align: right;"><strong><?php echo lang('@Date'); ?>:</strong></td><td style="text-align: right; width: 90px;"><?php echo date('d-m-Y', strtotime($inv['inv_date'])); ?></td></tr>
                <tr><td style="text-align: right;"><strong><?php echo lang('@Due Date'); ?>:</strong></td><td style="text-align: right;"><?php echo date('d-m-Y', strtotime($inv['inv_due_date'])); ?></td></tr>
            </table>
        </div>
    </div>

    <table style="width:100%; border-collapse: collapse; margin-bottom: 30px; margin-top: 20px; table-layout: fixed;">
        <thead>
            <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                <th style="padding:12px; text-align:left; width: 45%;"><?php echo lang('@Description'); ?></th>
                <th style="padding:12px; text-align:center; width: 10%;"><?php echo lang('@VAT'); ?></th>
                <th style="padding:12px; text-align:right; width: 10%;"><?php echo lang('@Qty'); ?></th>
                <th style="padding:12px; text-align:right; width: 15%;"><?php echo lang('@Price'); ?></th>
                <th style="padding:12px; text-align:right; width: 20%;"><?php echo lang('@Total'); ?></th>
            </tr>
        </thead>
        <tbody>
    <?php 
    $total_netto = 0; 
    $total_moms = 0;

    // Vi sikrer os at vi henter data
    $lines_sql = "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC";
    $lines_res = mysqli_query($conn, $lines_sql);

    if ($lines_res) {
        while ($l = mysqli_fetch_assoc($lines_res)) {
            // Tving værdier til tal for at undgå beregningsfejl
            $qty = (float)($l['quantity'] ?? 0);
            $price = (float)($l['price_each'] ?? 0);
            $subtotal = $qty * $price;
            
            // Hvis line_vat_rate er tom/NULL, kig i kontoplanen som backup
            $v_rate = (isset($l['line_vat_rate']) && $l['line_vat_rate'] !== null) 
                      ? (float)$l['line_vat_rate'] 
                      : 25.0; 

            $line_vat = $subtotal * ($v_rate / 100);
            
            $total_netto += $subtotal;
            $total_moms += $line_vat;
    ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding:12px;"><?php echo htmlspecialchars($l['line_text'] ?? 'Vare'); ?></td>
                <td style="text-align:center;"><?php echo (int)$v_rate; ?>%</td>
                <td style="text-align:right;"><?php echo number_format($qty, 2, ',', '.'); ?></td>
                <td style="text-align:right;"><?php echo number_format($price, 2, ',', '.'); ?></td>
                <td style="text-align:right; font-weight:bold;"><?php echo number_format($subtotal, 2, ',', '.'); ?></td>
            </tr>
    <?php 
        } 
    } else {
        echo "<tr><td colspan='5'>Ingen linjer fundet eller fejl i SQL: " . mysqli_error($conn) . "</td></tr>";
    }
    ?>
</tbody>
    </table>

    <div style="width: 300px; margin-left: auto; background: #fafafa; padding: 15px; border-radius: 4px; border: 1px solid #f0f0f0; margin-bottom: 40px;">
        <div style="display:flex; justify-content: space-between; margin-bottom: 5px;">
            <span><?php echo lang('@Subtotal'); ?>:</span>
            <span><?php echo number_format($total_netto, 2, ',', '.'); ?> kr.</span>
        </div>
        <div style="display:flex; justify-content: space-between; margin-bottom: 5px;">
            <span><?php echo lang('@VAT'); ?>:</span>
            <span><?php echo number_format($total_moms, 2, ',', '.'); ?> kr.</span>
        </div>
        <div style="display:flex; justify-content: space-between; border-top: 2px solid #333; padding-top: 10px; font-weight: bold; font-size: 1.2em; color: #2c3e50;">
            <span><?php echo lang('@Total'); ?>:</span>
            <span><?php echo number_format($total_netto + $total_moms, 2, ',', '.'); ?> kr.</span>
        </div>
    </div>

    <div style="display: flex; gap: 10px; border-top: 1px solid #eee; padding-top: 20px;">
        <a href="invoice_lines_edit.page.php?id=<?php echo $inv_id; ?>" style="background:#3498db; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold;">✏️ <?php echo lang('@Edit Lines'); ?></a>
        <a href="invoice_print.php?id=<?php echo $inv_id; ?>" target="_blank" style="background:#2ecc71; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold;">🖨️ <?php echo lang('@Print / PDF'); ?></a>
        <a href="invoices.page.php" style="margin-left:auto; color:#95a5a6; text-decoration:none; padding:10px;"><?php echo lang('@Back to List'); ?></a>
    </div> 
</div>

<?php
htm_Footer();
ob_end_flush();
?>