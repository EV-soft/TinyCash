<?php # /report_income.php v:1.3.1 d:2026-09-15 i:evs
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

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfrit årstal-
// filter, kun til at kunne sammenligne mod et budget for ET bestemt år
// (account_budgets, db-setup/migrate_account_budgets.php - ét årligt beløb
// pr. konto, se budget_edit.php). Standard er stadig HELE bogføringen
// (ingen filter, uændret fra før), præcis som banneret nedenfor allerede
// forklarer - budget-sammenligningen vises kun når et konkret årstal vælges.
$year_filter = (isset($_GET['year']) && trim($_GET['year']) !== '') ? (int)$_GET['year'] : null;
$date_sql = $year_filter ? "j.jou_date BETWEEN '" . $year_filter . "-01-01' AND '" . $year_filter . "-12-31'" : '1=1';
// RETTET: årsafslutningens lukkepostering (dateret på årets sidste dag) nulstiller netop indtægts- og
// udgiftskontiene - talte den med, viste et LUKKET år omsætning 0 og udgifter 0 (og "hele perioden" kun
// det åbne år). Samme udelukkelse som trial_balance.php og inc/annual_report.lib.php.
$date_sql .= " AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')";

$has_budget_table = false;
if (DB::is_sqlite()) {
    $btchk = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='account_budgets'");
    $has_budget_table = ($btchk && DB::fetch_assoc($btchk));
} else {
    $btchk = DB::query($conn, "SHOW TABLES LIKE 'account_budgets'");
    $has_budget_table = ($btchk && DB::num_rows($btchk) > 0);
}
$budgets = [];
if ($has_budget_table && $year_filter) {
    $bud_res = DB::query($conn, "SELECT acc_id, budget_amount FROM account_budgets WHERE year = $year_filter");
    while ($b = DB::fetch_assoc($bud_res)) { $budgets[(int)$b['acc_id']] = (float)$b['budget_amount']; }
}
$show_budget_cols = $has_budget_table && $year_filter && !empty($budgets);

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
    WHERE a.acc_type = 'income' AND $date_sql");
$revenue = (float)(DB::fetch_assoc($res_sales)['total'] ?? 0);

// Udgifter pr. konto: udgiftskonti er DEBET (positivt) ved en normal postering.
$res_costs = DB::query($conn, "
    SELECT a.acc_id, a.acc_name, SUM(l.amount) AS cat_total
    FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    JOIN accounts a ON l.acc_id = a.acc_id
    WHERE a.acc_type = 'expense' AND $date_sql
    GROUP BY a.acc_id, a.acc_name
    HAVING SUM(l.amount) != 0
    ORDER BY a.acc_id ASC");

// Moms: de to specifikke, konfigurerede momskonti - IKKE en generisk
// 'vat'-gruppe, da den dækker både salgs- og købsmoms under ét.
$res_sales_vat = DB::query($conn, "
    SELECT SUM(-l.amount) AS total FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat) . ") AND $date_sql");
$sales_vat = (float)(DB::fetch_assoc($res_sales_vat)['total'] ?? 0);

$res_purchase_vat = DB::query($conn, "
    SELECT SUM(l.amount) AS total FROM ledger l
    JOIN journal j ON l.jou_id = j.jou_id
    WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_purchase_vat) . ") AND $date_sql");
$purchase_vat = (float)(DB::fetch_assoc($res_purchase_vat)['total'] ?? 0);

$vat_to_pay = $sales_vat - $purchase_vat;
$total_costs = 0;

// Samlet omsætnings-budget - budgettet gemmes pr. konto, men omsætningen
// vises herover kun som ÉT samlet tal, ikke pr. konto - summerer derfor
// budgettet for alle indtægtskonti til samme sammenligningsniveau.
$revenue_budget = 0.0;
if ($show_budget_cols) {
    $income_acc_res = DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_type = 'income'");
    while ($ia = DB::fetch_assoc($income_acc_res)) {
        if (isset($budgets[(int)$ia['acc_id']])) $revenue_budget += $budgets[(int)$ia['acc_id']];
    }
}

echo "<div style='max-width:900px; margin:0 auto;'>";

    echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:15px;">';
    echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Year') . '</label>';
    echo '<input type="number" name="year" value="' . htmlspecialchars((string)($_GET['year'] ?? '')) . '" placeholder="' . lang('@All Time') . '" style="width:110px; padding:6px;"></div>';
    htm_Button(labl: '@Show', type: 'primary', attr: 'data-hint="'.lang('@Show the income statement for this year, or leave empty for all-time').'"');
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 25):
    // denne side bygger selv sin tabel i hånden (ikke htm_Table), som ellers
    // ville have givet en gratis $expo-CSV-knap - se report_income_export.php,
    // der genberegner PRÆCIS samme forespørgsler ud fra samme ?year=.
    htm_Button(icon: 'fa-file-csv', labl: '@Download CSV', type: 'success',
        link: 'report_income_export.php' . ($year_filter ? '?year=' . $year_filter : ''),
        attr: 'data-hint="'.lang('@Download this income statement as CSV').'" style="margin-left:8px;"');
    if ($year_filter) {
        echo ' <a href="report_income.php" style="align-self:center; margin-left:8px; font-size:0.85em;">' . lang('@Clear (show all-time)') . '</a>';
        echo ' <a href="budget_edit.php?year=' . $year_filter . '" style="align-self:center; margin-left:8px; font-size:0.85em;">' . lang('@Edit Budgets for this Year') . '</a>';
    }
    echo '</form>';

    if (!$year_filter) {
        htm_Banner('@This report sums the entire bookkeeping (all dates). For a specific fiscal year, use Close Fiscal Year, which lets you calculate a period-limited result.', 'warning');
    } elseif ($has_budget_table && !$show_budget_cols) {
        htm_Banner(sprintf(lang('@No budget has been set for %d yet - showing actual figures only. Set one on Account Budgets.'), $year_filter), 'info');
    }

    // --- CARD 1: RESULTATOPGØRELSE ---
    htm_Card_(lang('@Official Income Statement') . ($year_filter ? ' - ' . $year_filter : ''), '600');
    ?>
    <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
        <tr style="background:#f8f9fa; font-weight:bold; border-bottom: 2px solid #dee2e6;">
            <td style="padding:15px; text-transform: uppercase;"><?php echo lang('@REVENUE'); ?></td>
            <td style="padding:15px; text-align:right; color:#27ae60;">
                <?php echo number_format($revenue, 2, ',', '.') . " " . $currency; ?>
            </td>
            <?php if ($show_budget_cols): $rev_diff = $revenue - $revenue_budget; ?>
            <td style="padding:15px; text-align:right; color:var(--text-muted);"><?php echo $revenue_budget > 0 ? number_format($revenue_budget, 2, ',', '.') : '—'; ?></td>
            <td style="padding:15px; text-align:right; font-weight:bold; color:<?php echo $rev_diff >= 0 ? '#27ae60' : '#e74c3c'; ?>;"><?php echo ($rev_diff >= 0 ? '+' : '') . number_format($rev_diff, 2, ',', '.'); ?></td>
            <?php endif; ?>
        </tr>

        <tr style="height:10px;"><td colspan="<?php echo $show_budget_cols ? 4 : 2; ?>"></td></tr>

        <tr style="background:#f8f9fa; font-weight:bold; border-bottom: 2px solid #dee2e6;">
            <td style="padding:15px; text-transform: uppercase;"><?php echo lang('@COSTS'); ?></td>
            <td style="padding:15px; text-align:right;"><?php echo lang('@AMOUNT'); ?></td>
            <?php if ($show_budget_cols): ?>
            <td style="padding:15px; text-align:right;"><?php echo lang('@Budget'); ?></td>
            <td style="padding:15px; text-align:right;"><?php echo lang('@Variance'); ?></td>
            <?php endif; ?>
        </tr>

        <?php
        if ($res_costs && DB::num_rows($res_costs) > 0):
            while ($cost = DB::fetch_assoc($res_costs)):
                $total_costs += (float)$cost['cat_total'];
                $cost_budget = $budgets[(int)$cost['acc_id']] ?? null;
                // Afvigelse for en UDGIFT: et FALDENDE forbrug ift. budgettet er
                // godt (grønt), modsat omsætning ovenfor - fortegnet vendes
                // derfor bevidst her ift. omsætningens $rev_diff.
                $cost_diff = $cost_budget !== null ? $cost_budget - (float)$cost['cat_total'] : null;
                ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:12px 15px;">
                        <span style="color:#95a5a6; font-size:0.8em; margin-right: 10px;"><?php echo $cost['acc_id']; ?></span>
                        <?php echo htmlspecialchars($cost['acc_name']); ?>
                    </td>
                    <td style="padding:12px 15px; text-align:right; color:#e74c3c;">
                        - <?php echo number_format($cost['cat_total'], 2, ',', '.'); ?>
                    </td>
                    <?php if ($show_budget_cols): ?>
                    <td style="padding:12px 15px; text-align:right; color:var(--text-muted);"><?php echo $cost_budget !== null ? number_format($cost_budget, 2, ',', '.') : '—'; ?></td>
                    <td style="padding:12px 15px; text-align:right; font-weight:bold; color:<?php echo $cost_diff === null ? 'var(--text-muted)' : ($cost_diff >= 0 ? '#27ae60' : '#e74c3c'); ?>;">
                        <?php echo $cost_diff !== null ? ($cost_diff >= 0 ? '+' : '') . number_format($cost_diff, 2, ',', '.') : '—'; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endwhile;
        endif; ?>

        <tr style="background:#2c3e50; color:white; font-weight:bold; font-size:1.2em;">
            <td style="padding:20px;"><?php echo lang('@ANNUAL RESULT'); ?></td>
            <td style="padding:20px; text-align:right; color:<?php echo ($revenue - $total_costs >= 0) ? '#2ecc71' : '#ff7675'; ?>;">
                <?php echo number_format($revenue - $total_costs, 2, ',', '.') . " " . $currency; ?>
            </td>
            <?php if ($show_budget_cols): ?><td colspan="2"></td><?php endif; ?>
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
