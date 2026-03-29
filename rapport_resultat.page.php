<?php # rapport_resultat.page.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

htm_Header(lang('@Income Statement'));
showMenu();

// --- 1. DATA BEREGNING ---

// Hent Omsætning (fra betalte fakturaer)
$res_sales = mysqli_query($conn, "SELECT SUM(il.quantity * il.price_each) as total 
                                 FROM invoice_lines il 
                                 JOIN invoices i ON il.inv_id = i.inv_id 
                                 WHERE i.inv_status = 'paid'");
$row_sales = mysqli_fetch_assoc($res_sales);
$revenue = $row_sales['total'] ?? 0;

// Hent Udgifter grupperet efter Standard-kategori (std_ref_id)
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

// Hent Købsmoms direkte fra transaktionstabellen
$res_purchase_vat = mysqli_query($conn, "SELECT SUM(vat_amount) as vat FROM transactions");
$purchase_vat = mysqli_fetch_assoc($res_purchase_vat)['vat'] ?? 0;

// Samlet moms-beregning (VIGTIGT: Variabelnavn rettet til vat_to_pay)
$vat_to_pay = $sales_vat - $purchase_vat;

$total_costs = 0; 
?>

<div style="max-width:900px; margin:20px auto; font-family:sans-serif; color:#2c3e50;">
    
    <h2 style="border-bottom:3px solid #3498db; padding-bottom:10px;">📊 Officiel Resultatopgørelse</h2>

    <table style="width:100%; border-collapse:collapse; background:white; margin-top:20px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
        <tr style="background:#f1f2f6; font-weight:bold;">
            <td style="padding:15px;">OMSÆTNING</td>
            <td style="padding:15px; text-align:right; color:#27ae60;"><?php echo number_format($revenue, 2, ',', '.'); ?> kr.</td>
        </tr>
        
        <tr style="height:20px;"><td colspan="2"></td></tr>

        <tr style="background:#f1f2f6; font-weight:bold;">
            <td style="padding:15px;">OMKOSTNINGER (Standardkategorier)</td>
            <td style="padding:15px; text-align:right;">BELØB</td>
        </tr>

        <?php 
        if($res_costs && mysqli_num_rows($res_costs) > 0): 
            while($cost = mysqli_fetch_assoc($res_costs)): 
                $total_costs += $cost['cat_total']; ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:12px 15px 12px 30px;">
                        <span style="color:#95a5a6; font-size:0.8em;"><?php echo $cost['std_id']; ?></span> 
                        <?php echo htmlspecialchars($cost['std_name']); ?>
                    </td>
                    <td style="padding:12px 15px; text-align:right; color:#e74c3c;">
                        - <?php echo number_format($cost['cat_total'], 2, ',', '.'); ?>
                    </td>
                </tr>
            <?php endwhile; 
        endif; ?>

        <tr style="background:#2c3e50; color:white; font-weight:bold; font-size:1.2em;">
            <td style="padding:20px;">ÅRETS RESULTAT (Før skat)</td>
            <td style="padding:20px; text-align:right; color:<?php echo ($revenue - $total_costs >= 0) ? '#2ecc71' : '#ff7675'; ?>;">
                <?php echo number_format($revenue - $total_costs, 2, ',', '.'); ?> kr.
            </td>
        </tr>
    </table>

    <div style="margin-top:40px; background:#fdfdfd; padding:25px; border-radius:8px; border:1px solid #dcdde1; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
        <h3 style="margin-top:0; color:#2f3640; border-bottom:1px solid #eee; padding-bottom:10px;">📉 Præcis Momsopgørelse</h3>
        
        <div style="display:flex; justify-content: space-between; margin-bottom:10px;">
            <span>Skyldig Salgsmoms (fra fakturaer):</span>
            <span style="font-weight:bold; color:#e74c3c;"><?php echo number_format($sales_vat, 2, ',', '.'); ?> kr.</span>
        </div>

        <div style="display:flex; justify-content: space-between; margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid #eee;">
            <span>Fradragsberettiget Købsmoms (fra bogføring):</span>
            <span style="font-weight:bold; color:#27ae60;">- <?php echo number_format($purchase_vat, 2, ',', '.'); ?> kr.</span>
        </div>

        <div style="display:flex; justify-content: space-between; font-size:1.3em; font-weight:bold;">
            <span><?php echo ($vat_to_pay >= 0) ? 'Moms at betale:' : 'Moms til gode:'; ?></span>
            <span style="color:<?php echo ($vat_to_pay >= 0) ? '#e67e22' : '#27ae60'; ?>;">
                <?php echo number_format(abs($vat_to_pay), 2, ',', '.'); ?> kr.
            </span>
        </div>
    </div>

</div>

<?php htm_Footer(); ?>