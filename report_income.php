<?php # /report_income.php v:1.3.0 d:2026-08-30 i:evs
# RETTET 2026-08-15: rapporten hentede FØR omsætning direkte fra
# invoices/invoice_lines (status='paid') og udgifter fra den afkoblede
# transactions-tabel, som expense_edit.php (det rigtige udgiftsflow) aldrig
# skriver til - tallene afspejlede derfor IKKE den faktiske bogføring
# (journal+ledger). Henter nu alt fra ledger/journal, ligesom resten af
# systemets rapporter og posteringer. Se regnskabsloven-analysen 2026-08-15.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header('@Income Statement');
showMenu();

// 1. Hent valuta fra indstillinger
$s = get_settings($conn);
$currency = $s['currency'] ?? 'DKK';

// Konfigurerbare moms-konti (samme som resten af posteringsflowet) - bruges
// til at vise salgs-/købsmoms hver for sig, uden at forudsætte faste ID'er.
$acc_vat          = (int)($s['conf_acc_vat'] ?? 6900);          // udgående moms
$acc_purchase_vat = (int)($s['conf_acc_purchase_vat'] ?? 6910); // indgående moms

// --- 2. DATA FRA DEN RIGTIGE BOGFØRING (journal + ledger) ---
// Annullerede posteringer (is_cancelled) og deres modposteringer indgår
// begge - de balancerer hinanden ud automatisk, ligesom year_end_close.php.

// Omsætning: indtægtskonti er KREDIT (negativt) ved en normal salgspostering,
// så beløbet negeres for at vise et positivt tal.
$res_sales = DB::query($conn, "
    SELECT SUM(-l.amount) AS total
    FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    JOIN accounts a ON l.acc_id = a.acc_id
    WHERE a.acc_type = 'income'");
$revenue = (float)(DB::fetch_assoc($res_sales)['total'] ?? 0);

// Udgifter pr. konto: udgiftskonti er DEBET (positivt) ved en normal postering.
$res_costs = DB::query($conn, "
    SELECT a.acc_id, a.acc_name, SUM(l.amount) AS cat_total
    FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    JOIN accounts a ON l.acc_id = a.acc_id
    WHERE a.acc_type = 'expense'
    GROUP BY a.acc_id, a.acc_name
    HAVING SUM(l.amount) != 0
    ORDER BY a.acc_id ASC");

// Moms: de to specifikke, konfigurerede momskonti - IKKE en generisk
// 'vat'-gruppe, da den dækker både salgs- og købsmoms under ét.
$res_sales_vat = DB::query($conn, "
    SELECT SUM(-l.amount) AS total FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    WHERE l.acc_id = $acc_vat");
$sales_vat = (float)(DB::fetch_assoc($res_sales_vat)['total'] ?? 0);

$res_purchase_vat = DB::query($conn, "
    SELECT SUM(l.amount) AS total FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    WHERE l.acc_id = $acc_purchase_vat");
$purchase_vat = (float)(DB::fetch_assoc($res_purchase_vat)['total'] ?? 0);

$vat_to_pay = $sales_vat - $purchase_vat;
$total_costs = 0;

echo "<div style='max-width:900px; margin:0 auto;'>";

    htm_Banner('@This report sums the entire bookkeeping (all dates). For a specific fiscal year, use Close Fiscal Year, which lets you calculate a period-limited result.', 'warning');

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
        if ($res_costs && DB::num_rows($res_costs) > 0):
            while ($cost = DB::fetch_assoc($res_costs)):
                $total_costs += (float)$cost['cat_total']; ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:12px 15px;">
                        <span style="color:#95a5a6; font-size:0.8em; margin-right: 10px;"><?php echo $cost['acc_id']; ?></span>
                        <?php echo htmlspecialchars($cost['acc_name']); ?>
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
