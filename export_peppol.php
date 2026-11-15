<?php # /export_peppol.php v:1.3.1 d:2026-09-15 i:evs
// Peppol BIS Billing 3.0 e-faktura/e-kreditnota som download (knappen "Peppol XML" i invoice_view.php, ?id=<inv_id>).
// Selve dokumentet bygges af peppol_document() i inc/peppol.inc.php; en kopi arkiveres i storage/einvoices/ som OIOUBL.
ob_start();
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/peppol.inc.php';

$inv_id = (int)($_GET['id'] ?? 0);
if ($inv_id <= 0) { ob_end_clean(); http_response_code(400); die('Invalid invoice id'); }
try {
    $doc = peppol_document($conn, $inv_id);
} catch (Exception $e) {
    ob_end_clean(); http_response_code(409); die(htmlspecialchars($e->getMessage()));
}

$archive_dir = is_dir(__DIR__ . '/storage/einvoices') ? rtrim(tc_account_storage_dir(__DIR__ . '/storage/einvoices'), '/') : __DIR__ . '/storage/einvoices';
if (is_dir($archive_dir) && is_writable($archive_dir)) {
    @file_put_contents($archive_dir . '/' . $doc['filename'], $doc['xml']);
}

ob_end_clean();
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $doc['filename'] . '"');
header('Content-Length: ' . strlen($doc['xml']));
echo $doc['xml'];
exit;
