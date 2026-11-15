<?php # /nemhandel_lookup.php v:1.3.1 d:2026-09-15 i:evs
// JSON: kan kunden modtage e-fakturaer via Nemhandel? Kaldes af assets/tc-nemhandel.js fra fakturaen og kundekortet.
// Tager kun et kunde-id (ikke vilkårlige numre) - opslaget og dets cache ligger i inc/nemhandel.inc.php.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/nemhandel.inc.php';
header('Content-Type: application/json; charset=utf-8');

$cust_id = (int)($_GET['cust_id'] ?? 0);
$out = ['success' => false];
if ($cust_id > 0 && tc_pack_feature('oioubl')) {
    $opt = fn(string $c) => db_has_column($conn, 'customers', $c) ? ", $c" : '';
    $c = DB::fetch_assoc(DB::query($conn, "SELECT cust_cvr" . $opt('cust_ean') . $opt('cust_country') . " FROM customers WHERE cust_id = $cust_id"));
    $key = $c ? nemhandel_customer_key($c) : null;
    $cc = strtoupper(trim((string)($c['cust_country'] ?? '')));
    if ($c && $cc !== '' && $cc !== 'DK') $out = ['success' => true, 'foreign' => true];      // (Nemhandel er dansk - intet at vise)
    elseif ($key) $out = nemhandel_lookup($key[0], $key[1]) + ['key' => $key[0] . ' ' . $key[1]];
    else $out = ['success' => true, 'found' => false, 'nokey' => true];
}
ob_end_clean();
echo json_encode($out, JSON_UNESCAPED_UNICODE);
