<?php # /balance_sheet.php v:1.3.0 d:2026-08-30 i:evs
# Ny side - fandtes ikke i systemet før (§regnskabslov-status, fase 2 trin 1).
# Statusopgørelse (Aktiver/Passiver) efter ÅRL skema 1 - forenklet til det
# kontoplanen understøtter. Beregningen ligger i inc/annual_report.lib.php
# (delt med annual_report.php) - se den fil for fortegnskonventionen.
# v1.1.0: beregningslogik udtrukket til inc/annual_report.lib.php (DRY, deles
# nu med annual_report.php i stedet for at være duplikeret).
# v1.2.0: tilføjet samme "udkast, ikke indberetningsklar"-advarsel som
# annual_report.php fik - denne side viser samme type data (bruger-anmodet)
# v1.2.1: begrænset til user_level 3 (bruger-anmodet) - $rLev skal sættes FØR
# auth.inc.php inkluderes (se inc/auth.inc.php linje ~97).
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/annual_report.lib.php';

$s = get_settings($conn);
$currency = $s['currency'] ?? 'DKK';

$as_of = $_GET['as_of'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of)) $as_of = date('Y-m-d');

$bs = arl_balance_sheet($conn, $as_of);

htm_Header(lang('@Balance Sheet'));
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<form method="get" style="margin-bottom:16px; display:flex; align-items:center; gap:10px;">';
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@Status as of') . ':</label>';
echo '<input type="date" name="as_of" value="' . htmlspecialchars($as_of) . '" onchange="this.form.submit()" style="padding:6px; border:1px solid var(--border-color); border-radius:4px;">';
echo '</form>';

// Samme forbehold som annual_report.php (§regnskabslov-status): dette er en
// intern statusopgørelse beregnet direkte fra bogføringen - ikke en officielt
// indberettet balance. Genbruger samme oversættelsesnøgle for konsistens.
echo '<div style="margin-bottom:16px; padding:10px 14px; background:#fbeaea; border-left:4px solid #c0392b; border-radius:4px; color:#c0392b; font-size:0.85em; font-weight:bold;">'
   . lang('@DRAFT - not suitable for direct filing with the Danish Business Authority. Requires digital XBRL submission via virk.dk or an accountant.')
   . '</div>';

if (abs($bs['diff']) > 0.01) {
    htm_Alert(sprintf(lang('@Balance does not match: Assets and Liabilities+Equity differ by %s %s. This may indicate a posting outside the normal flows.'), number_format($bs['diff'], 2, ',', '.'), $currency), 'error');
} else {
    htm_Alert(lang('@Assets equal Liabilities + Equity. The balance is correct.'), 'success');
}

echo "<div style='display:grid; grid-template-columns: 1fr 1fr; gap:20px;'>";

    // ===== AKTIVER =====
    htm_Card_(lang('@Assets'), '480');
    $rows = [];
    foreach ($bs['asset_rows'] as $r) {
        $rows[] = [$r['acc_id'] . ' - ' . htmlspecialchars($r['acc_name']), '<div style="text-align:right;">' . number_format($r['balance'], 2, ',', '.') . '</div>'];
    }
    if ($bs['vat_is_asset'] && $bs['vat_net'] != 0) {
        $rows[] = [lang('@VAT receivable'), '<div style="text-align:right;">' . number_format($bs['vat_net'], 2, ',', '.') . '</div>'];
    }
    if (empty($rows)) {
        echo '<p style="color:var(--text-muted); font-style:italic;">' . lang('@No asset postings found.') . '</p>';
    } else {
        htm_Table(['@Account', '@Balance'], $rows, 'assets_tbl', 100);
    }
    echo '<div style="display:flex; justify-content:space-between; padding:14px 0; margin-top:8px; border-top:2px solid var(--color-primary); font-weight:bold;">';
    echo '<span>' . lang('@Total Assets') . '</span><span>' . number_format($bs['assets_total'], 2, ',', '.') . ' ' . $currency . '</span>';
    echo '</div>';
    htm_Card_end();

    // ===== PASSIVER =====
    htm_Card_(lang('@Liabilities and Equity'), '480');

    echo '<h4 style="margin:0 0 8px; font-size:0.95em; color:var(--text-muted);">' . lang('@Equity') . '</h4>';
    $rows = [];
    foreach ($bs['equity_rows'] as $r) {
        $rows[] = [$r['acc_id'] . ' - ' . htmlspecialchars($r['acc_name']), '<div style="text-align:right;">' . number_format(-$r['balance'], 2, ',', '.') . '</div>'];
    }
    $rows[] = ['<em>' . lang('@Result for the year (not yet closed)') . '</em>', '<div style="text-align:right;">' . number_format($bs['unclosed_result'], 2, ',', '.') . '</div>'];
    htm_Table(['@Account', '@Balance'], $rows, 'equity_tbl', 100);

    echo '<h4 style="margin:16px 0 8px; font-size:0.95em; color:var(--text-muted);">' . lang('@Liabilities') . '</h4>';
    $rows = [];
    foreach ($bs['liability_rows'] as $r) {
        $rows[] = [$r['acc_id'] . ' - ' . htmlspecialchars($r['acc_name']), '<div style="text-align:right;">' . number_format(-$r['balance'], 2, ',', '.') . '</div>'];
    }
    if (!$bs['vat_is_asset'] && $bs['vat_net'] != 0) {
        $rows[] = [lang('@VAT payable'), '<div style="text-align:right;">' . number_format(-$bs['vat_net'], 2, ',', '.') . '</div>'];
    }
    if (empty($rows)) {
        echo '<p style="color:var(--text-muted); font-style:italic;">' . lang('@No liability postings found.') . '</p>';
    } else {
        htm_Table(['@Account', '@Balance'], $rows, 'liab_tbl', 100);
    }

    echo '<div style="display:flex; justify-content:space-between; padding:14px 0; margin-top:8px; border-top:2px solid var(--color-primary); font-weight:bold;">';
    echo '<span>' . lang('@Total Liabilities and Equity') . '</span><span>' . number_format($bs['passives_total'], 2, ',', '.') . ' ' . $currency . '</span>';
    echo '</div>';
    htm_Card_end();

echo "</div>"; // grid
echo "</div>"; // container

htm_Footer();
?>
