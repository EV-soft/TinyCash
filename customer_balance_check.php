<?php # /customer_balance_check.php v:1.3.1 d:2026-09-15 i:evs
# NY (bruger-anmodet, "kan bogholderen ønske sig mere"): lille JSON-endepunkt
# der fortæller invoice_edit.php's kunde-dropdown, om den valgte kunde
# allerede har en forfalden saldo - genbruger get_customer_statement_data()
# (samme beregning som kundekortets sammendrag, [[bookkeeper-wishlist-round10]]),
# ingen ny saldoberegning. Kaldes via fetch() ved skift i kunde-dropdownen,
# da en helt ny faktura (inv_id=0) ikke kender kunden server-side FØR
# formularen sendes.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/customer_statement.inc.php';

header('Content-Type: application/json; charset=utf-8');

$cust_id = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
if ($cust_id <= 0) {
    echo json_encode(['overdue' => false]);
    ob_end_flush();
    exit;
}

$stmt = get_customer_statement_data($conn, $cust_id);
if (!$stmt) {
    echo json_encode(['overdue' => false, 'over_limit' => false]);
    ob_end_flush();
    exit;
}

$s = get_settings($conn);

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): udvider dette
// allerede eksisterende endepunkt med et ANDET, uafhængigt tjek - en
// konfigurerbar kreditgrænse for kundens SAMLEDE udestående saldo (ikke kun
// forfaldne fakturaer, som "overdue" ovenfor allerede dækker). En kunde der
// altid betaler til tiden kan sagtens vokse forbi en fornuftig kreditgrænse
// uden nogensinde at udløse "overdue" - de to advarsler er bevidst adskilte,
// begge kan være sande på samme tid. Samme "stille degradering hvis ikke
// migreret endnu"-mønster som andre nyere kolonner.
$has_credit_limit_col = false;
if (DB::is_sqlite()) {
    $clc = DB::query($conn, "PRAGMA table_info(customers)");
    if ($clc) { while ($clcr = DB::fetch_assoc($clc)) { if ($clcr['name'] === 'credit_limit') { $has_credit_limit_col = true; break; } } }
} else {
    $clc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit'");
    $has_credit_limit_col = ($clc && DB::num_rows($clc) > 0);
}
$credit_limit = null;
if ($has_credit_limit_col) {
    $cl_row = DB::fetch_assoc(DB::query($conn, "SELECT credit_limit FROM customers WHERE cust_id = $cust_id"));
    $credit_limit = ($cl_row && $cl_row['credit_limit'] !== null) ? (float)$cl_row['credit_limit'] : null;
}
$balance = round((float)$stmt['total_outstanding'], 2);
$over_limit = ($credit_limit !== null && $balance > $credit_limit);

if ($stmt['overdue_invoices'] <= 0 && !$over_limit) {
    echo json_encode(['overdue' => false, 'over_limit' => false]);
    ob_end_flush();
    exit;
}

echo json_encode([
    'overdue'        => $stmt['overdue_invoices'] > 0,
    'overdue_count'  => (int)$stmt['overdue_invoices'],
    'over_limit'     => $over_limit,
    'credit_limit'   => $credit_limit,
    'balance'        => $balance,
    'currency'       => $s['currency'] ?? 'DKK',
    'cust_name'      => $stmt['cust']['cust_name'] ?? '',
]);
ob_end_flush();
exit;
?>
