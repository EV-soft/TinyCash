<?php # /inc/reminder.inc.php v:1.0.0 d:2026-11-04 i:evs
// Fælles for rykkermailen (reminder_action.php) og rykkerblanketten (reminder_view.php): fakturaens rykkerdata,
// restbeløb, gebyr efter renteloven § 9b og teksten - så mail og blanket altid siger det samme.

// RYKKERGEBYRER: et gebyr på en sendt rykker bogføres (debet debitor, kredit gebyrkontoen - momsfrit) og knyttes til
// fakturaen i invoice_fees, så det indgår i fakturaens skyldige beløb overalt (invoice_fees_sql()/_dkk()) - én samlet
// betaling af faktura + gebyr lukker begge. Før stod gebyret kun i teksten og blev aldrig bogført.
function rf_account($conn): int {
    return (int)setting('conf_acc_reminder_fee', 1090);
}
// Kan et gebyr bogføres? (tabellen, gebyrkontoen og debitorkontoen findes, dagen er ikke låst) - ellers kræves intet gebyr
function rf_ready($conn): bool {
    return db_has_table($conn, 'invoice_fees') && tc_account_ok($conn, rf_account($conn)) && tc_account_ok($conn, (int)setting('conf_acc_debitor', 8100))
        && !is_date_locked($conn, date('Y-m-d'));
}
// Tabel + gebyrkonto (idempotent). Returnerer linjer til kalderens udskrift.
function rf_ensure_schema($conn, bool $is_sqlite): array {
    $out = [];
    if (!db_has_table($conn, 'invoice_fees')) {
        $ok = DB::query($conn, $is_sqlite
            ? "CREATE TABLE invoice_fees (fee_id INTEGER PRIMARY KEY AUTOINCREMENT, inv_id INTEGER NOT NULL, fee_date TEXT NOT NULL,
                   amount REAL NOT NULL, reminder_no INTEGER, voucher_no INTEGER, created_by INTEGER, created_at TEXT)"
            : "CREATE TABLE invoice_fees (fee_id INT AUTO_INCREMENT PRIMARY KEY, inv_id INT NOT NULL, fee_date DATE NOT NULL,
                   amount DECIMAL(12,2) NOT NULL, reminder_no INT, voucher_no INT, created_by INT, created_at DATETIME, INDEX (inv_id)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out[] = $ok ? "[OK] Tabellen 'invoice_fees' oprettet - rykkergebyrer bogføres nu." : "[FEJL] invoice_fees: " . DB::error($conn);
    } else {
        $out[] = "[SPRUNGET OVER] Tabellen 'invoice_fees' findes allerede.";
    }
    $acc = rf_account($conn);
    if (tc_account_ok($conn, $acc)) {
        $out[] = "[SPRUNGET OVER] Gebyrkontoen $acc findes allerede.";
    } elseif (DB::insert($conn, 'accounts', ['acc_id' => $acc, 'acc_name' => 'Rykkergebyrer', 'acc_type' => 'income', 'vat_rate' => 0])) {
        $out[] = "[OK] Konto $acc 'Rykkergebyrer' (indtægt, momsfri) oprettet.";
    } else {
        $out[] = "[FEJL] Konto $acc: " . DB::error($conn);
    }
    return $out;
}
// Bogfører et gebyr på fakturaen. Returnerer bilagsnummeret; kaster en undtagelse ved fejl.
// Atomisk: hele bilaget eller intet (indlejret i kalderens transaktion bliver det et savepoint) - se DB::transaction()
function rf_post_fee($conn, int $inv_id, float $amount, int $reminder_no, string $date): int {
    return DB::transaction($conn, fn() => rf_post_fee_steps($conn, $inv_id, $amount, $reminder_no, $date));
}
function rf_post_fee_steps($conn, int $inv_id, float $amount, int $reminder_no, string $date): int {
    $amount = round($amount, 2);
    if ($amount <= 0) return 0;
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT invoice_no FROM invoices WHERE inv_id = $inv_id"));
    if (!$inv) throw new Exception(lang('@The invoice does not exist.'));
    $voucher = next_voucher_no($conn);
    $cb  = journal_created_by_sql($conn);
    $txt = DB::escape($conn, sprintf(lang('@Reminder fee, reminder no. %d, invoice #%s'), $reminder_no, $inv['invoice_no']));
    if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
                          VALUES ('" . DB::escape($conn, $date) . "', '$txt', 'reminder_fee', $voucher, '" . DB::escape($conn, setting('currency', 'DKK')) . "'{$cb['val']})") === false) {
        throw new Exception(DB::error($conn));
    }
    $jou = (int)DB::insert_id($conn);
    ledger_post($conn, $jou, (int)setting('conf_acc_debitor', 8100), $amount);
    ledger_post($conn, $jou, rf_account($conn), -$amount);
    DB::insert($conn, 'invoice_fees', ['inv_id' => $inv_id, 'fee_date' => $date, 'amount' => $amount, 'reminder_no' => $reminder_no,
        'voucher_no' => $voucher, 'created_by' => (int)($_SESSION['user_id'] ?? 0), 'created_at' => date('Y-m-d H:i:s')]);
    log_action($conn, 'POST_REMINDER_FEE', 'invoices', $inv_id, null, ['amount' => $amount, 'reminder_no' => $reminder_no, 'voucher_no' => $voucher]);
    return $voucher;
}

// Fakturaen med det en rykker skal bruge (restbeløb i bogføringsvalutaen = total + kreditnotaer - betalinger), eller null
function reminder_fetch($conn, int $inv_id): ?array {
    if ($inv_id <= 0) return null;
    $extra  = db_has_column($conn, 'customers', 'cust_no_reminders') ? ', c.cust_no_reminders' : '';
    $extra .= db_has_column($conn, 'customers', 'cust_language') ? ', c.cust_language' : '';
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_due_date, i.inv_status, i.reminder_count,
                   i.currency AS inv_currency, i.exch_rate, i.reminder_sent_at, i.cust_id, c.cust_name, c.cust_email, c.cust_address $extra,
                   " . invoice_total_sql($conn) . " AS total, " . invoice_paid_sql() . " AS paid
            FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id"));
    if (!$inv) return null;
    require_once __DIR__ . '/doc_paper.inc.php';
    if (array_key_exists('cust_language', $inv)) $inv['cust_language'] = doc_customer_lang($conn, (int)$inv['cust_id']);      // (udenlandsk kunde uden valgt sprog: engelsk)
    return $inv;
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
        if (!empty($nv['reminder_count'])) $out[(int)$nv['reminder_count']] = ['at' => db_ts_local((string)$r['log_date']), 'fee' => isset($nv['fee']) ? (float)$nv['fee'] : null];
    }
    return $out;
}

// Hele døgn mellem to lokale tidspunkter (DateTime regner sommertid rigtigt; et negativt interval giver 0)
function reminder_whole_days(string $from, string $to): int {
    try { $d = (new DateTime($from))->diff(new DateTime($to)); return $d->invert ? 0 : (int)$d->days; }
    catch (\Throwable $e) { return 0; }
}

// Rykker nr. $reminder_no: beløb, gebyr og tekst. Kaldes med kundens sprog aktivt (doc_lang_push()).
// $prev_sent_at: hvornår den forrige rykker blev sendt (gebyr kræver mindst 10 dage imellem, renteloven § 9b).
// En allerede sendt rykker: $sent_at er dens afsendelsestidspunkt, og $fee det gebyr der blev beregnet dengang (kendes det)
function reminder_compose(array $s, array $inv, int $reminder_no, ?string $prev_sent_at, ?string $sent_at = null, ?float $fee = null): array {
    $cur          = $s['currency'] ?? 'DKK';
    $amount_due   = round((float)$inv['total'] - (float)$inv['paid'], 2);
    $days_overdue = days_between($inv['inv_due_date'], $sent_at ? date('Y-m-d', strtotime($sent_at)) : date('Y-m-d'));      // ($sent_at er allerede lokal tid - reminder_sent_log())
    $f_no         = '#' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT);
    $due_date_fmt = date(CONF_DATE_FORMAT, strtotime($inv['inv_due_date']));
    // En faktura i fremmed valuta: restbeløbet i fakturaens egen valuta (kunden skylder den valuta)
    $show_cur = $cur;  $show_amount = $amount_due;
    $inv_cur = strtoupper((string)($inv['inv_currency'] ?? ''));
    if ((float)($inv['exch_rate'] ?? 0) > 0 && $inv_cur !== '' && $inv_cur !== strtoupper($cur)) {
        $show_cur = $inv_cur;  $show_amount = round($amount_due / (float)$inv['exch_rate'], 2);
    }
    $amount_fmt = fmt_dk($show_amount) . ' ' . $show_cur;

    // Gebyr efter landepakkens regler (Danmark, renteloven § 9b: kun 2. og 3. rykker, mindst 10 dage efter den
    // forrige, højst 100 kr i DKK) - se tc_reminder_fee_allowed()
    if ($fee === null) {
        // (første rykker: dagene siden forfald - fx Norge/Finland: et gebyr tidligst 14 dage efter forfaldsdagen)
        // (hele døgn efter vægurets tid - sekunder / 86400 talte 10 dage hen over sommertidens start som 9, og gebyret faldt bort)
        $days_since_prev = $prev_sent_at ? reminder_whole_days($prev_sent_at, $sent_at ?? date('Y-m-d H:i:s')) : max(0, (int)$days_overdue);
        $fee = tc_reminder_fee_allowed((float)($s['reminder_fee_amount'] ?? 0), $reminder_no, $days_since_prev, (string)$cur);
    }
    $fee_fmt  = fmt_dk($fee) . ' ' . $cur;
    $fee_note = $fee > 0 ? ' ' . sprintf(lang('@(a reminder fee of %s applies to this reminder)'), $fee_fmt) : '';

    if ($reminder_no === 1) {
        $title   = lang('@Payment Reminder');
        $body = sprintf(lang("@Hi %s,\n\nOur records show that invoice %s, due on %s, has not yet been paid (%d days overdue).\n\nAmount due: %s\n\nPlease arrange payment as soon as possible. If you have already paid, please disregard this reminder.\n\nBest regards,"),
            $inv['cust_name'], $f_no, $due_date_fmt, $days_overdue, $amount_fmt . $fee_note);      // (gebyr på 1. rykker - fx Sverige/Norge - skal nævnes)
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
