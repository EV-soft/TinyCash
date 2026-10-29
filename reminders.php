<?php # /reminders.php v:1.3.1 d:2026-09-15 i:evs
# Rykkerfunktion for forfaldne fakturaer - fandtes slet ikke i systemet før
# (fra forslagslisten: "der findes ingen automatisk påmindelse ved
# overskredet forfaldsdato"). Viser alle bogførte fakturaer (status 'sent',
# dvs. sendt men ikke betalt) med overskredet forfaldsdato, med mulighed for
# at sende en rykker-mail direkte herfra. Bruger-anmodet.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header('@Payment Reminders');
showMenu();

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'sent') htm_Alert(lang('@Reminder sent successfully.'), 'success');
    elseif ($_GET['msg'] === 'error') htm_Alert(lang('@Could not send the reminder. Check your mail settings.'), 'error');
    elseif ($_GET['msg'] === 'already_sent_today') htm_Alert(lang('@A reminder was already sent for this invoice today - please wait until tomorrow to send another.'), 'warning');
    elseif ($_GET['msg'] === 'bulk') {
        $b_sent   = (int)($_GET['sent'] ?? 0);
        $b_failed = (int)($_GET['failed'] ?? 0);
        if ($b_failed > 0) {
            // RETTET (selv-fanget, "find fem fejl"): denne besked nævnte ikke
            // "kunden har fravalgt rykkere" som en mulig årsag, selvom det nu
            // er en reel afvisningsgrund i send_single_reminder() (se
            // reminder_action.php). Excluderede kunder kan normalt ikke
            // vælges til bulk-afsendelse via selve UI'en (ingen checkbox
            // vises for dem på reminders.php), men den bagvedliggende
            // fejlbesked bør stadig være retvisende for en admin, der
            // undersøger et "0 rykkere fejlede" resultat efter en direkte
            // POST, eller blot læser teksten og undrer sig.
            htm_Alert(sprintf(lang('@%d reminder(s) sent. %d could not be sent (already reminded today, already paid, excluded from reminders, or a mail error) - please check them individually.'), $b_sent, $b_failed), 'warning');
        } else {
            htm_Alert(sprintf(lang('@%d reminder(s) sent successfully.'), $b_sent), 'success');
        }
    }
}

htm_Card_(capt: '@Payment Reminders', wdth: 1100);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Invoices that have been sent but not paid, and whose due date has passed. Send a reminder directly from here.') .
    '</p>';

$today = date('Y-m-d');
// "(100+rate)/100" i stedet for "(1+rate/100)" - sidstnævnte udregner
// rate/100 som heltalsdivision på SQLite FØR additionen (25/100 = 0), så
// momsen aldrig blev lagt til. Fundet og rettet flere steder i kodebasen
// samme dag (project_view.php, reconcile_list.php) - se deres versionshoveder.
// "total" er fakturaens fulde beløb; "paid" er summen af allerede
// registrerede delvise indbetalinger (§partial-invoice-payments). En
// faktura kan sagtens stå som 'sent' selvom kunden allerede har betalt en
// del af den (status skifter først til 'paid' ved FULD betaling, se
// reconcile_action.php) - "amount_due" (total - paid) er derfor det
// reelle skyldige beløb, og det der skal vises/rykkes for, ikke hele
// fakturabeløbet igen.
// RETTET (§reel-multi-valuta-bogforing, §bugs-batch-32-review): "total" var
// FØR fakturaens EGEN valuta (fx EUR) uden gange med exch_rate, men
// sammenlignet mod "paid" (altid DKK, fra rigtige bankbeløb) - samme
// fejlklasse fundet og rettet samme runde i reconcile_action.php/
// invoice_view.php/sales_hub.php. Ganger nu med invoices.exch_rate (1 hvis
// ikke sat = uændret for en almindelig DKK-faktura).
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfrit fravalg
// af automatiske rykkere pr. kunde (customer_edit.php) - relevant for
// følsomme kunderelationer, hvor bogholderen hellere vil rykke manuelt/
// telefonisk. Kunden vises stadig på listen (samme "vis, men markér, skjul
// aldrig stille"-princip som den manglende-mailadresse-markering allerede
// bruger) - blot uden mulighed for at vælge den til masseudsendelse eller
// klikke "Send rykker". Samme "stille degradering hvis ikke migreret
// endnu"-mønster som andre nyere kolonner.
$has_no_rem_col = false;
if (DB::is_sqlite()) {
    $nrc = DB::query($conn, "PRAGMA table_info(customers)");
    if ($nrc) { while ($nrcr = DB::fetch_assoc($nrc)) { if ($nrcr['name'] === 'cust_no_reminders') { $has_no_rem_col = true; break; } } }
} else {
    $nrc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_no_reminders'");
    $has_no_rem_col = ($nrc && DB::num_rows($nrc) > 0);
}
$no_rem_select = $has_no_rem_col ? ", c.cust_no_reminders" : "";

$rem_disc = invoice_discount_sql($conn);
$sql = "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_due_date, i.reminder_sent_at, i.reminder_count,
               c.cust_name, c.cust_email
               $no_rem_select,
               (SELECT COALESCE(SUM(quantity * price_each $rem_disc * (100 + line_vat_rate) / 100.0), 0)
                FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . " AS total,
               (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
        FROM invoices i
        JOIN customers c ON i.cust_id = c.cust_id
        WHERE i.inv_status = 'sent' AND i.inv_due_date IS NOT NULL AND i.inv_due_date < '$today'
        ORDER BY i.inv_due_date ASC";
$res = DB::query($conn, $sql);

// RETTET (bruger-anmodet, "andre bogholder behov"): rykkere kunne kun sendes
// én ad gangen, selvom listen typisk viser mange forfaldne fakturaer på én
// gang - samme "no bulk action"-hul som reconcile_list.php havde. Kolonnen
// her renderes som rå HTML (htm_Table behandler en label med "<" som HTML,
// ikke en lang()-nøgle), så select-all-checkboxen kan sidde i selve
// tabelhovedet i stedet for udenfor.
// "&nbsp;" foran checkboksen er bevidst: htm_Table erstatter en action-
// kolonnes label med "@Actions" hvis strip_tags($labl) bliver tom streng
// (dens regel for at genkende en tom "handlinger"-kolonne) - en ren
// <input>-tag uden tekst ville derfor blive overskrevet. &nbsp; overlever
// strip_tags og holder betingelsen fra at ramme.
$headers = ['&nbsp;<input type="checkbox" id="bulkSelectAll" onchange="toggleAllReminders(this.checked)" data-hint="' . htmlspecialchars(lang('@Select all')) . '">',
    '@Invoice', '@Customer', '@Due Date', '@Days Overdue', '@Amount', '@Last Reminder', '@Actions'];
$data = [];

if (!$res) {
    echo htm_Alert("SQL Error: " . DB::error($conn), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $amount_due = (float)$row['total'] - (float)$row['paid'];
        if ($amount_due <= 0.01) continue; // reelt allerede fuldt betalt (afrundingsrest) - ingen grund til at ryk for 0 kr.

        $days_overdue = days_between($row['inv_due_date'], $today);
        $due_color = $days_overdue > 14 ? '#c0392b' : ($days_overdue > 7 ? '#e67e22' : '#7f8c8d');

        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 27):
        // reminder_action.php sender nu et af 3 optrappende niveauer
        // (venlig/fastere/sidste-frist) afhængigt af reminder_count - denne
        // hint viser hvilket niveau NÆSTE afsendte rykker rammer, så
        // bogholderen ved hvad "Send rykker"-knappen reelt udløser.
        $next_reminder_no = (int)$row['reminder_count'] + 1;
        $next_tier_label = $next_reminder_no === 1 ? lang('@1st') : ($next_reminder_no === 2 ? lang('@2nd') : lang('@Final'));
        $last_reminder = $row['reminder_sent_at']
            ? date(CONF_DATE_FORMAT, strtotime($row['reminder_sent_at'])) . ' (' . (int)$row['reminder_count'] . ') <span style="color:var(--text-muted); font-size:0.85em;">→ ' . sprintf(lang('@Next: %s reminder'), $next_tier_label) . '</span>'
            : '<span style="color:var(--text-muted);">' . lang('@Never') . '</span>';

        $no_reminders = !empty($row['cust_no_reminders']);

        if ($no_reminders) {
            $confirm = '';
            $send_btn = '<span style="color:var(--text-muted); font-size:0.85em;" data-hint="'.htmlspecialchars(lang('@This customer is excluded from automatic reminders - remove the exclusion on the customer card to enable it')).'"><i class="fa-solid fa-ban"></i> '.lang('@Excluded').'</span>';
        } else {
            $confirm = addslashes(sprintf(lang('@Send a payment reminder to %s?'), $row['cust_email']));
            $send_btn = '<a href="reminder_action.php?id=' . (int)$row['inv_id'] . '" data-hint="' . lang('@Email a reminder for the invoice to the customer (you are asked first)') . '" '
                . 'onclick="return confirm(\'' . $confirm . '\')" '
                . 'style="background:var(--color-primary); color:#fff; padding:6px 14px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em;">'
                . '<i class="fa-solid fa-envelope"></i> ' . lang('@Send Reminder') . '</a>';
        }

        $data[] = [
            $no_reminders ? '' : '<input type="checkbox" class="bulk-reminder-checkbox" value="' . (int)$row['inv_id'] . '" onchange="updateReminderSelectedCount()">',
            '<a href="invoice_view.php?id=' . (int)$row['inv_id'] . '">#' . str_pad($row['invoice_no'], 6, "0", STR_PAD_LEFT) . '</a>',
            htmlspecialchars($row['cust_name']),
            date(CONF_DATE_FORMAT, strtotime($row['inv_due_date'])),
            '<span style="color:' . $due_color . '; font-weight:bold;">' . $days_overdue . ' ' . lang('@days') . '</span>',
            number_format($amount_due, 2, ',', '.') . ' ' . $cur,
            $last_reminder,
            $send_btn,
        ];
    }
}

if (empty($data) && $res) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No overdue invoices - everything is paid on time.') . "</p>";
} elseif (!empty($data)) {
    // Bulk-knap indsat via htm_Table's $html-parameter - vises i samme
    // værktøjslinje som søgefeltet, samme placering CSV-eksportknappen ville
    // have haft (samme mønster som reconcile_list.php's egen bulk-knap,
    // blot her indlejret i htm_Table i stedet for en fritstående <div>, da
    // rykkerlisten allerede brugte htm_Table til selve tabellen).
    $bulk_html = '<button type="button" id="bulkReminderBtn" onclick="submitBulkReminders()" disabled '
        . 'style="background:var(--color-primary); color:#fff; border:none; padding:6px 14px; '
        . 'border-radius:4px; font-weight:bold; cursor:pointer; font-size:13px; opacity:0.5;">'
        . '<i class="fa-solid fa-envelope-circle-check"></i> ' . lang('@Send Selected Reminders')
        . ' (<span id="bulkReminderCount">0</span>)</button>';
    htm_Table($headers, $data, 'remindersTbl', 100, $bulk_html, true, [], '600px');

    ?>
    <form id="bulkReminderForm" method="post" action="reminder_action.php" style="display:none;"><?php csrf_field(); ?></form>
    <script>
    function updateReminderSelectedCount() {
        var n = document.querySelectorAll('.bulk-reminder-checkbox:checked').length;
        document.getElementById('bulkReminderCount').innerText = n;
        var btn = document.getElementById('bulkReminderBtn');
        btn.disabled = (n === 0);
        btn.style.opacity = (n === 0) ? '0.5' : '1';
    }
    function toggleAllReminders(checked) {
        document.querySelectorAll('.bulk-reminder-checkbox').forEach(function(cb) { cb.checked = checked; });
        updateReminderSelectedCount();
    }
    function submitBulkReminders() {
        var checked = document.querySelectorAll('.bulk-reminder-checkbox:checked');
        if (checked.length === 0) return;
        var confirmMsg = <?php echo json_encode(lang('@Send a payment reminder for %d selected invoice(s)?')); ?>.replace('%d', checked.length);
        if (!confirm(confirmMsg)) return;
        var form = document.getElementById('bulkReminderForm');
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
