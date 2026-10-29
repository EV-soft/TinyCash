<?php # /send_invoice_action.php v:1.3.0 d:2026-08-30 i:evs
# AJAX-handler der mailer en faktura til kunden. Kræver at fakturaen allerede
# er bogført (kladder kan ikke sendes) - forhindrer at en usset faktura ender
# i kundens indbakke uden nogensinde at være bogført. Ruller status til
# 'sent' EFTER afsendelse, men kun hvis fakturaen ikke allerede er 'paid'
# eller 'credited' (en gensendelse må ikke rulle en betalt/krediteret
# fakturas status tilbage). Markeringen logges til revisionssporet.
// RETTET: display_errors=1 skrev enhver PHP-advarsel ind i JSON-svaret - browseren kunne så ikke læse
// det ("Netværksfejl", stille fejlet arkivering). Fejl logges i stedet; svaret er ren JSON.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    require_once 'inc/auth.inc.php';
    require_once 'inc/db_connect.inc.php';
    require_once 'inc/mail_handler.lib.php';
    require_once 'inc/php2htm.lib.php';
    require_once 'inc/audit.inc.php';
    require_once 'inc/invoice_pdf_archive.inc.php';

    // AUTOMATISK KONTROL AF DATABASETABEL (Fakta-sikring) - nu cross-engine.
    // Den gamle version brugte ren MySQL-syntaks (AUTO_INCREMENT uden INTEGER
    // PRIMARY KEY, ENUM, afsluttende ENGINE=...), som er en syntaksfejl på
    // SQLite - tabellen blev derfor ALDRIG oprettet på SQLite-installationer.
    if (DB::is_sqlite()) {
        DB::query($conn, "CREATE TABLE IF NOT EXISTS tbl_maillog (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          faktura_id INTEGER,
          modtager_mail TEXT NOT NULL,
          afsendt_dato TIMESTAMP NOT NULL,
          status TEXT NOT NULL DEFAULT 'Sendt',
          server_respons TEXT
        )");
    } else {
        DB::query($conn, "CREATE TABLE IF NOT EXISTS `tbl_maillog` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `faktura_id` int(11) DEFAULT NULL,
          `modtager_mail` varchar(255) NOT NULL,
          `afsendt_dato` datetime NOT NULL,
          `status` enum('Sendt','Fejl') NOT NULL DEFAULT 'Sendt',
          `server_respons` text DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        echo json_encode(['success' => false, 'error' => lang('@Invalid ID')]);
        exit;
    }

    $has_cust_lang_col_si = false;
    if (DB::is_sqlite()) {
        $clgc_si = DB::query($conn, "PRAGMA table_info(customers)");
        if ($clgc_si) { while ($clgcr_si = DB::fetch_assoc($clgc_si)) { if ($clgcr_si['name'] === 'cust_language') { $has_cust_lang_col_si = true; break; } } }
    } else {
        $clgc_si = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_language'");
        $has_cust_lang_col_si = ($clgc_si && DB::num_rows($clgc_si) > 0);
    }
    $cust_lang_select_si = $has_cust_lang_col_si ? ", c.cust_language" : "";

    $sql = "SELECT i.*, c.cust_name, c.cust_email $cust_lang_select_si FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
    $res = DB::query($conn, $sql);
    if (!$res) throw new Exception("Database error: " . DB::error($conn));
    $inv = DB::fetch_assoc($res);
    if (!$inv) {
        echo json_encode(['success' => false, 'error' => lang('@Invoice or customer not found')]);
        exit;
    }

    // KRITISK: en kladde-faktura (aldrig bogført - intet fakturanummer,
    // ingen posteringer, intet lagertræk) kunne før mailes til kunden og
    // derefter blive markeret 'sent' nedenfor, som om den var en færdig,
    // bogført faktura - fakturaindhold og bogholderi kunne dermed glide fra
    // hinanden. Kræv nu at fakturaen er bogført (invoice_post_action.php)
    // FØR den kan sendes. Fundet ved en faktura-/fakturaflow-gennemgang.
    if (strtolower($inv['inv_status']) === 'draft') {
        echo json_encode(['success' => false, 'error' => lang('@This invoice is still a draft and must be posted before it can be sent.')]);
        exit;
    }

    // RETTET (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]):
    // gemte FØR kun PDF'en som en MIDLERTIDIG arbejdsfil direkte i storage/
    // (til selve mail-vedhæftningen) og SLETTEDE den igen efter en vellykket
    // afsendelse (se den fjernede unlink() nedenfor) - kun en faktura, hvis
    // afsendelse reelt FEJLEDE, beholdt ved et tilfælde sin PDF-kopi. Bruger
    // nu den delte invoice_store_uploaded_pdf() (inc/invoice_pdf_archive.
    // inc.php), som gemmer PERMANENT under storage/invoices/ - samme fil
    // bruges nu til BÅDE selve mail-vedhæftningen OG den varige arkivering.
    $store_result = invoice_store_uploaded_pdf($conn, $id);
    if (!$store_result['success']) {
        echo json_encode(['success' => false, 'error' => $store_result['error']]);
        exit;
    }
    $pdfPath = $store_result['path'];

    // RETTET: en kreditnota fik "Faktura #<intern id>" i emnelinjen - nu "Kreditnota KN-000012"
    $is_credit_note = !empty($inv['credit_ref']);
    $invoice_number = !empty($inv['invoice_no']) ? $inv['invoice_no'] : ($is_credit_note && !empty($inv['credit_note_no']) ? 'KN-' . str_pad($inv['credit_note_no'], 6, '0', STR_PAD_LEFT) : $id);
    $f_no = !empty($inv['invoice_no']) ? '#' . str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT)
          : ($is_credit_note && !empty($inv['credit_note_no']) ? 'KN-' . str_pad($inv['credit_note_no'], 6, '0', STR_PAD_LEFT) : '#' . $id);
    $doc_key  = $is_credit_note ? '@Credit Note' : '@Invoice';
    $text_key = $is_credit_note ? "@Hi %s,\n\nPlease find your credit note attached.\n\nBest regards," : "@Hi %s,\n\nPlease find your invoice attached.\n\nBest regards,";

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
    // samme princip som invoice_view.php's papir-sprogskift - kun emnelinjen
    // (den eneste lang()-afhængige del af selve mailen her) skiftes
    // midlertidigt til kundens sprog, straks tilbagerullet bagefter.
    if ($has_cust_lang_col_si && !empty($inv['cust_language'])) {
        $si_lang_orig = $_SESSION['lang'] ?? 'da';
        $_SESSION['lang'] = $inv['cust_language'];
        $subject = lang($doc_key) . ' ' . $f_no;
        $default_text = sprintf(lang($text_key), $inv['cust_name']);
        $_SESSION['lang'] = $si_lang_orig;
    } else {
        $subject = lang($doc_key) . ' ' . $f_no;
        $default_text = sprintf(lang($text_key), $inv['cust_name']);
    }

    // Her sikrer vi os, at funktionen get_settings rent faktisk eksisterer i dit miljø
    $sys_settings = function_exists('get_settings') ? get_settings($conn) : [];

    if (isset($_POST['custom_body']) && trim($_POST['custom_body']) !== '') {
        $body = nl2br(htmlspecialchars($_POST['custom_body']));
    } else {
        // RETTET: standardteksten var hårdkodet engelsk ("Hi ..., Please find your invoice attached") - også til en
        // dansk kunde og uanset kundens fakturasprog - og kundenavnet blev escapet to gange ("A &amp;amp; B").
        // Nu en oversat tekst på kundens sprog (se emnelinjen ovenfor), escapet én gang (herunder).
        $fallback_text = (!$is_credit_note && tc_custom_mail_body($sys_settings) !== '') ? tc_custom_mail_body($sys_settings) : $default_text;
        $body = nl2br(htmlspecialchars($fallback_text));
    }

    // KØR SELVE AFSENDELSEN
    $result = sendTinyMail($inv['cust_email'], $inv['cust_name'], $subject, $body, $pdfPath, $invoice_number);

    // ANALYSER REEL STATUS FRA MAIL-HANDLEREN
    if (isset($result['success']) && $result['success'] == true) {
        $log_status = 'Sendt';
        $log_msg = 'Mail afleveret fejlfrit til den lokale mail-server/relay.';

        // RETTET (se [[bugs-batch-10-review]]): satte ubetinget status til
        // 'sent', selv hvis fakturaen allerede var 'paid' eller 'credited' -
        // at gensende en kopi af en allerede betalt faktura (fx fordi kunden
        // bad om det igen) rullede den fejlagtigt tilbage til "afventer
        // betaling" i alle lister/rapporter, selvom betalingen og
        // posteringerne stod urørt. Kun 'sent' hvis den ikke allerede er
        // nået længere end det.
        // (RETTET: også en kreditnota - status 'credit' - blev sat til 'sent' og lignede så en ubetalt faktura)
        if (!in_array(strtolower($inv['inv_status']), ['paid', 'credited', 'credit'], true)) {
            DB::query($conn, "UPDATE invoices SET inv_status = 'sent' WHERE inv_id = $id");
            log_action($conn, 'MARK_INVOICE_SENT', 'invoices', $id,
                ['status' => $inv['inv_status']], ['status' => 'sent', 'to' => $inv['cust_email']]);
        }

        // RETTET (se den store kommentar ved selve lagrings-kaldet ovenfor):
        // PDF'en er nu en PERMANENT arkivkopi (bogføringslov-opbevaringskrav),
        // ikke en midlertidig arbejdsfil - slettes derfor IKKE længere her,
        // uanset om afsendelsen lykkedes.
    } else {
        $log_status = 'Fejl';
        $log_msg = isset($result['error']) ? $result['error'] : 'Ukendt fejl opstået i sendTinyMail()biblioteket.';
    }

    // SKRIV DET HISTORISKE BEVIS - via DB::insert() (cross-engine, parameteriseret,
    // ingen manuel stmt-håndtering og ingen MySQL-specifik NOW()).
    DB::insert($conn, 'tbl_maillog', [
        'faktura_id'     => $id,
        'modtager_mail'  => $inv['cust_email'],
        'afsendt_dato'   => date('Y-m-d H:i:s'),
        'status'         => $log_status,
        'server_respons' => $log_msg,
    ]);

    echo json_encode($result);
    exit;

} catch (Throwable $e) {
    error_log('[send_invoice_action] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $sys_err = lang('@PHP Error:') . ' ' . $e->getMessage();      // (fil og linje kun i loggen - ikke serverens stier til browseren)

    // Log-forsøget må ALDRIG selv kunne kaste en uhåndteret fejl videre - det
    // var netop det der før gjorde en almindelig fejl (fx mail-serverfejl) til
    // en hård 500 i stedet for en pæn JSON-fejlbesked til browseren.
    try {
        if (isset($conn) && isset($id) && $id > 0) {
            DB::insert($conn, 'tbl_maillog', [
                'faktura_id'     => $id,
                'modtager_mail'  => isset($inv['cust_email']) ? $inv['cust_email'] : 'unknown',
                'afsendt_dato'   => date('Y-m-d H:i:s'),
                'status'         => 'Fejl',
                'server_respons' => $sys_err,
            ]);
        }
    } catch (Throwable $log_e) {
        error_log('[send_invoice_action] Kunne heller ikke logge fejlen: ' . $log_e->getMessage());
    }

    echo json_encode([
        'success' => false,
        'error' => $sys_err
    ]);
    exit;
}
?>
