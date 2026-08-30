<?php # /reminder_action.php v:1.3.0 d:2026-08-30 i:evs
# Sender selve rykker-mailen for reminders.php og opdaterer
# reminder_sent_at/reminder_count. Genbruger sendTinyMail() (samme
# mail-motor som send_invoice_action.php), ingen ny SMTP-kode.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/mail_handler.lib.php';
require_once 'inc/audit.inc.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: reminders.php"); exit; }

// "(100+rate)/100" - se reminders.php for hvorfor "(1+rate/100)" er forkert på SQLite.
// RETTET (§reel-multi-valuta-bogforing, §bugs-batch-32-review): "total"
// ganges nu med exch_rate - se reminders.php's egen kommentar for baggrunden.
$sql = "SELECT i.inv_id, i.invoice_no, i.inv_due_date, i.inv_status, i.reminder_count,
               c.cust_name, c.cust_email,
               (SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0)
                FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) AS total,
               (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
        FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
$inv = DB::fetch_assoc(DB::query($conn, $sql));

// Kun bogførte, endnu ubetalte fakturaer med en overskredet forfaldsdato kan
// rykkes for - samme princip som send_invoice_action.php's statustjek
// (aldrig en kladde) og en ekstra beskyttelse mod at rykke for noget der
// reelt allerede er betalt eller slet ikke er forfaldet endnu.
if (!$inv || strtolower($inv['inv_status']) !== 'sent' || empty($inv['inv_due_date']) || $inv['inv_due_date'] >= date('Y-m-d')) {
    header("Location: reminders.php?msg=error");
    exit;
}

// Fakturaen kan stå som 'sent' selvom kunden allerede har betalt en del af
// den (§partial-invoice-payments - status skifter først til 'paid' ved FULD
// betaling). Rykkeren skal kræve det reelt skyldige restbeløb, ikke hele
// fakturabeløbet igen - ellers overopkræves en kunde der allerede har
// betalt delvist.
$amount_due = (float)$inv['total'] - (float)$inv['paid'];
if ($amount_due <= 0.01) {
    header("Location: reminders.php?msg=error");
    exit;
}

// RETTET (§bugs-batch-23-review): der var intet der forhindrede at samme
// rykker blev sendt to gange for samme faktura - kun en client-side
// confirm()-dialog beskyttede knappen, som ikke standser et dobbeltklik der
// nåede at sende begge requests før siden nåede at navigere væk, eller to
// åbne faner/vinduer på samme rykker-liste. Samme TOCTOU-mønster som andre
// handlinger i denne omgang (reconcile_action.php m.fl.) - løsningen er en
// atomisk "claim" FØR selve mailen sendes: kun den forespørgsel der reelt
// vinder kapløbet (målt via DB::affected_rows(), ikke en efterfølgende
// SELECT) får lov at sende. Claimet er begrænset til "allerede rykket i
// dag" - ikke for evigt - så en legitim ny rykker i morgen stadig kan sendes.
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
    header("Location: reminders.php?msg=already_sent_today");
    exit;
}

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$days_overdue = (int)((strtotime(date('Y-m-d')) - strtotime($inv['inv_due_date'])) / 86400);
$f_no = '#' . str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT);
$due_date_fmt = date(CONF_DATE_FORMAT, strtotime($inv['inv_due_date']));
$amount_fmt = number_format($amount_due, 2, ',', '.') . ' ' . $cur;

$subject = lang('@Payment Reminder') . ' - ' . lang('@Invoice') . ' ' . $f_no;
$body = nl2br(htmlspecialchars(sprintf(
    lang("@Hi %s,\n\nOur records show that invoice %s, due on %s, has not yet been paid (%d days overdue).\n\nAmount due: %s\n\nPlease arrange payment as soon as possible. If you have already paid, please disregard this reminder.\n\nBest regards,"),
    $inv['cust_name'], $f_no, $due_date_fmt, $days_overdue, $amount_fmt
)));

$result = sendTinyMail($inv['cust_email'], $inv['cust_name'], $subject, $body, null, $inv['invoice_no']);

if (isset($result['success']) && $result['success'] == true) {
    DB::query($conn, "UPDATE invoices SET reminder_count = reminder_count + 1 WHERE inv_id = $id");
    log_action($conn, 'SEND_REMINDER', 'invoices', $id,
        ['reminder_count' => (int)$inv['reminder_count']],
        ['reminder_count' => (int)$inv['reminder_count'] + 1, 'to' => $inv['cust_email'], 'days_overdue' => $days_overdue]);
    header("Location: reminders.php?msg=sent");
} else {
    // Mailen fejlede reelt - rul claim'et tilbage, så en efterfølgende
    // legitim gensendelse (fx efter at mailindstillingerne er rettet) ikke
    // blokeres af "allerede rykket i dag" for noget der faktisk aldrig blev
    // sendt.
    $revert_sql = $old_sent_at
        ? "UPDATE invoices SET reminder_sent_at = '" . DB::escape($conn, $old_sent_at) . "' WHERE inv_id = $id"
        : "UPDATE invoices SET reminder_sent_at = NULL WHERE inv_id = $id";
    DB::query($conn, $revert_sql);
    header("Location: reminders.php?msg=error");
}
exit;
?>
