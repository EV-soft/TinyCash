<?php # /report_income_export.php v:1.3.1 d:2026-09-15 i:evs
# CSV-eksport af resultatopgørelsen (report_income.php) - samme forespørgsler
# og ?year=-filter som selve rapporten, for at undgå at de to nogensinde kan
# vise forskellige tal. Samme csv_safe()-mønster (CSV-injection-beskyttelse)
# som inventory_export.php/export.php.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

function csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

$s = get_settings($conn);
$currency = $s['currency'] ?? 'DKK';

$year_filter = (isset($_GET['year']) && trim($_GET['year']) !== '') ? (int)$_GET['year'] : null;
// Året er regnskabsåret der slutter i det år (et forskudt regnskabsår 1/7-30/6 blev før skåret over ved nytår)
[$fy_start, $fy_end] = $year_filter ? tc_fiscal_year_of($conn, $year_filter) : ['', ''];
$date_sql = $year_filter ? "j.jou_date BETWEEN '$fy_start' AND '$fy_end'" : '1=1';
// RETTET (som report_income.php): årsafslutningens lukkepostering (dateret på årets sidste dag) nulstiller netop indtægts- og
// udgiftskontiene - talte den med, viste et LUKKET år omsætning 0 og udgifter 0 (og "hele perioden" kun
// det åbne år). Samme udelukkelse som trial_balance.php og inc/annual_report.lib.php.
$date_sql .= " AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')";

$acc_vat          = (int)($s['conf_acc_vat'] ?? 6900);
$acc_purchase_vat = (int)($s['conf_acc_purchase_vat'] ?? 6910);

$res_sales = DB::query($conn, "
    SELECT SUM(-l.amount) AS total
    FROM ledger l JOIN journal j ON l.jou_id = j.jou_id JOIN accounts a ON l.acc_id = a.acc_id
    WHERE a.acc_type = 'income' AND $date_sql");
$revenue = (float)(DB::fetch_assoc($res_sales)['total'] ?? 0);

$res_costs = DB::query($conn, "
    SELECT a.acc_id, a.acc_name, SUM(l.amount) AS cat_total
    FROM ledger l JOIN journal j ON l.jou_id = j.jou_id JOIN accounts a ON l.acc_id = a.acc_id
    WHERE a.acc_type = 'expense' AND $date_sql
    GROUP BY a.acc_id, a.acc_name
    HAVING SUM(l.amount) != 0
    ORDER BY a.acc_id ASC");

$res_sales_vat = DB::query($conn, "
    SELECT SUM(-l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
    WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat) . ") AND $date_sql");
$sales_vat = (float)(DB::fetch_assoc($res_sales_vat)['total'] ?? 0);

$res_purchase_vat = DB::query($conn, "
    SELECT SUM(l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
    WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_purchase_vat) . ") AND $date_sql");
$purchase_vat = (float)(DB::fetch_assoc($res_purchase_vat)['total'] ?? 0);

$vat_to_pay = $sales_vat - $purchase_vat;
// + moms af køb i udlandet (omvendt betalingspligt): fratrukket som købsmoms ovenfor, men skyldig på sin egen konto
// (fx 7700) - uden den var tilsvaret her lavere end på momsangivelsen
if (db_has_column($conn, 'vat_codes', 'vat_type')) {
    $rc_accs = [];
    $rc_res = DB::query($conn, "SELECT DISTINCT vat_account FROM vat_codes WHERE vat_account IS NOT NULL AND vat_type IN ('" . implode("','", TC_VAT_RC_TYPES) . "')");
    while ($rc_res && ($rc_r = DB::fetch_row($rc_res))) if ((int)$rc_r[0] > 0) $rc_accs[] = (int)$rc_r[0];
    $rc_accs = array_diff($rc_accs, array_map('intval', array_merge(explode(',', tc_vat_acc_list($conn, $acc_vat)), explode(',', tc_vat_acc_list($conn, $acc_purchase_vat)))));
    if ($rc_accs) {
        $rc_row = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(-l.amount), 0) FROM ledger l JOIN journal j ON l.jou_id = j.jou_id WHERE l.acc_id IN (" . implode(',', $rc_accs) . ") AND $date_sql"));
        $vat_to_pay += round((float)($rc_row[0] ?? 0), 2);
    }
}
$total_costs = 0.0;
$cost_rows = [];
if ($res_costs) {
    while ($cost = DB::fetch_assoc($res_costs)) {
        $total_costs += (float)$cost['cat_total'];
        $cost_rows[] = $cost;
    }
}

if (ob_get_level()) ob_end_clean();
$filename = 'resultatopgorelse_' . ($year_filter ?: 'alle_aar') . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, [lang('@Section'), lang('@Account'), lang('@Amount') . ' (' . $currency . ')'], ';', '"', '');
fputcsv($output, [lang('@REVENUE'), '', fmt_dk_input($revenue)], ';', '"', '');
foreach ($cost_rows as $cost) {
    fputcsv($output, [lang('@COSTS'), csv_safe($cost['acc_id'] . ' - ' . $cost['acc_name']), fmt_dk_input(-(float)$cost['cat_total'])], ';', '"', '');
}
fputcsv($output, [lang('@ANNUAL RESULT'), '', fmt_dk_input($revenue - $total_costs)], ';', '"', '');
fputcsv($output, [], ';', '"', '');
fputcsv($output, [lang('@Sales VAT Due (from invoices)'), '', fmt_dk_input($sales_vat)], ';', '"', '');
fputcsv($output, [lang('@Deductible Purchase VAT (from bookkeeping)'), '', fmt_dk_input(-$purchase_vat)], ';', '"', '');
fputcsv($output, [$vat_to_pay >= 0 ? lang('@VAT to pay:') : lang('@VAT to receive:'), '', fmt_dk_input(abs($vat_to_pay))], ';', '"', '');

fclose($output);
exit;
