<?php # /eu_sales_list.php v:1.3.1 d:2026-09-15 i:evs
// Listeindberetning ("EU-salg uden moms"): salg til virksomheder i andre EU-lande pr. købers momsnummer, delt i varer
// og ydelser, for en måned eller et kvartal. Landepakkens legal.eu_sales_list giver listens navn (DK: "EU-salg uden
// moms", SE: periodisk sammanställning, FI: yhteenvetoilmoitus) og de felter på momsangivelsen, listen afstemmes mod,
// så en postering uden faktura på en EU-konto opdages. CSV-filen er TinyCash' eget arbejdsark, ikke myndighedens
// uploadformat.
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/vat_return.inc.php';
require_country_feature($conn, 'eu_sales_list');

$ptype  = ($_GET['ptype'] ?? '') === 'month' ? 'month' : 'quarter';
// (uden valg: den senest afsluttede periode - listen indberettes efter periodens udløb)
[$def_year, $def_num] = vat_period_last_closed($ptype);
$year   = max(2000, min(2100, (int)($_GET['year'] ?? $def_year)));
$num    = (int)($_GET['num'] ?? (isset($_GET['year']) ? 1 : $def_num));
$num    = max(1, min($ptype === 'month' ? 12 : 4, $num));
$first  = $ptype === 'month' ? $num : ($num - 1) * 3 + 1;
$ps = sprintf('%04d-%02d-01', $year, $first);
$pe = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $ptype === 'month' ? $num : $num * 3)));
$plabel = $ptype === 'month' ? tc_month_name($num) . " $year" : lang('@Quarter') . " $num $year";

$list = vat_eu_sales_list($conn, $ps, $pe);
$vr_fields = vat_return_fields($conn, $ps, $pe)['fields'];
$box = array_column($vr_fields, 'value', 'box');
$cur = htmlspecialchars(tc_cur_label());

if (($_GET['csv'] ?? '') === '1') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eu_salg_uden_moms_' . $year . '_' . ($ptype === 'month' ? sprintf('%02d', $num) : "Q$num") . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [lang('@Country'), lang('@VAT number'), lang('@Customer'), lang('@Goods'), lang('@Triangular trade'), lang('@Services')], ';', '"', '');
    // (kundenavnet kan komme fra brugerinput - et førende =,+,-,@ ville blive en formel i et regneark)
    foreach ($list['rows'] as $r) fputcsv($out, [$r['country'], $r['vat_id'], preg_replace('/^[=+\-@]/', "'$0", $r['cust_name']),
        fmt_dk_input(round($r['goods'])), '0', fmt_dk_input(round($r['services']))], ';', '"', '');
    fclose($out);
    exit;
}

htm_Header(capt: '@EU sales without VAT', mwidth: 1000);
showMenu();
htm_Card_(capt: lang('@EU sales without VAT') . ' - ' . $plabel, wdth: 1000);
echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:15px;">';
htm_Field(icon: 'fa-calendar', labl: '@Year', name: 'year', valu: $year, type: 'number', wdth: '110px', legd: 'align-left');
htm_Field(icon: 'fa-calendar-week', labl: '@Period', name: 'ptype', valu: $ptype, type: 'sele', opti: ['quarter' => lang('@Quarter'), 'month' => lang('@Month')], wdth: '130px', legd: 'align-left');
htm_Field(icon: 'fa-hashtag', labl: '@Number', name: 'num', valu: $num, type: 'number', wdth: '90px', legd: 'align-left', hint: '@Quarter 1-4 or month 1-12');
htm_Button(labl: '@Show', type: 'primary');
echo '</form>';
htm_Note(sprintf(lang('@Sales of goods and services without VAT to businesses in other EU countries, per buyer VAT number - the same invoices as the EU sales boxes on the VAT return. Report them on: %s. Whether it is monthly or quarterly depends on the size of the EU sales - check the rules for your company.'),
    htmlspecialchars(lang((string)tc_country_pack('legal.eu_sales_list.name', '@EU sales without VAT')))), 'font-size:0.9em;');

if ($list['missing']) {
    $li = '';
    foreach ($list['missing'] as $m) $li .= '<li>' . sprintf(lang('@%s to %s: %s'), htmlspecialchars($m['doc']), '<a href="customer_edit.php?id=' . (int)$m['cust_id'] . '">' . htmlspecialchars($m['cust_name']) . '</a>', fmt_dk($m['net']) . " $cur") . '</li>';
    echo '<div style="margin:0 0 15px; padding:10px 14px; border-left:4px solid var(--color-warning); background:var(--bg-panel); border-radius:4px; font-size:0.9em;">'
       . '<strong>' . lang('@EU sales to customers without a VAT number - they cannot be put on the list:') . '</strong><ul style="margin:6px 0 4px 18px;">' . $li . '</ul>'
       . lang('@A sale without VAT to another EU country requires the buyer\'s VAT number. Enter it on the customer card (and check it with VIES) - or the sale must carry VAT.') . '</div>';
}

if (!$list['rows']) {
    htm_Note(lang('@No EU sales without VAT in this period.'));
} else {
    $th = 'style="padding:8px; text-align:right; border-bottom:2px solid var(--border-color);"';
    $tl = 'style="padding:8px; text-align:left; border-bottom:2px solid var(--border-color);"';
    echo '<table style="width:100%; border-collapse:collapse;"><tr>'
        . "<th $tl>" . lang('@Country') . "</th><th $tl>" . lang('@VAT number') . "</th><th $tl>" . lang('@Customer') . '</th>'
        . "<th $th>" . lang('@Goods') . " ($cur)</th><th $th>" . lang('@Services') . " ($cur)</th><th $th>" . lang('@Invoices') . '</th></tr>';
    $td = 'style="padding:6px 8px; border-bottom:1px solid var(--border-color);"';
    $tdr = 'style="padding:6px 8px; text-align:right; border-bottom:1px solid var(--border-color); white-space:nowrap;"';
    foreach ($list['rows'] as $r) {
        echo "<tr><td $td>" . htmlspecialchars($r['country']) . "</td><td $td>" . htmlspecialchars($r['vat_id']) . "</td><td $td>" . htmlspecialchars($r['cust_name']) . '</td>'
            . "<td $tdr>" . fmt_dk(round($r['goods']), 0) . "</td><td $tdr>" . fmt_dk(round($r['services']), 0) . "</td><td $tdr>" . (int)$r['invoices'] . '</td></tr>';
    }
    $tf = 'style="padding:8px; text-align:right; font-weight:bold; border-top:2px solid var(--border-color);"';
    echo '<tr><td colspan="3" style="padding:8px; font-weight:bold; border-top:2px solid var(--border-color);">' . lang('@Total') . "</td><td $tf>" . fmt_dk(round($list['goods']), 0)
        . "</td><td $tf>" . fmt_dk(round($list['services']), 0) . "</td><td $tf></td></tr></table>";
    echo '<div style="margin-top:12px;">';
    htm_Button(icon: 'fa-file-csv', labl: '@Download CSV', type: 'success', link: 'eu_sales_list.php?' . http_build_query(['year' => $year, 'ptype' => $ptype, 'num' => $num, 'csv' => 1]));
    echo '</div>';
}

// Afstemning mod momsangivelsens EU-felter (bogført på EU-konti, uanset om der er en faktura bag)
$diff = [];
$labels = array_column($vr_fields, 'label', 'box');
// 'all': et felt med både varer og ydelser (Hollands 3b, Estlands 3.1) - ellers blev det sammenlignet med kun den ene del
$list['all'] = (float)$list['goods'] + (float)$list['services'];
foreach ((array)tc_country_pack('legal.eu_sales_list.boxes', []) as $k => $b) {
    if (!isset($list[$k], $box[$b])) continue;
    if (abs(round($box[$b]) - round($list[$k])) >= 1) $diff[] = sprintf(lang('@%s on the VAT return is %s, the list totals %s.'), htmlspecialchars(lang($labels[$b] ?? $b)), fmt_dk(round($box[$b]), 0) . " $cur", fmt_dk(round($list[$k]), 0) . " $cur");
}
if ($diff) echo '<div style="margin:15px 0 0; padding:10px 14px; border-left:4px solid var(--color-warning); background:var(--bg-panel); border-radius:4px; font-size:0.9em;">'
    . implode('<br>', $diff) . '<br>' . lang('@The difference is postings on EU accounts without an invoice (e.g. a manual journal entry), or sales to customers without a VAT number. Only invoiced sales can be put on the list.') . '</div>';
htm_Card_end();
htm_Footer();
ob_end_flush();
