<?php # /quote_followup_action.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): sender en
# opfølgnings-mail for et sendt, udløbet/snart-udløbende tilbud
# (quote_followup.php) og opdaterer followup_sent_at/followup_count. Samme
# opbygning som reminder_action.php - send_single_followup() genbruges af
# både det enkelte GET-flow og det nye bulk-POST-flow.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/mail_handler.lib.php';
require_once 'inc/audit.inc.php';

/**
 * Sender én opfølgning for tilbuddet med id $id. Returnerer status som en
 * af: 'sent', 'already_sent_today', 'error' (ugyldigt/ikke-opfølgbart
 * tilbud, eller mailen fejlede reelt).
 */
function send_single_followup($conn, array $s, int $id): string {
    if ($id <= 0) return 'error';

    $sql = "SELECT q.quote_id, q.quote_no, q.valid_until, q.status, q.followup_count, q.followup_sent_at,
                   c.cust_name, c.cust_email,
                   (SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0) FROM quote_lines WHERE quote_id = q.quote_id) AS total
            FROM quotes q JOIN customers c ON q.cust_id = c.cust_id WHERE q.quote_id = $id";
    $q = DB::fetch_assoc(DB::query($conn, $sql));

    // Kun sendte tilbud med en mailadresse kan følges op på - et draft/
    // accepted/rejected/converted tilbud har ingen "opfølgning" der giver
    // mening (samme princip som reminders.php's status='sent'-krav).
    if (!$q || strtolower($q['status']) !== 'sent' || empty($q['cust_email']) || empty($q['valid_until'])) {
        return 'error';
    }

    // Samme TOCTOU-sikre "allerede fulgt op i dag"-claim som
    // reminder_action.php - kun den forespørgsel der reelt vinder kapløbet
    // (målt via DB::affected_rows()) sender mailen.
    $today_start = date('Y-m-d') . ' 00:00:00';
    $now         = date('Y-m-d H:i:s');
    $old_sent_at = $q['followup_sent_at'];
    $claim_sql   = $old_sent_at
        ? "UPDATE quotes SET followup_sent_at = '$now' WHERE quote_id = $id AND followup_sent_at < '$today_start'"
        : "UPDATE quotes SET followup_sent_at = '$now' WHERE quote_id = $id AND followup_sent_at IS NULL";
    $claim_res = DB::query($conn, $claim_sql);
    if (!$claim_res || DB::affected_rows($conn, $claim_res) < 1) {
        return 'already_sent_today';
    }

    $cur = $s['currency'] ?? 'DKK';
    $q_no = 'T-' . str_pad((string)$q['quote_no'], 6, '0', STR_PAD_LEFT);
    $valid_until_fmt = date(CONF_DATE_FORMAT, strtotime($q['valid_until']));
    $total_fmt = number_format((float)$q['total'], 2, ',', '.') . ' ' . $cur;
    $is_expired = $q['valid_until'] < date('Y-m-d');

    $subject = lang('@Quote Follow-up') . ' - ' . $q_no;
    $body_key = $is_expired
        ? "@Hi %s,\n\nWe haven't heard back from you regarding quote %s (total %s), which expired on %s.\n\nIf you are still interested, please let us know and we will be happy to issue an updated quote.\n\nBest regards,"
        : "@Hi %s,\n\nJust a friendly reminder that quote %s (total %s) is valid until %s.\n\nPlease let us know if you have any questions or would like to proceed.\n\nBest regards,";
    $body = nl2br(htmlspecialchars(sprintf(lang($body_key), $q['cust_name'], $q_no, $total_fmt, $valid_until_fmt)));

    $result = sendTinyMail($q['cust_email'], $q['cust_name'], $subject, $body);

    if (isset($result['success']) && $result['success'] == true) {
        DB::query($conn, "UPDATE quotes SET followup_count = followup_count + 1 WHERE quote_id = $id");
        log_action($conn, 'SEND_QUOTE_FOLLOWUP', 'quotes', $id,
            ['followup_count' => (int)$q['followup_count']],
            ['followup_count' => (int)$q['followup_count'] + 1, 'to' => $q['cust_email']]);
        return 'sent';
    }

    // Mailen fejlede reelt - rul claim'et tilbage, samme begrundelse som
    // reminder_action.php.
    $revert_sql = $old_sent_at
        ? "UPDATE quotes SET followup_sent_at = '" . DB::escape($conn, $old_sent_at) . "' WHERE quote_id = $id"
        : "UPDATE quotes SET followup_sent_at = NULL WHERE quote_id = $id";
    DB::query($conn, $revert_sql);
    return 'error';
}

$s = get_settings($conn);

// --- BULK-FLOW: POST med ids[] - "Send valgte opfølgninger" ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $sent = 0; $failed = 0;
    foreach ($_POST['ids'] as $raw_id) {
        $status = send_single_followup($conn, $s, (int)$raw_id);
        if ($status === 'sent') { $sent++; } else { $failed++; }
    }
    header("Location: quote_followup.php?msg=bulk&sent=$sent&failed=$failed");
    ob_end_flush();
    exit;
}

// --- ENKELT-FLOW ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: quote_followup.php"); exit; }

$status = send_single_followup($conn, $s, $id);
if ($status === 'sent') {
    header("Location: quote_followup.php?msg=sent");
} elseif ($status === 'already_sent_today') {
    header("Location: quote_followup.php?msg=already_sent_today");
} else {
    header("Location: quote_followup.php?msg=error");
}
ob_end_flush();
exit;
?>
