<?php # /vat_reconciliation.php v:1.3.1 d:2026-09-15 i:evs
# Momsafstemning: sammenligner en bottom-up genberegning af moms (ud fra
# fakturalinjer/udgifter, samme formler som selve bogføringen bruger -
# invoice_dkk_totals()/expense_edit.php's gross-til-netto-udregning) mod de
# FAKTISK posterede beløb på momskontiene i hovedbogen for samme periode
# (samme konti/periode-forespørgsel som vat_report.php selv bruger).
#
# NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): der findes allerede
# en debitor-/kreditorafstemning (subledger_reconciliation.php), men INGEN
# tilsvarende afstemning for moms - vat_report.php viser i dag udelukkende
# det FAKTISK bogførte beløb (rettet i dens egen v1.2.0 til netop at undgå at
# stole på en genberegning), så en fejlagtig manuel postering direkte på en
# momskonto (uden om normal fakturering/udgiftsregistrering) ville aldrig
# blive opdaget af vat_report.php selv. Denne side er bevidst det MODSATTE
# perspektiv: den genberegner moms BOTTOM-UP og sammenligner mod det bogførte
# beløb, præcis den kontrol subledger_reconciliation.php allerede laver for
# debitorer/kreditorer - samme "afstemt/ikke afstemt"-visning og advarselstekst.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

require_dkk_base_currency($conn);

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$acc_vat_out = (int)($s['conf_acc_vat']          ?? 6900);
$acc_vat_in  = (int)($s['conf_acc_purchase_vat'] ?? 6910);

// --- Periodevalg - samme logik/valgmuligheder som vat_report.php, så de to
// rapporter altid kan vise "samme periode" side om side.
$year        = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$period_type = in_array($_GET['period_type'] ?? '', ['year', 'half', 'quarter', 'month'], true) ? $_GET['period_type'] : 'year';
$period_num  = isset($_GET['period_num']) ? (int)$_GET['period_num'] : 1;

switch ($period_type) {
    case 'month':
        $period_num   = max(1, min(12, $period_num));
        $period_start = sprintf('%04d-%02d-01', $year, $period_num);
        $period_end   = date('Y-m-t', strtotime($period_start));
        $period_label = date('F', mktime(0, 0, 0, $period_num, 1)) . ' ' . $year;
        break;
    case 'quarter':
        $period_num   = max(1, min(4, $period_num));
        $start_month  = ($period_num - 1) * 3 + 1;
        $period_start = sprintf('%04d-%02d-01', $year, $start_month);
        $period_end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $start_month + 2)));
        $period_label = lang('@Quarter') . ' ' . $period_num . ' ' . $year;
        break;
    case 'half':
        $period_num   = max(1, min(2, $period_num));
        $start_month  = ($period_num - 1) * 6 + 1;
        $period_start = sprintf('%04d-%02d-01', $year, $start_month);
        $period_end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $start_month + 5)));
        $period_label = lang('@Half-year') . ' ' . $period_num . ' ' . $year;
        break;
    case 'year':
    default:
        $period_type  = 'year';
        $period_start = "$year-01-01";
        $period_end   = "$year-12-31";
        $period_label = (string)$year;
        break;
}
$ps = DB::escape($conn, $period_start);
$pe = DB::escape($conn, $period_end);

// --- 1. FAKTISK BOGFØRT MOMS (samme forespørgsel som vat_report.php) ---
$sv_row = DB::fetch_assoc(DB::query($conn,
    "SELECT SUM(-l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
     WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat_out) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"));
$gl_sales_vat = (float)($sv_row['total'] ?? 0);

$pv_row = DB::fetch_assoc(DB::query($conn,
    "SELECT SUM(l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
     WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat_in) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"));
$gl_purchase_vat = (float)($pv_row['total'] ?? 0);

// --- 2. BOTTOM-UP GENBEREGNET MOMS - udgående: samme formel som
// invoice_dkk_totals() (inc/db_connect.inc.php), for FAKTISK bogførte
// fakturaer (alt der ikke længere er en kladde).
//
// SELV-FANGET UNDER LIVE-TEST: forsøgte oprindeligt at JOIN'e via
// invoices.jou_id = journal.jou_id for at bruge PRÆCIS samme periode-
// grundlag (journalens jou_date) som momskontoens saldo ovenfor - men
// invoices.jou_id viste sig at være NULL for samtlige fakturaer i den
// virkelige database. invoice_post_action.php opretter journal-rækken og
// bruger dens $jou_id til selve hovedbogsposteringen, men skriver den
// ALDRIG tilbage på invoices.jou_id - kolonnen findes i skemaet, men bruges
// reelt ingen steder. Et join herpå ville derfor ALTID give 0 rækker og
// en falsk "afvigelse" på hele det bogførte beløb, uanset om noget reelt er
// galt. Filtrerer i stedet på fakturaens EGEN inv_date, samme princip som
// subledger_reconciliation.php's debitor-forespørgsel (som slet ikke joiner
// til journalen) - en lille, accepteret upræcision hvis en faktura undtagelsesvist
// bogføres på en anden dato end den selv viser, men langt at foretrække frem
// for et join der aldrig kan matche noget som helst.
// RETTET: (1) linjens rabat (discount_pct) var ikke med - hver rabatlinje gav en falsk afvigelse; (2) moms der
// bogføres på salgsmomskontoen UDEN en faktura manglede: et indtægtsbilag (udgift af typen "income") og
// webbutikkens samlede dagsbilag (model B); (3) udgifts-siden talte ubogførte kladder med.
$vr_disc = invoice_discount_sql($conn, 'il.');
$sub_sales_row = DB::fetch_assoc(DB::query($conn,
    "SELECT COALESCE(SUM(il.quantity * il.price_each $vr_disc * il.line_vat_rate / 100.0 * COALESCE(NULLIF(i.exch_rate, 0), 1)), 0) AS total
     FROM invoices i
     JOIN invoice_lines il ON il.inv_id = i.inv_id
     WHERE i.inv_date BETWEEN '$ps' AND '$pe' AND LOWER(i.inv_status) != 'draft'"));
$sub_sales_vat = (float)($sub_sales_row['total'] ?? 0);
$inc_res = @DB::query($conn,
    "SELECT exp_id FROM expenses
     WHERE is_cancelled = 0 AND voucher_no IS NOT NULL AND exp_date BETWEEN '$ps' AND '$pe' AND exp_type = 'income'");
if ($inc_res) while ($r = DB::fetch_assoc($inc_res)) foreach (expense_get_lines($conn, (int)$r['exp_id']) as $line) $sub_sales_vat += $line['vat'];
$shop_vat = @DB::query($conn, "SELECT COALESCE(SUM(vat), 0) AS v FROM shop_orders WHERE status = 'booked' AND order_date BETWEEN '$ps' AND '$pe'");
if ($shop_vat && ($sv = DB::fetch_assoc($shop_vat))) $sub_sales_vat += (float)$sv['v'];

// --- indgående: genbruger nu expense_get_lines() (inc/db_connect.inc.php) -
// den ENESTE autoritative kilde til beløb/momssats pr. udgift, siden en
// udgift blev splitbar på flere konti (runde 30, expense_lines) - en udgift
// UDEN linjer giver samme "gross - net"-tal som før (én syntetisk linje),
// så denne rettelse er bagudkompatibel. Periode filtreres stadig på
// udgiftens EGEN dato, samme mønster som supplier_list.php/aging_report.php.
$exp_res = @DB::query($conn,
    "SELECT exp_id FROM expenses
     WHERE is_cancelled = 0 AND voucher_no IS NOT NULL AND exp_date BETWEEN '$ps' AND '$pe' AND (exp_type IS NULL OR exp_type != 'income')");
$sub_purchase_vat = 0.0;
if ($exp_res) {
    while ($r = DB::fetch_assoc($exp_res)) {
        foreach (expense_get_lines($conn, (int)$r['exp_id']) as $line) {
            $sub_purchase_vat += $line['vat'];
        }
    }
}

$diff_sales    = round($sub_sales_vat - $gl_sales_vat, 2);
$diff_purchase = round($sub_purchase_vat - $gl_purchase_vat, 2);

function vat_reconciliation_render_section($capt, $sub_total, $gl_total, $diff, $acc_id, $cur) {
    htm_Card_($capt, 900);
    echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Recalculated from source documents') . '</div><div style="font-weight:bold; font-size:1.2em;">' . number_format($sub_total, 2, ',', '.') . ' ' . $cur . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Posted General Ledger Balance') . ' (' . lang('@Account') . ' ' . $acc_id . ')</div><div style="font-weight:bold; font-size:1.2em;"><a href="account_card.php?acc_id=' . $acc_id . '">' . number_format($gl_total, 2, ',', '.') . ' ' . $cur . '</a></div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Difference') . '</div><div style="font-weight:bold; font-size:1.2em; color:' . (abs($diff) > 0.01 ? 'var(--color-danger)' : 'inherit') . ';">' . number_format($diff, 2, ',', '.') . ' ' . $cur . '</div></div>';
    echo '</div>';

    if (abs($diff) <= 0.01) {
        htm_Alert(lang('@Reconciled - the recalculated VAT matches the posted general ledger account.'), 'success', 900, true);
    } else {
        htm_Alert(sprintf(lang('@NOT reconciled - the recalculated VAT and the posted general ledger account differ by %s. This may indicate a manual posting outside the normal invoicing/expense flow, or a mixed VAT rate on this account.'), number_format(abs($diff), 2, ',', '.') . ' ' . $cur), 'error', 900, true);
    }
    echo '<p><a href="vat_report.php">' . lang('@View VAT Report') . '</a> · <a href="account_card.php?acc_id=' . $acc_id . '">' . lang('@View Account Card') . '</a></p>';
    htm_Card_end();
}

htm_Header('@VAT Reconciliation');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<p style="color:var(--text-muted); margin-bottom:10px;">'
    . lang('@Compares VAT recalculated from source invoices/expenses against the actually posted VAT account balances - a mismatch may indicate a manual journal entry that bypassed the normal invoicing/expense flow.')
    . '</p>';

echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px; flex-wrap:wrap;">';
echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Year') . '</label>';
echo '<input type="number" name="year" value="' . $year . '" style="width:90px; padding:6px;"></div>';
echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Period Type') . '</label>';
echo '<select name="period_type" id="period_type" onchange="document.getElementById(\'period_num_wrap\').style.display = (this.value==\'year\'?\'none\':\'block\');">';
foreach (['year' => '@Full Year', 'half' => '@Half-year', 'quarter' => '@Quarter', 'month' => '@Month'] as $val => $lbl) {
    echo '<option value="' . $val . '" ' . ($period_type === $val ? 'selected' : '') . '>' . lang($lbl) . '</option>';
}
echo '</select></div>';
echo '<div id="period_num_wrap" style="display:' . ($period_type === 'year' ? 'none' : 'block') . ';">';
echo '<label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Period No.') . '</label>';
echo '<input type="number" name="period_num" min="1" value="' . $period_num . '" style="width:70px; padding:6px;"></div>';
htm_Button(labl: '@Show', type: 'primary', attr: 'data-hint="'.lang('@Show the VAT reconciliation for the selected period').'"');
echo '</form>';

vat_reconciliation_render_section(lang('@Sales VAT Reconciliation') . ' - ' . $period_label, $sub_sales_vat, $gl_sales_vat, $diff_sales, $acc_vat_out, $cur);
vat_reconciliation_render_section(lang('@Purchase VAT Reconciliation') . ' - ' . $period_label, $sub_purchase_vat, $gl_purchase_vat, $diff_purchase, $acc_vat_in, $cur);

echo "</div>";
htm_Footer();
ob_end_flush();
?>
