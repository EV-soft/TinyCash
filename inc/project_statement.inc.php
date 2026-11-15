<?php # /inc/project_statement.inc.php v:1.3.1 d:2026-09-15 i:evs
# NYT (bruger-anmodet, forbillede: en "Sagers kontokort"-rapport fra et advokatsystem): et
# projekts kontokort - en kronologisk oversigt over ALLE økonomiske
# bevægelser knyttet til ét projekt, med løbende saldo, modelleret direkte
# efter Unik-eksemplets "Sagers kontokort". Samme "udtræk beregningslogik
# til en fælles funktion"-arkitektur som get_customer_statement_data()
# (inc/customer_statement.inc.php) - project_statement.php bruger denne.
#
# VIGTIG FORSKEL fra project_view.php's eget øverste sammendrag: dét viser
# projektets RENTABILITET (indtægt minus omkostning, et P&L-perspektiv).
# Kontokortet her viser i stedet, hvad KUNDEN SKYLDER for netop dette
# projekt/denne sag (et tilgodehavende-perspektiv, ligesom et
# kundekontoudtog) - derfor har udgifter/omkostninger MODSAT fortegn her end
# i project_view.php: et udlæg/en omkostning afholdt FOR kunden (fx en
# retsafgift) forøger det, kunden skylder (positivt beløb, "debet"), præcis
# som i Unik-eksemplets "Retsafgift, stævning 1.350,00"-linje - det er
# IKKE en omkostning der trækkes fra, men et beløb der lægges oven i
# tilgodehavendet, indtil kunden betaler/refunderes. Tilsvarende er en
# "anden indtægt" (fx en forsikringsudbetaling modtaget FOR kunden) en
# KREDIT (negativt beløb, reducerer tilgodehavendet) - modsat project_view.
# php, hvor den tælles som positiv indtægt i rentabilitets-regnskabet.
# Fakturaer (debet) og deres indbetalinger (kredit) følger præcis samme
# mønster/fortegn som det allerede eksisterende kundekontoudtog.

require_once __DIR__ . '/invoice_layouts.inc.php';  // il_column_exists()
require_once __DIR__ . '/customer_statement.inc.php';  // cs_is_writeoff_note()

/**
 * Beregner et projekts fulde kontokort-datagrundlag: kronologisk
 * begivenhedsliste over fakturaer, fakturabetalinger, udgifter/omkostninger
 * (debet) og andre indtægter (kredit), løbende saldo, samt "Saldo primo"
 * ved periodefilter. Returnerer null hvis projektet ikke findes.
 */

function get_project_statement_data($conn, int $proj_id, string $from = '', string $to = ''): ?array {
    $res_p = DB::query($conn, "
        SELECT p.*, c.cust_name, c.cust_id
        FROM projects p
        LEFT JOIN customers c ON p.cust_id = c.cust_id
        WHERE p.proj_id = $proj_id
    ");
    $proj = DB::fetch_assoc($res_p);
    if (!$proj) return null;

    $events = [];

    // --- Fakturaer (debet) + deres indbetalinger (kredit) - samme
    // "kladder tæller ikke med endnu"-princip som kundekontoudtoget. ---
    // RETTET: kreditnotaer arver fakturaens projekt, men har intet invoice_no - de stod som "Faktura #000000".
    // Nu "Kreditnota KN-NNNNNN" (samme visning som kundekontoudtoget).
    $ps_cn_col = il_column_exists($conn, 'invoices', 'credit_note_no') ? ', credit_note_no' : '';
    $res_i = DB::query($conn, "
        SELECT inv_id, invoice_no, inv_date, credit_ref$ps_cn_col
        FROM invoices
        WHERE (proj_id = $proj_id OR inv_id IN (SELECT inv_id FROM invoice_lines WHERE proj_id = $proj_id)) AND LOWER(inv_status) != 'draft'      -- (også linjer med projektet)
        ORDER BY inv_date ASC, inv_id ASC
    ");
    $ps_disc = invoice_discount_sql($conn);  $ps_disc_il = invoice_discount_sql($conn, 'il.');
    while ($inv = DB::fetch_assoc($res_i)) {
        $inv_full  = invoice_dkk_totals($conn, (int)$inv['inv_id'])['incl'];
        // Kun projektets egne linjer (linjens projekt, ellers fakturaens - som project_view.php). Før talte HELE fakturaen
        // med på hvert projekt der havde blot én linje på den, så en faktura delt mellem to projekter stod dobbelt.
        // Gebyrer og betalinger fordeles i samme forhold.
        $ps_part = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(il.quantity * il.price_each $ps_disc_il * (100 + il.line_vat_rate) / 100.0), 0)
            FROM invoice_lines il JOIN invoices i ON i.inv_id = il.inv_id
            WHERE il.inv_id = " . (int)$inv['inv_id'] . " AND COALESCE(NULLIF(il.proj_id, 0), i.proj_id) = $proj_id"));
        $ps_all  = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(quantity * price_each $ps_disc * (100 + line_vat_rate) / 100.0), 0) FROM invoice_lines WHERE inv_id = " . (int)$inv['inv_id']));
        $ps_share = abs((float)($ps_all[0] ?? 0)) >= 0.005 ? max(0.0, min(1.0, (float)($ps_part[0] ?? 0) / (float)$ps_all[0])) : 1.0;
        if ($ps_share <= 0) continue;      // (projektet står kun i hovedet, alle linjer hører til et andet projekt)
        $inv_total = round($inv_full * $ps_share, 2);
        $ps_label = !empty($inv['credit_ref'])
            ? lang('@Credit Note') . (!empty($inv['credit_note_no']) ? ' KN-' . str_pad((string)$inv['credit_note_no'], 6, '0', STR_PAD_LEFT) : '')
            : lang('@Invoice') . ' #' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT);
        $events[] = [
            'date'   => $inv['inv_date'],
            'label'  => $ps_label,
            'link'   => 'invoice_view.php?id=' . $inv['inv_id'],
            'amount' => $inv_total,
        ];

        // Bogførte rykkergebyrer skyldes også (som på kundens kontoudtog) - før manglede de, mens kundens betaling af
        // faktura + gebyr blev trukket fra, så saldoen blev for lav
        if (empty($inv['credit_ref']) && db_has_table($conn, 'invoice_fees')) {
            $fres = DB::query($conn, "SELECT fee_date, amount, reminder_no FROM invoice_fees WHERE inv_id = " . (int)$inv['inv_id'] . " ORDER BY fee_id");
            while ($fres && ($f = DB::fetch_assoc($fres))) {
                $events[] = ['date' => $f['fee_date'], 'label' => sprintf(lang('@Reminder fee, reminder no. %d, invoice #%s'), (int)$f['reminder_no'], str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT)),
                             'link' => 'invoice_view.php?id=' . $inv['inv_id'], 'amount' => round((float)$f['amount'] * $ps_share, 2)];
            }
        }
        $pay_res = DB::query($conn, "SELECT payment_date, amount, note FROM invoice_payments
                                      WHERE inv_id = " . $inv['inv_id'] . " ORDER BY payment_date ASC, payment_id ASC");
        while ($p = DB::fetch_assoc($pay_res)) {
            $events[] = [
                'date'   => $p['payment_date'],
                'label'  => !empty($inv['credit_ref']) ? lang('@Refund paid out') . ' - ' . $ps_label      // (kreditnotaen har intet fakturanummer)
                          : (cs_is_writeoff_note((string)($p['note'] ?? '')) ? lang('@Written off as bad debt') . ' - ' . lang('@Invoice') : (cs_is_fx_note((string)($p['note'] ?? '')) ? lang('@Currency exchange difference') . ' - ' . lang('@Invoice') : lang('@Payment for invoice'))) . ' #' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT),      // (afskrivning, se customer_statement.inc.php)
                'link'   => 'invoice_view.php?id=' . $inv['inv_id'],
                'amount' => round((float)$p['amount'] * $ps_share, 2) * -1,
            ];
        }
    }

    // RETTET: udgiftskladder (intet bilagsnummer, ikke bogført) talte med som udlæg kunden skylder. ---
    $res_e = DB::query($conn, "
        SELECT exp_id, exp_date, supplier, description, amount, exp_type
        FROM expenses
        WHERE proj_id = $proj_id AND is_cancelled = 0 AND voucher_no IS NOT NULL
        ORDER BY exp_date ASC, exp_id ASC
    ");
    while ($e = DB::fetch_assoc($res_e)) {
        $is_expense = ($e['exp_type'] === 'expense');
        $label_text = trim($e['description'] ?? '') !== '' ? $e['description'] : $e['supplier'];
        $events[] = [
            'date'   => $e['exp_date'],
            'label'  => ($is_expense ? lang('@Disbursement') : lang('@Other Income')) . ': ' . $label_text,
            'link'   => 'expense_edit.php?id=' . $e['exp_id'],
            'amount' => $is_expense ? round((float)$e['amount'], 2) : round((float)$e['amount'], 2) * -1,
        ];
    }

    // Kronologisk rækkefølge - stabil sortering, samme princip som
    // kundekontoudtogets egen (samme dato beholder indsættelsesrækkefølgen).
    usort($events, function ($a, $b) { return strcmp($a['date'], $b['date']); });

    // Løbende saldo over HELE historikken.
    $running = 0;
    foreach ($events as &$e) { $running += $e['amount']; $e['balance'] = round($running, 2); }
    unset($e);
    $total_balance = round($running, 2);

    // --- Periodefilter: saldoen bevares korrekt via en "Saldo primo"-linje,
    // selv når kun en del af historikken vises - samme mønster som
    // kundekontoudtoget. ---
    $opening_balance = 0;
    $visible_events  = [];
    foreach ($events as $e) {
        if ($from !== '' && $e['date'] < $from) { $opening_balance = $e['balance']; continue; }
        if ($to   !== '' && $e['date'] > $to)   { continue; }
        $visible_events[] = $e;
    }
    // Med en til-dato skal saldoen være saldoen ved periodens slutning, ikke dagens (som kundekontoudtoget)
    $closing_balance = $visible_events ? end($visible_events)['balance'] : $opening_balance;

    return [
        'proj'            => $proj,
        'events'          => $events,
        'visible_events'  => $visible_events,
        'opening_balance' => $opening_balance,
        'total_balance'   => $total_balance,
        'closing_balance' => round((float)$closing_balance, 2),
    ];
}
