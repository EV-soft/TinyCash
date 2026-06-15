<?php # /send_invoice_action.php v:1.0.0 d:2026-06-15 i:evs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
try {
    require_once 'inc/auth.inc.php';
    require_once 'inc/db_connect.inc.php';
    require_once 'inc/mail_handler.lib.php';
    require_once 'inc/php2htm.lib.php';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        echo json_encode(['success' => false, 'error' => lang('@Invalid ID')]);
        exit;
    }
    $sql = "SELECT i.*, c.cust_name, c.cust_email FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
    $res = mysqli_query($conn, $sql);
    if (!$res) throw new Exception("Database error: " . mysqli_error($conn));
    $inv = mysqli_fetch_assoc($res);
    if (!$inv) {
        echo json_encode(['success' => false, 'error' => lang('@Invoice or customer not found')]);
        exit;
    }
    $dir = __DIR__ . '/storage';
    $pdfPath = $dir . '/invoice_' . $id . '.pdf';
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
    $invoice_number = !empty($inv['invoice_no']) ? $inv['invoice_no'] : $id;
    $f_no = !empty($inv['invoice_no']) ? '#' . str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT) : '#' . $id;
    $subject = lang('@Invoice') . ' ' . $f_no;
    $sys_settings = get_settings($conn);
    if (isset($_POST['custom_body']) && trim($_POST['custom_body']) !== '') {
        $body = nl2br(htmlspecialchars($_POST['custom_body']));
    } else {
        $fallback_text = !empty($sys_settings['default_mail_body']) 
            ? $sys_settings['default_mail_body'] 
            : "Hi " . htmlspecialchars($inv['cust_name']) . ",\n\nPlease find your invoice attached.\n\nBest regards,";
        $body = nl2br(htmlspecialchars($fallback_text));
    }
    $result = sendTinyMail($inv['cust_email'], $inv['cust_name'], $subject, $body, $pdfPath, $invoice_number);
    if (isset($result['success']) && $result['success'] == true) {
        mysqli_query($conn, "UPDATE invoices SET inv_status = 'sent' WHERE inv_id = $id");
        if (file_exists($pdfPath)) unlink($pdfPath);
    }
    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    $sys_err = lang('@PHP Error:') . ' ' . $e->getMessage() . ' ' . lang('@in') . ' ' . $e->getFile() . ' ' . lang('@on line') . ' ' . $e->getLine();
    echo json_encode([
        'success' => false, 
        'error' => $sys_err
    ]);
    exit;
}
?>