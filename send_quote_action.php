<?php # /send_quote_action.php v:1.3.0 d:2026-08-30 i:evs
# AJAX-handler der mailer et tilbud/en ordrebekræftelse til kunden - samme
# mønster som send_invoice_action.php, men UDEN dens kladde-låsning: et
# tilbud SKAL netop kunne sendes mens det stadig er 'draft' (det er selve
# afsendelsen der gør det til 'sent') - der er intet "bogført"-krav for et
# tilbud, i modsætning til en faktura.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    require_once 'inc/auth.inc.php';
    require_once 'inc/db_connect.inc.php';
    require_once 'inc/mail_handler.lib.php';
    require_once 'inc/php2htm.lib.php';
    require_once 'inc/audit.inc.php';

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        echo json_encode(['success' => false, 'error' => lang('@Invalid ID')]);
        exit;
    }

    $sql = "SELECT q.*, c.cust_name, c.cust_email FROM quotes q JOIN customers c ON q.cust_id = c.cust_id WHERE q.quote_id = $id";
    $res = DB::query($conn, $sql);
    if (!$res) throw new Exception("Database error: " . DB::error($conn));
    $q = DB::fetch_assoc($res);
    if (!$q) {
        echo json_encode(['success' => false, 'error' => lang('@Invoice or customer not found')]);
        exit;
    }

    if (empty($q['cust_email'])) {
        echo json_encode(['success' => false, 'error' => lang('@This customer has no email address registered.')]);
        exit;
    }

    $dir = __DIR__ . '/storage';
    $pdfPath = $dir . '/quote_' . $id . '.pdf';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfPath);
    } elseif (isset($_POST['pdf_data'])) {
        $data = $_POST['pdf_data'];
        if (strpos($data, ',') !== false) $data = explode(',', $data)[1];
        file_put_contents($pdfPath, base64_decode($data));
    } else {
        echo json_encode(['success' => false, 'error' => lang('@No PDF data received from the browser.')]);
        exit;
    }

    if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
        echo json_encode(['success' => false, 'error' => lang('@PDF file could not be saved or was empty.')]);
        exit;
    }

    $quote_no_display = 'T-' . str_pad((string)$q['quote_no'], 6, '0', STR_PAD_LEFT);
    $is_confirmation = in_array($q['status'], ['accepted', 'converted'], true);
    $doc_title = $is_confirmation ? lang('@Order Confirmation') : lang('@Quote');
    $subject = $doc_title . ' ' . $quote_no_display;
    // ASCII-sikkert (uden æ/ø/å) til selve vedhæftningens filnavn - se
    // sendTinyMail()'s nye $doc_label-parameter, §bugs-batch-31-review.
    $doc_label = $is_confirmation ? 'Ordrebekraeftelse' : 'Tilbud';

    $sys_settings = function_exists('get_settings') ? get_settings($conn) : [];
    if (isset($_POST['custom_body']) && trim($_POST['custom_body']) !== '') {
        $body = nl2br(htmlspecialchars($_POST['custom_body']));
    } else {
        $fallback_text = !empty($sys_settings['default_mail_body'])
            ? $sys_settings['default_mail_body']
            : "Hi " . htmlspecialchars($q['cust_name']) . ",\n\nPlease find your quote attached.\n\nBest regards,";
        $body = nl2br(htmlspecialchars($fallback_text));
    }

    $result = sendTinyMail($q['cust_email'], $q['cust_name'], $subject, $body, $pdfPath, $q['quote_no'], $doc_label);

    if (isset($result['success']) && $result['success'] == true) {
        // Kun 'draft' -> 'sent' - en gensendelse af et allerede accepteret/
        // afvist/konverteret tilbud må ikke rulle den reelle kunde-beslutning
        // tilbage (samme princip som send_invoice_action.php's paid/credited-
        // undtagelse).
        if ($q['status'] === 'draft') {
            DB::query($conn, "UPDATE quotes SET status = 'sent' WHERE quote_id = $id");
            log_action($conn, 'MARK_QUOTE_SENT', 'quotes', $id, ['status' => 'draft'], ['status' => 'sent', 'to' => $q['cust_email']]);
        }
        if (file_exists($pdfPath)) unlink($pdfPath);
    }

    echo json_encode($result);
    exit;

} catch (Throwable $e) {
    $sys_err = lang('@PHP Error:') . ' ' . $e->getMessage() . ' ' . lang('@in') . ' ' . $e->getFile() . ' ' . lang('@on line') . ' ' . $e->getLine();
    echo json_encode(['success' => false, 'error' => $sys_err]);
    exit;
}
?>
