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

/**
 * Sender ét kontoudtog for kunden med id $cust_id. Returnerer status som en
 * af: 'sent', 'no_email' (kunden har ingen mailadresse), 'error' (kunden
 * findes ikke, eller mailen fejlede reelt).
 */
function send_single_statement($conn, array $s, int $cust_id, string $from = '', string $to = ''): string {
    if ($cust_id <= 0) return 'error';

    $stmt = get_customer_statement_data($conn, $cust_id, $from, $to);
    if ($stmt === null) return 'error';
    if (empty($stmt['cust']['cust_email'])) return 'no_email';
    $cust = $stmt['cust'];
    $cur  = $s['currency'] ?? 'DKK';

    // --- Byg mailens HTML-tabel - samme rækker/beløb som selve siden viste,
    // blot i et enklere, mail-venligt layout (ingen links/farver der kræver CSS). ---
    $rows_html = '';
    if ($from !== '' && !empty($stmt['opening_balance'])) {
        $rows_html .= '<tr><td>' . htmlspecialchars(date(CONF_DATE_FORMAT, strtotime($from))) . ' ' . lang('@or earlier') . '</td>'
            . '<td><em>' . lang('@Opening Balance') . '</em></td><td></td>'
            . '<td style="text-align:right;"><strong>' . number_format($stmt['opening_balance'], 2, ',', '.') . ' ' . $cur . '</strong></td></tr>';
    }
    foreach ($stmt['visible_events'] as $e) {
        $rows_html .= '<tr>'
            . '<td>' . htmlspecialchars(date(CONF_DATE_FORMAT, strtotime($e['date']))) . '</td>'
            . '<td>' . htmlspecialchars($e['label']) . '</td>'
            . '<td style="text-align:right;">' . number_format($e['amount'], 2, ',', '.') . ' ' . $cur . '</td>'
            . '<td style="text-align:right;">' . number_format($e['balance'], 2, ',', '.') . ' ' . $cur . '</td>'
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
        . '<td style="text-align:right;">' . number_format($to !== '' ? $stmt['closing_balance'] : $stmt['total_outstanding'], 2, ',', '.') . ' ' . $cur . '</td></tr>';
    $body .= '</table>';
    $body .= '<p>' . lang('@Best regards,') . '</p>';

    $result = sendTinyMail($cust['cust_email'], $cust['cust_name'], $subject, $body);

    if (isset($result['success']) && $result['success'] == true) {
        log_action($conn, 'SEND_STATEMENT', 'customers', $cust_id, null, ['to' => $cust['cust_email'], 'from' => $from, 'to_date' => $to]);
        return 'sent';
    }
    return 'error';
}

$s = get_settings($conn);

// --- BULK-FLOW (bruger-anmodet): POST med ids[] - "Send valgte kontoudtog".
// Sender hver kundes fulde, aktuelle kontoudtog (intet periodefilter - det
// giver kun mening at vælge en periode for ÉN kunde ad gangen fra
// customer_statement.php's egen formular). ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $sent = 0; $failed = 0;
    foreach ($_POST['ids'] as $raw_id) {
        $status = send_single_statement($conn, $s, (int)$raw_id);
        if ($status === 'sent') { $sent++; } else { $failed++; }
    }
    header("Location: customer_statements.php?msg=bulk&sent=$sent&failed=$failed");
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

if ($cust_id <= 0) { header("Location: sales_hub.php"); exit; }

$status = send_single_statement($conn, $s, $cust_id, $from, $to);
header("Location: customer_statement.php?id=$cust_id&from=" . urlencode($from) . "&to=" . urlencode($to)
    . "&msg=" . ($status === 'sent' ? 'sent' : 'error'));
ob_end_flush();
exit;
?>
