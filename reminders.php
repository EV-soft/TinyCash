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

htm_Card_(capt: '@Payment Reminders', wdth: 1100);

htm_Note(lang('@Invoices that have been sent but not paid, and whose due date has passed. Send a reminder directly from here.'), 'font-size:0.9em; margin-top:-5px;');

$today = date('Y-m-d');
// "(100+rate)/100" i stedet for "(1+rate/100)" - sidstnævnte udregner rate/100 som heltalsdivision på SQLite FØR
// additionen (25/100 = 0), så momsen aldrig blev lagt til. En faktura kan sagtens stå som 'sent' selvom kunden
// allerede har betalt en del af den (status skifter først til 'paid' ved FULD betaling, se reconcile_action.php) -
// "amount_due" (total - paid) er derfor det reelle skyldige beløb, og det der skal vises/rykkes for, ikke hele
// fakturabeløbet igen.
// RETTET: "total" var FØR fakturaens EGEN valuta (fx EUR) uden gange med exch_rate, men sammenlignet mod "paid"
// (altid DKK, fra rigtige bankbeløb) - samme fejlklasse fundet og rettet samme runde i
// reconcile_action.php/invoice_view.php/sales_hub.php. Ganger nu med invoices.exch_rate (1 hvis ikke sat = uændret
// for en almindelig DKK-faktura).
// NYT: valgfrit fravalg af automatiske rykkere pr. kunde (customer_edit.php) - relevant for følsomme
// kunderelationer, hvor bogholderen hellere vil rykke manuelt/telefonisk. Samme "stille degradering hvis ikke
// migreret endnu"-mønster som andre nyere kolonner.
$has_no_rem_col = db_has_column($conn, 'customers', 'cust_no_reminders');
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
        WHERE i.inv_status = 'sent' AND i.inv_due_date IS NOT NULL AND i.inv_due_date <> '' AND i.inv_due_date < '$today'
        ORDER BY i.inv_due_date ASC";
$res = DB::query($conn, $sql);

// RETTET: rykkere kunne kun sendes én ad gangen, selvom listen typisk viser mange forfaldne fakturaer på én gang -
// samme "no bulk action"-hul som reconcile_list.php havde. Kolonnen her renderes som rå HTML (htm_Table behandler
// en label med "<" som HTML, ikke en lang-nøgle), så select-all-checkboxen kan sidde i selve tabelhovedet i stedet
// for udenfor.
$headers = ['&nbsp;<input type="checkbox" id="bulkSelectAll" onchange="toggleAllReminders(this.checked)" data-hint="' . htmlspecialchars(lang('@Select all')) . '">',
    '@Invoice', '@Customer', '@Due Date', '@Days Overdue', '@Amount', '@Last Reminder', '@Actions'];
$data = [];

if (!$res) {
    echo htm_Alert(lang('@Error:') . ' ' . htmlspecialchars((string)DB::error($conn)), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $amount_due = (float)$row['total'] - (float)$row['paid'];
        if ($amount_due <= 0.01) continue; // reelt allerede fuldt betalt (afrundingsrest) - ingen grund til at ryk for 0 kr.

        $days_overdue = days_between($row['inv_due_date'], $today);
        $due_color = $days_overdue > 14 ? '#c0392b' : ($days_overdue > 7 ? '#e67e22' : '#7f8c8d');

        // NYT: reminder_action.php sender nu et af 3 optrappende niveauer (venlig/fastere/sidste-frist) afhængigt
        // af reminder_count - denne hint viser hvilket niveau NÆSTE afsendte rykker rammer, så bogholderen ved
        // hvad "Send rykker"-knappen reelt udløser.
        $next_reminder_no = (int)$row['reminder_count'] + 1;
        $next_tier_label = $next_reminder_no === 1 ? lang('@1st') : ($next_reminder_no === 2 ? lang('@2nd') : lang('@Final'));
        $last_reminder = $row['reminder_sent_at']
            ? date(CONF_DATE_FORMAT, strtotime($row['reminder_sent_at'])) . ' (' . (int)$row['reminder_count'] . ') <span style="color:var(--text-muted); font-size:0.85em;">→ ' . sprintf(lang('@Next: %s reminder'), $next_tier_label) . '</span>'
            : '<span style="color:var(--text-muted);">' . lang('@Never') . '</span>';

        $no_reminders = !empty($row['cust_no_reminders']);

        if ($no_reminders) {
            $send_btn = '<span style="color:var(--text-muted); font-size:0.85em;" data-hint="'.htmlspecialchars(lang('@This customer is excluded from automatic reminders - remove the exclusion on the customer card to enable it')).'"><i class="fa-solid fa-ban"></i> '.lang('@Excluded').'</span>';
        } elseif (empty($row['cust_email'])) {
            // RETTET: en kunde uden e-mailadresse fik en "Send rykker"-knap, der altid endte i en uforklaret fejl
            // (samme visning som customer_statements.php)
            $no_reminders = true;
            $send_btn = '<span style="color:var(--text-muted); font-size:0.85em;">' . lang('@No email address') . '</span>';
        } else {
            $send_btn = '<a href="reminder_action.php?id=' . (int)$row['inv_id'] . '" data-hint="' . lang('@Email a reminder for the invoice to the customer (you are asked first)') . '" '
                . 'onclick="' . htm_confirm_js(sprintf(lang('@Send a payment reminder to %s?'), $row['cust_email'])) . '" '      // (e-mailen escapes til JS og attribut)
                . 'style="background:var(--color-primary); color:#fff; padding:6px 14px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em;">'
                . '<i class="fa-solid fa-envelope"></i> ' . lang('@Send Reminder') . '</a>';
        }

        $data[] = [
            $no_reminders ? '' : '<input type="checkbox" class="bulk-reminder-checkbox" value="' . (int)$row['inv_id'] . '" onchange="updateReminderSelectedCount()">',
            '<a href="invoice_view.php?id=' . (int)$row['inv_id'] . '">#' . str_pad($row['invoice_no'], 6, "0", STR_PAD_LEFT) . '</a>',
            htmlspecialchars($row['cust_name']),
            date(CONF_DATE_FORMAT, strtotime($row['inv_due_date'])),
            '<span style="color:' . $due_color . '; font-weight:bold;">' . $days_overdue . ' ' . lang('@days') . '</span>',
            fmt_dk($amount_due) . ' ' . $cur,
            $last_reminder,
            $send_btn . (!empty($row['cust_no_reminders']) ? '' : ' <a href="reminder_view.php?id=' . (int)$row['inv_id'] . '" data-hint="' . lang('@Show the reminder as a letter - print it, save it as PDF or design its layout') . '" style="margin-left:6px; font-size:0.85em;"><i class="fa-solid fa-file-lines"></i> ' . lang('@Reminder letter') . '</a>'),      // (ikke for en kunde der har fravalgt rykkere - brevet findes ikke)
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
