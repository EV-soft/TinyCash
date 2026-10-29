<?php # /expense_duplicate_check.php v:1.0.0 d:2026-09-15 i:evs
# NY (bruger-anmodet, "kan bogholderen ønske sig mere"): lille JSON-endepunkt
# der advarer - blokerer IKKE - hvis en udgift med samme leverandør, beløb og
# dato allerede findes, FØR brugeren gemmer. Klassisk fejlkilde: en kvittering
# der ved en fejl uploades/registreres to gange. Kaldes via fetch() fra
# expense_edit.php ved skift i leverandør-/beløbs-/dato-felterne.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

header('Content-Type: application/json; charset=utf-8');

$supplier   = trim($_GET['supplier'] ?? '');
$amount     = isset($_GET['amount']) ? parse_dk_number($_GET['amount']) : 0.0;      // RETTET: "1.250,00" blev til 1,25 - dubletter over 999 kr. blev aldrig fundet
$exp_date   = $_GET['exp_date'] ?? '';
$exclude_id = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

if ($supplier === '' || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_date)) {
    echo json_encode(['duplicate' => false]);
    ob_end_flush();
    exit;
}

$sql = "SELECT exp_id, description, voucher_no FROM expenses
        WHERE is_cancelled = 0 AND exp_type = 'expense'
        AND LOWER(TRIM(supplier)) = LOWER(TRIM('" . DB::escape($conn, $supplier) . "'))
        AND amount = " . $amount . "
        AND exp_date = '" . DB::escape($conn, $exp_date) . "'";
if ($exclude_id > 0) $sql .= " AND exp_id != $exclude_id";
$sql .= " LIMIT 1";

$row = DB::fetch_assoc(DB::query($conn, $sql));

if ($row) {
    echo json_encode([
        'duplicate'   => true,
        'exp_id'      => (int)$row['exp_id'],
        'description' => $row['description'] ?? '',
        'voucher_no'  => $row['voucher_no'] ?? null,
    ]);
} else {
    echo json_encode(['duplicate' => false]);
}
ob_end_flush();
exit;
?>
