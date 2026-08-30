<?php # /inc/mail_handler.lib.php v:1.3.0 d:2026-08-30 i:evs
# Cleaned - Dompdf Removed
# v1.3.0: SMTPSecure var hardkodet til STARTTLS uanset MAIL_PORT (fejlede for
# port 465/implicit SSL) + manglede en eksplicit $mail->Timeout (faldt
# tilbage til PHPMailers standard på 300 sek.). Fundet ved en mail-/
# notifikationsgennemgang - samme fund som tidligere rettet i
# inc/auto_backup.inc.php.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Hent de nødvendige afhængigheder med absolutte stier via __DIR__
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/mail_config.inc.php';

// generateInvoicePDF() ER SLETTET, DA BROWSEREN (html2pdf.js) NU GENERERER FILEN

// NYT (§bugs-batch-31-review): $doc_label var FØR hardkodet til "Faktura" -
// vedhæftningens filnavn blev derfor ALTID "Faktura_<nr>.pdf", selv når
// send_quote_action.php (se [[quotes-feature]]) sendte et TILBUD, ikke en
// faktura. En kunde der modtog et tilbud pr. mail ville se en vedhæftning
// der påstod at være "Faktura_1.pdf" - forvirrende i sig selv, og i værste
// fald risiko for at modtagerens eget bogholderi fejlagtigt registrerede et
// tilbud som en rigtig faktura, blot fordi filnavnet sagde det. Tilføjet et
// valgfrit $doc_label-parameter, med "Faktura" som standard - eksisterende
// kald (send_invoice_action.php/reminder_action.php) er derfor 100% uændrede.
function sendTinyMail($toEmail, $toName, $subject, $body, $attachment = null, $invoice_no = '', $doc_label = 'Faktura') {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        // Var hardkodet til STARTTLS uanset port - fejlede for enhver
        // opsætning med MAIL_PORT=465 (implicit SSL, forkert protokol for
        // STARTTLS). Samme forgrening som inc/auto_backup.inc.php bruger.
        $mail->SMTPSecure = (MAIL_PORT == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        // Kort, eksplicit timeout - uden denne falder PHPMailer tilbage til
        // sin egen standard på 300 sekunder, og en langsom/uopnåelig SMTP-
        // server kunne hænge fakturaafsendelsen i op til 5 minutter. Samme
        // fund som blev rettet i inc/auto_backup.inc.php.
        $mail->Timeout    = 15;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_REPLY_TO);
        $mail->addBCC(MAIL_USER); 

        // Modtager den færdige fysiske PDF fra browser-uploaden
        if ($attachment && file_exists($attachment)) {
            $pæntNavn = ($invoice_no) ? "{$doc_label}_" . $invoice_no . ".pdf" : "{$doc_label}.pdf";
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
