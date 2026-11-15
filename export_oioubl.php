<?php # /export_oioubl.php v:1.3.1 d:2026-09-15 i:evs
// OIOUBL 2.1 e-faktura/e-kreditnota som download (knappen "OIOUBL XML" i invoice_view.php, ?id=<inv_id>).
// Selve dokumentet bygges af oioubl_document() i inc/oioubl.inc.php; en kopi arkiveres i storage/einvoices/.

ob_start(); // fang evt. tilfældig output fra includes, så header() virker bagefter
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';        // kræver login (redirect til login.php ellers)
require_once 'inc/php2htm.lib.php';     // lang()
require_once 'inc/oioubl.inc.php';

// OIOUBL er det danske offentlige e-fakturaformat (NemHandel) - giver kun mening med DKK som bogføringsvaluta
require_country_feature($conn, 'oioubl');

$inv_id = (int)($_GET['id'] ?? 0);
if ($inv_id <= 0) { ob_end_clean(); http_response_code(400); die('Invalid invoice id'); }
try {
    $doc = oioubl_document($conn, $inv_id);
} catch (Exception $e) {
    ob_end_clean(); http_response_code(409); die(htmlspecialchars($e->getMessage()));
}

// Arkivér kopi (best-effort) - samme filnavn ved ny eksport, så filens mtime er seneste eksport
$archive_dir = is_dir(__DIR__ . '/storage/einvoices') ? rtrim(tc_account_storage_dir(__DIR__ . '/storage/einvoices'), '/') : __DIR__ . '/storage/einvoices';      // (eget regnskab)
if (is_dir($archive_dir) && is_writable($archive_dir)) {
    @file_put_contents($archive_dir . '/' . $doc['filename'], $doc['xml']);
}

ob_end_clean(); // smid evt. buffret output fra includes væk, så kun XML sendes
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $doc['filename'] . '"');
header('Content-Length: ' . strlen($doc['xml']));
echo $doc['xml'];
exit;
