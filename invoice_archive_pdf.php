<?php # /invoice_archive_pdf.php v:1.3.1 d:2026-09-15 i:evs
# NYT (bruger-anmodet lukning af BEK 811/2026-gennemgangens fund 2, se
# [[digital-standard-bookkeeping-registration-goal]]): AJAX-endpoint der
# STILLE gemmer en klient-genereret PDF-kopi af en bogført faktura til det
# permanente arkiv (storage/invoices/, se inc/invoice_pdf_archive.inc.php) -
# uden at sende nogen mail, i modsætning til send_invoice_action.php. Kaldes
# automatisk fra invoice_view.php, hver gang en bogført faktura uden
# allerede arkiveret PDF vises (se dens egen "selvhelbredende"-begrundelse) -
# ikke kun når brugeren aktivt trykker "Send via Mail".
// RETTET: display_errors=1 skrev enhver PHP-advarsel ind i JSON-svaret - browseren kunne så ikke læse
// det ("Netværksfejl", stille fejlet arkivering). Fejl logges i stedet; svaret er ren JSON.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    require_once 'inc/auth.inc.php';
    require_once 'inc/db_connect.inc.php';
    require_once 'inc/php2htm.lib.php';
    require_once 'inc/invoice_pdf_archive.inc.php';

    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'error' => lang('@Security check failed (CSRF). Please reload the page and try again.')]);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        echo json_encode(['success' => false, 'error' => lang('@Invalid ID')]);
        exit;
    }

    $inv = DB::fetch_assoc(DB::query($conn, "SELECT inv_id, inv_status FROM invoices WHERE inv_id = $id"));
    if (!$inv) {
        echo json_encode(['success' => false, 'error' => lang('@Invoice or customer not found')]);
        exit;
    }

    // Samme regel som send_invoice_action.php: kun bogførte fakturaer har et
    // fakturanummer at arkivere under - en kladde skal (endnu) ikke
    // arkiveres.
    if (strtolower($inv['inv_status']) === 'draft') {
        echo json_encode(['success' => false, 'error' => lang('@This invoice is still a draft and must be posted before it can be sent.')]);
        exit;
    }

    $result = invoice_store_uploaded_pdf($conn, $id);
    echo json_encode($result);
    exit;

} catch (Throwable $e) {
    error_log('[invoice_archive_pdf] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => lang('@PHP Error:') . ' ' . $e->getMessage(),      // (fil og linje kun i loggen)
    ]);
    exit;
}
?>
