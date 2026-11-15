<?php # /vat_report_export.php v:1.3.1 d:2026-09-15 i:evs
# CSV-eksport af momsopgørelsen (vat_report.php) - samme periodelogik
# (?year=/?period_type=/?period_num=) og samme forespørgsler mod de
# konfigurerede momskonti, for at undgå at de to nogensinde kan vise
# forskellige tal. Samme csv_safe()-mønster som inventory_export.php.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_country_feature($conn, 'vat_report');

function csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

require_once 'inc/vat_return.inc.php';      // (momsperioderne og angivelsens felter)
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
[$period_start, $period_end, $period_label, $period_type, $period_num] =
    vat_period_bounds($year, (string)($_GET['period_type'] ?? vat_period_default()), (int)($_GET['period_num'] ?? 1));

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
require_once 'inc/vat_return.inc.php';      // momsangivelsens felter (landepakken) - samme tal som vat_report.php
$vr = vat_return_fields($conn, $period_start, $period_end);
if ($vr['fields']) $vat_payable = $vr['payable'];

$res = DB::query($conn, "
    SELECT a.vat_code AS vat_id, a.vat_rate, SUM(l.amount) AS net_amount
    FROM ledger l JOIN journal j ON l.jou_id = j.jou_id JOIN accounts a ON l.acc_id = a.acc_id
    WHERE j.jou_date BETWEEN '$ps' AND '$pe' AND a.vat_code IS NOT NULL AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
    GROUP BY a.vat_code, a.vat_rate
    HAVING SUM(l.amount) != 0
    ORDER BY a.vat_code ASC");

if (ob_get_level()) ob_end_clean();
// (filnavnet af år/type/nummer - den oversatte periodetekst kan have tegn som ä/ð, som ikke må stå i en HTTP-header)
$filename = 'momsopgorelse_' . $year . '_' . $period_type . ($period_type !== 'year' ? '_' . $period_num : '') . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, [lang('@VAT Report') . ' - ' . $period_label], ';', '"', '');
fputcsv($output, [], ';', '"', '');
fputcsv($output, [lang('@Sales VAT'), fmt_dk_input($sales_vat) . ' ' . tc_cur_label()], ';', '"', '');
fputcsv($output, [lang('@Purchase VAT'), fmt_dk_input($purchase_vat) . ' ' . tc_cur_label()], ';', '"', '');
fputcsv($output, [sprintf(lang('@VAT payable (rounded to whole %s for %s)'), tc_cur_label(), (string)tc_country_pack('vat_portal', '')), fmt_dk_input(round($vat_payable), 0) . ' ' . tc_cur_label()], ';', '"', '');
fputcsv($output, [], ';', '"', '');
if ($vr['fields']) {
    fputcsv($output, [lang('@VAT return fields')], ';', '"', '');
    foreach ($vr['fields'] as $fld) fputcsv($output, [lang($fld['label']), fmt_dk_input(round($fld['value']), 0) . ' ' . tc_cur_label()], ';', '"', '');
    fputcsv($output, [], ';', '"', '');
}
fputcsv($output, [lang('@VAT Code'), lang('@Net Amount'), lang('@VAT Rate')], ';', '"', '');
if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        fputcsv($output, [csv_safe($row['vat_id'] ?? '-'), fmt_dk_input($row['net_amount']), fmt_pct($row['vat_rate']) . '%'], ';', '"', '');
    }
}

fclose($output);
exit;
