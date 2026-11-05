<?php # /customer_statement_send.php v:1.3.2 d:2026-09-15 i:evs
# NY (bruger-anmodet, "kan bogholderen ønske sig mere"): sender kundens
# kontoudtog (nøjagtig samme periode/data som customer_statement.php netop
# viste) direkte som en mail til kunden - genbruger samme sendTinyMail()-
# motor som fakturaer/rykkere allerede bruger (inc/mail_handler.lib.php),
# ingen ny SMTP-kode. Bygger en simpel HTML-tabel direkte i mailkroppen
# (samme "ingen PDF-vedhæftning"-tilgang som reminder_action.php), ikke en
# fuld kopi af print-visningens layout.
#
# RETTET (bruger-anmodet, "kan bogholderen ønske sig mere", ny runde): al
# logik for at sende ÉT kontoudtog er nu udtrukket til send_single_statement(),
# så både det oprindelige enkelt-GET-flow (uændret ekstern adfærd) og et nyt
# bulk-POST-flow (customer_statements.php's "Send valgte kontoudtog") kan
# dele nøjagtig samme, velafprøvede kode - ingen duplikeret forretningslogik.
# Samme mønster som reminder_action.php's send_single_reminder() fra samme session.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/mail_handler.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/customer_statement.inc.php';
require_once 'inc/invoice_layouts.inc.php';  // il_column_exists()

/**
 * Sender ét kontoudtog for kunden med id $cust_id. Returnerer status som en
 * af: 'sent', 'no_email' (kunden har ingen mailadresse), 'error' (kunden
 * findes ikke, eller mailen fejlede reelt).
 */
function send_single_statement($conn, array $s, int $cust_id, string $from = '', string $to = ''): string {
    if ($cust_id <= 0) return 'error';

    // RETTET: kontoudtoget (også rækketeksterne, som get_customer_statement_data() bygger) blev altid skrevet på
    // den indloggede brugers sprog, ikke kundens (kundekortets sprog, som fakturamailen allerede følger).
    // Skiftes midlertidigt, og rulles tilbage når mailen er bygget.
    $st_lang_orig = $_SESSION['lang'] ?? 'da';
    if (il_column_exists($conn, 'customers', 'cust_language')) {
        $st_lang = DB::fetch_row(DB::query($conn, "SELECT cust_language FROM customers WHERE cust_id = $cust_id"));
        if (!empty($st_lang[0])) $_SESSION['lang'] = $st_lang[0];
    }
    $stmt = get_customer_statement_data($conn, $cust_id, $from, $to);
    if ($stmt === null || empty($stmt['cust']['cust_email'])) {
        $_SESSION['lang'] = $st_lang_orig;
        return $stmt === null ? 'error' : 'no_email';
    }
    $cust = $stmt['cust'];
    $cur  = $s['currency'] ?? 'DKK';

    // --- Byg mailens HTML-tabel - samme rækker/beløb som selve siden viste,
    // blot i et enklere, mail-venligt layout (ingen links/farver der kræver CSS). ---
    $rows_html = '';
    if ($from !== '' && !empty($stmt['opening_balance'])) {
        $rows_html .= '<tr><td>' . htmlspecialchars(date(CONF_DATE_FORMAT, strtotime($from))) . ' ' . lang('@or earlier') . '</td>'
            . '<td><em>' . lang('@Opening Balance') . '</em></td><td></td>'
            . '<td style="text-align:right;"><strong>' . fmt_dk($stmt['opening_balance']) . ' ' . $cur . '</strong></td></tr>';
    }
    foreach ($stmt['visible_events'] as $e) {
        $rows_html .= '<tr>'
            . '<td>' . htmlspecialchars(date(CONF_DATE_FORMAT, strtotime($e['date']))) . '</td>'
            . '<td>' . htmlspecialchars($e['label']) . '</td>'
            . '<td style="text-align:right;">' . fmt_dk($e['amount']) . ' ' . $cur . '</td>'
            . '<td style="text-align:right;">' . fmt_dk($e['balance']) . ' ' . $cur . '</td>'
            . '</tr>';
    }
    if ($rows_html === '') {
        $rows_html = '<tr><td colspan="4">' . lang('@No transactions in this period.') . '</td></tr>';
    }

    $period_label = '';
    if ($from !== '' || $to !== '') {
        $period_label = ' (' . ($from !== '' ? date(CONF_DATE_FORMAT, strtotime($from)) : '...') . ' - '
            . ($to !== '' ? date(CONF_DATE_FORMAT, strtotime($to)) : '...') . ')';
    }

    $subject = lang('@Account Statement') . ': ' . $cust['cust_name'] . $period_label;

    $body  = '<p>' . sprintf(lang('@Hi %s,'), htmlspecialchars($cust['cust_name'])) . '</p>';
    $body .= '<p>' . lang('@Please find your account statement below.') . '</p>';
    $body .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; font-family:sans-serif; font-size:13px;">';
    $body .= '<tr style="background:#f0f0f0; font-weight:bold; text-align:left;">'
        . '<td>' . lang('@Date') . '</td><td>' . lang('@Description') . '</td>'
        . '<td style="text-align:right;">' . lang('@Amount') . '</td><td style="text-align:right;">' . lang('@Balance') . '</td></tr>';
    $body .= $rows_html;
    $body .= '<tr style="font-weight:bold; border-top:2px solid #333;">'
        . '<td colspan="3">' . ($to !== '' ? lang('@Closing Balance') : lang('@Total Outstanding')) . '</td>'
        . '<td style="text-align:right;">' . fmt_dk($to !== '' ? $stmt['closing_balance'] : $stmt['total_outstanding']) . ' ' . $cur . '</td></tr>';
    $body .= '</table>';
    $body .= '<p>' . lang('@Best regards,') . '</p>';
    $_SESSION['lang'] = $st_lang_orig;

    $result = sendTinyMail($cust['cust_email'], $cust['cust_name'], $subject, $body);

    if (isset($result['success']) && $result['success'] == true) {
        log_action($conn, 'SEND_STATEMENT', 'customers', $cust_id, null, ['to' => $cust['cust_email'], 'from' => $from, 'to_date' => $to]);
        return 'sent';
    }
    return 'error';
}

$s = get_settings($conn);

// --- BULK-FLOW: POST med ids[] - "Send valgte kontoudtog". ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $sent = 0; $failed = 0;
    foreach ($_POST['ids'] as $raw_id) {
        $status = send_single_statement($conn, $s, (int)$raw_id);
        if ($status === 'sent') { $sent++; } else { $failed++; }
    }
    redirect('customer_statements.php', $failed > 0
        ? sprintf(lang('@%d statement(s) sent. %d could not be sent (no email address, or a mail error) - please check them individually.'), $sent, $failed)
        : sprintf(lang('@%d statement(s) sent successfully.'), $sent), $failed > 0 ? 'warning' : 'success');
    ob_end_flush();
    exit;
}

// --- ENKELT-FLOW (uændret ekstern adfærd) ---
$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$from    = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to      = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : '';
// RETTET: periodedatoerne blev ikke kontrolleret - en ugyldig dato ("31-06-2026", "abc") blev sammenlignet som tekst
// og vist som 01-01-1970, og udtoget viste stille den forkerte periode. En ugyldig dato ignoreres nu.
foreach (['from', 'to'] as $cs_k) if ($$cs_k !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$cs_k) || !checkdate((int)substr($$cs_k, 5, 2), (int)substr($$cs_k, 8, 2), (int)substr($$cs_k, 0, 4)))) $$cs_k = '';
if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];      // (en omvendt periode gav et tomt udtog med "saldo primo" fra slutdatoen)

if ($cust_id <= 0) { redirect("sales_hub.php"); }

$status = send_single_statement($conn, $s, $cust_id, $from, $to);
redirect("customer_statement.php?id=$cust_id&from=" . urlencode($from) . "&to=" . urlencode($to),
    $status === 'sent' ? '@Account statement sent successfully.' : '@Could not send the account statement. Check your mail settings.',
    $status === 'sent' ? 'success' : 'error');
ob_end_flush();
exit;
?>
