<?php # /ioss_report.php v:1.3.1 d:2026-09-15 i:evs
// IOSS-angivelsen (importordningen) for en måned: fjernsalg af varer sendt fra et land uden for EU til forbrugere i EU,
// pr. modtagerland og momssats, i regnskabets valuta og i EUR (ECB-kursen på månedens sidste dag). Angives og betales
// senest ved udgangen af den følgende måned i IOSS-løsningen - også en måned uden salg skal angives (en nulangivelse).
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_country_feature($conn, 'ioss');

$year  = max(2021, min(2100, (int)($_GET['year'] ?? date('Y', strtotime('first day of last month')))));
$month = max(1, min(12, (int)($_GET['month'] ?? date('n', strtotime('first day of last month')))));
$ps  = sprintf('%04d-%02d-01', $year, $month);
$pe  = date('Y-m-t', strtotime($ps));
$due = date('Y-m-t', strtotime($ps . ' +1 month'));
$cur = strtoupper((string)setting('currency', 'DKK'));

// Kursen: indtastet, ellers ECB's kurs for månedens sidste dag (null = kunne ikke hentes - siden viser kun egen valuta)
$rate = isset($_GET['rate']) && trim((string)$_GET['rate']) !== '' ? tc_num($_GET['rate'], 0.0001, 100000) : null;
if ($rate === null) $rate = oss_ecb_rate($pe, $cur);
$to_eur = fn(float $v) => $cur === 'EUR' ? $v : ($rate ? round($v / $rate, 2) : null);

$rows = ioss_ready($conn) ? oss_report_rows($conn, $ps, $pe, 'inv_ioss', true) : [];
// (kreditnotaer til fakturaer fra en tidligere måned: rettelser af den måned)
$corr = ioss_ready($conn) ? oss_corrections_by_period(oss_report_corrections($conn, $ps, $pe, 'inv_ioss'),
    fn(string $d) => [substr($d, 0, 7), substr($d, 5, 2) . '/' . substr($d, 0, 4), date('Y-m-t', strtotime(substr($d, 0, 7) . '-01'))], $cur) : [];
$cc_names = tc_country_codes();

if (($_GET['csv'] ?? '') === '1') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ioss_' . sprintf('%04d-%02d', $year, $month) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [lang('@Country'), lang('@VAT rate'), lang('@Taxable amount') . " $cur", lang('@VAT') . " $cur", lang('@Taxable amount') . ' EUR', lang('@VAT') . ' EUR'], ';', '"', '');
    foreach ($rows as $r) fputcsv($out, [$r['country'], fmt_dk_input($r['rate'], 2), fmt_dk_input($r['net']), fmt_dk_input($r['vat']),
        $to_eur($r['net']) !== null ? fmt_dk_input($to_eur($r['net'])) : '', $to_eur($r['vat']) !== null ? fmt_dk_input($to_eur($r['vat'])) : ''], ';', '"', '');
    foreach ($corr as $c) fputcsv($out, [$c['country'], lang('@Correction') . ' ' . $c['period'], '', fmt_dk_input($c['vat']), '', $c['vat_eur'] !== null ? fmt_dk_input($c['vat_eur']) : ''], ';', '"', '');
    fclose($out);
    exit;
}

htm_Header(capt: '@IOSS Return', mwidth: 1000);
showMenu();
htm_Card_(capt: lang('@IOSS Return') . ' - ' . sprintf('%02d/%04d', $month, $year), wdth: 1000);

if (!ioss_ready($conn)) htm_Banner('@IOSS is not set up in the database yet - run the migration migrate_ioss.php.', 'warning');
elseif (!ioss_registered()) htm_Banner('@The company has no IOSS number (Company Settings). The return below only shows invoices posted while it had one.', 'info');

echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:15px;">';
htm_Field(icon: 'fa-calendar', labl: '@Year', name: 'year', valu: $year, type: 'number', wdth: '110px', legd: 'align-left');
$months = [];
for ($m = 1; $m <= 12; $m++) $months[$m] = sprintf('%02d', $m);
htm_Field(icon: 'fa-calendar-day', labl: '@Month', name: 'month', valu: $month, type: 'sele', opti: $months, wdth: '110px', legd: 'align-left');
if ($cur !== 'EUR') htm_Field(icon: 'fa-euro-sign', labl: sprintf(lang('@1 EUR in %s'), $cur), name: 'rate', valu: $rate ? fmt_dk_input($rate, 4) : '', wdth: '160px', legd: 'align-left',
    hint: '@The ECB rate on the last day of the month is fetched automatically. Enter another rate only if the tax authority asks for it.');
htm_Button(labl: '@Show', type: 'primary');
echo '</form>';
$ioss_no = (string)setting('ioss_number', '');
htm_Note(sprintf(lang('@Period %s - %s. Amounts in EUR are converted with %s.'), date(CONF_DATE_FORMAT, strtotime($ps)), date(CONF_DATE_FORMAT, strtotime($pe)),
        $cur === 'EUR' ? 'EUR' : ($rate ? '1 EUR = ' . fmt_dk($rate, 4) . " $cur" : lang('@no rate (the ECB rate could not be fetched - enter it above)')))
    . ' ' . sprintf(lang('@File and pay by %s.'), date(CONF_DATE_FORMAT, strtotime($due)))
    . ($ioss_no !== '' ? ' ' . lang('@IOSS number') . ': ' . htmlspecialchars($ioss_no) : ''));

echo oss_corrections_html($corr, $cur, $cc_names);
if (!$rows) {
    htm_Note(lang('@No IOSS sales in this month. A return must still be filed (a nil return).'));
} else {
    $th = 'style="padding:8px; text-align:right; border-bottom:2px solid var(--border-color);"';
    echo '<table style="width:100%; border-collapse:collapse;"><tr><th style="padding:8px; text-align:left; border-bottom:2px solid var(--border-color);">' . lang('@Country') . '</th>'
        . "<th $th>" . lang('@VAT rate') . "</th><th $th>" . lang('@Taxable amount') . " $cur</th><th $th>" . lang('@VAT') . " $cur</th>"
        . ($cur !== 'EUR' ? "<th $th>" . lang('@Taxable amount') . " EUR</th><th $th>" . lang('@VAT') . ' EUR</th>' : '') . '</tr>';
    $sum = ['net' => 0.0, 'vat' => 0.0, 'net_eur' => 0.0, 'vat_eur' => 0.0];
    foreach ($rows as $r) {
        $td = 'style="padding:6px 8px; text-align:right; border-bottom:1px solid var(--border-color); white-space:nowrap;"';
        echo '<tr><td style="padding:6px 8px; border-bottom:1px solid var(--border-color);">' . htmlspecialchars($r['country']) . ' - ' . htmlspecialchars(lang($cc_names[$r['country']] ?? '@' . $r['country'])) . '</td>'
            . "<td $td>" . fmt_pct($r['rate']) . " %</td><td $td>" . fmt_dk($r['net']) . "</td><td $td>" . fmt_dk($r['vat']) . '</td>'
            . ($cur !== 'EUR' ? "<td $td>" . ($rate ? fmt_dk($to_eur($r['net'])) : '-') . "</td><td $td>" . ($rate ? fmt_dk($to_eur($r['vat'])) : '-') . '</td>' : '') . '</tr>';
        $sum['net'] += $r['net'];  $sum['vat'] += $r['vat'];
        if ($rate) { $sum['net_eur'] += $to_eur($r['net']);  $sum['vat_eur'] += $to_eur($r['vat']); }
    }
    $tf = 'style="padding:8px; text-align:right; font-weight:bold; border-top:2px solid var(--border-color);"';
    echo '<tr><td style="padding:8px; font-weight:bold; border-top:2px solid var(--border-color);">' . lang('@Total') . "</td><td $tf></td><td $tf>" . fmt_dk($sum['net']) . "</td><td $tf>" . fmt_dk($sum['vat']) . '</td>'
        . ($cur !== 'EUR' ? "<td $tf>" . ($rate ? fmt_dk($sum['net_eur']) : '-') . "</td><td $tf>" . ($rate ? fmt_dk($sum['vat_eur']) : '-') . '</td>' : '') . '</tr></table>';
    echo '<div style="margin-top:12px;">';
    htm_Button(icon: 'fa-file-csv', labl: '@Download CSV', type: 'success', link: 'ioss_report.php?' . http_build_query(['year' => $year, 'month' => $month, 'rate' => $rate ? fmt_dk_input($rate, 4) : '', 'csv' => 1]));
    echo '</div>';
    htm_Note(lang('@The VAT in the table is calculated per invoice from the net amount and the rate. Compare the total with the balance of the IOSS VAT account before you file.'), 'margin-top:10px; font-size:0.9em;');
}
htm_Note(lang('@IOSS VAT is due when the payment is accepted. TinyCash reports by the invoice date - book the invoice on the day the customer pays (a web shop order paid at checkout already is).'), 'margin-top:10px; font-size:0.9em;');
htm_Card_end();
htm_Footer();
