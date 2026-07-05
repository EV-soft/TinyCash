<?php # /send_invoice_action.php v:1.1.0 d:2026-07-05 i:evs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    require_once 'inc/auth.inc.php';
    require_once 'inc/db_connect.inc.php';
    require_once 'inc/mail_handler.lib.php';
    require_once 'inc/php2htm.lib.php';
    
    // AUTOMATISK KONTROL AF DATABASETABEL (Fakta-sikring)
    DB::query($conn, "CREATE TABLE IF NOT EXISTS `tbl_maillog` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `faktura_id` int(11) DEFAULT NULL,
      `modtager_mail` varchar(255) NOT NULL,
      `afsendt_dato` datetime NOT NULL,
      `status` enum('Sendt','Fejl') NOT NULL DEFAULT 'Sendt',
      `server_respons` text DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        echo json_encode(['success' => false, 'error' => lang('@Invalid ID')]);
        exit;
    }
    
    $sql = "SELECT i.*, c.cust_name, c.cust_email FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
    $res = DB::query($conn, $sql);
    if (!$res) throw new Exception("Database error: " . DB::error($conn));
    $inv = DB::fetch_assoc($res);
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
    
    // Her sikrer vi os, at funktionen get_settings rent faktisk eksisterer i dit miljø
    $sys_settings = function_exists('get_settings') ? get_settings($conn) : [];
    
    if (isset($_POST['custom_body']) && trim($_POST['custom_body']) !== '') {
        $body = nl2br(htmlspecialchars($_POST['custom_body']));
    } else {
        $fallback_text = !empty($sys_settings['default_mail_body']) 
            ? $sys_settings['default_mail_body'] 
            : "Hi " . htmlspecialchars($inv['cust_name']) . ",\n\nPlease find your invoice attached.\n\nBest regards,";
        $body = nl2br(htmlspecialchars($fallback_text));
    }
    
    // KØR SELVE AFSENDELSEN
    $result = sendTinyMail($inv['cust_email'], $inv['cust_name'], $subject, $body, $pdfPath, $invoice_number);
    
    // ANALYSER REEL STATUS FRA MAIL-HANDLEREN
    if (isset($result['success']) && $result['success'] == true) {
        $log_status = 'Sendt';
        $log_msg = 'Mail afleveret fejlfrit til den lokale mail-server/relay.';
        
        // Marker fakturaen som afsendt i hovedtabellen
        DB::query($conn, "UPDATE invoices SET inv_status = 'sent' WHERE inv_id = $id");
        
        // Slet den midlertidige PDF fra systemet
        if (file_exists($pdfPath)) unlink($pdfPath);
    } else {
        $log_status = 'Fejl';
        $log_msg = isset($result['error']) ? $result['error'] : 'Ukendt fejl opstået i sendTinyMail()biblioteket.';
    }
    
    // SKRIV DET HISTORISKE BEVIS (Ingen gætværk, rene facts i databasen)
    $stmt = DB::prepare($conn, "INSERT INTO tbl_maillog (faktura_id, modtager_mail, afsendt_dato, status, server_respons) VALUES (?, ?, NOW(), ?, ?)");
    DB::stmt_bind_param($stmt, "isss", $id, $inv['cust_email'], $log_status, $log_msg);
    DB::stmt_execute($stmt);
    DB::stmt_close($stmt);

    echo json_encode($result);
    exit;

} catch (Throwable $e) {
    $sys_err = lang('@PHP Error:') . ' ' . $e->getMessage() . ' ' . lang('@in') . ' ' . $e->getFile() . ' ' . lang('@on line') . ' ' . $e->getLine();
    
    // Hvis alt bryder sammen i PHP, gemmes den kritiske systemfejl også i databasen, så sporet ikke tabes
    if (isset($conn) && isset($id) && $id > 0) {
        $err_status = 'Fejl';
        $stmt = DB::prepare($conn, "INSERT INTO tbl_maillog (faktura_id, modtager_mail, afsendt_dato, status, server_respons) VALUES (?, ?, NOW(), ?, ?)");
        $fallback_mail = isset($inv['cust_email']) ? $inv['cust_email'] : 'unknown';
        DB::stmt_bind_param($stmt, "isss", $id, $fallback_mail, $err_status, $sys_err);
        DB::stmt_execute($stmt);
        DB::stmt_close($stmt);
    }

    echo json_encode([
        'success' => false, 
        'error' => $sys_err
    ]);
    exit;
}
?>