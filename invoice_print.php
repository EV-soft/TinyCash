<?php # invoice_print.page.php v:0.8.1 d:2026-04-12 i:Gemini m:1
ob_start(); 
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// Hent ID
$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($inv_id === 0) { 
    die("Fejl: Intet ID angivet."); 
}

// 1. Hent firma-data (get_settings skal returnere indstillinger fra databasen)
$f = get_settings($conn);

// 2. Hent faktura og kunde (Vi bruger join for at få alt i ét kald)
$sql = "SELECT i.*, c.cust_name, c.cust_address, c.cust_cvr 
        FROM invoices i 
        JOIN customers c ON i.cust_id = c.cust_id 
        WHERE i.inv_id = $inv_id";
$inv_res = mysqli_query($conn, $sql);
$inv = mysqli_fetch_assoc($inv_res);

// HER VAR FEJLEN FØR - NU RETTET:
if (!$inv) { 
    die(lang('@Fejl: Faktura ikke fundet.')); 
}

// 3. Hent linjer med deres respektive momssatser via accounts/vat_codes join
$sql_lines = "SELECT l.*, v.vat_rate 
              FROM invoice_lines l
              LEFT JOIN accounts a ON l.acc_id = a.acc_id
              LEFT JOIN vat_codes v ON a.vat_code = v.vat_id
              WHERE l.inv_id = $inv_id 
              ORDER BY l.line_id ASC";

$line_res = mysqli_query($conn, $sql_lines);
$total_netto = 0;
$vat_summary = []; // Til at opsummere f.eks. 25% moms og 15% moms hver for sig
$lines = [];

while($l = mysqli_fetch_assoc($line_res)) {
    $lines[] = $l;
    $ln = (float)$l['price_each'] * (float)$l['quantity'];
    $total_netto += $ln;
    
    // Brug fundet vat_rate, ellers fallback til 25
    $v_rate = (isset($l['vat_rate'])) ? (float)$l['vat_rate'] : 25;
    $v_amount = $ln * ($v_rate / 100);
    
    // Gem til total moms og til specifik oversigt per sats
    if(!isset($vat_summary[$v_rate])) $vat_summary[$v_rate] = 0;
    $vat_summary[$v_rate] += $v_amount;
}

$total_moms = array_sum($vat_summary);
$total_brutto = $total_netto + $total_moms;

?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Faktura #<?php echo $inv['invoice_no']; ?></title>
    <style>
        body { font-family: sans-serif; font-size: 14px; color: #333; margin: 0; padding: 20px; background: #fff; }
        .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; position: relative; }
        .top-table { width: 100%; line-height: 1.6; text-align: left; border-collapse: collapse; margin-bottom: 40px; }
        .line-table { width: 100%; text-align: left; border-collapse: collapse; margin-top: 20px; }
        .line-table th { background: #f8f9fa; padding: 10px; border-bottom: 2px solid #ddd; }
        .line-table td { padding: 10px; border-bottom: 1px solid #eee; }
        .totals { float: right; width: 250px; margin-top: 20px; }
        .footer { margin-top: 100px; font-size: 11px; border-top: 1px solid #eee; padding-top: 10px; color: #777; }
        .logo { max-height: 70px; max-width: 250px; }
        @media print { .no-print { display: none; } .invoice-box { border: none; padding: 0; } }
    </style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:20px;">
    <button onclick="window.print()" style="padding:10px 20px; cursor:pointer; background:#27ae60; color:white; border:none; border-radius:4px; font-weight:bold;">🖨 Print Faktura</button>
    <a href="invoice_view.page.php?id=<?php echo $inv_id; ?>" style="margin-left: 10px; color: #666; text-decoration: none;">Tilbage</a>
</div>

<div class="invoice-box">
    <table class="line-table">
    <thead>
        <tr>
            <th><?php echo lang('@Description'); ?></th>
            <th style="text-align:center; width:60px;"><?php echo lang('@VAT'); ?></th> <th style="text-align:center;"><?php echo lang('@Qty'); ?></th>
            <th style="text-align:right;"><?php echo lang('@Price'); ?></th>
            <th style="text-align:right;"><?php echo lang('@Total'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($lines as $l): 
            $rate = (isset($l['vat_rate'])) ? (int)$l['vat_rate'] : 25;
        ?>
        <tr>
            <td><?php echo htmlspecialchars($l['line_text']); ?></td>
            <td style="text-align:center; color:#777; font-size:0.9em;"><?php echo $rate; ?>%</td> <td style="text-align:center;"><?php echo number_format($l['quantity'], 2, ',', '.'); ?></td>
            <td style="text-align:right;"><?php echo number_format($l['price_each'], 2, ',', '.'); ?></td>
            <td style="text-align:right;"><?php echo number_format($l['price_each'] * $l['quantity'], 2, ',', '.'); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
    <table class="top-table">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div style="background: #fcfcfc; padding: 15px; border: 1px solid #f0f0f0; border-radius: 4px;">
                    <strong><?php echo lang('@Bill To'); ?>:</strong><br>
                    <?php echo htmlspecialchars($inv['cust_name']); ?><br>
                    <?php echo nl2br(htmlspecialchars($inv['cust_address'])); ?><br>
                    <?php if(!empty($inv['cust_cvr'])) echo "CVR: " . htmlspecialchars($inv['cust_cvr']); ?>
                </div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                <table style="width: 100%;">
                    <tr><td style="text-align: right;"><strong><?php echo lang('@Date'); ?>:</strong></td><td style="text-align: right; width: 100px;"><?php echo date('d-m-Y', strtotime($inv['inv_date'])); ?></td></tr>
                    <tr><td style="text-align: right;"><strong><?php echo lang('@Due Date'); ?>:</strong></td><td style="text-align: right;"><?php echo date('d-m-Y', strtotime($inv['inv_due_date'])); ?></td></tr>
                </table>
            </td>
        </tr>
    </table>


<div class="totals">
    <table style="width: 100%;">
        <tr>
            <td><?php echo lang('@Subtotal'); ?>:</td>
            <td style="text-align:right;"><?php echo number_format($total_netto, 2, ',', '.'); ?></td>
        </tr>
        
        <?php foreach($vat_summary as $rate => $amount): ?>
        <tr>
            <td><?php echo lang('@VAT'); ?> (<?php echo $rate; ?>%):</td>
            <td style="text-align:right;"><?php echo number_format($amount, 2, ',', '.'); ?></td>
        </tr>
        <?php endforeach; ?>

        <tr style="font-weight:bold; font-size: 1.2em;">
            <td style="padding-top:5px; border-top:1px solid #333;"><?php echo lang('@Total'); ?>:</td>
            <td style="text-align:right; padding-top:5px; border-top:1px solid #333;">
                <?php echo ($f['currency'] ?? 'DKK'); ?> <?php echo number_format($total_brutto, 2, ',', '.'); ?>
            </td>
        </tr>
    </table>
</div>

    <div style="clear: both;"></div>

    <div class="footer">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 33%;">
                    <strong><?php echo lang('@Bank'); ?>:</strong><br>
                    <?php echo htmlspecialchars($f['bank_name'] ?? ''); ?><br>
                    Reg: <?php echo htmlspecialchars($f['bank_reg'] ?? ''); ?> Konto: <?php echo htmlspecialchars($f['bank_account'] ?? ''); ?>
                </td>
                <td style="width: 33%; text-align: center;">
                    <strong><?php echo lang('@Contact'); ?>:</strong><br>
                    <?php echo htmlspecialchars($f['company_email'] ?? ''); ?><br>
                    <?php echo htmlspecialchars($f['company_website'] ?? ''); ?>
                </td>
                <td style="width: 33%; text-align: right;">
                    <strong><?php echo lang('@Information'); ?>:</strong><br>
                    <?php echo nl2br(htmlspecialchars($f['invoice_footer'] ?? '')); ?>
                </td>
            </tr>
        </table>
    </div>
</div>

</body>
</html>