<?php # /quote_followup.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): opfølgning
# på sendte tilbud der er udløbet eller snart udløber uden svar fra kunden -
# quote_list.php viser allerede en "(Udløbet)"-badge, men intet mindede
# bogholderen om reelt at følge op, i modsætning til reminders.php's
# fakturarykkere. Samme struktur/layout som reminders.php, blot for tilbud:
# enkelt- og bulk-afsendelse, samme TOCTOU-sikre "allerede fulgt op i dag"-
# claim-mønster (§bugs-batch-23-review-arven).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header('@Quote Follow-up');
showMenu();

$table_exists = @DB::query($conn, "SELECT 1 FROM quotes LIMIT 1") !== false;
if (!$table_exists) {
    htm_Card_(capt: '@Quote Follow-up', wdth: 1100);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The quotes database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

// Samme stille-degraderings-mønster som expense_list.php's
// $supplier_module_ready - findes followup_sent_at/followup_count endnu
// (kun tilfældet efter db-setup/migrate_quote_followup.php er kørt)?
$followup_ready = false;
if (DB::is_sqlite()) {
    $col_check = DB::query($conn, "PRAGMA table_info(quotes)");
    if ($col_check) {
        while ($cr = DB::fetch_assoc($col_check)) { if ($cr['name'] === 'followup_sent_at') { $followup_ready = true; break; } }
    }
} else {
    $col_check = DB::query($conn, "SHOW COLUMNS FROM quotes LIKE 'followup_sent_at'");
    $followup_ready = ($col_check && DB::num_rows($col_check) > 0);
}
if (!$followup_ready) {
    htm_Card_(capt: '@Quote Follow-up', wdth: 1100);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The quote follow-up database structure has not been set up yet. Run db-setup/migrate_quote_followup.php as an admin.'), 'warning');
    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'sent') htm_Alert(lang('@Follow-up sent successfully.'), 'success');
    elseif ($_GET['msg'] === 'error') htm_Alert(lang('@Could not send the follow-up. Check your mail settings.'), 'error');
    elseif ($_GET['msg'] === 'already_sent_today') htm_Banner('<i class="fa fa-info-circle"></i> ' . lang('@A follow-up was already sent for this quote today - please wait until tomorrow to send another.'), 'warning');
    elseif ($_GET['msg'] === 'bulk') {
        $b_sent   = (int)($_GET['sent'] ?? 0);
        $b_failed = (int)($_GET['failed'] ?? 0);
        if ($b_failed > 0) {
            htm_Banner('<i class="fa-solid fa-triangle-exclamation"></i> ' . sprintf(lang('@%d follow-up(s) sent. %d could not be sent (already followed up today, no longer eligible, or a mail error) - please check them individually.'), $b_sent, $b_failed), 'warning');
        } else {
            htm_Alert(sprintf(lang('@%d follow-up(s) sent successfully.'), $b_sent), 'success');
        }
    }
}

htm_Card_(capt: '@Quote Follow-up', wdth: 1100);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Sent quotes that have expired, or are about to expire, with no reply yet. Send a follow-up directly from here.') .
    '</p>';

$today       = date('Y-m-d');
$soon_cutoff = date('Y-m-d', strtotime('+7 days'));

$sql = "SELECT q.quote_id, q.quote_no, q.quote_date, q.valid_until, q.followup_sent_at, q.followup_count,
               c.cust_name, c.cust_email,
               (SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0) FROM quote_lines WHERE quote_id = q.quote_id) AS total
        FROM quotes q
        JOIN customers c ON q.cust_id = c.cust_id
        WHERE q.status = 'sent' AND q.valid_until IS NOT NULL AND q.valid_until <= '$soon_cutoff'
        ORDER BY q.valid_until ASC";
$res = DB::query($conn, $sql);

// RETTET (bruger-anmodet): bulk-udgave - samme mønster som reminders.php.
$headers = ['&nbsp;<input type="checkbox" id="bulkSelectAll" onchange="toggleAllFollowups(this.checked)" data-hint="' . htmlspecialchars(lang('@Select all')) . '">',
    '@Quote No', '@Customer', '@Valid Until', '@Total', '@Last Follow-up', '@Actions'];
$data = [];

if (!$res) {
    echo htm_Alert("SQL Error: " . DB::error($conn), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        if (empty($row['cust_email'])) continue; // ingen mailadresse at følge op på - se selve knappens data-hint andre steder for samme princip

        $qid = (int)$row['quote_id'];
        $days_over = days_between($row['valid_until'], $today);
        if ($days_over > 0) {
            $due_color = $days_over > 14 ? '#c0392b' : ($days_over > 7 ? '#e67e22' : '#7f8c8d');
            $due_label = sprintf(lang('@%d days expired'), $days_over);
        } else {
            $due_color = 'var(--color-warning)';
            $due_label = sprintf(lang('@Expires in %d days'), -$days_over);
        }

        $last_followup = $row['followup_sent_at']
            ? date(CONF_DATE_FORMAT, strtotime($row['followup_sent_at'])) . ' (' . (int)$row['followup_count'] . ')'
            : '<span style="color:var(--text-muted);">' . lang('@Never') . '</span>';

        $confirm = addslashes(sprintf(lang('@Send a follow-up email to %s?'), $row['cust_email']));
        $send_btn = '<a href="quote_followup_action.php?id=' . $qid . '" data-hint="' . lang('@Email a follow-up on the quote to the customer (you are asked first)') . '" '
            . 'onclick="return confirm(\'' . $confirm . '\')" '
            . 'style="background:var(--color-primary); color:#fff; padding:6px 14px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em;">'
            . '<i class="fa-solid fa-envelope"></i> ' . lang('@Send Follow-up') . '</a>';

        $data[] = [
            '<input type="checkbox" class="bulk-followup-checkbox" value="' . $qid . '" onchange="updateFollowupSelectedCount()">',
            '<a href="quote_view.php?id=' . $qid . '">T-' . str_pad((string)$row['quote_no'], 6, '0', STR_PAD_LEFT) . '</a>',
            htmlspecialchars($row['cust_name']),
            '<span style="color:' . $due_color . '; font-weight:bold;">' . date(CONF_DATE_FORMAT, strtotime($row['valid_until'])) . ' (' . $due_label . ')</span>',
            number_format((float)$row['total'], 2, ',', '.') . ' ' . $cur,
            $last_followup,
            $send_btn,
        ];
    }
}

if (empty($data) && $res) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No quotes need follow-up right now.') . "</p>";
} elseif (!empty($data)) {
    $bulk_html = '<button type="button" id="bulkFollowupBtn" onclick="submitBulkFollowups()" disabled '
        . 'style="background:var(--color-primary); color:#fff; border:none; padding:6px 14px; '
        . 'border-radius:4px; font-weight:bold; cursor:pointer; font-size:13px; opacity:0.5;">'
        . '<i class="fa-solid fa-envelope-circle-check"></i> ' . lang('@Send Selected Follow-ups')
        . ' (<span id="bulkFollowupCount">0</span>)</button>';
    htm_Table($headers, $data, 'followupTbl', 100, $bulk_html, true, [], '600px');

    ?>
    <form id="bulkFollowupForm" method="post" action="quote_followup_action.php" style="display:none;"><?php csrf_field(); ?></form>
    <script>
    function updateFollowupSelectedCount() {
        var n = document.querySelectorAll('.bulk-followup-checkbox:checked').length;
        document.getElementById('bulkFollowupCount').innerText = n;
        var btn = document.getElementById('bulkFollowupBtn');
        btn.disabled = (n === 0);
        btn.style.opacity = (n === 0) ? '0.5' : '1';
    }
    function toggleAllFollowups(checked) {
        document.querySelectorAll('.bulk-followup-checkbox').forEach(function(cb) { cb.checked = checked; });
        updateFollowupSelectedCount();
    }
    function submitBulkFollowups() {
        var checked = document.querySelectorAll('.bulk-followup-checkbox:checked');
        if (checked.length === 0) return;
        var confirmMsg = <?php echo json_encode(lang('@Send a follow-up for %d selected quote(s)?')); ?>.replace('%d', checked.length);
        if (!confirm(confirmMsg)) return;
        var form = document.getElementById('bulkFollowupForm');
        checked.forEach(function(cb) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
            form.appendChild(inp);
        });
        form.submit();
    }
    </script>
    <?php
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
