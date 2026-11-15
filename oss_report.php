<?php # /oss_report.php v:1.3.1 d:2026-09-15 i:evs
// EU-OSS-angivelsen (EU-ordningen) for et kvartal: salg til private i andre EU-lande pr. land og momssats, med
// grundlag og moms i regnskabets valuta og i EUR (ECB-kursen på kvartalets sidste dag, som OSS-reglerne kræver).
// Viser også tærsklen på 10.000 EUR. Tallene indtastes i Skattestyrelsens OSS-løsning; betalingen sker samme sted.
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_country_feature($conn, 'oss');

// (standard: det seneste afsluttede kvartal - det er det, der skal angives)
$prev_q  = strtotime(date('Y-m-01') . ' -3 months');
$year    = max(2021, min(2100, (int)($_GET['year'] ?? date('Y', $prev_q))));
$quarter = max(1, min(4, (int)($_GET['quarter'] ?? ceil((int)date('n', $prev_q) / 3))));
$ps = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
$pe = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $quarter * 3)));
$cur = strtoupper((string)setting('currency', 'DKK'));

// Kursen: indtastet, ellers ECB's kurs for kvartalets sidste dag (null = kunne ikke hentes - siden viser kun egen valuta)
$rate = isset($_GET['rate']) && trim((string)$_GET['rate']) !== '' ? tc_num($_GET['rate'], 0.0001, 100000) : null;
if ($rate === null) $rate = oss_ecb_rate($pe, $cur);
$to_eur = fn(float $v) => $rate ? round($v / $rate, 2) : null;

$rows = oss_report_rows($conn, $ps, $pe, 'inv_oss', true);
// (kreditnotaer til fakturaer fra et tidligere kvartal: rettelser af det kvartal, ikke dette kvartals salg)
$corr = oss_corrections_by_period(oss_report_corrections($conn, $ps, $pe), function (string $d) {
    $y = (int)substr($d, 0, 4);  $q = (int)ceil((int)substr($d, 5, 2) / 3);
    return [sprintf('%04d-%d', $y, $q), 'Q' . $q . ' ' . $y, date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $q * 3)))];
}, $cur);
$cc_names = tc_country_codes();

// CSV: samme tal som tabellen
if (($_GET['csv'] ?? '') === '1') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="oss_' . $year . '_Q' . $quarter . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [lang('@Country'), lang('@VAT rate'), lang('@Taxable amount') . " $cur", lang('@VAT') . " $cur", lang('@Taxable amount') . ' EUR', lang('@VAT') . ' EUR'], ';', '"', '');
    foreach ($rows as $r) fputcsv($out, [$r['country'], fmt_dk_input($r['rate'], 2), fmt_dk_input($r['net']), fmt_dk_input($r['vat']),
        $rate ? fmt_dk_input($to_eur($r['net'])) : '', $rate ? fmt_dk_input($to_eur($r['vat'])) : ''], ';', '"', '');
    foreach ($corr as $c) fputcsv($out, [$c['country'], lang('@Correction') . ' ' . $c['period'], '', fmt_dk_input($c['vat']), '', $c['vat_eur'] !== null ? fmt_dk_input($c['vat_eur']) : ''], ';', '"', '');
    fclose($out);
    exit;
}

htm_Header(capt: '@EU-OSS Return', mwidth: 1000);
showMenu();
htm_Card_(capt: lang('@EU-OSS Return') . ' - ' . lang('@Quarter') . " $quarter $year", wdth: 1000);

if (!oss_registered()) htm_Banner('@The company is not registered for EU-OSS (Company Settings). The return below only shows invoices posted while it was.', 'info');

echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:15px;">';
htm_Field(icon: 'fa-calendar', labl: '@Year', name: 'year', valu: $year, type: 'number', wdth: '110px', legd: 'align-left');
htm_Field(icon: 'fa-calendar-week', labl: '@Quarter', name: 'quarter', valu: $quarter, type: 'sele', opti: [1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'], wdth: '110px', legd: 'align-left');
if ($cur !== 'EUR') htm_Field(icon: 'fa-euro-sign', labl: sprintf(lang('@1 EUR in %s'), $cur), name: 'rate', valu: $rate ? fmt_dk_input($rate, 4) : '', wdth: '160px', legd: 'align-left',
    hint: '@The ECB rate on the last day of the quarter is fetched automatically. Enter another rate only if the tax authority asks for it.');
htm_Button(labl: '@Show', type: 'primary');
echo '</form>';
htm_Note(sprintf(lang('@Period %s - %s. Amounts in EUR are converted with %s.'), date(CONF_DATE_FORMAT, strtotime($ps)), date(CONF_DATE_FORMAT, strtotime($pe)),
    $cur === 'EUR' ? 'EUR' : ($rate ? '1 EUR = ' . fmt_dk($rate, 4) . " $cur" : lang('@no rate (the ECB rate could not be fetched - enter it above)')))
    . ' ' . sprintf(lang('@File and pay by %s.'), date(CONF_DATE_FORMAT, strtotime(date('Y-m-t', strtotime($pe . ' +1 day'))))));

echo oss_corrections_html($corr, $cur, $cc_names);
if (!$rows) {
    htm_Note(lang('@No EU-OSS sales in this quarter.'));
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
    htm_Button(icon: 'fa-file-csv', labl: '@Download CSV', type: 'success', link: 'oss_report.php?' . http_build_query(['year' => $year, 'quarter' => $quarter, 'rate' => $rate ? fmt_dk_input($rate, 4) : '', 'csv' => 1]));
    echo '</div>';
    htm_Note(lang('@The VAT in the table is calculated per invoice from the net amount and the rate. Compare the total with the balance of the OSS VAT account before you file.'), 'margin-top:10px; font-size:0.9em;');
}
htm_Card_end();

// Tærsklen: 10.000 EUR samlet salg til private i andre EU-lande i år eller sidste år
htm_Card_(capt: '@EU-OSS threshold (10,000 EUR)', wdth: 1000);
$eur_now = $rate ?: oss_ecb_rate(date('Y-m-d'), $cur);
foreach ([(int)date('Y') - 1, (int)date('Y')] as $y) {
    $v = oss_b2c_eu_sales($conn, $y);
    $v_eur = $eur_now ? round($v / $eur_now, 2) : null;
    $over = $v_eur !== null && $v_eur > OSS_THRESHOLD_EUR;
    echo '<p>' . sprintf(lang('@%d: sales to private customers in other EU countries %s %s'), $y, fmt_dk($v), htmlspecialchars($cur))
        . ($v_eur !== null && $cur !== 'EUR' ? ' (' . fmt_dk($v_eur) . ' EUR)' : '') . ($over ? ' - <strong>' . lang('@above the threshold') . '</strong>' : '') . '</p>';
    if ($over && !oss_registered()) htm_Banner('@The sales are above 10,000 EUR: the VAT of the customer\'s country must be charged. Register for EU-OSS and turn it on under Company Settings.', 'warning');
}
htm_Note(lang('@Counts posted invoices to customers with a country in the EU (not your own) and no VAT number. Customers without a country count as domestic.'), 'font-size:0.9em;');
htm_Card_end();
htm_Footer();
