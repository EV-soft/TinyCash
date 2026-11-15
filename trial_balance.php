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
$default_from = tc_fiscal_year_bounds($conn)[0];      // (regnskabsårets start - ikke altid 1. januar)
$default_to   = date('Y-m-d');
$from = $_GET['from'] ?? $default_from;
$to   = $_GET['to']   ?? $default_to;
if (!tc_is_date((string)$from)) $from = $default_from;      // (tc_is_date: "2026-02-31" bestod formattjekket)
if (!tc_is_date((string)$to))   $to   = $default_to;
if ($from > $to) [$from, $to] = [$to, $from];      // (en omvendt periode viste saldo primo fra slutdatoen og ingen bevægelser)

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
// Indtægts- og udgiftskonti nulstilles ved hvert regnskabsår: deres primo regnes fra starten af det regnskabsår $from
// ligger i, og ultimo fra starten af det år $to ligger i - som kontokortet. Før tog de alle tidligere års bevægelser
// med, når et år ikke var afsluttet. Tidligere års resultat (ikke afsluttet) står i stedet på sin egen linje, så
// kolonnerne stadig går i nul.
$fy_from = DB::escape($conn, tc_fiscal_year_bounds($conn, $from)[0]);
$fy_to   = DB::escape($conn, tc_fiscal_year_bounds($conn, $to)[0]);
$tb_pl   = "a.acc_type IN ('income', 'expense')";
$tb_bal  = "COALESCE(a.acc_type, '') NOT IN ('income', 'expense')";
$sql = "SELECT l.acc_id, COALESCE(a.acc_name, '" . DB::escape($conn, '⚠ ' . lang('@not in the chart of accounts')) . "') AS acc_name, a.acc_type,
        SUM(CASE WHEN ($tb_bal AND j.jou_date < '$ps') OR ($tb_pl AND j.jou_date >= '$fy_from' AND j.jou_date < '$ps') THEN l.amount ELSE 0 END) AS primo,
        SUM(CASE WHEN j.jou_date BETWEEN '$ps' AND '$pe'
                 AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
                 AND l.amount > 0 THEN l.amount ELSE 0 END) AS debit,
        SUM(CASE WHEN j.jou_date BETWEEN '$ps' AND '$pe'
                 AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
                 AND l.amount < 0 THEN -l.amount ELSE 0 END) AS credit,
        SUM(CASE WHEN ($tb_bal AND j.jou_date <= '$pe') OR ($tb_pl AND j.jou_date >= '$fy_to' AND j.jou_date <= '$pe') THEN l.amount ELSE 0 END) AS ultimo
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
    while ($r = DB::fetch_assoc($res)) $rows[] = $r;
    // Resultatet af tidligere regnskabsår der ikke er afsluttet (efter en årsafslutning er det 0 og vises ikke)
    $tb_prev = DB::fetch_assoc(DB::query($conn, "SELECT SUM(CASE WHEN j.jou_date < '$fy_from' THEN l.amount ELSE 0 END) AS primo,
            SUM(CASE WHEN j.jou_date < '$fy_to' THEN l.amount ELSE 0 END) AS ultimo
        FROM ledger l JOIN journal j ON l.jou_id = j.jou_id JOIN accounts a ON l.acc_id = a.acc_id WHERE $tb_pl AND j.jou_date < '$fy_to'"));
    if (abs((float)($tb_prev['primo'] ?? 0)) >= 0.005 || abs((float)($tb_prev['ultimo'] ?? 0)) >= 0.005) {
        $rows[] = ['acc_id' => '', 'acc_name' => lang('@Result of earlier fiscal years (not closed)'), 'acc_type' => 'prev_result',
                   'primo' => (float)$tb_prev['primo'], 'debit' => 0, 'credit' => 0, 'ultimo' => (float)$tb_prev['ultimo']];
    }
    foreach ($rows as $r) {
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
// (< 0.01 godtog en difference på præcis 1 øre - flydende tal: 0,29 - 0,28 = 0,00999...)
$is_balanced = (abs(round($tot_primo, 2)) < 0.005) && (abs(round($tot_ultimo, 2)) < 0.005) && (abs(round($tot_debit - $tot_credit, 2)) < 0.005);
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
        $name_cell = ($r['acc_type'] ?? '') === 'prev_result'
            ? '<a href="year_end_close.php" data-hint="' . htmlspecialchars(lang('@The income and expense accounts of an earlier fiscal year have not been closed - close the year to move the result to equity')) . '"><em>' . htmlspecialchars($r['acc_name']) . '</em></a>'
            : '<a href="' . htmlspecialchars($card_link) . '" data-hint="' . htmlspecialchars(lang('@View Account Card')) . '">' . htmlspecialchars($r['acc_name']) . '</a>';
        $data[] = [
            $r['acc_id'],
            $name_cell,
            '<div style="text-align:right;">' . fmt_dk((float)$r['primo']) . '</div>',
            '<div style="text-align:right; color:green;">' . fmt_dk((float)$r['debit']) . '</div>',
            '<div style="text-align:right; color:red;">' . fmt_dk((float)$r['credit']) . '</div>',
            '<div style="text-align:right; font-weight:bold;">' . fmt_dk((float)$r['ultimo']) . '</div>',
        ];
    }
    htm_Table($headers, $data, 'trial_balance_tbl', 200, '', true, [], '600px', 'saldobalance.csv');

    echo '<div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin-top:15px; padding-top:12px; border-top:2px solid var(--color-primary); font-weight:bold;">';
    echo '<div>' . lang('@Total') . ' ' . lang('@Opening Balance') . '<br>' . fmt_dk($tot_primo) . ' ' . $currency . '</div>';
    echo '<div>' . lang('@Total') . ' ' . lang('@Debit') . '<br><span style="color:green;">' . fmt_dk($tot_debit) . ' ' . $currency . '</span></div>';
    echo '<div>' . lang('@Total') . ' ' . lang('@Credit') . '<br><span style="color:red;">' . fmt_dk($tot_credit) . ' ' . $currency . '</span></div>';
    echo '<div>' . lang('@Total') . ' ' . lang('@Closing Balance') . '<br>' . fmt_dk($tot_ultimo) . ' ' . $currency . '</div>';
    echo '</div>';
}

htm_Card_end();
echo "</div>";
htm_Footer();
