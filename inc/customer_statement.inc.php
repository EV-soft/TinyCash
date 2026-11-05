<?php # /inc/customer_statement.inc.php v:1.3.1 d:2026-09-15 i:evs
# NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): kundekontoudtogets
# beregningslogik (kronologisk begivenhedsliste, løbende saldo, åbne/
# forfaldne fakturaer, "Saldo primo" ved periodefilter) udtrukket fra
# customer_statement.php til en genbrugelig funktion - customer_statement.php
# selv og den nye customer_statement_send.php (mail-udgaven) skal begge
# kunne beregne PRÆCIS samme saldo, uden at have samme forretningslogik
# kopieret to steder. Samme "udtræk til fælles funktion"-mønster som
# reconcile_one_bank_line()/send_single_reminder()/mark_expense_paid() fra
# samme session.

// Er en invoice_payments-note en afskrivning? Notens præfiks er lang('@Written off as bad debt') på det sprog brugeren
// havde, da afskrivningen blev lavet - derfor sammenlignes mod alle sprogs oversættelser (og den engelske nøgle).
function cs_is_writeoff_note(string $note): bool {
    static $prefixes = null;
    if ($note === '') return false;
    if ($prefixes === null) {
        $prefixes = ['Written off as bad debt'];
        $d = json_decode((string)@file_get_contents(dirname(__DIR__) . '/json-data/languages.json'), true);
        foreach (($d['language'] ?? []) as $l) {
            $t = trim((string)($l['translation']['@Written off as bad debt'] ?? ''));
            if ($t !== '') $prefixes[] = $t;
        }
        $prefixes = array_unique($prefixes);
    }
    foreach ($prefixes as $pre) if (strncasecmp($note, $pre, strlen($pre)) === 0) return true;
    return false;
}

/**
 * Beregner et kundekontoudtogs fulde datagrundlag: kronologisk
 * begivenhedsliste, løbende saldo, "Saldo primo" ved periodefilter, samt
 * åbne/forfaldne fakturatællinger. Returnerer null hvis kunden ikke findes.
 */
function get_customer_statement_data($conn, int $cust_id, string $from = '', string $to = ''): ?array {
    $res_c = DB::query($conn, "SELECT * FROM customers WHERE cust_id = $cust_id");
    $cust  = DB::fetch_assoc($res_c);
    if (!$cust) return null;

    // --- Hent alle bogførte fakturaer (kladder udelades - ingen fakturanummer, ingen reel transaktion endnu) ---
    // NYT: credit_note_no findes kun efter db-setup/migrate_credit_note_no.php er kørt - samme "stille
    // degradering"-mønster som andre nyere kolonner.
    $has_cn_no_col = db_has_column($conn, 'invoices', 'credit_note_no');
    $cs_cn_no_select = $has_cn_no_col ? ", credit_note_no" : "";
    $res_i = DB::query($conn, "SELECT inv_id, invoice_no, inv_date, inv_due_date, credit_ref, inv_status$cs_cn_no_select
                                FROM invoices
                                WHERE cust_id = $cust_id AND LOWER(inv_status) != 'draft'
                                ORDER BY inv_date ASC, inv_id ASC");
    $invoices = [];
    while ($row = DB::fetch_assoc($res_i)) { $invoices[] = $row; }

    // --- Byg den fulde, kronologiske begivenhedsliste (hele historikken,
    // uanset periodefilter - saldoen skal altid være korrekt, se "Saldo
    // primo" nedenfor) ---
    $events           = [];
    $open_invoices    = 0;
    $overdue_invoices = 0;
    $today            = date('Y-m-d');

    foreach ($invoices as $inv) {
        // Se customer_statement.php's tidligere version af denne kommentar
        // (§reel-multi-valuta-bogforing, §bugs-batch-32-review) for baggrunden
        // om invoice_dkk_totals().
        $inv_total = invoice_dkk_totals($conn, $inv['inv_id'])['incl'];

        $is_credit = !empty($inv['credit_ref']);
        // RETTET (runde 33): en kreditnota har intet invoice_no - viste før
        // fejlagtigt "Kreditnota #000000" for enhver kreditnota. Bruger nu
        // dens eget, separate credit_note_no.
        if ($is_credit) {
            $inv_label = lang('@Credit Note') . ' ' . (!empty($inv['credit_note_no']) ? 'KN-'.str_pad($inv['credit_note_no'], 6, '0', STR_PAD_LEFT) : '#------');
        } else {
            $inv_label = lang('@Invoice') . ' #' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT);
        }

        $events[] = [
            'date'   => $inv['inv_date'],
            'label'  => $inv_label,
            'link'   => 'invoice_view.php?id=' . $inv['inv_id'],
            'amount' => $inv_total,
        ];

        $paid_row = DB::fetch_assoc(DB::query($conn,
            "SELECT COALESCE(SUM(amount), 0) AS paid FROM invoice_payments WHERE inv_id = " . $inv['inv_id']));
        $paid = round((float)($paid_row['paid'] ?? 0), 2);
        $remaining = round($inv_total + ($is_credit ? 0.0 : invoice_credited_dkk($conn, (int)$inv['inv_id'])) - $paid, 2);     // (minus en delvis kreditnota)

        $is_credited = (strtolower($inv['inv_status'] ?? '') === 'credited');
        if (!$is_credit && !$is_credited && $remaining > 0.01) {
            $open_invoices++;
            if (!empty($inv['inv_due_date']) && $inv['inv_due_date'] < $today) $overdue_invoices++;
        }

        $pay_res = DB::query($conn, "SELECT payment_date, amount, note FROM invoice_payments
                                      WHERE inv_id = " . $inv['inv_id'] . " ORDER BY payment_date ASC, payment_id ASC");
        while ($p = DB::fetch_assoc($pay_res)) {
            // RETTET: en afskrivning (tab på debitor, invoice_writeoff.php) gemmes som en "betaling" og stod derfor som
            // "Betaling for faktura #..." - også på kontoudtoget der mailes til kunden, som en betaling kunden aldrig
            // har foretaget. Genkendes på notens præfiks (skrevet på brugerens sprog på afskrivningstidspunktet).
            $is_wo = cs_is_writeoff_note((string)($p['note'] ?? ''));
            $events[] = [
                'date'   => $p['payment_date'],
                'label'  => lang($is_wo ? '@Written off as bad debt' : '@Payment for invoice') . ($is_wo ? ' - ' . lang('@Invoice') : '') . ' #' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT),
                'link'   => 'invoice_view.php?id=' . $inv['inv_id'],
                'amount' => round((float)$p['amount'], 2) * -1,
            ];
        }
    }

    // Kronologisk rækkefølge - stabil sortering, samme dato beholder
    // indsættelsesrækkefølgen (faktura før dens egne betalinger).
    usort($events, function ($a, $b) { return strcmp($a['date'], $b['date']); });

    // Løbende saldo over HELE historikken.
    $running = 0;
    foreach ($events as &$e) { $running += $e['amount']; $e['balance'] = round($running, 2); }
    unset($e);
    $total_outstanding = round($running, 2);

    // --- Periodefilter: saldoen bevares korrekt via en "Saldo primo"-linje,
    // selv når kun en del af historikken vises. ---
    $opening_balance = 0;
    $visible_events  = [];
    foreach ($events as $e) {
        if ($from !== '' && $e['date'] < $from) { $opening_balance = $e['balance']; continue; }
        if ($to   !== '' && $e['date'] > $to)   { continue; }
        $visible_events[] = $e;
    }
    // RETTET: et udtog for en AFSLUTTET periode (til-dato) sluttede med dagens samlede saldo - den passede ikke med
    // sidste linjes saldo, og kunden fik et udtog der ikke stemte. Saldoen ved periodens slutning følger nu med.
    $closing_balance = $visible_events ? end($visible_events)['balance'] : $opening_balance;

    return [
        'cust'              => $cust,
        'invoices'          => $invoices,
        'events'            => $events,
        'visible_events'    => $visible_events,
        'opening_balance'   => $opening_balance,
        'total_outstanding' => $total_outstanding,
        'closing_balance'   => round((float)$closing_balance, 2),
        'open_invoices'     => $open_invoices,
        'overdue_invoices'  => $overdue_invoices,
    ];
}
