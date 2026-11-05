<?php # /inc/reminder.inc.php v:1.0.0 d:2026-11-04 i:evs
// Fælles for rykkermailen (reminder_action.php) og rykkerblanketten (reminder_view.php): fakturaens rykkerdata,
// restbeløb, gebyr efter renteloven § 9b og teksten - så mail og blanket altid siger det samme.

// Fakturaen med det en rykker skal bruge (restbeløb i bogføringsvalutaen = total + kreditnotaer - betalinger), eller null
function reminder_fetch($conn, int $inv_id): ?array {
    if ($inv_id <= 0) return null;
    $extra  = db_has_column($conn, 'customers', 'cust_no_reminders') ? ', c.cust_no_reminders' : '';
    $extra .= db_has_column($conn, 'customers', 'cust_language') ? ', c.cust_language' : '';
    $disc = invoice_discount_sql($conn);
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_due_date, i.inv_status, i.reminder_count,
                   i.currency AS inv_currency, i.exch_rate, i.reminder_sent_at, i.cust_id, c.cust_name, c.cust_email, c.cust_address $extra,
                   (SELECT COALESCE(SUM(quantity * price_each $disc * (100 + line_vat_rate) / 100.0), 0)
                    FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . " AS total,
                   (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
            FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id"));
    return $inv ?: null;
}

// Kan der rykkes for fakturaen? (bogført, forfalden, ikke betalt, kunden har ikke fravalgt rykkere)
function reminder_due(array $inv): bool {
    return strtolower((string)$inv['inv_status']) === 'sent' && !empty($inv['inv_due_date']) && $inv['inv_due_date'] < date('Y-m-d')
        && empty($inv['cust_no_reminders']) && ((float)$inv['total'] - (float)$inv['paid']) > 0.01;
}

// De sendte rykkere for fakturaen ifølge revisionsloggen: [rykker nr. => ['at' => logtidspunkt, 'fee' => gebyr|null]]
// ('fee' logges fra 2026-11-05 - for ældre rykkere er det null, og gebyret genberegnes ud fra tidspunkterne)
function reminder_sent_log($conn, int $inv_id): array {
    $out = [];
    $res = DB::query($conn, "SELECT log_date, new_values FROM audit_log WHERE action_type = 'SEND_REMINDER' AND table_name = 'invoices' AND row_id = $inv_id ORDER BY log_id");
    while ($res && ($r = DB::fetch_assoc($res))) {
        $nv = json_decode((string)$r['new_values'], true) ?: [];
        if (!empty($nv['reminder_count'])) $out[(int)$nv['reminder_count']] = ['at' => (string)$r['log_date'], 'fee' => isset($nv['fee']) ? (float)$nv['fee'] : null];
    }
    return $out;
}

// Rykker nr. $reminder_no: beløb, gebyr og tekst. Kaldes med kundens sprog aktivt (doc_lang_push()).
// $prev_sent_at: hvornår den forrige rykker blev sendt (gebyr kræver mindst 10 dage imellem, renteloven § 9b).
// En allerede sendt rykker: $sent_at er dens afsendelsestidspunkt, og $fee det gebyr der blev beregnet dengang (kendes det)
function reminder_compose(array $s, array $inv, int $reminder_no, ?string $prev_sent_at, ?string $sent_at = null, ?float $fee = null): array {
    $cur          = $s['currency'] ?? 'DKK';
    $amount_due   = round((float)$inv['total'] - (float)$inv['paid'], 2);
    $days_overdue = days_between($inv['inv_due_date'], $sent_at ? date('Y-m-d', strtotime(db_ts_local($sent_at))) : date('Y-m-d'));
    $f_no         = '#' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT);
    $due_date_fmt = date(CONF_DATE_FORMAT, strtotime($inv['inv_due_date']));
    // En faktura i fremmed valuta: restbeløbet i fakturaens egen valuta (kunden skylder den valuta)
    $show_cur = $cur;  $show_amount = $amount_due;
    $inv_cur = strtoupper((string)($inv['inv_currency'] ?? ''));
    if ((float)($inv['exch_rate'] ?? 0) > 0 && $inv_cur !== '' && $inv_cur !== strtoupper($cur)) {
        $show_cur = $inv_cur;  $show_amount = round($amount_due / (float)$inv['exch_rate'], 2);
    }
    $amount_fmt = fmt_dk($show_amount) . ' ' . $show_cur;

    // Gebyr (renteloven § 9b): kun 2. og 3. rykker, mindst 10 dage efter den forrige, højst 100 kr (i DKK)
    if ($fee === null) {
        $fee = ($reminder_no >= 2) ? (float)($s['reminder_fee_amount'] ?? 0) : 0.0;
        $days_since_prev = $prev_sent_at ? (int)floor((($sent_at ? strtotime($sent_at) : time()) - strtotime($prev_sent_at)) / 86400) : PHP_INT_MAX;
        if ($reminder_no > 3 || $days_since_prev < 10) $fee = 0.0;
        if (strtoupper($cur) === 'DKK') $fee = min($fee, 100.0);
    }
    $fee_fmt  = fmt_dk($fee) . ' ' . $cur;
    $fee_note = $fee > 0 ? ' ' . sprintf(lang('@(a reminder fee of %s applies to this reminder)'), $fee_fmt) : '';

    if ($reminder_no === 1) {
        $title   = lang('@Payment Reminder');
        $body = sprintf(lang("@Hi %s,\n\nOur records show that invoice %s, due on %s, has not yet been paid (%d days overdue).\n\nAmount due: %s\n\nPlease arrange payment as soon as possible. If you have already paid, please disregard this reminder.\n\nBest regards,"),
            $inv['cust_name'], $f_no, $due_date_fmt, $days_overdue, $amount_fmt);
    } elseif ($reminder_no === 2) {
        $title   = lang('@2nd Payment Reminder');
        $body = sprintf(lang("@Hi %s,\n\nThis is our SECOND reminder that invoice %s, due on %s, remains unpaid (%d days overdue).\n\nAmount due: %s%s\n\nPlease arrange payment immediately. Contact us right away if there is a problem paying this invoice.\n\nBest regards,"),
            $inv['cust_name'], $f_no, $due_date_fmt, $days_overdue, $amount_fmt, $fee_note);
    } else {
        $title   = lang('@FINAL Payment Reminder');
        $body = sprintf(lang("@Hi %s,\n\nThis is reminder no. %d for invoice %s, due on %s, still unpaid (%d days overdue).\n\nAmount due: %s%s\n\nThis is a FINAL notice. If payment is not received shortly, we may refer this matter to debt collection without further warning.\n\nBest regards,"),
            $inv['cust_name'], $reminder_no, $f_no, $due_date_fmt, $days_overdue, $amount_fmt, $fee_note);
    }
    return ['title' => $title, 'subject' => $title . ' - ' . lang('@Invoice') . ' ' . $f_no, 'body_text' => $body,
            'amount_due' => $amount_due, 'show_amount' => $show_amount, 'show_cur' => $show_cur, 'amount_fmt' => $amount_fmt,
            'fee' => $fee, 'fee_fmt' => $fee_fmt, 'cur' => $cur, 'f_no' => $f_no, 'due_date_fmt' => $due_date_fmt, 'days_overdue' => $days_overdue];
}
