<?php # /inc/reconcile.inc.php v:1.0.0 d:2026-09-15 i:evs
# NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): reconcile_one_
# bank_line() udtrukket fra reconcile_action.php til sin egen fil.
# reconcile_action.php havde selv UBETINGET kode i filens bund (uden for
# funktionen) der læser $_POST direkte og enten redirecter eller die()'r -
# et require_once af selve reconcile_action.php ville derfor øjeblikkeligt
# udføre den kode også, og for ethvert andet request end en rigtig
# reconcile_action.php-POST ville det ramme "ENKELT-TILSTAND"-grenen med
# $tmp_id=0 og die() med "@No transaction selected." - en skjult, farlig
# faldgrube for et fremtidigt sted (denne runde: invoice_mark_paid.php) der
# har brug for selve funktionen uden resten af filen. Denne fil indeholder
# KUN funktionen - reconcile_action.php kræver den nu i stedet for at
# definere den selv, ingen ekstern adfærdsændring for reconcile_list.php.
function reconcile_one_bank_line($conn, $global_settings, int $tmp_id, int $target_id, int $acc_id, float $fee_amount, int $fee_acc_id, int $proj_id, bool $close_fx_invoice): array {
    if ($tmp_id === 0) return ['ok' => false, 'message' => lang('@No transaction selected.')];

    $res  = DB::query($conn, "SELECT * FROM bank_statement_temp WHERE tmp_id = $tmp_id");
    $bank = DB::fetch_assoc($res);
    if (!$bank) return ['ok' => false, 'message' => lang('@Bank entry not found.')];

    // RETTET (§bugs-batch-19-review): denne linje var ALDRIG tjekket nogen
    // steder i filen - reconcile_list.php's egen liste filtrerer ganske vist
    // is_processed=1 fra (linje 158), men selve action-endpointet her stolede
    // blindt på, at brugerfladen aldrig ville sende samme tmp_id to gange. Et
    // dobbeltklik på "Bogfør" (eller et genindsendt formular-POST, fx via
    // browserens tilbage-knap) kunne derfor bogføre den SAMME banktransaktion
    // to gange - dobbelt journalpost, dobbelt fakturabetaling, dobbelt
    // auto-oprettet udgift. Tjekket her er et tidligt, billigt afslag før
    // transaktionen overhovedet startes; den atomiske genkontrol nedenfor (ved
    // selve UPDATE'en) er den der reelt lukker kapløbsvinduet.
    if ((int)$bank['is_processed'] === 1) {
        return ['ok' => false, 'already_processed' => true];
    }

    $bank_date   = $bank['trans_date'];
    $bank_amount = (float)$bank['amount'];
    $bank_text   = DB::real_escape_string($conn, $bank['text_val']);

    // Projekt-felt til SQL — NULL hvis intet valgt
    $proj_sql = ($proj_id > 0) ? $proj_id : 'NULL';

    // Tjek om projekt-modulet er aktivt (for at afgøre om expense-auto-oprettelse er relevant)
    $s = get_settings($conn);
    $projects_active = !empty($s['module_projects']) && $s['module_projects'] == '1';

    // Bankkontoen - konfigurerbar (company_settings.php), IKKE hardkodet 1000.
    // 1000 er "Salg af eventyrvarer" i standard-kontoplanen, en SALGSKONTO -
    // tidligere bogførte hver bankafstemning derfor bankbeløbet som salg i
    // stedet for på bankkontoen. Rettet 2026-08-15, se posting-accounts.
    $acc_bank = (int)($s['conf_acc_bank'] ?? 5000);

    // Debitorkontoen - bruges nu (2026-08-20) til at nedbringe tilgodehavendet,
    // når en faktura betales. Se ALVORLIGT FUND lige nedenfor.
    $acc_debitor = (int)($s['conf_acc_debitor'] ?? 8100);

    // Periodespærring: en NY postering må ikke kunne bogføres ind i en allerede
    // låst periode - manglede her (fundet ved en systematisk gennemgang,
    // §bogforingslov-compliance). $bank_date kommer fra den importerede
    // banklinje, så et gammelt/glemt bankudtog kan ikke bogføres bagom en lukket
    // periode.
    if (is_date_locked($conn, $bank_date)) {
        return ['ok' => false, 'message' => lang('@This transaction date is in a locked accounting period and cannot be posted.')];
    }
    // RETTET: gebyrkontoen er fast foreslået som 2320 - findes den ikke (fx i Erhvervsstyrelsens standardkontoplan,
    // hvor bankgebyrer er 2390), viste valglisten "Vælg konto" (værdi tom = 0), og gebyret blev bogført på konto 0,
    // en konto der ikke findes.
    if ($fee_amount > 0 && ($fee_acc_id <= 0 || !DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = " . (int)$fee_acc_id)))) {
        return ['ok' => false, 'message' => lang('@Please choose an existing account for the fee.')];
    }

    // KRITISK: en kladde-faktura (aldrig bogført) kunne før markeres 'paid'
    // direkte her, helt uden om invoice_post_action.php - fakturaen ville aldrig
    // få et fakturanummer, ingen posteringer, intet lagertræk, men fremstå som
    // betalt. reconcile_list.php's dropdown filtrerede kun 'paid' fra, ikke
    // 'draft', så dette var nåbart via den almindelige brugerflade. Fundet ved
    // en faktura-/fakturaflow-gennemgang.
    $target_status = null;
    if ($target_id > 0) {
        $target_status = DB::fetch_assoc(DB::query($conn, "SELECT inv_status, invoice_no FROM invoices WHERE inv_id = $target_id"));
        if (!$target_status) {
            return ['ok' => false, 'message' => lang('@Invoice not found.')];
        }
        if (strtolower($target_status['inv_status']) === 'draft') {
            return ['ok' => false, 'message' => lang('@This invoice is still a draft and must be posted before it can be marked as paid.')];
        }
        // RETTET: retningen blev ikke kontrolleret - en UDbetaling (fx til en leverandør) matchet med en kundefaktura
        // blev registreret som en negativ "betaling": fakturaens betalte beløb faldt, og debitorkontoen steg. Og en
        // INDbetaling matchet med en kreditnota (negativ) markerede kreditnotaen "betalt". En faktura betales med en
        // indbetaling, en kreditnota (tilbagebetaling til kunden) med en udbetaling.
        $is_cn = DB::fetch_assoc(DB::query($conn, "SELECT credit_ref FROM invoices WHERE inv_id = $target_id"));
        $is_cn = !empty($is_cn['credit_ref']);
        if ($bank_amount == 0 || ($is_cn ? $bank_amount > 0 : $bank_amount < 0)) {
            return ['ok' => false, 'message' => $is_cn
                ? lang('@A credit note can only be matched with an outgoing payment (a refund to the customer).')
                : lang('@An invoice can only be matched with an incoming payment - this bank transaction is an outgoing payment.')];
        }
    }

    DB::begin_transaction($conn);
    try {

        // --- 1. OPRET JOURNAL POST ---
        // Bilagsnummer fra den fælles tæller (manglede helt før - bankafstemninger
        // var usporbare i den samlede bilagsrække).
        $voucher_no   = next_voucher_no($conn);
        // RETTET: teksten viste fakturaens interne id (fx #1000036), ikke dens fakturanummer (#1010) - posteringen
        // kunne ikke findes ud fra fakturanummeret i hovedbogen, kontokortet eller SAF-T-eksporten
        $journal_text = ($target_id > 0)
            ? lang('@Payment inv. #') . ($target_status['invoice_no'] ?: $target_id)
            : lang('@Bank entry:') . " " . $bank_text;

        // proj_id gemmes på journal-rækken (altid, uanset scenarie)
        // RETTET (§currency-setting-is-cosmetic-label): journal.currency blev
        // aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK') - bankposteringer
        // er altid i firmaets faktisk konfigurerede bogføringsvaluta.
        $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
        // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
        $cb = journal_created_by_sql($conn);
        DB::query($conn, "INSERT INTO journal (jou_date, jou_text, proj_id, voucher_no, currency{$cb['col']})
                          VALUES ('$bank_date', '$journal_text', $proj_sql, $voucher_no, '$jou_currency'{$cb['val']})");
        $jou_id = DB::insert_id($conn);

        // --- 2. BOGFØR SELVE TRANSAKTIONEN ---
        if ($target_id > 0) {
            // Scenarie A: Faktura (helt eller delvist) betalt — proj_id ikke
            // relevant her (fakturaen bærer allerede projektinfo via invoice_lines)
            //
            // ALVORLIGT FUND 2026-08-20: denne postering DEBITEREDE FØR kun
            // bankkontoen (+bank_amount) - der var INGEN modsvarende KREDIT af
            // debitorkontoen. Journalposten balancerede derfor ALDRIG til 0, og
            // debitorsaldoen (8100) blev aldrig nedbragt af en eneste betaling
            // registreret via bankafstemningen, uanset hvor mange kunder der
            // reelt havde betalt - kun invoice_post_action.php's oprindelige
            // DEBET af debitor blev nogensinde bogført. Bekræftet direkte: en
            // fuld betaling af en 1.000 kr-faktura efterlod journalposten med
            // kun én linje (SUM = 1.000, skal være 0). Fundet under arbejdet med
            // delvis betaling (samme rettelse er en forudsætning for at kunne
            // nedbringe debitorsaldoen korrekt ved en DELVIS betaling også).
            //
            // NYT samme dag: delvis betaling. En indbetaling logges nu altid i
            // invoice_payments (uanset om den dækker det fulde beløb eller ej).
            // Fakturaen markeres kun 'paid' i invoices.inv_status, når summen af
            // ALLE dens indbetalinger dækker det fulde, momsbeviste beløb -
            // ellers forbliver den 'sent', men med et delvist betalt beløb
            // synligt (sales_hub.php, invoice_view.php, reconcile_list.php).
            DB::query($conn, "INSERT INTO invoice_payments (inv_id, payment_date, amount, created_by)
                              VALUES ($target_id, '$bank_date', $bank_amount, " . (int)($_SESSION['user_id'] ?? 0) . ")");

            // RETTET (§reel-multi-valuta-bogforing): "total" blev FØR beregnet
            // direkte fra invoice_lines (fakturaens EGEN valuta, fx EUR) og
            // sammenlignet UMIDDELBART mod $total_paid (altid i DKK, fra selve
            // bankafstemningen) - to forskellige valutaenheder behandlet som
            // samme tal for enhver udenlandsk faktura. Bruger nu den fælles
            // invoice_dkk_totals() (inc/db_connect.inc.php), som ALTID regner i
            // DKK (nøjagtig samme beregning som invoice_post_action.php selv
            // bogførte til), så sammenligningen nedenfor giver mening for både
            // DKK- og udenlandske fakturaer.
            $inv_totals = invoice_dkk_totals($conn, $target_id);
            $inv_total    = round($inv_totals['incl'] + invoice_credited_dkk($conn, $target_id), 2);    // (minus en delvis kreditnota)
            $is_fx_invoice = ($inv_totals['exch_rate'] > 0);

            $paid_row = DB::fetch_assoc(DB::query($conn,
                "SELECT COALESCE(SUM(amount), 0) AS paid FROM invoice_payments WHERE inv_id = $target_id"));
            $total_paid = (float)($paid_row['paid'] ?? 0);

            // NYT: en udenlandsk faktura rammer stort set ALDRIG sin bogførte
            // DKK-total præcist ved betaling (kursen har typisk flyttet sig
            // siden faktureringen) - uden en eksplicit bekræftelse ville den
            // derfor aldrig kunne nå 'paid' via det almindelige beløbs-match
            // nedenfor. $close_fx_invoice (kun tilbudt i UI'en for reelt
            // udenlandske fakturaer, se reconcile_list.php) lukker fakturaen
            // uanset beløbsforskel og bogfører differencen som en rigtig
            // kursgevinst/-tab i stedet for at lade en uforklaret rest stå.
            // Sikkerhedsgrænse: nægter at tolke en forskel på over halvdelen af
            // fakturaen som en "kursregulering" - det er langt uden for enhver
            // realistisk kursudsving og tyder i stedet på en forkert matchet
            // banktransaktion eller en decideret tastefejl.
            $fx_residual = 0.0;
            if ($close_fx_invoice && $is_fx_invoice) {
                $fx_residual = round($inv_total - $total_paid, 2);
                if ($inv_total > 0 && abs($fx_residual) > $inv_total * 0.5) {
                    throw new Exception(lang('@The difference is too large to be interpreted as a currency exchange adjustment. Please check that the correct invoice and bank transaction are matched.'));
                }
            }
            $fully_paid = ($total_paid >= $inv_total - 0.01) || ($close_fx_invoice && $is_fx_invoice);
            $new_status = $fully_paid ? 'paid' : $target_status['inv_status'];

            if ($fully_paid) {
                DB::query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $target_id");
            }
            log_action($conn, 'RECORD_INVOICE_PAYMENT', 'invoices', $target_id,
                ['status' => $target_status['inv_status'], 'total_paid_before' => round($total_paid - $bank_amount, 2)],
                ['status' => $new_status, 'amount' => $bank_amount, 'total_paid' => round($total_paid, 2), 'invoice_total' => $inv_total, 'fx_residual' => $fx_residual]);

            ledger_post($conn, $jou_id, $acc_bank, $bank_amount);
            ledger_post($conn, $jou_id, $acc_debitor, $bank_amount * -1);

            // NYT: selve kursgevinst/-tab-posteringen. Debitorkontoens samlede
            // bevægelse for denne faktura er nu ($inv_total - $total_paid) =
            // $fx_residual - nulstiller den PRÆCIST med én sidste postering, og
            // lægger den modsatte side på kursgevinst/-tab-kontoen. Positiv
            // $fx_residual (mindre modtaget end bogført) = tab (debiteres,
            // reducerer nettoindtjeningen); negativ = gevinst (krediteres,
            // øger den). De to linjer summerer altid til 0 uanset fortegn.
            if (abs($fx_residual) > 0.01) {
                $acc_fx = (int)($s['conf_acc_fx'] ?? 7200);
                ledger_post($conn, $jou_id, $acc_debitor, $fx_residual * -1);
                ledger_post($conn, $jou_id, $acc_fx, $fx_residual);
            }

            if ($fee_amount > 0) {
                ledger_post($conn, $jou_id, $fee_acc_id, $fee_amount);
                ledger_post($conn, $jou_id, $acc_bank, $fee_amount * -1);
            }

        } elseif ($acc_id > 0) {
            // Scenarie B: Direkte bogføring (udgift/intern postering)
            // RETTET (KRITISK): fortegnene var vendt - en udbetaling på 1.250 kr. (bank_amount = -1250) KREDITEREDE
            // udgiftskontoen (-1250) og DEBITEREDE banken (+1250): udgiften faldt, og banksaldoen STEG. Samme
            // omvendte retning for en indbetaling til en indtægtskonto. Banken følger nu bankens eget fortegn,
            // modkontoen det modsatte. Og momsen manglede helt: på en resultatkonto med en momssats udskilles
            // momsen nu (købsmoms på en udgift, salgsmoms på en indtægt) - samme regel som en udgift i
            // expense_edit.php (expense_get_lines()).
            $b_acc   = DB::fetch_assoc(DB::query($conn, "SELECT acc_type, vat_rate FROM accounts WHERE acc_id = $acc_id"));
            $b_pl    = $b_acc && in_array($b_acc['acc_type'], ['income', 'expense'], true);
            $b_rate  = $b_pl ? (float)($b_acc['vat_rate'] ?? 0) : 0.0;
            $b_gross = round(-$bank_amount, 2);                                   // modkontoens side (positiv = debet)
            $b_vat   = $b_rate > 0 ? round($b_gross * $b_rate / (100 + $b_rate), 2) : 0.0;
            ledger_post($conn, $jou_id, $acc_id, round($b_gross - $b_vat, 2));
            if (abs($b_vat) >= 0.005) {
                $b_vat_acc = $bank_amount < 0 ? (int)($s['conf_acc_purchase_vat'] ?? 6910) : (int)($s['conf_acc_vat'] ?? 6900);
                ledger_post($conn, $jou_id, $b_vat_acc, $b_vat);
            }
            ledger_post($conn, $jou_id, $acc_bank, $bank_amount);

            // Auto-opret expense-række så udgiften er synlig i udgiftslisten
            // og korrekt knyttet til projektet hvis modulet er aktivt
            $supplier_esc = $bank_text;
            $created_by   = $_SESSION['user_id'] ?? 0;
            $exp_proj_sql = ($projects_active && $proj_id > 0) ? $proj_id : 'NULL';

            // RETTET (§bugs-batch-25-review): denne udgift oprettes direkte fra en
            // REEL, allerede gennemført banktransaktion (pengene har allerede
            // forladt kontoen - det er selve grunden til at den findes i
            // bank_statement_temp) - den er per definition betalt, men
            // paid_date/due_date (leverandørmodulet, se db-setup/migrate_
            // suppliers.php) blev aldrig sat her. Uden paid_date viste expense_
            // list.php hverken "Betalt" eller en forfaldsdato for denne postering
            // (bare en tom streg) - misvisende for noget der reelt aldrig har
            // været i restance. Sætter nu paid_date = selve banktransaktionens
            // dato, kun hvis kolonnen findes (samme "stille degradering hvis ikke
            // migreret endnu"-mønster som expense_edit.php/expense_list.php).
            $has_paid_date_col = false;
            if (DB::is_sqlite()) {
                $pd_check = DB::query($conn, "PRAGMA table_info(expenses)");
                if ($pd_check) { while ($pdr = DB::fetch_assoc($pd_check)) { if ($pdr['name'] === 'paid_date') { $has_paid_date_col = true; break; } } }
            } else {
                $pd_check = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE 'paid_date'");
                $has_paid_date_col = ($pd_check && DB::num_rows($pd_check) > 0);
            }
            $paid_date_col_sql = $has_paid_date_col ? ", paid_date" : "";
            $paid_date_val_sql = $has_paid_date_col ? ", '$bank_date'" : "";

            // RETTET: rækken fik intet bilagsnummer (og det negative bankbeløb) - den lignede en UBOGFØRT kladde
            // med et minusbeløb, og blev den åbnet og gemt, blev den bogført en gang til. Den får nu journalens
            // bilagsnummer (= bogført, kan annulleres som andre udgifter), beløbet som et positivt brutto, typen
            // efter retningen og den momssats der blev brugt. Kun for resultatkonti - en overførsel til en
            // balancekonto (opsparing, lån, privat) er ingen udgift.
            if ($b_pl) {
                $b_type = $bank_amount < 0 ? 'expense' : 'income';
                DB::query($conn, "INSERT INTO expenses
                                    (exp_date, supplier, account_id, amount, description, voucher_no, exp_type, vat_rate,
                                     proj_id,     created_by, created_at$paid_date_col_sql)
                                  VALUES
                                    ('$bank_date', '$supplier_esc', $acc_id, " . abs($b_gross) . ", '$bank_text', $voucher_no, '$b_type', $b_rate,
                                     $exp_proj_sql, $created_by, CURRENT_TIMESTAMP$paid_date_val_sql)");
            }
        }

        // --- 3. MARKER SOM BEHANDLET ---
        // RETTET (§bugs-batch-19-review): tjekket for "allerede behandlet" foran
        // filen (linje ~30) kører kun ÉN gang, før transaktionen starter - to
        // næsten-samtidige bogføringsforsøg for samme banklinje kunne begge bestå
        // det tjek, FØR nogen af dem nåede at skrive, og dermed begge poste hele
        // trin 1+2 ovenfor (journalpost, evt. fakturabetaling/lagerregulering,
        // evt. auto-udgift). WHERE-klausulen her tjekker nu atomisk is_processed
        // = 0 ved selve skrivningen. Antal reelt berørte rækker måles direkte fra
        // UPDATE'en (DB::affected_rows()) i stedet for blot at SELECT'e
        // sluttilstanden bagefter - en efterfølgende SELECT ville vise
        // is_processed=1 for BEGGE forespørgsler, uanset hvem der reelt vandt
        // kapløbet, og ville derfor ikke pålideligt kunne skelne "jeg vandt" fra
        // "nogen andre vandt, og jeg ser bare deres resultat".
        $upd_result = DB::query($conn, "UPDATE bank_statement_temp SET is_processed = 1 WHERE tmp_id = $tmp_id AND is_processed = 0");
        if (!$upd_result || DB::affected_rows($conn, $upd_result) < 1) {
            throw new Exception('Denne banktransaktion blev allerede bogført af en anden, samtidig forespørgsel (kapløb) - intet blev dublet.');
        }

        DB::commit($conn);
        return ['ok' => true];

    } catch (Exception $e) {
        DB::rollback($conn);
        return ['ok' => false, 'message' => lang('@Error:') . " " . $e->getMessage()];
    }
}


// UDBETALING FRA EN BETALINGSUDBYDER (webbutik): Bambora / Stripe / Shopify Payments / MobilePay samler kundernes
// betalinger og udbetaler dem - fratrukket deres gebyr - til banken. Butikkens ordrer står på butikkens
// mellemregningskonto (inc/shop.inc.php); udbetalingen nulstiller den:
//   DEBET bank = udbetalt beløb   DEBET gebyrkonto = gebyret   KREDIT mellemregning = udbetalt + gebyr
// Et brutto der er STØRRE end mellemregningens saldo afvises (ordrerne er ikke kommet ind endnu, eller forkert butik).
if (!function_exists('shop_clearing_balance')) {
    function shop_clearing_balance($conn, int $acc): float {
        $r =DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) FROM ledger WHERE acc_id = $acc"));
        return round((float)($r[0] ?? 0), 2);
    }
}
function reconcile_shop_payout($conn, $global_settings, int $tmp_id, int $shop_id, float $fee_amount, int $fee_acc_id): array {
    $bank = DB::fetch_assoc(DB::query($conn, "SELECT * FROM bank_statement_temp WHERE tmp_id = $tmp_id"));
    if (!$bank) return ['ok' => false, 'message' => lang('@Bank entry not found.')];
    if ((int)$bank['is_processed'] === 1) return ['ok' => false, 'already_processed' => true];
    $amount = round((float)$bank['amount'], 2);
    if ($amount <= 0) return ['ok' => false, 'message' => lang('@A payout from a payment provider must be an incoming payment.')];
    $fee_amount = round($fee_amount, 2);
    if ($fee_amount < 0) return ['ok' => false, 'message' => lang('@The fee cannot be negative.')];
    $shop = DB::fetch_assoc(DB::query($conn, "SELECT shop_id, name, clearing_acc FROM shop_connections WHERE shop_id = $shop_id"));
    $clear = (int)($shop['clearing_acc'] ?? 0);
    if (!$shop || $clear <= 0 || !DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $clear"))) {
        return ['ok' => false, 'message' => lang('@The web shop has no valid clearing account.')];
    }
    if ($fee_amount > 0 && ($fee_acc_id <= 0 || !DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $fee_acc_id")))) {
        return ['ok' => false, 'message' => lang('@Please choose an existing account for the fee.')];
    }
    $date = (string)$bank['trans_date'];
    if (is_date_locked($conn, $date)) return ['ok' => false, 'message' => lang('@This transaction date is in a locked accounting period and cannot be posted.')];
    $gross = round($amount + $fee_amount, 2);
    $bal   = shop_clearing_balance($conn, $clear);
    if ($gross > $bal + 0.01) {
        return ['ok' => false, 'message' => sprintf(lang('@The payout plus fee (%1$s) is more than the clearing account of %2$s holds (%3$s) - are all orders received, and is it the right shop?'),
            number_format($gross, 2, ',', '.'), $shop['name'], number_format($bal, 2, ',', '.'))];
    }
    $s = get_settings($conn);
    $acc_bank = (int)($s['conf_acc_bank'] ?? 5000);
    if (ledger_accounts_missing($conn, [$acc_bank])) return ['ok' => false, 'message' => sprintf(lang('@The account %d does not exist in the chart of accounts - check Company Settings (posting accounts) or the chart of accounts. Nothing was posted.'), $acc_bank)];
    DB::begin_transaction($conn);
    try {
        $voucher = next_voucher_no($conn);
        $text = DB::escape($conn, sprintf(lang('@Payout web shop %s'), $shop['name']) . ': ' . $bank['text_val']);
        $cur  = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
        $cb   = journal_created_by_sql($conn);
        if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
                              VALUES ('" . DB::escape($conn, $date) . "', '$text', 'shop_payout', $voucher, '$cur'{$cb['val']})") === false) throw new Exception(DB::error($conn));
        $jou_id = (int)DB::insert_id($conn);
        ledger_post($conn, $jou_id, $acc_bank, $amount);
        if ($fee_amount > 0) ledger_post($conn, $jou_id, $fee_acc_id, $fee_amount);
        ledger_post($conn, $jou_id, $clear, -$gross);
        $upd = DB::query($conn, "UPDATE bank_statement_temp SET is_processed = 1 WHERE tmp_id = $tmp_id AND is_processed = 0");
        if (!$upd || DB::affected_rows($conn, $upd) < 1) throw new Exception(lang('@This bank transaction was already posted by another, simultaneous request.'));
        log_action($conn, 'SHOP_PAYOUT', 'journal', $jou_id, null, ['shop' => $shop['name'], 'amount' => $amount, 'fee' => $fee_amount, 'voucher_no' => $voucher, 'tmp_id' => $tmp_id]);
        DB::commit($conn);
        return ['ok' => true];
    } catch (Throwable $e) {
        DB::rollback($conn);
        return ['ok' => false, 'message' => lang('@Error:') . ' ' . $e->getMessage()];
    }
}
