<?php # /reconcile_action.php v:1.3.0 d:2026-08-30 i:evs
# fee_amount manglede parse_dk_number() - se [[settings-fees-reconcile-bugs-review]]
# v1.7.1: fee_amount castedes direkte via (float), samme sårbarhed som andre
# beløbsfelter denne session (komma-formateret input kunne blive stille
# afkortet) - gebyret bogføres direkte på hovedbogen, så det er en reel
# regnskabsrisiko, ikke kun kosmetisk. Erstattet med parse_dk_number().
# v1.7.0: betalingsposteringen krediterede aldrig debitorkontoen, balancerede derfor aldrig til 0; rettet + tilføjet delvis betaling via ny invoice_payments-tabel, se selve posteringen for fuld forklaring.
# (Tilføjet proj_id på journal + auto-expense ved scenarie B)
# v1.3.0: bankkonto fra conf_acc_bank (var hardkodet forkert til 1000/"Salg");
# journalpost får nu voucher_no fra den fælles next_voucher_no()
# v1.4.0: tilføjet periodespærring (is_date_locked) - manglede helt
# v1.5.0: KRITISK - en kladde-faktura kunne markeres 'paid' direkte her, helt
# uden om bogføringen. Tilføjet statustjek. Faktura-/fakturaflow-gennemgang.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

$tmp_id     = isset($_POST['tmp_id'])     ? (int)$_POST['tmp_id']     : 0;
$target_id  = isset($_POST['target_id'])  ? (int)$_POST['target_id']  : 0;
$acc_id     = isset($_POST['acc_id'])     ? (int)$_POST['acc_id']     : 0;
$fee_amount = isset($_POST['fee_amount']) ? parse_dk_number($_POST['fee_amount']) : 0;
$fee_acc_id = isset($_POST['fee_acc_id']) ? (int)$_POST['fee_acc_id'] : 2320;
$proj_id    = isset($_POST['proj_id'])    ? (int)$_POST['proj_id']    : 0;  // NYT
// NYT (§reel-multi-valuta-bogforing): brugeren har eksplicit bekræftet at
// denne betaling skal lukke en udenlandsk faktura, selvom det modtagne DKK-
// beløb (næsten uundgåeligt) ikke rammer den bogførte DKK-total præcist -
// se reconcile_list.php's fx-wrap/toggleFxCheckbox() for UI'en. Kun relevant
// for scenarie A (target_id > 0); ignoreres helt for scenarie B.
$close_fx_invoice = isset($_POST['close_fx_invoice']) && $_POST['close_fx_invoice'] === '1';

if ($tmp_id === 0) die(lang('@No transaction selected.'));

$res  = DB::query($conn, "SELECT * FROM bank_statement_temp WHERE tmp_id = $tmp_id");
$bank = DB::fetch_assoc($res);
if (!$bank) die(lang('@Bank entry not found.'));

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
    header("Location: reconcile_list.php?msg=already_processed");
    exit;
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
    die(lang('@This transaction date is in a locked accounting period and cannot be posted.'));
}

// KRITISK: en kladde-faktura (aldrig bogført) kunne før markeres 'paid'
// direkte her, helt uden om invoice_post_action.php - fakturaen ville aldrig
// få et fakturanummer, ingen posteringer, intet lagertræk, men fremstå som
// betalt. reconcile_list.php's dropdown filtrerede kun 'paid' fra, ikke
// 'draft', så dette var nåbart via den almindelige brugerflade. Fundet ved
// en faktura-/fakturaflow-gennemgang.
if ($target_id > 0) {
    $target_status = DB::fetch_assoc(DB::query($conn, "SELECT inv_status FROM invoices WHERE inv_id = $target_id"));
    if (!$target_status) {
        die(lang('@Invoice not found.'));
    }
    if (strtolower($target_status['inv_status']) === 'draft') {
        die(lang('@This invoice is still a draft and must be posted before it can be marked as paid.'));
    }
}

DB::begin_transaction($conn);
try {

    // --- 1. OPRET JOURNAL POST ---
    // Bilagsnummer fra den fælles tæller (manglede helt før - bankafstemninger
    // var usporbare i den samlede bilagsrække).
    $voucher_no   = next_voucher_no($conn);
    $journal_text = ($target_id > 0)
        ? lang('@Payment inv. #') . $target_id
        : lang('@Bank entry:') . " " . $bank_text;

    // proj_id gemmes på journal-rækken (altid, uanset scenarie)
    // RETTET (§currency-setting-is-cosmetic-label): journal.currency blev
    // aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK') - bankposteringer
    // er altid i firmaets faktisk konfigurerede bogføringsvaluta.
    $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
    DB::query($conn, "INSERT INTO journal (jou_date, jou_text, proj_id, voucher_no, currency)
                      VALUES ('$bank_date', '$journal_text', $proj_sql, $voucher_no, '$jou_currency')");
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
        $inv_total    = $inv_totals['incl'];
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
        ledger_post($conn, $jou_id, $acc_id, $bank_amount);
        ledger_post($conn, $jou_id, $acc_bank, $bank_amount * -1);

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

        DB::query($conn, "INSERT INTO expenses
                            (exp_date, supplier, account_id, amount, description,
                             proj_id,     created_by, created_at$paid_date_col_sql)
                          VALUES
                            ('$bank_date', '$supplier_esc', $acc_id, $bank_amount, '$bank_text',
                             $exp_proj_sql, $created_by, CURRENT_TIMESTAMP$paid_date_val_sql)");
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
    header("Location: reconcile_list.php?msg=success");

} catch (Exception $e) {
    DB::rollback($conn);
    die(lang('@Error:') . " " . $e->getMessage());
}
ob_end_flush();
?>
