<?php # /vat_report.php v:1.3.0 d:2026-08-30 i:evs
# v1.2.0: ALVORLIGT FUND - beregnede FØR selv moms ud fra en kontos statiske
# vat_rate ganget med nettobeløbet, i stedet for at læse den faktisk bogførte
# moms fra momskontiene. Kunne afvige væsentligt fra virkeligheden, hvis en
# enkelt postering havde en momssats der afveg fra kontoens standard (fx et
# momsfrit udenlandsk køb på en ellers 25%-konto - expense_edit.php
# understøtter netop dette per-postering). Verificeret konkret: 1.000 kr til
# 0% moms på en 25%-konto gav FØR 250 kr beregnet moms mod 0 kr reelt
# bogført. Omskrevet til at læse de faktiske bogførte beløb fra de
# konfigurerede momskonti - samme mønster som report_income.php allerede
# brugte korrekt. Tilføjet valg af periode (måned/kvartal/halvår/år) -
# støttede før kun et helt kalenderår, mens de fleste danske virksomheder
# afregner moms kvartalsvis eller halvårligt. Slutbeløbene til selve
# indberetningen afrundes nu til hele kroner (som SKATs TastSelv Erhverv
# kræver) - de øre-præcise beløb vises stadig i detaljetabellen.
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// NYT: denne rapport er specifikt formet efter dansk momsindberetning (afrunding til hele kroner til SKATs
// TastSelv Erhverv, jf. kommentaren ovenfor) og giver ikke mening for en virksomhed, der bruger en anden
// bogføringsvaluta end DKK.
require_country_feature($conn, 'vat_report');

htm_Header('@VAT Report');
showMenu();

// -------------------------------------------------------------------------
// 1. PERIODEVALG - år er altid påkrævet; periodetype afgør om det er hele
// året eller et udsnit af det (halvår/kvartal/måned), som er de faktiske
// afregningsperioder de fleste danske virksomheder bruger over for SKAT.
// -------------------------------------------------------------------------
require_once 'inc/vat_return.inc.php';      // (momsperioderne og angivelsens felter)
// (uden valg: den senest afsluttede periode af landets type - den der skal angives)
[$def_year, $def_num] = vat_period_last_closed((string)($_GET['period_type'] ?? vat_period_default()));
$year = isset($_GET['year']) ? (int)$_GET['year'] : $def_year;
[$period_start, $period_end, $period_label, $period_type, $period_num] =
    vat_period_bounds($year, (string)($_GET['period_type'] ?? vat_period_default()), (int)($_GET['period_num'] ?? (isset($_GET['year']) ? 1 : $def_num)));

$ps = DB::escape($conn, $period_start);
$pe = DB::escape($conn, $period_end);

// -------------------------------------------------------------------------
// 2. FAKTISK BOGFØRT MOMS - læses direkte fra de konfigurerede momskonti,
// IKKE genberegnet fra nettobeløb × kontoens statiske sats (se v1.2.0-note
// ovenfor for hvorfor det var forkert). Samme mønster som report_income.php.
// -------------------------------------------------------------------------
$s           = get_settings($conn);
$acc_vat_out = (int)($s['conf_acc_vat']          ?? 6900); // udgående moms (salg)
$acc_vat_in  = (int)($s['conf_acc_purchase_vat'] ?? 6910); // indgående moms (køb)

$sv_row = DB::fetch_assoc(DB::query($conn,
    "SELECT SUM(-l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
     WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat_out) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"));
$sales_vat = (float)($sv_row['total'] ?? 0);

$pv_row = DB::fetch_assoc(DB::query($conn,
    "SELECT SUM(l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
     WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat_in) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"));
$purchase_vat = (float)($pv_row['total'] ?? 0);

$vat_payable = $sales_vat - $purchase_vat;

// Momsangivelsens felter efter landepakken (inc/vat_return.inc.php) - momstilsvaret tager nu også moms af køb i
// udlandet (omvendt betalingspligt) med, som ikke står på salgsmomskontoen
require_once 'inc/vat_return.inc.php';
$vr = vat_return_fields($conn, $period_start, $period_end);
if ($vr['fields']) $vat_payable = $vr['payable'];

// -------------------------------------------------------------------------
// 3. NETTOOMSÆTNING/-KØB PR. MOMSGRUPPE - kontekst/detalje der viser
// GRUNDLAGET (hvilke konti/satser beløbene stammer fra), IKKE selve
// momstallet - det tal kommer udelukkende fra punkt 2 ovenfor.
// -------------------------------------------------------------------------
// RETTET: momsgrundlaget talte årsafslutningens lukkepostering med - den nulstiller indtægts-/udgiftskontiene
// på årets sidste dag, så grundlaget for december / 4. kvartal / hele året blev 0, når året var lukket.
$sql = "SELECT a.vat_code AS vat_id, a.vat_rate, SUM(l.amount) AS net_amount
        FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        JOIN accounts a ON l.acc_id = a.acc_id
        WHERE j.jou_date BETWEEN '$ps' AND '$pe' AND a.vat_code IS NOT NULL AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
        GROUP BY a.vat_code, a.vat_rate
        HAVING SUM(l.amount) != 0
        ORDER BY a.vat_code ASC";
$res = DB::query($conn, $sql);

// RETTET: '@VAT Report' blev sammensat med den dynamiske periode-tekst (fx årstal) FØR den blev sendt til
// htm_Card_, som selv kalder lang på hele strengen. Enhver sådan sammensat streng ("VAT Report - 2026", "VAT
// Report - 2027" osv.) er unik og findes aldrig som opslagsnøgle i languages.json, så oversættelsen kunne aldrig
// virke - samme mønster som customer_statement.php/supplier_statement.php allerede gør korrekt: lang kaldes FØRST
// på den statiske del alene, og den dynamiske del sættes til bagefter på den allerede oversatte streng.
htm_Card_(lang('@VAT Report') . ' - ' . $period_label, 900);

// --- Periodevælger ---
echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px; flex-wrap:wrap;">';
echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Year') . '</label>';
echo '<input type="number" name="year" value="' . $year . '" style="width:90px; padding:6px;"></div>';
echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Period Type') . '</label>';
echo '<select name="period_type" id="period_type" onchange="document.getElementById(\'period_num_wrap\').style.display = (this.value==\'year\'?\'none\':\'block\');">';
foreach (array_map(fn($t) => $t[0], VAT_PERIOD_TYPES) as $val => $lbl) {
    echo '<option value="' . $val . '" ' . ($period_type === $val ? 'selected' : '') . '>' . lang($lbl) . '</option>';
}
echo '</select></div>';
echo '<div id="period_num_wrap" style="display:' . ($period_type === 'year' ? 'none' : 'block') . ';">';
echo '<label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Period No.') . '</label>';
echo '<input type="number" name="period_num" min="1" value="' . $period_num . '" style="width:70px; padding:6px;"></div>';
htm_Button(labl: '@Show', type: 'primary', attr: 'data-hint="'.lang('@Show the VAT report for the selected period').'"');
echo '</form>';

// NYT: denne side bygger selv sin tabel i hånden (ikke htm_Table), som ellers ville have givet en gratis
// $expo-CSV-knap - se vat_report_export.php, der genberegner PRÆCIS samme forespørgsler ud fra samme
// URL-parametre.
$export_qs = http_build_query(['year' => $year, 'period_type' => $period_type, 'period_num' => $period_num]);
htm_Button(icon: 'fa-file-csv', labl: '@Download CSV', type: 'success', link: 'vat_report_export.php?' . $export_qs,
    attr: 'data-hint="'.lang('@Download this VAT report as CSV').'"');

// --- Hovedresultat: de tal der reelt skal bruges til TastSelv Erhverv ---
$box_color = $vat_payable >= 0 ? '#c0392b' : '#27ae60';
echo '<div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px; margin-bottom:25px;">';
echo '<div style="background:var(--bg-panel); border-radius:6px; padding:15px; text-align:center;">
        <div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase; font-weight:bold;">' . lang('@Sales VAT') . '</div>
        <div style="font-size:1.4em; font-weight:bold; margin-top:5px;">' . fmt_dk($sales_vat) . ' ' . htmlspecialchars(tc_cur_label()) . '</div></div>';
echo '<div style="background:var(--bg-panel); border-radius:6px; padding:15px; text-align:center;">
        <div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase; font-weight:bold;">' . lang('@Purchase VAT') . '</div>
        <div style="font-size:1.4em; font-weight:bold; margin-top:5px;">' . fmt_dk($purchase_vat) . ' ' . htmlspecialchars(tc_cur_label()) . '</div></div>';
echo '<div style="background:var(--bg-panel); border-radius:6px; padding:15px; text-align:center; border:2px solid ' . $box_color . ';">
        <div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase; font-weight:bold;">' . sprintf(lang('@VAT payable (rounded to whole %s for %s)'), tc_cur_label(), (string)tc_country_pack('vat_portal', '')) . '</div>
        <div style="font-size:1.6em; font-weight:bold; color:' . $box_color . '; margin-top:5px;">' . fmt_dk(round($vat_payable), 0) . ' ' . htmlspecialchars(tc_cur_label()) . '</div></div>';
echo '</div>';

// --- Momsangivelsens felter (landepakken) ---
if ($vr['fields']) {
    $vr_cur = htmlspecialchars(tc_cur_label());
    echo '<h3 style="margin:10px 0 5px;">' . lang('@VAT return fields') . '</h3>';
    echo '<table style="width:100%; border-collapse:collapse; margin-bottom:20px;">';
    foreach ($vr['fields'] as $i => $fld) {
        $sep = ($i > 0 && !$fld['payable'] && $vr['fields'][$i - 1]['payable']) ? 'border-top:2px solid var(--border-color);' : '';
        echo '<tr style="border-bottom:1px solid var(--border-color);' . $sep . '"><td style="padding:8px;">' . htmlspecialchars(lang($fld['label'])) . '</td>'
           . '<td style="padding:8px; text-align:right; white-space:nowrap; font-weight:bold;">' . fmt_dk(round($fld['value']), 0) . ' ' . $vr_cur . '</td></tr>';
    }
    echo '</table>';
    htm_Note(lang('@Amounts are rounded to whole units as on the VAT return. The boxes A, B and C come from accounts whose VAT code has an EU or export type (VAT Codes page). Box B sales must also be reported to "EU sales without VAT".'), 'font-size:0.85em; margin-top:-12px;');
    if (tc_pack_feature('eu_sales_list')) {
        $eu_qs = match ($period_type) { 'month' => ['ptype' => 'month', 'num' => $period_num], 'bimonth' => ['ptype' => 'month', 'num' => $period_num * 2 - 1],
                                        'quarter' => ['ptype' => 'quarter', 'num' => $period_num], default => ['ptype' => 'quarter', 'num' => 1] };
        htm_Button(icon: 'fa-list', labl: '@EU sales without VAT', type: 'secondary', link: 'eu_sales_list.php?' . http_build_query(['year' => $year] + $eu_qs));
    }
    if ($vr['unplaced']) {
        $rows = '';
        foreach ($vr['unplaced'] as $u) $rows .= '<li>' . sprintf(lang('@Invoice %s to %s (%s): %s on account %d'), htmlspecialchars((string)$u['invoice_no']), htmlspecialchars((string)$u['cust_name']), htmlspecialchars($u['country']), fmt_dk($u['net']) . ' ' . $vr_cur, (int)$u['acc']) . '</li>';
        echo '<div style="margin:0 0 20px; padding:10px 14px; border-left:4px solid var(--color-warning); background:var(--bg-panel); border-radius:4px; font-size:0.9em;">'
           . '<strong>' . lang('@Sales without VAT to customers abroad that are not in box B or C:') . '</strong><ul style="margin:6px 0 4px 18px;">' . $rows . '</ul>'
           . lang('@Give the product an account with an EU or export VAT code (e.g. 1050 / 1150 / 1100 in the standard chart), so the sale is counted in the right box.') . '</div>';
    }
}

// --- Detalje/kontekst-tabel ---
if (DB::num_rows($res) == 0) {
    echo "<p style='padding:10px 0; color:var(--text-muted);'>" . lang('@No data found for this period') . '</p>';
} else {
?>
<p style="font-size:0.85em; color:var(--text-muted); margin-bottom:5px;"><?php echo lang('@Breakdown by account VAT category - for reference only. The figures above (from the actual posted VAT accounts) are the ones to report.'); ?></p>
<table style="width:100%; border-collapse:collapse; margin-top:5px;">
    <thead>
        <tr style="background:#2c3e50; color:white;">
            <th style="padding:12px; text-align:left;"><?php echo lang('@VAT Code'); ?></th>
            <th style="padding:12px; text-align:right;"><?php echo lang('@Net Amount'); ?></th>
            <th style="padding:12px; text-align:right;"><?php echo lang('@VAT Rate'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = DB::fetch_assoc($res)): ?>
        <tr style="border-bottom:1px solid #eee;">
            <td style="padding:10px;"><?php echo htmlspecialchars($row['vat_id'] ?? '-'); ?></td>
            <td style="padding:10px; text-align:right;"><?php echo fmt_dk($row['net_amount']); ?></td>
            <td style="padding:10px; text-align:right;"><?php echo fmt_pct($row['vat_rate']); ?>%</td>
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
