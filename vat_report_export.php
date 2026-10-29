<?php # /vat_report_export.php v:1.3.1 d:2026-09-15 i:evs
# CSV-eksport af momsopgørelsen (vat_report.php) - samme periodelogik
# (?year=/?period_type=/?period_num=) og samme forespørgsler mod de
# konfigurerede momskonti, for at undgå at de to nogensinde kan vise
# forskellige tal. Samme csv_safe()-mønster som inventory_export.php.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_dkk_base_currency($conn);

function csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

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

$s           = get_settings($conn);
$acc_vat_out = (int)($s['conf_acc_vat']          ?? 6900);
$acc_vat_in  = (int)($s['conf_acc_purchase_vat'] ?? 6910);

$sv_row = DB::fetch_assoc(DB::query($conn,
    "SELECT SUM(-l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
     WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat_out) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"));
$sales_vat = (float)($sv_row['total'] ?? 0);

$pv_row = DB::fetch_assoc(DB::query($conn,
    "SELECT SUM(l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
     WHERE l.acc_id IN (" . tc_vat_acc_list($conn, $acc_vat_in) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"));
$purchase_vat = (float)($pv_row['total'] ?? 0);

$vat_payable = $sales_vat - $purchase_vat;

$res = DB::query($conn, "
    SELECT a.vat_code AS vat_id, a.vat_rate, SUM(l.amount) AS net_amount
    FROM ledger l JOIN journal j ON l.jou_id = j.jou_id JOIN accounts a ON l.acc_id = a.acc_id
    WHERE j.jou_date BETWEEN '$ps' AND '$pe' AND a.vat_code IS NOT NULL AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
    GROUP BY a.vat_code, a.vat_rate
    HAVING SUM(l.amount) != 0
    ORDER BY a.vat_code ASC");

if (ob_get_level()) ob_end_clean();
$filename = 'momsopgorelse_' . $period_type . '_' . str_replace(' ', '_', $period_label) . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, [lang('@VAT Report') . ' - ' . $period_label], ';');
fputcsv($output, [], ';');
fputcsv($output, [lang('@Sales VAT'), number_format($sales_vat, 2, ',', '') . ' kr'], ';');
fputcsv($output, [lang('@Purchase VAT'), number_format($purchase_vat, 2, ',', '') . ' kr'], ';');
fputcsv($output, [lang('@VAT Payable (rounded to whole DKK for TastSelv)'), number_format(round($vat_payable), 0, ',', '') . ' kr'], ';');
fputcsv($output, [], ';');
fputcsv($output, [lang('@VAT Code'), lang('@Net Amount'), lang('@VAT Rate')], ';');
if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        fputcsv($output, [csv_safe($row['vat_id'] ?? '-'), number_format($row['net_amount'], 2, ',', ''), number_format($row['vat_rate'], 0) . '%'], ';');
    }
}

fclose($output);
exit;
