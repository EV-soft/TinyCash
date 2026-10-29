<?php # /trial_balance.php v:1.3.1 d:2026-09-15 i:evs
# Saldobalance: én linje pr. konto med primo (saldo før perioden), periodens
# debet/kredit-omsætning og ultimo (saldo til og med periodens slutdato) -
# det første en revisor typisk beder om ved en gennemgang. Dækker ALLE
# kontotyper (i modsætning til balance_sheet.php/report_income.php, som hver
# kun viser deres egen ÅRL-klassificerede delmængde) - en saldobalance er
# type-agnostisk og viser hele kontoplanen på én gang.
#
# Fortegnskonvention (samme som resten af systemet, jf. inc/annual_report.lib.php):
# DEBET = positivt ledger.amount, KREDIT = negativt. Debet-/kredit-kolonnerne
# viser periodens BRUTTO-omsætning (summen af hhv. positive og negative
# linjer hver for sig), ikke bare nettoet - det er selve pointen med en
# saldobalance frem for et enkelt saldotal.
#
# BEVIDST ingen filtrering på j.is_cancelled: en annulleret postering og dens
# modpostering skal begge tælles med, så de netter hinanden ud til 0 (samme
# regel som annual_report.lib.php - se dens header for fuld begrundelse).
#
# Periodens debet/kredit-kolonner udelukker year_end_close-lukkeposteringen
# (samme fix som arl_income_statement() - uden det ville en periode der
# rammer en årsafslutnings-dato se sin egen indtægt/udgift blive nulstillet
# af sin egen lukkepostering). Primo/ultimo (kumulative saldi) udelukker den
# IKKE - de skal vise den faktiske saldo, inkl. lukningens effekt, samme
# princip som arl_balance_sheet().
# Ingen $rLev sat - samme adgangsniveau som ledger_view.php/report_income.php
# (samme datatype: en read-only rapport over den eksisterende bogføring).
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s = get_settings($conn);
$currency = $s['currency'] ?? 'DKK';

// Periode - default hele indeværende kalenderår til dags dato, samme
// simple standard som resten af systemets ad-hoc-rapporter (report_income.php
// viser fx "hele bogføringen" som sin egen standard).
$default_from = date('Y-01-01');
$default_to   = date('Y-m-d');
$from = $_GET['from'] ?? $default_from;
$to   = $_GET['to']   ?? $default_to;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $default_to;

htm_Header('@Trial Balance');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<form method="get" style="margin-bottom:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">';
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@From') . ':</label>';
echo '<input type="date" name="from" value="' . htmlspecialchars($from) . '" style="padding:6px; border:1px solid var(--border-color); border-radius:4px;">';
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@To') . ':</label>';
echo '<input type="date" name="to" value="' . htmlspecialchars($to) . '" style="padding:6px; border:1px solid var(--border-color); border-radius:4px;">';
htm_Button('fa-filter', '@Filter', 'primary', '', '', 'type="submit"');
echo '</form>';

$ps = DB::escape($conn, $from);
$pe = DB::escape($conn, $to);

// RETTET: "JOIN accounts" skjulte posteringer på et kontonummer der ikke findes i kontoplanen - saldobalancen
// balancerede så ikke, uden at vise hvorfor. Nu LEFT JOIN: kontoen vises, markeret "findes ikke".
$sql = "SELECT l.acc_id, COALESCE(a.acc_name, '" . DB::escape($conn, '⚠ ' . lang('@not in the chart of accounts')) . "') AS acc_name, a.acc_type,
        SUM(CASE WHEN j.jou_date < '$ps' THEN l.amount ELSE 0 END) AS primo,
        SUM(CASE WHEN j.jou_date BETWEEN '$ps' AND '$pe'
                 AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
                 AND l.amount > 0 THEN l.amount ELSE 0 END) AS debit,
        SUM(CASE WHEN j.jou_date BETWEEN '$ps' AND '$pe'
                 AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
                 AND l.amount < 0 THEN -l.amount ELSE 0 END) AS credit,
        SUM(CASE WHEN j.jou_date <= '$pe' THEN l.amount ELSE 0 END) AS ultimo
        FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        LEFT JOIN accounts a ON l.acc_id = a.acc_id
        GROUP BY l.acc_id, a.acc_name, a.acc_type
        HAVING primo != 0 OR debit != 0 OR credit != 0 OR ultimo != 0
        ORDER BY l.acc_id ASC";
$res = DB::query($conn, $sql);

$rows = [];
$tot_primo = $tot_debit = $tot_credit = $tot_ultimo = 0.0;
if ($res) {
    while ($r = DB::fetch_assoc($res)) {
        $rows[] = $r;
        $tot_primo  += (float)$r['primo'];
        $tot_debit  += (float)$r['debit'];
        $tot_credit += (float)$r['credit'];
        $tot_ultimo += (float)$r['ultimo'];
    }
}

// Integritetstjek: i et rent dobbelt-bogholderi summer primo og ultimo altid
// til 0 på tværs af ALLE konti (hver postering balancerer i sig selv), og
// periodens samlede debet = samlede kredit. Et afvig her er et reelt signal
// om en postering lavet uden om de normale flows (fx direkte SQL).
$is_balanced = (abs($tot_primo) < 0.01) && (abs($tot_ultimo) < 0.01) && (abs($tot_debit - $tot_credit) < 0.01);
if ($is_balanced) {
    htm_Alert(lang('@The trial balance balances correctly: opening and closing balances net to zero, and debit equals credit for the period.'), 'success');
} else {
    htm_Alert(lang('@The trial balance does not balance. This may indicate a posting outside the normal flows.'), 'error');
}

htm_Card_('@Trial Balance', 1000);

if (empty($rows)) {
    htm_Alert(lang('@No account activity found for this period.'), 'info');
} else {
    $headers = ['@No.', '@Account Name', '@Opening Balance', '@Debit', '@Credit', '@Closing Balance'];
    $data = [];
    foreach ($rows as $r) {
        $card_link = 'account_card.php?acc_id=' . (int)$r['acc_id'] . '&from=' . urlencode($from) . '&to=' . urlencode($to);
        $data[] = [
            $r['acc_id'],
            '<a href="' . htmlspecialchars($card_link) . '" data-hint="' . htmlspecialchars(lang('@View Account Card')) . '">' . htmlspecialchars($r['acc_name']) . '</a>',
            '<div style="text-align:right;">' . number_format((float)$r['primo'], 2, ',', '.') . '</div>',
            '<div style="text-align:right; color:green;">' . number_format((float)$r['debit'], 2, ',', '.') . '</div>',
            '<div style="text-align:right; color:red;">' . number_format((float)$r['credit'], 2, ',', '.') . '</div>',
            '<div style="text-align:right; font-weight:bold;">' . number_format((float)$r['ultimo'], 2, ',', '.') . '</div>',
        ];
    }
    htm_Table($headers, $data, 'trial_balance_tbl', 200, '', true, [], '600px', 'saldobalance.csv');

    echo '<div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin-top:15px; padding-top:12px; border-top:2px solid var(--color-primary); font-weight:bold;">';
    echo '<div>' . lang('@Total') . ' ' . lang('@Opening Balance') . '<br>' . number_format($tot_primo, 2, ',', '.') . ' ' . $currency . '</div>';
    echo '<div>' . lang('@Total') . ' ' . lang('@Debit') . '<br><span style="color:green;">' . number_format($tot_debit, 2, ',', '.') . ' ' . $currency . '</span></div>';
    echo '<div>' . lang('@Total') . ' ' . lang('@Credit') . '<br><span style="color:red;">' . number_format($tot_credit, 2, ',', '.') . ' ' . $currency . '</span></div>';
    echo '<div>' . lang('@Total') . ' ' . lang('@Closing Balance') . '<br>' . number_format($tot_ultimo, 2, ',', '.') . ' ' . $currency . '</div>';
    echo '</div>';
}

htm_Card_end();
echo "</div>";
htm_Footer();
