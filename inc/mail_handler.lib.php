<?php
# inc/mail_handler.lib.php v:1.2.1 (Cleaned - Dompdf Removed)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Hent de nødvendige afhængigheder med absolutte stier via __DIR__
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/mail_config.inc.php';

// generateInvoicePDF() ER SLETTET, DA BROWSEREN (html2pdf.js) NU GENERERER FILEN

function sendTinyMail($toEmail, $toName, $subject, $body, $attachment = null, $invoice_no = '') {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_REPLY_TO);
        $mail->addBCC(MAIL_USER); 

        // Modtager den færdige fysiske PDF fra browser-uploaden
        if ($attachment && file_exists($attachment)) {
            $pæntNavn = ($invoice_no) ? "Faktura_" . $invoice_no . ".pdf" : "Faktura.pdf";
            $mail->addAttachment($attachment, $pæntNavn);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}