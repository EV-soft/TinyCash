<?php # faktura_print.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($inv_id === 0) { die("Fejl: Intet ID."); }

// 1. Hent firma-data fra din JSON-undermappe
$settings_file = __DIR__ . '/json-data/stamdata.json';
$f = [];
if (file_exists($settings_file)) {
    $f = json_decode(file_get_contents($settings_file), true);
}

// 2. Hent faktura og kunde (Rettet jf. din Blueprint)
$sql = "SELECT i.*, c.* FROM invoices i 
        JOIN customers c ON i.cust_id = c.cust_id 
        WHERE i.inv_id = $inv_id";
$inv_res = mysqli_query($conn, $sql);
$inv = mysqli_fetch_assoc($inv_res);

// 3. Beregn totaler fra varelinjer
$line_res = mysqli_query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id");
$total_netto = 0;
$lines = [];
while($l = mysqli_fetch_assoc($line_res)) {
    $lines[] = $l;
    $total_netto += ($l['price_each'] * $l['quantity']);
}
$moms = $total_netto * 0.25;
$total_brutto = $total_netto + $moms;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Faktura #<?php echo $inv['invoice_no']; ?></title>
    <style>
        body { font-family: sans-serif; font-size: 14px; color: #333; }
        .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; }
        .top-table { width: 100%; line-height: inherit; text-align: left; margin-bottom: 40px; }
        .line-table { width: 100%; text-align: left; border-collapse: collapse; }
        .line-table th { background: #eee; padding: 5px; border-bottom: 1px solid #ddd; }
        .line-table td { padding: 5px; border-bottom: 1px solid #eee; }
        .totals { float: right; width: 250px; margin-top: 20px; }
        .footer { margin-top: 100px; font-size: 11px; border-top: 1px solid #eee; padding-top: 10px; }
        @media print { .no-print { display: none; } .invoice-box { border: none; } }
    </style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:20px;">
    <button onclick="window.print()" style="padding:10px; cursor:pointer;">Print Faktura</button>
</div>

<div class="invoice-box">
    <table class="top-table">
        <tr>
            <td style="font-size: 25px; font-weight: bold; color: #3498db;">
                <?php echo lang('@INVOICE'); ?>
            </td>
            <td style="text-align: right;">
                <strong><?php echo $f['firmanavn']; ?></strong><br>
                <?php echo nl2br($f['adresse']); ?><br>
                CVR: <?php echo $f['cvr']; ?>
            </td>
        </tr>
    </table>

    <table class="top-table">
        <tr>
            <td>
                <strong><?php echo lang('@Bill To'); ?>:</strong><br>
                <?php echo $inv['cust_name']; ?><br>
                <?php echo nl2br($inv['cust_address']); ?>
            </td>
            <td style="text-align: right;">
                <?php echo lang('@Invoice No.'); ?>: <?php echo $inv['invoice_no']; ?><br>
                <?php echo lang('@Date'); ?>: <?php echo date('d-m-Y', strtotime($inv['inv_date'])); ?><br>
                <strong><?php echo lang('@Due Date'); ?>: <?php echo date('d-m-Y', strtotime($inv['inv_due_date'])); ?></strong>
            </td>
        </tr>
    </table>

    <table class="line-table">
        <thead>
            <tr>
                <th><?php echo lang('@Description'); ?></th>
                <th><?php echo lang('@Qty'); ?></th>
                <th><?php echo lang('@Price'); ?></th>
                <th style="text-align: right;"><?php echo lang('@Total'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($lines as $l): ?>
            <tr>
                <td><?php echo $l['line_text']; ?></td>
                <td><?php echo number_format($l['quantity'], 0); ?></td>
                <td><?php echo number_format($l['price_each'], 2, ',', '.'); ?></td>
                <td style="text-align: right;"><?php echo number_format($l['price_each'] * $l['quantity'], 2, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <table style="width: 100%;">
            <tr>
                <td>Subtotal:</td>
                <td style="text-align: right;"><?php echo number_format($total_netto, 2, ',', '.'); ?></td>
            </tr>
            <tr>
                <td>Moms (25%):</td>
                <td style="text-align: right;"><?php echo number_format($moms, 2, ',', '.'); ?></td>
            </tr>
            <tr style="font-weight: bold; font-size: 1.2em;">
                <td>Total:</td>
                <td style="text-align: right;">kr. <?php echo number_format($total_brutto, 2, ',', '.'); ?></td>
            </tr>
        </table>
    </div>

    <div style="clear: both;"></div>

    <div class="footer">
        <table style="width: 100%;">
            <tr>
                <td><strong>Bank:</strong> <?php echo $f['bank']; ?></td>
                <td><strong>Reg:</strong> <?php echo $f['reg_nr']; ?></td>
                <td><strong>Konto:</strong> <?php echo $f['konto_nr']; ?></td>
                <td style="text-align: right;"><strong>Email:</strong> <?php echo $f['email']; ?></td>
            </tr>
        </table>
    </div>
</div>

</body>
</html>