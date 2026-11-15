<?php # /inc/mail_handler.lib.php v:1.3.1 d:2026-09-15 i:evs
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

// NYT: $doc_label var FØR hardkodet til "Faktura" - vedhæftningens filnavn blev derfor ALTID "Faktura_<nr>.pdf",
// selv når send_quote_action.php sendte et TILBUD, ikke en faktura. Tilføjet et valgfrit $doc_label-parameter, med
// "Faktura" som standard - eksisterende kald (send_invoice_action.php/reminder_action.php) er derfor 100%
// uændrede.
// Den vedhæftede PDF's navn på det sprog der er aktivt nu (kundens): dansk som hidtil, ellers den oversatte titel.
// Før hed filen altid "Faktura_1001.pdf" / "Rykker_..." / "Kontoudtog.pdf" - også til en engelsk eller tysk kunde.
function tc_mail_doc_label(string $da_label, string $key): string {
    return (($_SESSION['lang'] ?? (function_exists('tc_default_lang') ? tc_default_lang() : 'da')) === 'da') ? $da_label : lang($key);
}
function sendTinyMail($toEmail, $toName, $subject, $body, $attachment = null, $invoice_no = '', $doc_label = 'Faktura') {
    // (filnavnet holdes ASCII - æ/ø/å og andre tegn gav et forvansket navn i flere mailprogrammer)
    $doc_label = preg_replace('/[^A-Za-z0-9_-]+/', '_', strtr((string)$doc_label, ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'é' => 'e', 'è' => 'e', 'ó' => 'o', 'á' => 'a', 'ą' => 'a', 'ę' => 'e', 'ł' => 'l', 'ś' => 's', 'ż' => 'z', 'ź' => 'z', 'ć' => 'c', 'ń' => 'n', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ė' => 'e', 'ū' => 'u', 'ī' => 'i', 'ā' => 'a', 'ē' => 'e', 'õ' => 'o'])) ?: 'Dokument';
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
        _mail_log_entry($toEmail, $subject, true, null);
        return ['success' => true];
    } catch (Exception $e) {
        _mail_log_entry($toEmail, $subject, false, $mail->ErrorInfo);
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}

// NYT: logger AL udgående post ét sted (db-setup/migrate_mail_log.php's mail_log-tabel) - sendTinyMail er den
// ENESTE funktion i hele kodebasen der reelt sender mail (bekræftet ved en fuld gennemsøgning), så et hook her
// fanger automatisk fakturaer/rykkere/kontoudtog/tilbudsopfølgning/backup-testmail uden at hvert enkelt
// afsendelsesflow selv skal huske at logge. Samme "stille degradering hvis ikke migreret endnu"-mønster som resten
// af kodebasens nyere tabeller - $GLOBALS['conn'] bruges direkte (sendTinyMail har ingen $conn-parameter), ingen
// fejl her må nogensinde afbryde selve mailafsendelsen ovenfor.
function _mail_log_entry($to_email, $subject, bool $success, ?string $error): void {
    if (empty($GLOBALS['conn'])) return;
    $conn = $GLOBALS['conn'];
    $exists = db_has_table($conn, 'mail_log');
    if (!$exists) return;
    @DB::insert($conn, 'mail_log', [
        'to_email'      => $to_email,
        'subject'       => $subject,
        'success'       => $success ? 1 : 0,
        'error_message' => $error,
        'user_id'       => (int)($_SESSION['user_id'] ?? 0),
    ]);
}
