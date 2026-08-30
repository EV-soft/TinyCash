<?php # /reminders.php v:1.3.0 d:2026-08-30 i:evs
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
$sql = "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_due_date, i.reminder_sent_at, i.reminder_count,
               c.cust_name, c.cust_email,
               (SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0)
                FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) AS total,
               (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
        FROM invoices i
        JOIN customers c ON i.cust_id = c.cust_id
        WHERE i.inv_status = 'sent' AND i.inv_due_date IS NOT NULL AND i.inv_due_date < '$today'
        ORDER BY i.inv_due_date ASC";
$res = DB::query($conn, $sql);

$headers = ['@Invoice', '@Customer', '@Due Date', '@Days Overdue', '@Amount', '@Last Reminder', '@Actions'];
$data = [];

if (!$res) {
    echo htm_Alert("SQL Error: " . DB::error($conn), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $amount_due = (float)$row['total'] - (float)$row['paid'];
        if ($amount_due <= 0.01) continue; // reelt allerede fuldt betalt (afrundingsrest) - ingen grund til at ryk for 0 kr.

        $days_overdue = (int)((strtotime($today) - strtotime($row['inv_due_date'])) / 86400);
        $due_color = $days_overdue > 14 ? '#c0392b' : ($days_overdue > 7 ? '#e67e22' : '#7f8c8d');

        $last_reminder = $row['reminder_sent_at']
            ? date(CONF_DATE_FORMAT, strtotime($row['reminder_sent_at'])) . ' (' . (int)$row['reminder_count'] . ')'
            : '<span style="color:var(--text-muted);">' . lang('@Never') . '</span>';

        $confirm = addslashes(sprintf(lang('@Send a payment reminder to %s?'), $row['cust_email']));
        $send_btn = '<a href="reminder_action.php?id=' . (int)$row['inv_id'] . '" '
            . 'onclick="return confirm(\'' . $confirm . '\')" '
            . 'style="background:var(--color-primary); color:#fff; padding:6px 14px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em;">'
            . '<i class="fa-solid fa-envelope"></i> ' . lang('@Send Reminder') . '</a>';

        $data[] = [
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
    htm_Table($headers, $data, 'remindersTbl', 100, '', true, [], '600px');
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
