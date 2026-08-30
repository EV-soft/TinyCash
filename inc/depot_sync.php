<?php # /inc/depot_sync.php v:1.3.0 d:2026-08-30 i:evs
# v1.0.1: rettet "in-array" (bindestreg) -> "in_array" - gav en fatal
# runtime-fejl ("Undefined constant 'in'"), ikke fanget af php -l, men
# bekræftet ved en direkte kørsel. Fundet ved en mail-/notifikationsgennemgang.
# BEMÆRK: denne fils IMAP-nøgler (IMAP_MAILBOX/IMAP_USERNAME/IMAP_PASSWORD)
# matcher IKKE resten af projektets konvention (IMAP_INVOICE_*/IMAP_VOUCHER_*/
# IMAP_VENDOR_* i env.ini) og funktionen er ikke linket fra nogen menu/knap -
# ser ud til at være en ufærdig, forladt prototype. Kun typo'en er rettet her,
# ikke funktionens design/tilkobling.
# /depot_sync.php v:1.0.0 d:2026-06-11 i:gemini ok
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

$msg = ""; $err = "";

// 1. Live IMAP Tjek (Email-mappen)
// Hent IMAP-indstillinger sikkert fra .env eller server-miljø
$mailbox  = $_ENV['IMAP_MAILBOX']  ?? $_SERVER['IMAP_MAILBOX']  ?? '{imap.ditdomæne.dk:993/imap/ssl}INBOX';
$username = $_ENV['IMAP_USERNAME'] ?? $_SERVER['IMAP_USERNAME'] ?? '';
$password = $_ENV['IMAP_PASSWORD'] ?? $_SERVER['IMAP_PASSWORD'] ?? '';

if (empty($username) || empty($password)) {
    // Hvis de ikke findes i miljøet, så lav et hurtigt fallback-tjek direkte i .env-filen
    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) { $envPath = __DIR__ . '/../.env'; } // Tjek også overmappen
    
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        if (preg_match('/IMAP_MAILBOX\s*=\s*["\']?([^"\'\s\n]+)/', $envContent, $matches))  { $mailbox = $matches[1]; }
        if (preg_match('/IMAP_USERNAME\s*=\s*["\']?([^"\'\s\n]+)/', $envContent, $matches)) { $username = $matches[1]; }
        if (preg_match('/IMAP_PASSWORD\s*=\s*["\']?([^"\'\s\n]+)/', $envContent, $matches)) { $password = $matches[1]; }
    }
}

if (!function_exists('imap_open')) {
    $err = "PHP IMAP-udvidelsen er ikke aktiveret på denne server.";
} else {
    // Forbind live til mailboksen
    $inbox = @imap_open($mailbox, $username, $password);
    
    if ($inbox) {
        $emails = imap_search($inbox, 'UNSEEN'); // Find kun ulæste mails
        $nye_filer = 0;

        if ($emails) {
            $target_dir = __DIR__ . '/storage/bilagsdepot/email/';
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

            foreach ($emails as $email_number) {
                $structure = imap_fetchstructure($inbox, $email_number);
                
                // Funktion til at trække vedhæftninger ud (forenklet oversigt)
                if (isset($structure->parts) && count($structure->parts)) {
                    for ($i = 0; $i < count($structure->parts); $i++) {
                        $part = $structure->parts[$i];
                        
                        // Tjek om delen er en fil (disposition = attachment)
                        if ($part->ifdparameters) {
                            foreach ($part->dparameters as $object) {
                                if (strtolower($object->attribute) == 'filename') {
                                    $filename = $object->value;
                                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                    
                                    // Tillad kun PDF og billeder
                                    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
                                        $body = imap_fetchbody($inbox, $email_number, $i+1);
                                        
                                        // Afkod filindholdet (typisk Base64)
                                        if ($part->encoding == 3) $body = base64_decode($body);
                                        elseif ($part->encoding == 4) $body = quoted_printable_decode($body);
                                        
                                        // Gem filen i depotets email-mappe
                                        $safe_filename = "MAIL_" . time() . "_" . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $filename);
                                        file_put_contents($target_dir . $safe_filename, $body);
                                        $nye_filer++;
                                    }
                                }
                            }
                        }
                    }
                }
                // Marker mailen som læst, så den ikke hentes igen næste gang
                imap_setflag_full($inbox, $email_number, "\\Seen");
            }
        }
        imap_close($inbox);
        $msg = $nye_filer > 0 ? "sync_ok&filer=" . $nye_filer : "sync_empty";
    } else {
        $err = "Kunne ikke forbinde til mailboksen: " . imap_last_error();
    }
}

// Send brugeren tilbage til udgiftssiden med statusbesked
if (!empty($err)) {
    header("Location: expense_edit.php?id=" . (isset($_GET['id']) ? (int)$_GET['id'] : 0) . "&err=" . urlencode($err));
} else {
    header("Location: expense_edit.php?id=" . (isset($_GET['id']) ? (int)$_GET['id'] : 0) . "&msg=" . $msg);
}
exit;
ob_end_flush();
?>
