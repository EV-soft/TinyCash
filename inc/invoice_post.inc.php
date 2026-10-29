<?php # /inc/invoice_post.inc.php v:1.3.1 d:2026-09-15 i:evs
# Fakturaens bogføring, betaling og kreditnota som fælles funktioner - brugt af siderne
# (invoice_post_action.php, invoice_credit.php) og af webbutik-ordrebehandleren (inc/shop.inc.php),
# så en ordre fra en webbutik bogføres med PRÆCIS samme kode som en faktura bogført i hånden.
# Kalderen styrer transaktionen (DB::begin_transaction / commit / rollback) - funktionerne kaster en
# Exception ved fejl, så kalderen kan rulle det hele tilbage.
require_once __DIR__ . '/audit.inc.php';
if (!function_exists('lang')) require_once __DIR__ . '/php2htm.lib.php';     // lang() (kreditnotaens tekster) - også fra webhook_invoice.php, der ikke har siden

// RETTET: hele omsætningen blev bogført på Firmaindstillingernes salgskonto - varens egen konto (Varekartotek ->
// "Konto") blev aldrig brugt, så fx salg af ydelser, EU-salg eller en varegruppe med egen konto endte samlet på én
// konto, og momsgrundlaget pr. momskode blev forkert. Nettobeløbet fordeles nu pr. varens salgskonto (en
// indtægtskonto der findes), ellers standardkontoen. Beløbene i DKK som invoice_dkk_totals(); en øreafrunding
// lægges på den største post, så summen altid er $total_excl. Bruges både af fakturaen og af kreditnotaen.
function invoice_sales_split($conn, int $inv_id, int $default_acc, float $total_excl): array {
    $disc = invoice_discount_sql($conn, 'il.');
    $exch = DB::fetch_assoc(DB::query($conn, "SELECT exch_rate FROM invoices WHERE inv_id = $inv_id"));
    $rate = (float)($exch['exch_rate'] ?? 0);
    $res = DB::query($conn, "SELECT il.quantity * il.price_each $disc AS net, a.acc_id AS pacc, a.acc_type
                             FROM invoice_lines il LEFT JOIN products p ON p.prod_id = il.prod_id AND il.prod_id > 0
                             LEFT JOIN accounts a ON a.acc_id = p.acc_id WHERE il.inv_id = $inv_id");
    $raw = [];
    while ($res && ($r = DB::fetch_assoc($res))) {
        $acc = ((int)($r['pacc'] ?? 0) > 0 && ($r['acc_type'] ?? '') === 'income') ? (int)$r['pacc'] : $default_acc;
        $raw[$acc] = ($raw[$acc] ?? 0.0) + (float)$r['net'];
    }
    $out = [];
    foreach ($raw as $acc => $net) {
        $v = round($net, 2);
        if ($rate > 0) $v = round($v * $rate, 2);
        $out[$acc] = $v;
    }
    if (!$out) return [$default_acc => round($total_excl, 2)];
    $diff = round($total_excl - array_sum($out), 2);
    if (abs($diff) >= 0.005) {
        $big = array_key_first($out);
        foreach ($out as $k => $v) if (abs($v) > abs($out[$big])) $big = $k;
        $out[$big] = round($out[$big] + $diff, 2);
    }
    return array_filter($out, fn($v) => abs($v) >= 0.005);
}

// Bogfører en kladde: fakturanummer, journal + hovedbog (debitor / salg / moms), lager ($track_stock) og
// layoutet stemples. Returnerer fakturanummeret.
function invoice_post($conn, int $inv_id, bool $track_stock = true): int {
    global $global_settings;
    $acc_debitor = (int)($global_settings['conf_acc_debitor'] ?? 8100); // Tilgodehavender (debitor)
    $acc_sales   = (int)($global_settings['conf_acc_sales']   ?? 1000); // Omsætning / salg
    $acc_vat     = (int)($global_settings['conf_acc_vat']     ?? 6900); // Moms, salg (udgående)

    // 1. Tjek status + hent dato + valutakurs.
    $row = DB::fetch_assoc(DB::query($conn, "SELECT inv_status, inv_date, exch_rate FROM invoices WHERE inv_id = $inv_id"));
    if (!$row) {
        throw new Exception('Fakturaen blev ikke fundet i systemet');
    }
    // Case-ufølsom: statusværdier er inkonsistente i data ('DRAFT' fra faktura-
    // formularen vs. 'draft' i backend/rapporter). strtolower gør posteringen
    // robust uanset skrivemåde.
    if (strtolower($row['inv_status']) !== 'draft') {
        throw new Exception('Fakturaen er allerede bogført (Status: ' . $row['inv_status'] . ')');
    }
    $inv_date = $row['inv_date'];

    // Periodespærring: en NY postering må ikke kunne bogføres ind i en
    // allerede låst periode - kreditnota-flowet (invoice_credit.php) havde
    // allerede dette tjek, men selve fakturabogføringen manglede det (fundet
    // ved en systematisk gennemgang, §bogforingslov-compliance).
    if (is_date_locked($conn, $inv_date)) {
        throw new Exception('Fakturadatoen er i en låst regnskabsperiode og kan ikke bogføres.');
    }
    // RETTET: en faktura uden linjer kunne bogføres - den fik et fakturanummer og et bilagsnummer (hulfri
    // nummerserier) og kunne bagefter kun fjernes med en kreditnota
    $line_cnt = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoice_lines WHERE inv_id = $inv_id"));
    if (!$line_cnt || (int)$line_cnt[0] === 0) {
        throw new Exception('Fakturaen har ingen linjer og kan ikke bogføres.');
    }

    // 2. Beløb: netto + moms + omregning til DKK - via den fælles
    // invoice_dkk_totals() (inc/db_connect.inc.php), som reconcile_action.php
    // nu også bruger, så de to ALDRIG kan glide fra hinanden igen (se dens
    // egen kommentar for baggrunden - §reel-multi-valuta-bogforing).
    $totals     = invoice_dkk_totals($conn, $inv_id);
    $total_excl = $totals['excl'];
    $total_vat  = $totals['vat'];
    $total_incl = $totals['incl'];

    // 3. Find næste fakturanummer - atomisk tæller (samme mønster som
    // next_voucher_no()), FØR blot "SELECT MAX(invoice_no)+1" uden nogen
    // låsning, sårbart for at to samtidige bogføringer kunne få samme
    // nummer. Se next_invoice_no() i inc/db_connect.inc.php.
    $next_invoice_no = next_invoice_no($conn);

    // 4. Opdater fakturaen med nummer og status 'sent'
    // RETTET (§bugs-batch-19-review): KRITISK kapløbsvindue - status-tjekket
    // i punkt 1 (SELECT ovenfor) og selve UPDATE'en her lå begge inde i
    // transaktionen, men UPDATE'ens WHERE-klausul genkontrollerede ALDRIG
    // status ved selve skrivningen. To næsten-samtidige bogføringsforsøg
    // (fx et utålmodigt dobbeltklik på "Bogfør" i sales_hub.php, som ikke
    // deaktiverer knappen mens forespørgslen er undervejs) kunne begge
    // bestå status-tjekket, FØR nogen af dem nåede at skrive - og begge ville
    // derefter uafhængigt tildele sig hvert sit fakturanummer/bilagsnummer
    // og bogføre fulde, dublerede posteringer for samme faktura. WHERE-
    // klausulen tjekker nu status ATOMISK sammen med selve skrivningen, og
    // et 0-rækker-resultat (nogen nåede først) afbryder øjeblikkeligt, FØR
    // noget som helst posteres til hovedbogen.
    DB::query($conn, "UPDATE invoices SET invoice_no = $next_invoice_no, inv_status = 'sent' WHERE inv_id = $inv_id AND inv_status = 'draft'");
    // Flere fakturalayouts: layoutet stemples på fakturaen ved bogføringen - den bevarer sit
    // udseende, også hvis kundens layout eller standarden senere skiftes (inc/invoice_layouts.inc.php)
    require_once __DIR__ . '/invoice_layouts.inc.php';
    if (il_ready($conn)) {
        $il_inv = DB::fetch_assoc(DB::query($conn, "SELECT inv_id, cust_id, inv_layout_id FROM invoices WHERE inv_id = $inv_id"));
        if ($il_inv && empty($il_inv['inv_layout_id'])) DB::query($conn, "UPDATE invoices SET inv_layout_id = " . il_for_invoice($conn, $il_inv) . " WHERE inv_id = $inv_id");
    }
    $recheck = DB::fetch_assoc(DB::query($conn, "SELECT inv_status, invoice_no FROM invoices WHERE inv_id = $inv_id"));
    if (!$recheck || strtolower($recheck['inv_status']) !== 'sent' || (int)$recheck['invoice_no'] !== $next_invoice_no) {
        throw new Exception('Fakturaen blev allerede bogført af en anden, samtidig forespørgsel (kapløb) - intet blev dublet.');
    }

    // 5. FINANSBOGFØRING (dobbelt bogholderi jf. bogføringsloven)
    // NB: voucher_no (bilagsnummer) er BEVIDST adskilt fra invoice_no (det
    // kundevendte fakturanummer) - de er to forskellige lovkrav om hulfri
    // nummerering, og voucher_no deles nu med kreditnotaer/udgifter/bank-
    // afstemning via next_voucher_no(), se inc/db_connect.inc.php.
    $voucher_no   = next_voucher_no($conn);
    $journal_text = DB::escape($conn, "Bogført salgsfaktura #" . $next_invoice_no);
    $jou_date     = DB::escape($conn, $inv_date);
    // RETTET (§currency-setting-is-cosmetic-label): journal.currency blev
    // aldrig sat (faldt altid tilbage til skemaets DEFAULT 'DKK'), uanset
    // firmaets faktisk konfigurerede bogføringsvaluta.
    $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
    // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]):
    // bruger-id for hvem der bogførte, direkte på journal-raden.
    $cb = journal_created_by_sql($conn);
    if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, voucher_no, currency{$cb['col']}) VALUES ('$jou_date', '$journal_text', $voucher_no, '$jou_currency'{$cb['val']})") === false) {
        throw new Exception('Kunne ikke oprette journalpost: ' . DB::error($conn));
    }
    $jou_id = DB::insert_id($conn);

    // Momskorrekt dobbeltpostering (balancerer til 0):
    //   DEBET  debitor        = total inkl. moms
    //   KREDIT omsætning      = netto (ekskl. moms)
    //   KREDIT udgående moms  = momsbeløb
    ledger_post($conn, $jou_id, $acc_debitor, $total_incl);
    foreach (invoice_sales_split($conn, $inv_id, $acc_sales, $total_excl) as $s_acc => $s_amt) ledger_post($conn, $jou_id, $s_acc, $s_amt * -1);
    if ($total_vat != 0) {
        ledger_post($conn, $jou_id, $acc_vat, $total_vat * -1);
    }

    // 6. LAGERREGULERING - trækkes fra HER (ved selve bogføringen), ikke ved
    // hvert kladde-gem i invoice_edit.php, som sletter+genindsætter linjerne
    // ved hvert gem og derfor ville trække forkert flere gange. Posteringen
    // her sker garanteret kun ÉN gang pr. faktura (status-tjekket i punkt 1
    // forhindrer genbogføring). Manglede FØR helt i den levende kode - kun
    // en forladt, ukoblet prototype (tools/faktura_gem.php) gjorde det
    // nogensinde. Fundet ved en lagerstyrings-gennemgang.
    $stock_lines = $track_stock ? DB::query($conn, "SELECT prod_id, quantity FROM invoice_lines WHERE inv_id = $inv_id AND prod_id > 0") : false;
    if ($stock_lines) {
        while ($sl = DB::fetch_assoc($stock_lines)) {
            $sp_id = (int)$sl['prod_id'];
            $sqty  = (float)$sl['quantity'];
            DB::query($conn, "UPDATE products SET prod_stock = prod_stock - $sqty WHERE prod_id = $sp_id");
        }
    }

    // Log handlingen i revisionssporet
    log_action($conn, 'POST_INVOICE', 'invoices', $inv_id, ['status' => 'draft'], ['status' => 'sent', 'invoice_no' => $next_invoice_no]);

    return (int)$next_invoice_no;
}

// Registrerer en betaling (eller med negativt beløb: en tilbagebetaling) på en bogført faktura:
// journal + hovedbog (DEBET $debit_acc - fx en mellemregningskonto for en betalingsudbyder - KREDIT debitor),
// invoice_payments, og status 'paid' når intet er åbent. Samme posteringer som bankafstemningen
// (reconcile_one_bank_line()), blot uden en banklinje.
function invoice_register_payment($conn, int $inv_id, string $date, float $amount, int $debit_acc, string $note = ''): void {
    global $global_settings;
    $amount = round($amount, 2);
    if (abs($amount) < 0.005) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Ugyldig betalingsdato.');
    if (is_date_locked($conn, $date)) throw new Exception('Betalingsdatoen er i en låst regnskabsperiode.');
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT inv_id, invoice_no, inv_status FROM invoices WHERE inv_id = $inv_id"));
    if (!$inv) throw new Exception('Fakturaen findes ikke.');
    if (strtolower((string)$inv['inv_status']) === 'draft') throw new Exception('Fakturaen er en kladde - bogfør den først.');
    $acc_debitor = (int)($global_settings['conf_acc_debitor'] ?? 8100);

    $voucher_no = next_voucher_no($conn);
    $text       = DB::escape($conn, ($amount > 0 ? 'Betaling' : 'Tilbagebetaling') . ' faktura #' . $inv['invoice_no'] . ($note !== '' ? ' - ' . $note : ''));
    $currency   = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
    $cb = journal_created_by_sql($conn);
    if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, voucher_no, currency{$cb['col']})
                          VALUES ('$date', '$text', $voucher_no, '$currency'{$cb['val']})") === false) {
        throw new Exception('Kunne ikke oprette journalpost: ' . DB::error($conn));
    }
    $jou_id = DB::insert_id($conn);
    ledger_post($conn, $jou_id, $debit_acc, $amount);
    ledger_post($conn, $jou_id, $acc_debitor, $amount * -1);
    $note_sql = DB::escape($conn, $note);
    if (DB::query($conn, "INSERT INTO invoice_payments (inv_id, payment_date, amount, note, created_by)
                          VALUES ($inv_id, '$date', $amount, '$note_sql', " . (int)($_SESSION['user_id'] ?? 0) . ")") === false) {
        throw new Exception('Kunne ikke registrere betalingen: ' . DB::error($conn));
    }
    $open = invoice_open_dkk($conn, $inv_id);
    $status = strtolower((string)$inv['inv_status']);
    if ($status === 'sent' && $open <= 0.01)      DB::query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $inv_id AND inv_status = 'sent'");
    elseif ($status === 'paid' && $open > 0.01)   DB::query($conn, "UPDATE invoices SET inv_status = 'sent' WHERE inv_id = $inv_id AND inv_status = 'paid'");
    log_action($conn, 'REGISTER_PAYMENT', 'invoices', $inv_id, ['inv_status' => $inv['inv_status']],
        ['amount' => $amount, 'date' => $date, 'debit_acc' => $debit_acc, 'voucher_no' => $voucher_no, 'open_after' => $open, 'note' => $note]);
}

// Opretter og bogfører en kreditnota til en bogført faktura. $qtys: [line_id => mængde der krediteres]
// (mangler en linje: hele dens mængde; 0: linjen krediteres ikke). Returnerer kreditnotaens inv_id.
// $track_stock: lægges varerne tilbage på lager? null = som da fakturaen blev bogført (se nedenfor).
function invoice_create_credit($conn, int $credit_ref, array $qtys, string $credit_date_raw, string $credit_note_raw, ?bool $track_stock = null): int {
    global $global_settings;
    $orig = DB::fetch_assoc(DB::query($conn, "SELECT i.*, c.cust_name FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $credit_ref"));
    if (!$orig) throw new Exception('Fakturaen findes ikke.');
    if (strtolower((string)$orig['inv_status']) === 'draft') throw new Exception('En kladde kan ikke krediteres.');
    if (!empty($orig['credit_ref'])) throw new Exception('En kreditnota kan ikke selv krediteres.');
    if (DB::fetch_assoc(DB::query($conn, "SELECT inv_id FROM invoices WHERE credit_ref = $credit_ref LIMIT 1"))) throw new Exception('Fakturaen er allerede krediteret.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $credit_date_raw)) throw new Exception('Ugyldig kreditnotadato.');
    if (is_date_locked($conn, $credit_date_raw)) throw new Exception('Kreditnotadatoen er i en låst regnskabsperiode.');
    // RETTET: kreditnotaen kunne dateres FØR fakturaen den krediterer (eller ud i fremtiden) - den kom så i en
    // tidligere momsperiode end selve salget, og debitorens kontoudtog viste krediteringen før fakturaen
    if ($credit_date_raw < substr((string)$orig['inv_date'], 0, 10) || $credit_date_raw > date('Y-m-d')) {
        throw new Exception(lang('@The credit note date must be between the invoice date and today.'));
    }
    $credit_date = DB::escape($conn, $credit_date_raw);
    $credit_note = DB::escape($conn, $credit_note_raw);
    $orig_lines  = [];
    $lines_res   = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $credit_ref ORDER BY line_id");
    while ($l = DB::fetch_assoc($lines_res)) { $orig_lines[] = $l; }

    // 1. Opret kreditnota-faktura med negative beløb
    // RETTET (se [[bugs-batch-10-review]]): orig_currency/exch_rate
    // blev ikke kopieret fra originalen - currency blev sat korrekt,
    // men en visning der (som invoice_view.php) læser orig_currency/
    // exch_rate specifikt for at vise fremmed-valuta-kurs-info ville
    // se ufuldstændige data på selve kreditnota-fakturaen.
    $orig_currency_sql = !empty($orig['orig_currency']) ? "'" . DB::escape($conn, $orig['orig_currency']) . "'" : 'NULL';
    $exch_rate_sql     = !empty($orig['exch_rate']) ? (float)$orig['exch_rate'] : 'NULL';
    DB::query($conn, "INSERT INTO invoices
        (cust_id, inv_date, inv_due_date, inv_status, inv_note,
         currency, credit_ref, proj_id, orig_currency, exch_rate)
        VALUES ({$orig['cust_id']}, '$credit_date', '$credit_date', 'credit',
        '{$credit_note}', '{$orig['currency']}', $credit_ref,
        " . ($orig['proj_id'] ? $orig['proj_id'] : 'NULL') . ", $orig_currency_sql, $exch_rate_sql)");
    $new_inv_id = DB::insert_id($conn);
    // Flere fakturalayouts: kreditnotaen får samme layout som fakturaen den krediterer
    require_once __DIR__ . '/invoice_layouts.inc.php';
    if (il_ready($conn)) DB::query($conn, "UPDATE invoices SET inv_layout_id = " . il_for_invoice($conn, $orig) . " WHERE inv_id = $new_inv_id");

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
    // 33): kreditnotaen fik hidtil ALDRIG et eget kundevendt
    // fakturanummer (kun voucher_no i journalen) - se
    // next_credit_note_no()'s egen kommentar (inc/db_connect.inc.php).
    // Stille degradering hvis migrationen ikke er kørt endnu (samme
    // mønster som andre nyere kolonner i denne kodebase).
    $cn_no = next_credit_note_no($conn);
    if ($cn_no !== null) {
        DB::query($conn, "UPDATE invoices SET credit_note_no = $cn_no WHERE inv_id = $new_inv_id");
    }

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): en
    // linje med rabat skal krediteres med SAMME rabatprocent - ellers
    // ville kreditnotaen (negativ mængde, men ingen rabat) IKKE
    // nulstille originalen præcist, kun tilnærmelsesvist (for meget
    // krediteret, svarende til selve rabatbeløbet).
    $has_discount_col_credit = false;
    if (DB::is_sqlite()) {
        $dcc_credit = DB::query($conn, "PRAGMA table_info(invoice_lines)");
        if ($dcc_credit) { while ($dccr_credit = DB::fetch_assoc($dcc_credit)) { if ($dccr_credit['name'] === 'discount_pct') { $has_discount_col_credit = true; break; } } }
    } else {
        $dcc_credit = DB::query($conn, "SHOW COLUMNS FROM invoice_lines LIKE 'discount_pct'");
        $has_discount_col_credit = ($dcc_credit && DB::num_rows($dcc_credit) > 0);
    }

    // 2. Kopiér linjer med negativt antal (nulstiller originalen HELT,
    // eller kun DELVIST - se dens egen kommentar nedenfor for
    // baggrund). En linje med credit_qty=0 (fravalgt af brugeren)
    // springes helt over.
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
    // 32): tidligere blev ALTID hele fakturaens oprindelige mængde
    // krediteret - ingen måde at kreditere fx kun ét returneret
    // produkt ud af tre. Brugeren kan nu vælge/justere mængden der
    // skal krediteres PR. LINJE (som standard forudfyldt til hele
    // den oprindelige mængde, uændret opførsel hvis intet ændres).
    // BEVIDST STADIG kun ÉN kreditnota pr. faktura (samme
    // "allerede krediteret"-spærring som før, se linje 150 nedenfor)
    // - dette giver fleksibilitet i INDHOLDET af den ene tilladte
    // kreditnota, ikke en løbende saldo af flere delvise krediteringer
    // over tid (ville kræve en større omlægning af selve
    // status-modellen, uden for denne rundes scope).
    // RETTET: kreditnotaen lagde ALTID varerne tilbage på lager - også når fakturaen aldrig trak dem fra
    // (en webbutik med "lagerstyring" fravalgt: invoice_post($track_stock = false)). Lageret steg så for
    // hver returnering. Hører fakturaen til en webordre, gælder butikkens valg; ellers trækker en
    // bogføring altid lageret, og kreditnotaen lægger det tilbage som før.
    if ($track_stock === null) {
        $track_stock = true;
        require_once __DIR__ . '/shop.inc.php';
        if (shop_ready($conn)) {
            $so = DB::fetch_assoc(DB::query($conn, "SELECT s.track_stock FROM shop_orders o JOIN shop_connections s ON s.shop_id = o.shop_id
                                                   WHERE o.inv_id = $credit_ref AND o.kind = 'order' LIMIT 1"));
            if ($so) $track_stock = !empty($so['track_stock']);
        }
    }
    $credit_qtys = $qtys;
    $credited_lines = 0;
    $is_partial = false;      // En linje fravalgt eller kun delvist krediteret: fakturaen er IKKE krediteret fuldt ud
    foreach ($orig_lines as $l) {
        $line_id = (int)$l['line_id'];
        $credit_qty_raw = isset($credit_qtys[$line_id]) ? ((is_int($credit_qtys[$line_id]) || is_float($credit_qtys[$line_id])) ? (float)$credit_qtys[$line_id] : parse_dk_number($credit_qtys[$line_id])) : (float)$l['quantity'];
        // Kan aldrig kreditere MERE end den oprindelige linjes mængde,
        // og aldrig et negativt tal (uanset hvad der blev POST'et).
        $credit_qty = max(0, min(abs($credit_qty_raw), abs((float)$l['quantity'])));
        if ($credit_qty < abs((float)$l['quantity']) - 0.000001) $is_partial = true;
        if ($credit_qty <= 0) continue; // fravalgt linje - springes helt over
        $credited_lines++;

        $neg_qty  = $credit_qty * ((float)$l['quantity'] < 0 ? 1 : -1);
        $txt      = DB::escape($conn, $l['line_text']);
        $prc      = (float)$l['price_each'];
        $vat      = (float)$l['line_vat_rate'];
        $pid      = (int)$l['prod_id'];
        $proj_sql = $l['proj_id'] ? (int)$l['proj_id'] : 'NULL';
        $disc_sql = $has_discount_col_credit ? (float)($l['discount_pct'] ?? 0) : 0;
        DB::query($conn, "INSERT INTO invoice_lines
            (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id"
            . ($has_discount_col_credit ? ", discount_pct" : "") . ")
            VALUES ($new_inv_id, '$txt', $neg_qty, $prc, $vat, $pid, $proj_sql"
            . ($has_discount_col_credit ? ", $disc_sql" : "") . ")");

        // LAGERREGULERING: læg KUN den faktisk krediterede mængde
        // tilbage på lager - modposten til trækket der sker i
        // invoice_post_action.php ved selve bogføringen.
        if ($pid > 0 && $track_stock) {
            DB::query($conn, "UPDATE products SET prod_stock = prod_stock + $credit_qty WHERE prod_id = $pid");
        }
    }

    // RETTET: alle mængder sat til 0 gav en TOM kreditnota (bilagsnummer, kreditnotanummer) - og
    // fakturaen blev alligevel markeret "krediteret": det skyldige beløb forsvandt fra debitorlisten,
    // rykkerne og bankafstemningen. Intet at kreditere = intet gøres (alt rulles tilbage).
    if ($credited_lines === 0) {
        throw new Exception(lang('@Nothing to credit - set the quantity to credit on at least one line.'));
    }

    // 3. Marker original faktura som krediteret
    // RETTET (§bugs-batch-19-review): "allerede krediteret"-tjekket
    // ovenfor (linje 48) køres kun ÉN gang tidligt i denne
    // sidevisning - to næsten-samtidige kreditnota-forsøg for samme
    // faktura (fx to faner, eller et dobbeltklik der sender formularen
    // to gange) kunne begge bestå det tjek FØR nogen af dem nåede at
    // skrive, og dermed begge oprette en fuld kreditnota (dobbelt
    // lagerregulering, dobbelt modpostering). WHERE-klausulen her
    // tjekker nu atomisk, at originalen IKKE allerede blev krediteret
    // af en anden, hurtigere transaktion, og et 0-rækker-resultat
    // ruller hele denne kreditnota (allerede indsatte linjer/lager-
    // regulering) tilbage i stedet for at lade den stå.
    // OPFØLGNING (samme runde): den første udgave af denne rettelse
    // tjekkede bagefter kun OM status var 'credited' - men det viser
    // blot om SLUTTILSTANDEN er korrekt, ikke om DENNE forespørgsel
    // selv satte den. Taberen af kapløbet ville se vinderens
    // allerede-committede 'credited'-status og fejlagtigt tro sit
    // eget forsøg lykkedes - og fortsætte med at committe sin egen
    // duplikerede kreditnota alligevel. Rettet til at måle antal
    // reelt berørte rækker fra selve UPDATE'en (DB::affected_rows()),
    // som kun er >0 for den forespørgsel der faktisk vandt kapløbet.
    // RETTET: kun en FULD kreditering markerer fakturaen 'credited'. En delvis beholder sin status - det
    // kunden stadig skylder, regnes som faktura + kreditnota - betalinger (invoice_open_dkk()).
    // Kapløb: højst én kreditnota pr. faktura - tælles efter indsættelsen (MySQL: FOR UPDATE ser en
    // samtidig, allerede committet kreditnota; SQLite serialiserer skrivningerne)
    $cn_count = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoices WHERE credit_ref = $credit_ref" . (DB::is_sqlite() ? '' : ' FOR UPDATE')));
    if (!$cn_count || (int)$cn_count[0] > 1) {
        throw new Exception('Fakturaen blev allerede krediteret af en anden, samtidig forespørgsel (kapløb) - intet blev dublet.');
    }
    if (!$is_partial) {
        $upd_result = DB::query($conn, "UPDATE invoices SET inv_status = 'credited' WHERE inv_id = $credit_ref AND inv_status != 'credited'");
        if (!$upd_result || DB::affected_rows($conn, $upd_result) < 1) {
            throw new Exception('Fakturaen blev allerede krediteret af en anden, samtidig forespørgsel (kapløb) - intet blev dublet.');
        }
    }

    // 4. Journalpostering — modpostering. Får nu et bilagsnummer fra den
    // fælles tæller (voucher_no manglede helt her før - kreditnotaer var
    // usporbare i den samlede bilagsrække).
    $cn_voucher_no = next_voucher_no($conn);
    // RETTET: fakturaens interne id (#1000036) i stedet for dens fakturanummer (#1010) - se inc/reconcile.inc.php
    $jou_text = DB::escape($conn, lang('@Credit note for invoice') . ' #' . ($orig['invoice_no'] ?: $credit_ref) . ': ' . $orig['cust_name']);
    // RETTET (§currency-setting-is-cosmetic-label): journal.currency
    // blev aldrig sat her (faldt altid tilbage til skemaets DEFAULT
    // 'DKK'), uanset firmaets faktisk konfigurerede bogføringsvaluta.
    $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
    // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
    $cb = journal_created_by_sql($conn);
    DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
        VALUES ('$credit_date', '$jou_text', 'credit_note', $cn_voucher_no, '$jou_currency'{$cb['val']})");
    $jou_id = DB::insert_id($conn);

    // 5. Ledger-modposteringer (nulstiller debitor og omsætning)
    // Debit: omsætningskonto (negativ = kreditering af omsætning)
    // Credit: debitor 1000 (negativ = reducer tilgodehavende)
    //
    // RETTET (§find-fem-fejl runde 40): genberegnede FØR selv disse
    // totaler ud fra $orig_lines med sin egen kopi af netto/moms/kurs-
    // logikken - PRÆCIS den samme beregning som invoice_dkk_totals()
    // (inc/db_connect.inc.php) allerede laver, og som blev udtrukket
    // netop for at invoice_post_action.php og reconcile_action.php
    // "ALDRIG kan glide fra hinanden igen" (se dens egen kommentar).
    // Denne fil var en tredje, ukoblet kopi af nøjagtig samme
    // beregning - ikke (endnu) divergeret, men enhver fremtidig
    // rettelse af invoice_dkk_totals() ville stille glide fra denne
    // fils egen kopi. Bruger nu den fælles funktion i stedet.
    //
    // RETTET (runde 32, delvis kreditnota): skal beregnes på selve
    // den NYE kreditnota-fakturas EGNE linjer ($new_inv_id, lige
    // indsat ovenfor) - IKKE på $credit_ref (originalen), som ved en
    // delvis kreditering ikke længere svarer til hvad der reelt
    // krediteres. Ved en FULD kreditering (uændret standardopførsel)
    // er de to identiske, så dette ændrer intet i det almindelige
    // tilfælde.
    $totals         = invoice_dkk_totals($conn, $new_inv_id);
    $total_excl_vat = $totals['excl'];
    $total_vat      = $totals['vat'];
    $total_incl_vat = $totals['incl'];

    // Konti — samme konfigurerbare konti som posteringsflowet (defaults til
    // standard-kontoplanen). Moms bogføres korrekt på 6900 (Moms, salg),
    // IKKE 2500 (Markedsføring), og debitor på 8100 - ikke 1000/1020.
    $acc_debitor = (int)($global_settings['conf_acc_debitor'] ?? 8100);
    $acc_sales   = (int)($global_settings['conf_acc_sales']   ?? 1000);
    $acc_vat     = (int)($global_settings['conf_acc_vat']     ?? 6900);

    // Kreditnota vender salget (modsatte fortegn af en normal postering):
    //   KREDIT debitor       = − total inkl. moms (reducerer tilgodehavende)
    //   DEBET  omsætning     = + netto            (reducerer omsætning)
    //   DEBET  udgående moms = + momsbeløb        (reducerer moms)
    // RETTET (KRITISK): totalerne er fra kreditnotaens EGNE linjer (runde 32), og de har negative mængder -
    // beløbene er altså allerede negative. "* -1" vendte dem en gang til: siden da bogførte en kreditnota
    // som en faktura (DEBET debitor, KREDIT omsætning/moms) - tilgodehavende, omsætning og moms steg i
    // stedet for at falde. Samme formel som fakturaens egen bogføring (invoice_post_action.php) giver
    // nu det rigtige fortegn ud fra dokumentets egne (negative) beløb.
    ledger_post($conn, $jou_id, $acc_debitor, $total_incl_vat);
    foreach (invoice_sales_split($conn, $new_inv_id, $acc_sales, $total_excl_vat) as $s_acc => $s_amt) ledger_post($conn, $jou_id, $s_acc, $s_amt * -1);      // (samme konti som fakturaen)
    if ($total_vat != 0) {
        ledger_post($conn, $jou_id, $acc_vat, $total_vat * -1);
    }

    // 6. Audit-log
    log_action($conn, 'CREATE_CREDIT_NOTE', 'invoices', $new_inv_id,
        ['credit_ref' => $credit_ref, 'orig_status' => $orig['inv_status']],
        ['inv_status' => 'credit', 'credit_date' => $credit_date]);
    if (!$is_partial) {
        log_action($conn, 'MARK_CREDITED', 'invoices', $credit_ref,
            ['inv_status' => $orig['inv_status']],
            ['inv_status' => 'credited', 'credit_inv_id' => $new_inv_id]);
    } else {
        // Delvis: fakturaen beholder sin status - er resten allerede betalt, er den nu betalt
        $open_after = invoice_open_dkk($conn, $credit_ref);
        if (strtolower($orig['inv_status']) === 'sent' && $open_after <= 0.01) {
            DB::query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $credit_ref AND inv_status = 'sent'");
        }
        log_action($conn, 'PARTIAL_CREDIT', 'invoices', $credit_ref,
            ['inv_status' => $orig['inv_status']],
            ['inv_status' => ($open_after <= 0.01 && strtolower($orig['inv_status']) === 'sent') ? 'paid' : $orig['inv_status'], 'credit_inv_id' => $new_inv_id, 'open_after' => $open_after]);
    }

    return (int)$new_inv_id;
}
