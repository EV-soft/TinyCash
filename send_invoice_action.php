<?php # /send_invoice_action.php v:1.3.0 d:2026-08-30 i:evs
# AJAX-handler der mailer en faktura til kunden. Kræver at fakturaen allerede
# er bogført (kladder kan ikke sendes) - forhindrer at en usset faktura ender
# i kundens indbakke uden nogensinde at være bogført. Ruller status til
# 'sent' EFTER afsendelse, men kun hvis fakturaen ikke allerede er 'paid'
# eller 'credited' (en gensendelse må ikke rulle en betalt/krediteret
# fakturas status tilbage). Markeringen logges til revisionssporet.
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

    $sql = "SELECT i.*, c.cust_name, c.cust_email FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
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

        // RETTET (se [[bugs-batch-10-review]]): satte ubetinget status til
        // 'sent', selv hvis fakturaen allerede var 'paid' eller 'credited' -
        // at gensende en kopi af en allerede betalt faktura (fx fordi kunden
        // bad om det igen) rullede den fejlagtigt tilbage til "afventer
        // betaling" i alle lister/rapporter, selvom betalingen og
        // posteringerne stod urørt. Kun 'sent' hvis den ikke allerede er
        // nået længere end det.
        if (!in_array(strtolower($inv['inv_status']), ['paid', 'credited'], true)) {
            DB::query($conn, "UPDATE invoices SET inv_status = 'sent' WHERE inv_id = $id");
            log_action($conn, 'MARK_INVOICE_SENT', 'invoices', $id,
                ['status' => $inv['inv_status']], ['status' => 'sent', 'to' => $inv['cust_email']]);
        }

        // Slet den midlertidige PDF fra systemet
        if (file_exists($pdfPath)) unlink($pdfPath);
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
    $sys_err = lang('@PHP Error:') . ' ' . $e->getMessage() . ' ' . lang('@in') . ' ' . $e->getFile() . ' ' . lang('@on line') . ' ' . $e->getLine();

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
