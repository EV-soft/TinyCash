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

/**
 * Sender én rykker for fakturaen med id $id. Returnerer status som en
 * af: 'sent', 'already_sent_today', 'error' (ugyldig/ikke-rykkerbar
 * faktura, eller mailen fejlede reelt).
 */
function send_single_reminder($conn, array $s, int $id): string {
    if ($id <= 0) return 'error';

    // "(100+rate)/100" - se reminders.php for hvorfor "(1+rate/100)" er forkert på SQLite.
    // RETTET (§reel-multi-valuta-bogforing, §bugs-batch-32-review): "total"
    // ganges nu med exch_rate - se reminders.php's egen kommentar for baggrunden.
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): fravalg af
    // rykkere håndhæves her SERVER-SIDE, ikke kun ved at skjule "Send
    // rykker"-knappen i reminders.php's liste - et direkte POST/GET mod
    // dette endepunkt (fx et genindsendt gammelt link) skal også respektere
    // fravalget. Samme "stille degradering hvis ikke migreret endnu"-
    // mønster som reminders.php's egen visning.
    $has_no_rem_col = false;
    if (DB::is_sqlite()) {
        $nrc = DB::query($conn, "PRAGMA table_info(customers)");
        if ($nrc) { while ($nrcr = DB::fetch_assoc($nrc)) { if ($nrcr['name'] === 'cust_no_reminders') { $has_no_rem_col = true; break; } } }
    } else {
        $nrc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_no_reminders'");
        $has_no_rem_col = ($nrc && DB::num_rows($nrc) > 0);
    }
    $no_rem_select = $has_no_rem_col ? ", c.cust_no_reminders" : "";

    $ra_disc = invoice_discount_sql($conn);
    $sql = "SELECT i.inv_id, i.invoice_no, i.inv_due_date, i.inv_status, i.reminder_count, i.currency AS inv_currency, i.exch_rate,
                   i.reminder_sent_at, c.cust_name, c.cust_email
                   $no_rem_select,
                   (SELECT COALESCE(SUM(quantity * price_each $ra_disc * (100 + line_vat_rate) / 100.0), 0)
                    FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . " AS total,
                   (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
            FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $id";
    $inv = DB::fetch_assoc(DB::query($conn, $sql));

    // Kun bogførte, endnu ubetalte fakturaer med en overskredet forfaldsdato kan
    // rykkes for - samme princip som send_invoice_action.php's statustjek
    // (aldrig en kladde) og en ekstra beskyttelse mod at rykke for noget der
    // reelt allerede er betalt eller slet ikke er forfaldet endnu.
    if (!$inv || strtolower($inv['inv_status']) !== 'sent' || empty($inv['inv_due_date']) || $inv['inv_due_date'] >= date('Y-m-d') || !empty($inv['cust_no_reminders'])) {
        return 'error';
    }

    // Fakturaen kan stå som 'sent' selvom kunden allerede har betalt en del af
    // den (§partial-invoice-payments - status skifter først til 'paid' ved FULD
    // betaling). Rykkeren skal kræve det reelt skyldige restbeløb, ikke hele
    // fakturabeløbet igen - ellers overopkræves en kunde der allerede har
    // betalt delvist.
    $amount_due = (float)$inv['total'] - (float)$inv['paid'];
    if ($amount_due <= 0.01) return 'error';

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
        return 'already_sent_today';
    }

    $cur = $s['currency'] ?? 'DKK';
    $days_overdue = days_between($inv['inv_due_date'], date('Y-m-d'));
    $f_no = '#' . str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT);
    $due_date_fmt = date(CONF_DATE_FORMAT, strtotime($inv['inv_due_date']));
    $amount_fmt = number_format($amount_due, 2, ',', '.') . ' ' . $cur;
    // RETTET: en faktura i fremmed valuta (fx 1.000 EUR) fik rykkeren med det bogførte KRONEbeløb ("7.460,00 DKK")
    // - kunden skylder fakturaens egen valuta. Restbeløbet regnes tilbage med fakturaens kurs.
    $inv_cur = strtoupper((string)($inv['inv_currency'] ?? ''));
    if ((float)($inv['exch_rate'] ?? 0) > 0 && $inv_cur !== '' && $inv_cur !== strtoupper($cur)) {
        $amount_fmt = number_format(round($amount_due / (float)$inv['exch_rate'], 2), 2, ',', '.') . ' ' . $inv_cur;
    }

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 27):
    // $inv['reminder_count'] (FØR selve optællingen nedenfor) fortæller
    // hvilken rykker dette REELT er - 0 = 1., 1 = 2., 2+ = 3. eller senere.
    // Samme mail blev tidligere sendt uanset hvor mange gange der allerede
    // var rykket. Gebyret er BEVIDST kun et bogholder-indtastet, nævnt beløb
    // i selve mailteksten - lægges IKKE automatisk oveni $amount_due eller
    // posteres nogen steder (det ville kræve en selvstændig regnskabsmæssig
    // vurdering af hver enkelt rykker, uden for denne rundes scope) - samme
    // "sikre, ikke-gættende"-princip som momsfrist-feltet (runde 25).
    $reminder_no = (int)$inv['reminder_count'] + 1;
    $fee = (float)($s['reminder_fee_amount'] ?? 0);
    // RETTET (renteloven § 9b): gebyret blev nævnt på enhver 2. og senere rykker - også dagen efter den
    // forrige, på 4., 5. ... rykker og med et beløb over lovens 100 kr. Et rykkergebyr må kun kræves, når
    // der er gået mindst 10 dage siden forrige rykker, for højst 3 rykkere, og højst 100 kr pr. rykker.
    $days_since_prev = $old_sent_at ? (int)floor((time() - strtotime($old_sent_at)) / 86400) : PHP_INT_MAX;
    if ($reminder_no > 3 || $days_since_prev < 10) $fee = 0;
    if (strtoupper($cur) === 'DKK') $fee = min($fee, 100.0);
    $fee_fmt = number_format($fee, 2, ',', '.') . ' ' . $cur;

    if ($reminder_no === 1) {
        $subject = lang('@Payment Reminder') . ' - ' . lang('@Invoice') . ' ' . $f_no;
        $body_text = sprintf(
            lang("@Hi %s,\n\nOur records show that invoice %s, due on %s, has not yet been paid (%d days overdue).\n\nAmount due: %s\n\nPlease arrange payment as soon as possible. If you have already paid, please disregard this reminder.\n\nBest regards,"),
            $inv['cust_name'], $f_no, $due_date_fmt, $days_overdue, $amount_fmt
        );
    } elseif ($reminder_no === 2) {
        $subject = lang('@2nd Payment Reminder') . ' - ' . lang('@Invoice') . ' ' . $f_no;
        $body_text = sprintf(
            lang("@Hi %s,\n\nThis is our SECOND reminder that invoice %s, due on %s, remains unpaid (%d days overdue).\n\nAmount due: %s%s\n\nPlease arrange payment immediately. Contact us right away if there is a problem paying this invoice.\n\nBest regards,"),
            $inv['cust_name'], $f_no, $due_date_fmt, $days_overdue, $amount_fmt,
            $fee > 0 ? ' ' . sprintf(lang('@(a reminder fee of %s applies to this reminder)'), $fee_fmt) : ''
        );
    } else {
        $subject = lang('@FINAL Payment Reminder') . ' - ' . lang('@Invoice') . ' ' . $f_no;
        $body_text = sprintf(
            lang("@Hi %s,\n\nThis is reminder no. %d for invoice %s, due on %s, still unpaid (%d days overdue).\n\nAmount due: %s%s\n\nThis is a FINAL notice. If payment is not received shortly, we may refer this matter to debt collection without further warning.\n\nBest regards,"),
            $inv['cust_name'], $reminder_no, $f_no, $due_date_fmt, $days_overdue, $amount_fmt,
            $fee > 0 ? ' ' . sprintf(lang('@(a reminder fee of %s applies to this reminder)'), $fee_fmt) : ''
        );
    }
    $body = nl2br(htmlspecialchars($body_text));

    $result = sendTinyMail($inv['cust_email'], $inv['cust_name'], $subject, $body, null, $inv['invoice_no']);

    if (isset($result['success']) && $result['success'] == true) {
        DB::query($conn, "UPDATE invoices SET reminder_count = reminder_count + 1 WHERE inv_id = $id");
        log_action($conn, 'SEND_REMINDER', 'invoices', $id,
            ['reminder_count' => (int)$inv['reminder_count']],
            ['reminder_count' => (int)$inv['reminder_count'] + 1, 'to' => $inv['cust_email'], 'days_overdue' => $days_overdue]);
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
    header("Location: reminders.php?msg=bulk&sent=$sent&failed=$failed");
    ob_end_flush();
    exit;
}

// --- ENKELT-FLOW (uændret ekstern adfærd) ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: reminders.php"); exit; }

$status = send_single_reminder($conn, $s, $id);
if ($status === 'sent') {
    header("Location: reminders.php?msg=sent");
} elseif ($status === 'already_sent_today') {
    header("Location: reminders.php?msg=already_sent_today");
} else {
    header("Location: reminders.php?msg=error");
}
ob_end_flush();
exit;
?>
