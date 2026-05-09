<?php # /report_income.php v:0.8.1 d:2026-04-11 i:evs m:1
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

htm_Header('@Income Statement');
showMenu();

// 1. Hent valuta fra indstillinger (lodret tabel struktur)
$sql = "SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1";
$res_settings = mysqli_query($conn, $sql);
$currency = 'DKK'; // Standard fallback
if ($res_settings && mysqli_num_rows($res_settings) > 0) {
    $row = mysqli_fetch_assoc($res_settings);
    $currency = $row['setting_value'];
}

// --- 2. DATA BEREGNING ---
// Hent Omsætning
$res_sales = mysqli_query($conn, "SELECT SUM(il.quantity * il.price_each) as total 
                                  FROM invoice_lines il 
                                  JOIN invoices i ON il.inv_id = i.inv_id 
                                  WHERE i.inv_status = 'paid'");
$row_sales = mysqli_fetch_assoc($res_sales);
$revenue = $row_sales['total'] ?? 0;

// Hent Udgifter
$sql_costs = "SELECT s.std_name, s.std_id, SUM(t.amount) as cat_total 
              FROM transactions t
              JOIN accounts a ON t.acc_id = a.acc_id
              JOIN std_accounts s ON a.std_ref_id = s.std_id
              GROUP BY s.std_id 
              ORDER BY s.std_id ASC";
$res_costs = mysqli_query($conn, $sql_costs);

// Hent Salgsmoms
$sql_sales_vat = "SELECT SUM((il.quantity * il.price_each) * (a.vat_rate / 100)) as total_vat 
                  FROM invoice_lines il 
                  JOIN invoices i ON il.inv_id = i.inv_id 
                  JOIN accounts a ON il.acc_id = a.acc_id 
                  WHERE i.inv_status = 'paid'";
$res_sales_vat = mysqli_query($conn, $sql_sales_vat);
$sales_vat = mysqli_fetch_assoc($res_sales_vat)['total_vat'] ?? 0;

// Hent Købsmoms
$res_purchase_vat = mysqli_query($conn, "SELECT SUM(vat_amount) as vat FROM transactions");
$purchase_vat = mysqli_fetch_assoc($res_purchase_vat)['vat'] ?? 0;

$vat_to_pay = $sales_vat - $purchase_vat;
$total_costs = 0; 

echo "<div style='max-width:900px; margin:0 auto;'>";

    // --- CARD 1: RESULTATOPGØRELSE ---
    htm_Card_(lang('@Official Income Statement'), '600');
    ?>
    <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
        <tr style="background:#f8f9fa; font-weight:bold; border-bottom: 2px solid #dee2e6;">
            <td style="padding:15px; text-transform: uppercase;"><?php echo lang('@REVENUE'); ?></td>
            <td style="padding:15px; text-align:right; color:#27ae60;">
                <?php echo number_format($revenue, 2, ',', '.') . " " . $currency; ?>
            </td>
        </tr>

        <tr style="height:10px;"><td colspan="2"></td></tr>

        <tr style="background:#f8f9fa; font-weight:bold; border-bottom: 2px solid #dee2e6;">
            <td style="padding:15px; text-transform: uppercase;"><?php echo lang('@COSTS'); ?></td>
            <td style="padding:15px; text-align:right;"><?php echo lang('@AMOUNT'); ?></td>
        </tr>

        <?php 
        if($res_costs && mysqli_num_rows($res_costs) > 0): 
            while($cost = mysqli_fetch_assoc($res_costs)): 
                $total_costs += $cost['cat_total']; ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:12px 15px;">
                        <span style="color:#95a5a6; font-size:0.8em; margin-right: 10px;"><?php echo $cost['std_id']; ?></span> 
                        <?php echo htmlspecialchars($cost['std_name']); ?>
                    </td>
                    <td style="padding:12px 15px; text-align:right; color:#e74c3c;">
                        - <?php echo number_format($cost['cat_total'], 2, ',', '.'); ?>
                    </td>
                </tr>
            <?php endwhile; 
        endif; ?>

        <tr style="background:#2c3e50; color:white; font-weight:bold; font-size:1.2em;">
            <td style="padding:20px;"><?php echo lang('@ANNUAL RESULT'); ?></td>
            <td style="padding:20px; text-align:right; color:<?php echo ($revenue - $total_costs >= 0) ? '#2ecc71' : '#ff7675'; ?>;">
                <?php echo number_format($revenue - $total_costs, 2, ',', '.') . " " . $currency; ?>
            </td>
        </tr>
    </table>
    <?php htm_Card_end(); ?>

    <div style="margin-top: 20px;"></div>

    <?php // --- CARD 2: MOMSOPGØRELSE --- ?>
    <?php htm_Card_('@Precise VAT Statement', '600'); ?>
    <div style="font-family:sans-serif; line-height: 1.8;">
        <div style="display:flex; justify-content: space-between; margin-bottom:10px;">
            <span><?php echo lang('@Sales VAT Due (from invoices)'); ?>:</span>
            <span style="font-weight:bold; color:#e74c3c;">
                <?php echo number_format($sales_vat, 2, ',', '.') . " " . $currency; ?>
            </span>
        </div>

        <div style="display:flex; justify-content: space-between; margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid #eee;">
            <span><?php echo lang('@Deductible Purchase VAT (from bookkeeping)'); ?>:</span>
            <span style="font-weight:bold; color:#27ae60;">
                - <?php echo number_format($purchase_vat, 2, ',', '.') . " " . $currency; ?>
            </span>
        </div>

        <div style="display:flex; justify-content: space-between; font-size:1.3em; font-weight:bold; background: #fdfdfd; padding: 10px; border-radius: 4px;">
            <span><?php echo ($vat_to_pay >= 0) ? lang('@VAT to pay:') : lang('@VAT to receive:'); ?></span>
            <span style="color:<?php echo ($vat_to_pay >= 0) ? '#e67e22' : '#27ae60'; ?>;">
                <?php echo number_format(abs($vat_to_pay), 2, ',', '.') . " " . $currency; ?>
            </span>
        </div>
    </div>
    <?php htm_Card_end(); ?>

</div>

<?php htm_Footer(); ?>