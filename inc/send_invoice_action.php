<?php # send_invoice_action.php (Placeret i PROGROOT)
ini_set('display_errors', 1);
error_reporting(E_ALL);
 
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php'; // Hvis templaten bruger htm_ funktioner
require_once 'inc/mail_handler.lib.php';

$id = (int)$_GET['id'];

$sql = "SELECT i.invoice_no, c.cust_name, c.cust_email FROM invoices i 
        JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
$res = mysqli_query($conn, $sql);
$data = mysqli_fetch_assoc($res);

if (!$data) {
    die(json_encode(['success' => false, 'error' => 'Invoice not found']));
}

// Generér HTML
ob_start();
include 'invoice_pdf_template.php'; 
$html = ob_get_clean();
// INDSÆT DISSE TO LINJER MIDLERTIDIGT:
echo "<h3>Dette er indholdet af HTML-variablen:</h3>";
die(htmlspecialchars($html));
// die($html);

// 3. Generér den fysiske PDF (bliver ikke nået lige nu)
$pdfPath = generateInvoicePDF($id, $html);

// Lav PDF
$pdfPath = generateInvoicePDF($id, $html);

// Send Mail
$subject = "Faktura #" . ($data['invoice_no'] ?: $id);
$body = "Hej " . $data['cust_name'] . "<br><br>Vedhæftet finder du din faktura.";

$result = sendTinyMail(
    $data['cust_email'], 
    $data['cust_name'], 
    $subject, 
    $body, 
    $pdfPath, 
    $data['invoice_no']
);

header('Content-Type: application/json');
if ($result['success']) {
    mysqli_query($conn, "UPDATE invoices SET inv_status = 'sent' WHERE inv_id = $id");
}
echo json_encode($result);
?>