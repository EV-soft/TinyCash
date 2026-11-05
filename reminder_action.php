<?php # /reminder_action.php v:1.3.1 d:2026-09-15 i:evs
# Sender selve rykker-mailen for reminders.php og opdaterer
# reminder_sent_at/reminder_count. Genbruger sendTinyMail() (samme
# mail-motor som send_invoice_action.php), ingen ny SMTP-kode.
# RETTET (bruger-anmodet, "andre bogholder behov"): al logik for at sende ÉN
# rykker er nu udtrukket til send_single_reminder(), så både det oprindelige
# enkelt-GET-flow (uændret ekstern adfærd) og et nyt bulk-POST-flow
# (reminders.php's "Send valgte rykkere") kan dele nøjagtig samme,
# velafprøvede kode - ingen duplikeret forretningslogik. Samme mønster som
# reconcile_action.php's reconcile_one_bank_line() fra samme omgang.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/mail_handler.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/invoice_layouts.inc.php';  // il_column_exists()
require_once 'inc/reminder.inc.php';

/**
 * Sender én rykker for fakturaen med id $id. Returnerer status som en
 * af: 'sent', 'already_sent_today', 'error' (ugyldig/ikke-rykkerbar
 * faktura, eller mailen fejlede reelt).
 */
function send_single_reminder($conn, array $s, int $id): string {
    if ($id <= 0) return 'error';

    // "(100+rate)/100" - se reminders.php for hvorfor "(1+rate/100)" er forkert på SQLite.
    // RETTET: "total" ganges nu med exch_rate - se reminders.php's egen kommentar for baggrunden.
    // NYT: fravalg af rykkere håndhæves her SERVER-SIDE, ikke kun ved at skjule "Send rykker"-knappen i
    // reminders.php's liste - et direkte POST/GET mod dette endepunkt (fx et genindsendt gammelt link) skal også
    // respektere fravalget. Samme "stille degradering hvis ikke migreret endnu"- mønster som reminders.php's egen
    // visning.
    $inv = reminder_fetch($conn, $id);      // (se inc/reminder.inc.php - fælles med rykkerblanketten)
    if (!$inv || !reminder_due($inv)) return 'error';
    $reminder_no = (int)$inv['reminder_count'] + 1;

    // RETTET: der var intet der forhindrede at samme rykker blev sendt to gange for samme faktura - kun en
    // client-side confirm-dialog beskyttede knappen, som ikke standser et dobbeltklik der nåede at sende begge
    // requests før siden nåede at navigere væk, eller to åbne faner/vinduer på samme rykker-liste. Claimet er
    // begrænset til "allerede rykket i dag" - ikke for evigt - så en legitim ny rykker i morgen stadig kan sendes.
    $today_start = date('Y-m-d') . ' 00:00:00';
    $now         = date('Y-m-d H:i:s');
    $old_sent_at = $inv['reminder_sent_at']; // til evt. tilbagerulning, se nedenfor
    $claim_sql   = $old_sent_at
        ? "UPDATE invoices SET reminder_sent_at = '$now' WHERE inv_id = $id AND reminder_sent_at < '$today_start'"
        : "UPDATE invoices SET reminder_sent_at = '$now' WHERE inv_id = $id AND reminder_sent_at IS NULL";
    $claim_res = DB::query($conn, $claim_sql);
    if (!$claim_res || DB::affected_rows($conn, $claim_res) < 1) {
        // Enten et tabt kapløb mod en anden, samtidig anmodning, eller der
        // allerede er sendt en rykker for denne faktura i dag.
        return 'already_sent_today';
    }

    // RETTET: rykkeren blev altid skrevet på den indloggede brugers sprog - en kunde med fx engelsk som sprog
    // (kundekortet) fik engelsk faktura, men dansk rykker. Skifter midlertidigt til kundens sprog, som
    // send_invoice_action.php gør, og ruller tilbage når mailen er bygget.
    require_once __DIR__ . '/inc/doc_paper.inc.php';
    doc_lang_push((string)($inv['cust_language'] ?? ''));
    $rm = reminder_compose($s, $inv, $reminder_no, $old_sent_at);      // (tekst, beløb og gebyr - samme som blanketten)
    $subject   = $rm['subject'];
    $body_text = $rm['body_text'];
    $body = nl2br(htmlspecialchars($body_text));
    doc_lang_pop();

    $result = sendTinyMail($inv['cust_email'], $inv['cust_name'], $subject, $body, null, $inv['invoice_no']);

    if (isset($result['success']) && $result['success'] == true) {
        DB::query($conn, "UPDATE invoices SET reminder_count = reminder_count + 1 WHERE inv_id = $id");
        log_action($conn, 'SEND_REMINDER', 'invoices', $id,
            ['reminder_count' => (int)$inv['reminder_count']],
            ['reminder_count' => (int)$inv['reminder_count'] + 1, 'to' => $inv['cust_email'], 'days_overdue' => $rm['days_overdue'], 'fee' => $rm['fee']]);      // (gebyret: rykkerblanketten viser det for en sendt rykker)
        return 'sent';
    }

    // Mailen fejlede reelt - rul claim'et tilbage, så en efterfølgende
    // legitim gensendelse (fx efter at mailindstillingerne er rettet) ikke
    // blokeres af "allerede rykket i dag" for noget der faktisk aldrig blev
    // sendt.
    $revert_sql = $old_sent_at
        ? "UPDATE invoices SET reminder_sent_at = '" . DB::escape($conn, $old_sent_at) . "' WHERE inv_id = $id"
        : "UPDATE invoices SET reminder_sent_at = NULL WHERE inv_id = $id";
    DB::query($conn, $revert_sql);
    return 'error';
}

$s = get_settings($conn);

// --- BULK-FLOW (bruger-anmodet): POST med ids[] - "Send valgte rykkere" ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $sent = 0; $failed = 0;
    foreach ($_POST['ids'] as $raw_id) {
        $status = send_single_reminder($conn, $s, (int)$raw_id);
        if ($status === 'sent') { $sent++; } else { $failed++; }
    }
    // (en kunde der har fravalgt rykkere, tæller også som "kunne ikke sendes" - se send_single_reminder())
    redirect('reminders.php', $failed > 0
        ? sprintf(lang('@%d reminder(s) sent. %d could not be sent (already reminded today, already paid, excluded from reminders, or a mail error) - please check them individually.'), $sent, $failed)
        : sprintf(lang('@%d reminder(s) sent successfully.'), $sent), $failed > 0 ? 'warning' : 'success');
    ob_end_flush();
    exit;
}

// --- ENKELT-FLOW (uændret ekstern adfærd) ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { redirect("reminders.php"); }

$status = send_single_reminder($conn, $s, $id);
if ($status === 'sent') {
    redirect('reminders.php', '@Reminder sent successfully.');
} elseif ($status === 'already_sent_today') {
    redirect('reminders.php', '@A reminder was already sent for this invoice today - please wait until tomorrow to send another.', 'warning');
} else {
    redirect('reminders.php', '@Could not send the reminder. Check your mail settings.', 'error');
}
ob_end_flush();
exit;
?>
