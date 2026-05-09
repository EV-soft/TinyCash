<?php # /vat_report.php v:0.9.1 d:2026-05-07 i:evs
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

htm_Header('@VAT Report');
showMenu(); 

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Denne query bruger vat_rate direkte fra accounts-tabellen
$sql = "SELECT 
            a.vat_code as vat_id, 
            a.vat_rate, 
            SUM(l.amount) as net_amount
        FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        JOIN accounts a ON l.acc_id = a.acc_id
        WHERE YEAR(j.jou_date) = $year
        GROUP BY a.vat_code, a.vat_rate";

$res = mysqli_query($conn, $sql);

htm_Card_('@VAT Report' . " - " . $year, 800);

if (mysqli_num_rows($res) == 0) {
    echo "<p style='padding:20px; color:#666;'>" . lang("@No data found for this period") . "</p>";
} else {
?>
<table style="width:100%; border-collapse:collapse; margin-top:20px;">
    <thead>
        <tr style="background:#2c3e50; color:white;">
            <th style="padding:12px; text-align:left;"><?php echo lang('@VAT Code'); ?></th>
            <th style="padding:12px; text-align:right;"><?php echo lang('@Net Amount'); ?></th>
            <th style="padding:12px; text-align:right;"><?php echo lang('@VAT Rate'); ?></th>
            <th style="padding:12px; text-align:right;"><?php echo lang('@Calculated VAT'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php 
        while($row = mysqli_fetch_assoc($res)): 
            $vat_rate = $row['vat_rate'] ?? 0;
            $vat_amount = $row['net_amount'] * ($vat_rate / 100);
        ?>
        <tr style="border-bottom:1px solid #eee;">
            <td style="padding:10px;"><?php echo htmlspecialchars($row['vat_id'] ?? '-'); ?></td>
            <td style="padding:10px; text-align:right;"><?php echo number_format($row['net_amount'], 2, ',', '.'); ?></td>
            <td style="padding:10px; text-align:right;"><?php echo number_format($vat_rate, 0); ?>%</td>
            <td style="padding:10px; text-align:right; font-weight:bold;"><?php echo number_format($vat_amount, 2, ',', '.'); ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<?php 
}
htm_Card_end();
htm_Footer();
ob_end_flush();
?>