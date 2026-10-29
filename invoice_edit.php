<?php # /invoice_edit.php v:1.3.1 d:2026-09-15 i:evs
# v1.6.0: linje-tabellen var hårdkodet til nøjagtigt 5 rækker (ingen "Tilføj
# linje"-knap) - en faktura med >5 reelle linjer (fx fra "Opret faktura af
# timer") kunne derfor stille miste linje 6+ permanent ved et helt
# almindeligt Gem. Rettet med en dynamisk "Tilføj linje"-knap + visning af
# ALLE eksisterende linjer, uanset antal. Se [[invoice-line-add-row-fix]].
# v1.5.0: ny faktura oprettet fra en projektside bar ikke projektet med sig
# (?proj_id=) - se [[project-bugs-review]].
# bruger-spurgte: kan beløbet mistes ved oprettelse af faktura? - ja, bekræftet + rettet, 2 runder
# v1.4.4: v1.4.3s str_replace(',', '.')-rettelse var selv utilstrækkelig -
# bruger rapporterede at en HÆVET pris "ikke virkede" ved gem. Årsag: et
# beløb skrevet med fuld dansk tusindtals-formatering ("1.500,00") blev af
# str_replace til "1.500.00" (to punktummer) - PHPs (float)-cast stopper ved
# det ekstra punktum og gav 1.5, IKKE 1500. Brug nu parse_dk_number()
# (inc/db_connect.inc.php), som korrekt skelner tusindtalsseparator fra
# decimaltegn. Se [[invoice-line-comma-amount-fix]].
# v1.4.3: quantity[]/price_each[]/line_vat[] manglede komma->punktum-
# normalisering ved gem af fakturalinjer (exch_rate lige ovenfor havde den
# allerede). PHP's rå (float)"199,95" stopper ved kommaet uden fejl og giver
# 199 - en tavs afkortning af beløbet, ikke en fejlmelding. Bekræftet direkte:
# en linje gemt med "199,95" endte som 199 kr i databasen FØR denne rettelse.
# (Valuta-sektion gates af module_currency; INSERT/UPDATE-fejl vises nu i stedet for falsk "saved")
# v1.4.0: en bogført faktura (status != draft) kunne FØR hård-slettes via
# ?del=1 helt uden tjek, og redigeres/genposteres uden at ledger nogensinde
# blev opdateret - fakturaindhold og hovedbog kunne stille og roligt glide
# fra hinanden. Begge dele blokeret nu; rettelse sker via kreditnota.
# Alvorligt fund ved en opfølgende bogføringslov-gennemgang 2026-08-15.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/customer_statement.inc.php';
require_once 'inc/field_visibility.inc.php';

$fv_overrides = get_field_visibility_overrides($conn);
$fv_is_admin  = (($_SESSION['user_role'] ?? '') === 'admin');
$fv_uLev      = (int)($_SESSION['user_level'] ?? 1);

// 1. HÅNDTER SLETNING
// Uforanderlighed (bogføringslov): en allerede bogført faktura (status !=
// draft) må IKKE kunne hård-slettes - fandtes intet tjek her overhovedet
// før (fundet ved en opfølgende gennemgang 2026-08-15, §bogforingslov-
// compliance). Rettelse af en bogført faktura skal ske via en kreditnota.
if (isset($_GET['del']) && $_GET['del'] == 1) {
    $inv_id = (int)$_GET['id'];
    $del_row = DB::fetch_assoc(DB::query($conn, "SELECT inv_status, cust_id, inv_date FROM invoices WHERE inv_id = $inv_id"));
    if ($del_row && strtolower($del_row['inv_status']) !== 'draft') {
        header("Location: invoice_edit.php?id=$inv_id&err=posted_no_delete"); exit;
    }
    // RETTET: kladden blev slettet, men det den var lavet AF blev ikke frigivet - timerne stod stadig som
    // "faktureret" (kunne aldrig faktureres igen), tilbuddet stod som "konverteret" til en faktura der ikke
    // fandtes, og en webordre pegede på den. Nu: timerne kan faktureres igen, tilbuddet er "accepteret" igen.
    DB::begin_transaction($conn);
    @DB::query($conn, "UPDATE time_entries SET is_invoiced = 0, inv_id = NULL WHERE inv_id = $inv_id");
    @DB::query($conn, "UPDATE quotes SET status = 'accepted', converted_invoice_id = NULL WHERE converted_invoice_id = $inv_id AND status = 'converted'");
    @DB::query($conn, "UPDATE shop_orders SET inv_id = NULL, status = 'draft_deleted' WHERE inv_id = $inv_id AND status = 'draft'");
    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    if (DB::query($conn, "DELETE FROM invoices WHERE inv_id=$inv_id") && $del_row) {
        log_action($conn, 'DELETE_DRAFT_INVOICE', 'invoices', $inv_id, $del_row, null);
    }
    DB::commit($conn);
    header("Location: sales_hub.php?msg=deleted"); exit;
}

// 2. HÅNDTER GEM / OPDATER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    $inv_id       = (int)$_POST['inv_id'];
    $cust_id      = (int)$_POST['cust_id'];
    $inv_date     = DB::escape($conn, $_POST['inv_date']);
    $inv_due_date = DB::escape($conn, $_POST['inv_due_date']);
    // RETTET: forfaldsdatoen kunne ligge FØR fakturadatoen (fx efter at fakturadatoen blev rykket) - fakturaen
    // var så "forfalden" allerede da den blev udstedt, og rykkerlisten/restancelisten talte dage fra før den fandtes
    if ($inv_due_date !== '' && $inv_date !== '' && $inv_due_date < $inv_date) {
        die(lang('@The due date cannot be before the invoice date.'));
    }
    // KRITISK (§bugs-batch-17-review): inv_status blev gemt direkte fra
    // POST'et Status-dropdown (draft/sent/paid/void), uden nogen server-side
    // kontrol. Dette gemme-endpoint bruges KUN til stadig-ikke-bogførte
    // fakturaer (den finansielle lås nedenfor blokerer enhver anden sti), så
    // en helt almindelig bruger kunne blot vælge "Betalt" i den synlige
    // dropdown og gemme - fakturaen blev sat til 'paid' UDEN nogensinde at
    // gå igennem invoice_post_action.php (intet fakturanummer, INGEN
    // hovedbogspostering, intet lagertræk). Nøjagtig samme fejlklasse som de
    // tre allerede lukkede huller i [[invoice-flow-integrity]] (send/
    // bankafstemning/linje-sletning kunne springe bogføringen over) - denne
    // fjerde, mest direkte vej (et almindeligt formularfelt) var overset.
    // Tvinger derfor status til at forblive 'draft' her, uanset hvad der
    // reelt blev sendt - den eneste gyldige vej fra kladde til bogført er
    // invoice_post_action.php.
    $inv_status   = 'draft';
    $cust_ref     = DB::escape($conn, $_POST['cust_reference'] ?? '');
    $inv_note     = DB::escape($conn, $_POST['inv_note']);
    $deliv_addr   = DB::escape($conn, $_POST['delivery_address']);
    $proj_id      = (int)($_POST['proj_id'] ?? 0);
    $proj_sql     = ($proj_id > 0) ? $proj_id : 'NULL';
    // Flere fakturalayouts: kladdens eget layout (tomt = kundens / standarden) - kun når migreret.
    // Gemmes kun på kladder (nedenfor) - en bogført faktura beholder det layout den blev sendt med.
    require_once 'inc/invoice_layouts.inc.php';
    $il_on_save    = il_ready($conn);
    $inv_layout_sql = ($il_on_save && ($il_pick = il_valid_id($conn, $_POST['inv_layout_id'] ?? 0)) > 0) ? $il_pick : 'NULL';

    // Valuta — fremmed valuta med kurs
    // RETTET (§currency-setting-is-cosmetic-label): "fremmed" blev afgjort ved
    // sammenligning mod den hardkodede streng 'DKK', ikke firmaets faktisk
    // konfigurerede bogføringsvaluta ($global_settings['currency']) - en
    // faktura i firmaets EGEN valuta (fx en SEK-faktura hos et SEK-firma)
    // blev derfor fejlagtigt behandlet som "fremmed" og fik unødvendigt en
    // exch_rate påført, mens en faktura i den hardkodede 'DKK' altid gled
    // igennem som "ikke fremmed" selv hos et ikke-DKK-firma, hvor DKK reelt
    // ER en fremmed valuta.
    $base_currency      = strtoupper(preg_replace('/[^A-Z]/', '', $global_settings['currency'] ?? 'DKK')) ?: 'DKK';
    $post_currency      = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['currency'] ?? $base_currency));
    $post_orig_currency = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['orig_currency'] ?? ''));
    $post_exch_rate     = parse_dk_number($_POST['exch_rate'] ?? '');

    if ($post_orig_currency !== '' && $post_orig_currency !== $base_currency && $post_exch_rate > 0) {
        $currency     = $post_orig_currency; // fakturaen vises i original valuta
        $orig_currency_sql = "'".DB::escape($conn, $post_orig_currency)."'";
        $exch_rate_sql     = $post_exch_rate;
        $new_orig_currency = $post_orig_currency;
        $new_exch_rate     = $post_exch_rate;
    } else {
        $currency          = $post_currency ?: $base_currency;
        $orig_currency_sql = 'NULL';
        $exch_rate_sql     = 'NULL';
        $new_orig_currency = null;
        $new_exch_rate     = null;
    }
    $db_currency = DB::escape($conn, $currency);

    // Linjer — bygges til ÉT normaliseret array her, uanset om fakturaen er en
    // kladde (bruges så til det reelle gem nedenfor) eller allerede bogført
    // (bruges KUN til sammenligning i den finansielle lås lige herunder).
    // RETTET (Timeregistrering, §time-tracking-feature): denne linje-
    // gemmelogik understøttede reelt KUN produktbaserede linjer - "if ($pid
    // <= 0 ...) continue;" sprang enhver linje UDEN et gyldigt prod_id helt
    // over, uanset om den havde en gyldig mængde/pris. En fritekst-linje
    // (fx en tidsregistrerings-linje som "25/08 - Rettede login-fejl", uden
    // noget tilknyttet produkt - se time_actions.php) blev derfor SLETTET
    // FULDSTÆNDIGT, ikke bare omdøbt, blot ved at åbne fakturaen her og
    // trykke Gem uden at røre linjen - bekræftet direkte: en faktura med 2
    // fritekst-linjer fra "Opret faktura af timer" endte med 0 linjer efter
    // et almindeligt "Gem" uden nogen ændringer. Roden er at line_text[]
    // reelt er en PRODUKT-vælger (en <select>), ikke et frit tekstfelt -
    // dens indsendte værdi er altid et prod_id, aldrig den viste tekst,
    // uanset hvad linjen egentlig indeholder. Løsning: et separat, skjult
    // line_desc[]-felt (se linje-rendering nedenfor) bærer linjens EGEN,
    // allerede gemte tekst uafhængigt af select'ens værdi - bruges som
    // fallback-tekst OG gør linjen "værd at gemme", når intet produkt er
    // valgt, i stedet for automatisk at blive kasseret.
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfri
    // rabat pr. linje - samme "stille degradering hvis ikke migreret
    // endnu"-mønster som andre nyere kolonner.
    $has_discount_col_ie = false;
    if (DB::is_sqlite()) {
        $dcc_ie = DB::query($conn, "PRAGMA table_info(invoice_lines)");
        if ($dcc_ie) { while ($dccr_ie = DB::fetch_assoc($dcc_ie)) { if ($dccr_ie['name'] === 'discount_pct') { $has_discount_col_ie = true; break; } } }
    } else {
        $dcc_ie = DB::query($conn, "SHOW COLUMNS FROM invoice_lines LIKE 'discount_pct'");
        $has_discount_col_ie = ($dcc_ie && DB::num_rows($dcc_ie) > 0);
    }

    $posted_lines = [];
    if (isset($_POST['line_text']) && is_array($_POST['line_text'])) {
        foreach ($_POST['line_text'] as $k => $v) {
            $pid = isset($_POST['prod_id'][$k]) ? (int)$_POST['prod_id'][$k] : 0;
            // RETTET v1.4.3->v1.4.4: den første rettelse (blot str_replace(','
            // ,'.')) var selv util­strækkelig - se parse_dk_number() i
            // inc/db_connect.inc.php for hvorfor et HÆVET beløb skrevet med
            // fuld dansk tusindtals-formatering ("1.500,00") blev endnu VÆRRE
            // ramt (endte som 1,5, ikke bare afrundet) end det oprindelige
            // "199,95"->"199"-fund. Bruger-bekræftet reelt oplevet: en hævet
            // pris "virkede ikke" ved gem.
            $qty = parse_dk_number($_POST['quantity'][$k]);
            $prc = parse_dk_number($_POST['price_each'][$k]);
            $vat = parse_dk_number($_POST['line_vat'][$k]);
            // Rabat: tomt/negativt/over-100 behandles alle som "ingen rabat",
            // samme forsvarsprincip som andre brugerindtastede procentfelter
            // i appen (fx vat_rate) - en fejltastning skal aldrig kunne give
            // en NEGATIV linjetotal.
            $disc = 0.0;
            if ($has_discount_col_ie && isset($_POST['line_discount'][$k]) && trim($_POST['line_discount'][$k]) !== '') {
                $disc = parse_dk_number($_POST['line_discount'][$k]);
                if ($disc < 0 || $disc > 100) $disc = 0.0;
            }
            $prod_row = DB::fetch_assoc(DB::query($conn, "SELECT prod_name FROM products WHERE prod_id=$pid"));
            $existing_desc = trim($_POST['line_desc'][$k] ?? '');
            if ($prod_row) {
                $txt = $prod_row['prod_name'];
            } elseif ($existing_desc !== '') {
                $txt = $existing_desc;
            } else {
                $txt = $_POST['line_text'][$k];
            }
            if ($qty <= 0 || ($pid <= 0 && $existing_desc === '')) continue;
            $posted_lines[] = ['text' => $txt, 'qty' => $qty, 'price' => $prc, 'vat' => $vat, 'prod_id' => $pid, 'discount' => $disc];
        }
    }

    // Uforanderlighed (bogføringslov): en allerede bogført faktura må ikke
    // kunne få sine finansielle felter (dato/linjer/beløb/valuta) ændret "on
    // the spot" - ledger-posteringen fra invoice_post_action.php ville ellers
    // stille og roligt glide fra det fakturaen faktisk viser. Rettelse af
    // disse felter skal ske via invoice_credit.php (kreditnota).
    // UNDTAGELSE (bruger-anmodet, samme princip som expense_edit.php har for
    // udgifter): rent beskrivende/kategoriserende felter - projekt,
    // kundereference, note, leveringsadresse, forfaldsdato - må stadig
    // ændres frit på en bogført faktura, da de hverken påvirker hovedbogen
    // eller det udstedte fakturabeløb. invoice_post_action.php sætter aldrig
    // noget projekt-felt på selve journalposten for fakturaer (i modsætning
    // til expense_edit.php, som eksplicit synkroniserer journal.proj_id) -
    // projekt-tilknytningen for fakturaer lever udelukkende i
    // invoices.proj_id + invoice_lines.proj_id, begge rene rapporterings-
    // felter. Sammenligner derfor de FAKTISK finansielle felter (dato,
    // valuta/kurs, linjeindhold) mod det gemte - kun hvis INGEN af dem er
    // ændret tillades gemmet at gå igennem (begrænset til metadata-felterne).
    if ($inv_id > 0) {
        $existing_inv = DB::fetch_assoc(DB::query($conn, "SELECT * FROM invoices WHERE inv_id = $inv_id"));
        if ($existing_inv && strtolower($existing_inv['inv_status']) !== 'draft') {
            $existing_lines = [];
            $ex_res = DB::query($conn, "SELECT line_text, quantity, price_each, line_vat_rate, prod_id"
                . ($has_discount_col_ie ? ", discount_pct" : "") . " FROM invoice_lines WHERE inv_id=$inv_id ORDER BY line_id");
            while ($er = DB::fetch_assoc($ex_res)) {
                $existing_lines[] = [
                    'text' => $er['line_text'], 'qty' => $er['quantity'], 'price' => $er['price_each'],
                    'vat' => $er['line_vat_rate'], 'prod_id' => (int)$er['prod_id'],
                    'discount' => $has_discount_col_ie ? (float)($er['discount_pct'] ?? 0) : 0.0,
                ];
            }

            $financial_changed = ($existing_inv['inv_date'] !== $inv_date)
                || ($existing_inv['currency'] !== $currency)
                || ((string)($existing_inv['orig_currency'] ?? '') !== (string)($new_orig_currency ?? ''))
                || (round((float)($existing_inv['exch_rate'] ?? 0), 4) !== round((float)($new_exch_rate ?? 0), 4))
                || (count($existing_lines) !== count($posted_lines));

            if (!$financial_changed) {
                foreach ($existing_lines as $i => $el) {
                    $pl = $posted_lines[$i];
                    if (trim($el['text']) !== trim($pl['text'])
                        || round((float)$el['qty'], 4)      !== round((float)$pl['qty'], 4)
                        || round((float)$el['price'], 4)    !== round((float)$pl['price'], 4)
                        || round((float)$el['vat'], 4)      !== round((float)$pl['vat'], 4)
                        || $el['prod_id']                   !== (int)$pl['prod_id']
                        || round((float)$el['discount'], 4) !== round((float)$pl['discount'], 4)) {
                        $financial_changed = true;
                        break;
                    }
                }
            }

            if ($financial_changed) {
                die(lang('@This invoice is already posted. Its details cannot be changed here — use Create Credit Note to correct it.'));
            }

            DB::query($conn, "UPDATE invoices SET inv_due_date='$inv_due_date', cust_reference='$cust_ref',
                inv_note='$inv_note', delivery_address='$deliv_addr', proj_id=$proj_sql WHERE inv_id=$inv_id");
            DB::query($conn, "UPDATE invoice_lines SET proj_id=$proj_sql WHERE inv_id=$inv_id");
            header("Location: invoice_edit.php?id=$inv_id&msg=saved"); exit;
        }
    }

    if ($inv_id > 0) {
        $save_ok = DB::query($conn, "UPDATE invoices SET
            inv_date='$inv_date', inv_due_date='$inv_due_date', inv_status='$inv_status',
            cust_reference='$cust_ref', inv_note='$inv_note', delivery_address='$deliv_addr',
            currency='$db_currency', proj_id=$proj_sql,
            orig_currency=$orig_currency_sql, exch_rate=$exch_rate_sql" . ($il_on_save ? ", inv_layout_id=$inv_layout_sql" : "") . "
            WHERE inv_id=$inv_id");
    } else {
        $save_ok = DB::query($conn, "INSERT INTO invoices
            (cust_id, inv_date, inv_due_date, inv_status, cust_reference, inv_note,
             delivery_address, currency, proj_id, orig_currency, exch_rate)
            VALUES ($cust_id, '$inv_date', '$inv_due_date', '$inv_status', '$cust_ref', '$inv_note',
             '$deliv_addr', '$db_currency', $proj_sql, $orig_currency_sql, $exch_rate_sql)");
        if ($save_ok) {
            $inv_id = DB::insert_id($conn);
            if ($il_on_save && $inv_layout_sql !== 'NULL') DB::query($conn, "UPDATE invoices SET inv_layout_id=$inv_layout_sql WHERE inv_id=$inv_id");
            // TILFØJET (bruger-rapporteret følgefejl til langsom-sidevisning-
            // fejlsøgningen, se [[auto-backup-check-perf-bug]]): denne, den
            // hyppigste vej til en ny faktura, loggede FØR kun sletning
            // (DELETE_DRAFT_INVOICE ovenfor), aldrig oprettelse - så
            // auto_backup_check()'s ændrings-tjek (som bl.a. kigger på
            // audit_log) aldrig så en ny faktura oprettet herfra, medmindre
            // den også ramte expenses/journal på en eller anden led.
            log_action($conn, 'CREATE_DRAFT_INVOICE', 'invoices', $inv_id, null, ['cust_id' => $cust_id, 'inv_date' => $inv_date]);
        }
    }

    // En fejlet INSERT/UPDATE (fx en manglende kolonne) må ALDRIG ende som en
    // falsk "saved"-redirect. Vis den reelle DB-fejl i stedet, så problemet er
    // synligt frem for at fakturaen tavst forsvinder.
    if (!$save_ok) {
        die(lang('@Error saving invoice: ') . DB::error($conn));
    }

    // Linjer (nået kun for kladder - en bogført faktura exit'er tidligere
    // ovenfor via den finansielle lås). Genbruger $posted_lines fra ovenfor,
    // så linjeindholdet ikke tolkes to gange forskelligt.
    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    foreach ($posted_lines as $pl) {
        $txt = DB::escape($conn, $pl['text']);
        DB::query($conn, "INSERT INTO invoice_lines
            (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id"
            . ($has_discount_col_ie ? ", discount_pct" : "") . ")
            VALUES ($inv_id, '$txt', {$pl['qty']}, {$pl['price']}, {$pl['vat']}, {$pl['prod_id']}, $proj_sql"
            . ($has_discount_col_ie ? ", {$pl['discount']}" : "") . ")");
    }
    header("Location: invoice_edit.php?id=$inv_id&msg=saved"); exit;
}

// 3. HENT DATA
$inv_id = (int)($_GET['id'] ?? 0);
$s = get_settings($conn);
$default_currency = $s['currency'] ?? 'DKK';
$currency_module  = !empty($s['module_currency']) && $s['module_currency'] == '1';

if ($inv_id > 0) {
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT * FROM invoices WHERE inv_id=$inv_id"));
    if (!$inv) die(lang('@Invoice not found'));
    $inv_lines = [];
    $lines_res = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id=$inv_id ORDER BY line_id");
    while ($l = DB::fetch_assoc($lines_res)) { $inv_lines[] = $l; }
} else {
    $inv = [
        'inv_id' => 0, 'cust_id' => 0, 'inv_date' => date('Y-m-d'),
        'inv_due_date' => date('Y-m-d', strtotime('+14 days')),
        'inv_status' => 'draft', 'delivery_address' => '', 'cust_reference' => '',
        'inv_note' => '', 'currency' => $default_currency,
        // RETTET (se [[project-bugs-review]]): en ny faktura oprettet via
        // "Opret faktura" fra selve projektsiden bar ikke projektet med sig.
        'proj_id' => (int)($_GET['proj_id'] ?? 0),
        'orig_currency' => null, 'exch_rate' => null
    ];
    // RETTET: $inv_lines blev aldrig sat for en NY faktura (kun i grenen
    // ovenfor) - virkede tidligere kun ved en tilfældighed, fordi den
    // hårdkodede 5-linjers-løkke udelukkende læste den via isset(), som
    // tåler en helt udefineret variabel. Direkte count($inv_lines) (se
    // $line_render_count nedenfor) gør ikke det samme - uden denne linje
    // gav "Opret ny faktura" en fatal TypeError.
    $inv_lines = [];
}

$cur          = $inv['currency']      ?? $default_currency;
$proj_id      = (int)($inv['proj_id']  ?? 0);
$orig_currency = $inv['orig_currency'] ?? null;
$exch_rate     = $inv['exch_rate']    ?? null;

// 4. RENDER SIDE
htm_Header($inv_id > 0 ? lang('@Edit Invoice')." #$inv_id" : lang('@Create New Invoice'));
showMenu();

$tools = htm_Button('fa-list', '@Back to Hub', 'secondary', 'sales_hub.php', '', 'data-hint="'.lang('@Return to the sales hub without saving').'"', '<div style="display:flex; gap:10px;"></div>', false);
$card_msg = '';
// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): specifikt
// tjek for 'duplicated' skal stå FØR det generiske isset($_GET['msg'])-
// tjek nedenfor, som ellers matcher enhver værdi af msg (inkl. denne) og
// ville vise den forkerte "Ændringer gemt"-besked på en helt frisk kladde
// der aldrig er blevet gemt endnu.
if (isset($_GET['msg']) && $_GET['msg'] === 'duplicated') {
    $card_msg = htm_Alert(sprintf(lang('@New draft created as a duplicate of invoice #%d - review and adjust before sending.'), (int)($_GET['src'] ?? 0)), 'info', 700, false);
} elseif (isset($_GET['msg'])) {
    $card_msg = htm_Alert(lang('@Changes saved successfully'), 'success', 700, false);
} elseif (isset($_GET['err']) && $_GET['err'] === 'posted_no_delete') {
    $card_msg = htm_Alert(lang('@This invoice is already posted and cannot be deleted. Use Create Credit Note to correct it.'), 'error', 700, false);
}
htm_Card_($inv_id > 0 ? '@Edit Invoice' : '@New Invoice', 1000, $card_msg, 'edit_form', true, $tools);

echo '<input type="hidden" name="inv_id" value="'.$inv_id.'">';

// Kunde + projekt
$cust_res = DB::query($conn, "SELECT cust_id, cust_name, cust_payment_days FROM customers WHERE " . customers_active_filter($conn) . " ORDER BY cust_name");
$cust_opt = [];  $cust_days_map = [];
while ($c = DB::fetch_assoc($cust_res)) { $cust_opt[$c['cust_id']] = $c['cust_name'];  $cust_days_map[$c['cust_id']] = (int)($c['cust_payment_days'] ?? 8); }
htm_Field(icon:'fa-user', labl:'@Customer', name:'cust_id', valu:$inv['cust_id'], type:'sele',
    opti:$cust_opt, extr:($inv_id > 0 ? 'disabled required' : 'required onchange="checkCustomerBalance(this.value)"'), wdth:'70%', echo:true);
htm_ProjektCodeField($conn, $proj_id ?: null, '30%');
if ($inv_id > 0) echo '<input type="hidden" name="cust_id" value="'.$inv['cust_id'].'">';
// RETTET: kundens betalingsfrist (kundekortet) blev aldrig brugt her - en ny faktura fik altid +14 dage.
// Forfaldsdatoen følger nu kundens frist, når kunden eller fakturadatoen vælges (kan stadig rettes i hånden).
if ($inv_id == 0) {
    echo '<script>(function(){ var days= ' . json_encode($cust_days_map) . ';'
       . 'function due(){ var c= document.querySelector("[name=cust_id]"), d= document.querySelector("[name=inv_date]"), f= document.querySelector("[name=inv_due_date]");'
       . ' if (!c || !d || !f || !d.value || days[c.value] === undefined) return;'
       . ' var t= new Date(d.value + "T12:00:00");  t.setDate(t.getDate() + days[c.value]);  f.value= t.toISOString().slice(0, 10); }'
       . 'document.addEventListener("change", function(e){ if (e.target.name === "cust_id" || e.target.name === "inv_date") due(); });'
       . '})();</script>';
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): advarer - blokerer
// IKKE - hvis den valgte kunde allerede har en forfalden saldo, FØR endnu
// mere "kredit" udvides til dem via en ny faktura. Genbruger
// get_customer_statement_data() via customer_balance_check.php (samme
// beregning som kundekortets sammendrag, se [[bookkeeper-wishlist-round10]]).
// For en EKSISTERENDE kladde kendes kunden allerede server-side, så
// advarslen forudberegnes med det samme (intet AJAX-kald nødvendigt); for
// en helt NY faktura kendes kunden først når dropdownen ændres, se
// checkCustomerBalance() nedenfor.
echo '<div id="cust_balance_warning" style="display:none; margin:10px 0; padding:10px 14px; background:var(--bg-panel); border-left:4px solid var(--color-warning); border-radius:4px; font-size:0.9em;"></div>';
if ($inv_id > 0 && (int)$inv['cust_id'] > 0) {
    $bal_stmt = get_customer_statement_data($conn, (int)$inv['cust_id']);
    if ($bal_stmt && $bal_stmt['overdue_invoices'] > 0) {
        $bal_cur = $global_settings['currency'] ?? 'DKK';
        echo '<div style="margin:10px 0; padding:10px 14px; background:var(--bg-panel); border-left:4px solid var(--color-warning); border-radius:4px; font-size:0.9em;">'
            . '<i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> '
            . sprintf(lang('@This customer already has %d overdue invoice(s) totaling %s.'), $bal_stmt['overdue_invoices'], number_format($bal_stmt['total_outstanding'], 2, ',', '.') . ' ' . $bal_cur)
            . '</div>';
    }
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): kreditgrænse -
    // uafhængig af "forfalden saldo" ovenfor, en kunde der altid betaler til
    // tiden kan sagtens vokse forbi en fornuftig grænse uden nogensinde at
    // udløse den advarsel.
    $has_credit_limit_col_ie = false;
    if (DB::is_sqlite()) {
        $clc_ie = DB::query($conn, "PRAGMA table_info(customers)");
        if ($clc_ie) { while ($clcr_ie = DB::fetch_assoc($clc_ie)) { if ($clcr_ie['name'] === 'credit_limit') { $has_credit_limit_col_ie = true; break; } } }
    } else {
        $clc_ie = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit'");
        $has_credit_limit_col_ie = ($clc_ie && DB::num_rows($clc_ie) > 0);
    }
    if ($has_credit_limit_col_ie && $bal_stmt) {
        $cl_row_ie = DB::fetch_assoc(DB::query($conn, "SELECT credit_limit FROM customers WHERE cust_id = " . (int)$inv['cust_id']));
        $cl_ie = ($cl_row_ie && $cl_row_ie['credit_limit'] !== null) ? (float)$cl_row_ie['credit_limit'] : null;
        if ($cl_ie !== null && (float)$bal_stmt['total_outstanding'] > $cl_ie) {
            $bal_cur_ie = $global_settings['currency'] ?? 'DKK';
            echo '<div style="margin:10px 0; padding:10px 14px; background:var(--bg-panel); border-left:4px solid var(--color-danger); border-radius:4px; font-size:0.9em;">'
                . '<i class="fa-solid fa-shield-halved" style="color:var(--color-danger);"></i> '
                . sprintf(lang('@This customer\'s outstanding balance (%s) already exceeds their credit limit (%s).'),
                    number_format((float)$bal_stmt['total_outstanding'], 2, ',', '.') . ' ' . $bal_cur_ie,
                    number_format($cl_ie, 2, ',', '.') . ' ' . $bal_cur_ie)
                . '</div>';
        }
    }
}

// Datoer + status
echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_Field(icon:'fa-calendar',       labl:'@Invoice Date', name:'inv_date',     valu:$inv['inv_date'],     type:'date', echo:true);
htm_Field(icon:'fa-calendar-check', labl:'@Due Date',     name:'inv_due_date', valu:$inv['inv_due_date'], type:'date', echo:true);
htm_Field(icon:'fa-toggle-on',      labl:'@Status',       name:'inv_status',   valu:strtolower($inv['inv_status'] ?? 'draft'),   type:'sele',
    opti:['draft'=>lang('@Draft'),'sent'=>lang('@Sent'),'paid'=>lang('@Paid'),'void'=>lang('@Void')], echo:true);
echo '</div>';

// ── Valuta-sektion (kun når valuta-modulet er aktivt) ───────────────────────────
// RETTET (§currency-setting-is-cosmetic-label): "fremmed" afgøres nu mod
// firmaets faktisk konfigurerede $default_currency, ikke en hardkodet 'DKK'.
$fc_checked = ($orig_currency && $orig_currency !== $default_currency) ? 'checked' : '';
if ($currency_module) {
// RETTET (bruger-anmodet, felt-højreklik-skjul): en L1/L2-bruger for hvem
// dette felt er skjult, skal STADIG kunne gemme en allerede-oprettet
// fremmed-valuta-faktura uden at valutadata går tabt - render_hideable_field()
// udelader HELE feltet fra output for en skjult bruger, så uden en fallback
// ville $_POST['orig_currency']/['exch_rate']/['currency'] alle mangle, og
// POST-håndteringen ville stille falde tilbage til firmaets basisvaluta (se
// linje ~104-116) og dermed NULSTILLE en eksisterende fakturas valutakurs.
// Samme mønster som allerede brugt i "modul deaktiveret"-grenen nedenfor.
render_hideable_field($fv_overrides, 'invoice_edit.orig_currency', $fv_is_admin, $fv_uLev, function() use ($fc_checked, $cur, $orig_currency, $default_currency, $exch_rate) {
echo '<div style="margin:10px 0 15px; padding:12px; background:var(--bg-panel); border-radius:8px; border:2px dashed blue;">';
echo '<div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">';
echo '<label style="font-weight:bold; font-size:13px; color:var(--text-main); white-space:nowrap;"><i class="fa fa-exchange" style="margin-right:5px; color:#7f8c8d;"></i>'.lang('@Foreign currency').'</label>';
echo '<label style="font-size:12px; cursor:pointer; display:flex; align-items:center; gap:5px; color:var(--text-muted);">';
echo '<input type="checkbox" id="fc-toggle" '.$fc_checked.' onchange="toggleFc(this.checked)"> '.lang('@Invoice is in foreign currency');
echo '</label>';
echo '<input type="hidden" name="currency" id="fc-currency-hidden" value="'.htmlspecialchars($cur).'">';
echo '</div>';

echo '<div id="fc-fields" style="display:'.($fc_checked ? 'grid' : 'none').'; grid-template-columns:140px 1fr 1fr auto; gap:10px; align-items:end;">';

// Valuta-valg
echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Currency').'</label>';
echo '<select name="orig_currency" id="fc-currency" onchange="fetchRate()" style="width:100%; padding:7px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main);">';
// RETTET: listen udelod altid 'DKK' (gav mening da hovedbogen kun var DKK -
// DKK var jo aldrig "fremmed"), men firmaets base kan nu være en anden valuta
// (fx SEK) - så er DKK reelt en fremmed valuta og skal kunne vælges. Filtrerer
// i stedet firmaets EGEN valuta fra listen, uanset hvilken den er.
$currencies = array_values(array_diff(['DKK','EUR','USD','GBP','SEK','NOK','CHF','JPY','CAD','AUD','PLN','CZK','HUF','RON','ISK'], [$default_currency]));
foreach ($currencies as $c) {
    $sel = ($orig_currency === $c || $cur === $c) ? ' selected' : '';
    echo '<option value="'.$c.'"'.$sel.'>'.$c.'</option>';
}
echo '</select></div>';

// Kurs
echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.sprintf(lang('@Exchange rate to %s'), htmlspecialchars($default_currency)).' <span id="fc-rate-date" style="font-weight:normal; font-size:10px; color:var(--text-muted);"></span></label>';
echo '<div style="display:flex; gap:4px;">';
echo '<input type="text" name="exch_rate" id="fc-rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'"
    placeholder="0,0000" oninput="updateCurrencyHidden()"
    style="flex:1; padding:7px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); text-align:right;">';
echo '<button type="button" onclick="fetchRate()" title="'.lang('@Fetch current rate').'"
    style="padding:7px 10px; background:var(--color-primary); color:white; border:none; border-radius:4px; cursor:pointer;">↻</button>';
echo '</div></div>';

// Info
echo '<div style="font-size:11px; color:var(--text-muted); padding-bottom:4px;">'.sprintf(lang('@Invoice lines are entered in foreign currency. %s equivalent is calculated on posting.'), htmlspecialchars($default_currency)).'</div>';

// Nulstil
echo '<div><button type="button" onclick="clearFc()" style="padding:7px 12px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; cursor:pointer; color:var(--text-muted); font-size:12px;">'.lang('@Clear').'</button></div>';
echo '</div>'; // fc-fields

// Gem kurs-info ved eksisterende faktura
if ($exch_rate && $orig_currency) {
    echo '<div style="margin-top:6px; font-size:11px; color:var(--text-muted);">';
    echo '<i class="fa fa-info-circle" style="color:var(--color-primary);"></i> ';
    echo lang('@Saved rate').': 1 '.htmlspecialchars($orig_currency).' = '.number_format($exch_rate, 4, ',', '').' '.htmlspecialchars($default_currency);
    echo '</div>';
}
echo '</div>'; // valuta-sektion
}, true); // has_own_box: sektionen har allerede sin egen stiplet blå kant

$fv_vis_fc = $fv_overrides['invoice_edit.orig_currency'] ?? field_visibility_defaults();
if (!$fv_is_admin && empty($fv_vis_fc[$fv_uLev])) {
    // Skjult for denne bruger - bevar eksisterende værdier som skjulte felter,
    // så et gem ikke nulstiller en allerede-sat fremmed-valuta-faktura.
    echo '<input type="hidden" name="currency" value="'.htmlspecialchars($cur).'">';
    if ($orig_currency && $orig_currency !== $default_currency) {
        echo '<input type="hidden" name="orig_currency" value="'.htmlspecialchars($orig_currency).'">';
        echo '<input type="hidden" name="exch_rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'">';
    }
}
} else {
    // Modul deaktiveret: bevar eksisterende valuta-værdier, så de ikke tabes ved gem
    echo '<input type="hidden" name="currency" value="'.htmlspecialchars($cur).'">';
    if ($orig_currency && $orig_currency !== $default_currency) {
        echo '<input type="hidden" name="orig_currency" value="'.htmlspecialchars($orig_currency).'">';
        echo '<input type="hidden" name="exch_rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'">';
    }
}
// ── Slut valuta-sektion ───────────────────────────────────────────────────────

htm_Field(icon:'fa-id-badge', labl:'@Customer Reference', name:'cust_reference', valu:$inv['cust_reference'] ?? '', type:'text', hint:'@E.g. order number, contact person or EAN', echo:true);
htm_Field(icon:'fa-comment',  labl:'@Delivery Address',   name:'delivery_address', valu:$inv['delivery_address'], type:'textarea', echo:true);

// Flere fakturalayouts (inc/invoice_layouts.inc.php): en kladde kan få sit eget layout - en
// bogført faktura viser blot det layout den blev sendt med (det stemples ved bogføringen)
require_once 'inc/invoice_layouts.inc.php';
if (il_ready($conn)) {
    $il_all = il_list($conn);
    if (strtolower($inv['inv_status'] ?? 'draft') === 'draft') {
        $il_opts = ['' => lang('@The layout of the customer (or the default)')];
        foreach ($il_all as $lid => $l) $il_opts[$lid] = $l['name'] . ((int)$l['is_default'] === 1 ? ' ★' : '');
        htm_Field(icon:'fa-table-cells-large', labl:'@Invoice Layout', name:'inv_layout_id', valu:(string)($inv['inv_layout_id'] ?? ''), type:'sele', opti:$il_opts,
                  wdth:'320px', hint:'@The layout of this invoice (view/print/PDF/email). Layouts are made in Design Mode on the invoice.', echo:true);
    } else {
        $il_used = il_for_invoice($conn, $inv);
        echo '<div style="margin:6px 0; font-size:0.9em; color:var(--text-muted);"><i class="fa-solid fa-table-cells-large"></i> '
           . lang('@Invoice Layout') . ': <b>' . htmlspecialchars($il_all[$il_used]['name'] ?? '') . '</b></div>';
    }
}

echo '<h3 style="margin:20px 0 10px 0;">'.lang('@Invoice Lines').'</h3>';

// Linjer-tabel (uændret)
$prod_res = DB::query($conn, "SELECT p.prod_id, p.prod_name, p.prod_price, p.prod_stock, a.vat_rate FROM products p LEFT JOIN accounts a ON p.acc_id = a.acc_id ORDER BY p.prod_name");
$prod_opt = [0 => '-- '.lang('@Select Product/Description').' --'];
$prod_vat_map = [0 => 25]; $prod_price_map = [0 => 0.00];
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): et lagerlavarsel
// ved selve faktureringen manglede - man opdagede først en overskredet
// lagerbeholdning EFTER fakturaen var gemt (prod_stock trækkes automatisk
// ved gemning, se invoice_actions.php), aldrig FØR. Samme "advarer, blokerer
// ikke"-princip som customer_balance_check.php/expense_duplicate_check.php:
// en bogholder kan sagtens have en gyldig grund til at fakturere mere end
// det aktuelt registrerede lager (fx en efterbestilling er på vej), så
// dette må aldrig forhindre selve gemningen.
$prod_stock_map = [0 => null];
while ($p = DB::fetch_assoc($prod_res)) {
    $prod_opt[$p['prod_id']] = $p['prod_name'];
    $prod_vat_map[$p['prod_id']]   = ($p['vat_rate']   !== null) ? (float)$p['vat_rate']   : 25.00;
    $prod_price_map[$p['prod_id']] = ($p['prod_price'] !== null) ? (float)$p['prod_price'] : 0.00;
    $prod_stock_map[$p['prod_id']] = ($p['prod_stock'] !== null) ? (int)$p['prod_stock'] : null;
}

// RETTET (bruger-spurgte "kan man oprette en række for extra linje(r)"):
// tabellen var hårdkodet til NØJAGTIGT 5 linjer (for ($i=0;$i<5;$i++)) - der
// var hverken en "Tilføj linje"-knap, ELLER nogen måde at se/redigere en
// linje nummer 6+, hvis fakturaen af en anden vej (fx "Opret faktura af
// timer" i time_actions.php, eller en genereret gentagende faktura med
// mange produkter) allerede havde fået flere end 5 linjer. VÆRRE: gem-
// håndteringen ovenfor sletter ALTID alle linjer først og genopretter dem
// udelukkende fra det, formularen reelt indsendte - så en faktura med 6+
// linjer, der blot blev åbnet og gemt her uden nogen tilsigtet ændring,
// ville stille og permanent miste linje 6 og fremefter (nøjagtig samme
// datatabs-mønster som den allerede rettede fritekst-linje-fejl beskrevet
// nedenfor, blot en anden rodårsag). Rettet ved (1) altid at vise MINDST
// lige så mange rækker som der reelt er linjer (aldrig færre - ingen
// eksisterende linje skjules/tabes længere), og (2) en "+ Tilføj linje"-
// knap, der via JS kloner en frisk, tom skabelon-række ind i tabellen -
// selve gem-logikken behøvede INGEN ændring, den itererer allerede
// generisk over hele $_POST['line_text'], uanset antal.
function build_invoice_line_row($i, $prod_id, $line_qty, $line_price, $line_vat, $existing_line_text, $prod_opt, $line_discount = null) {
    // RETTET (Timeregistrering): line_text[] er reelt en produkt-<select>,
    // ikke et frit tekstfelt - dens indsendte værdi er altid et prod_id.
    // line_desc[] bærer linjens EGEN, faktisk gemte tekst uafhængigt heraf,
    // så en fritekst-linje (intet prod_id, fx fra time_actions.php) hverken
    // mistes eller ombyttes til "0" ved et almindeligt Gem - se save-
    // håndteringen ovenfor. Vist som en lille note under feltet, når linjen
    // reelt er fritekst, så brugeren kan se hvad der faktisk står der, selv
    // om selve dropdown'en nødvendigvis viser "-- Vælg produkt --".
    $f_prod  = '<input type="hidden" name="prod_id[]" value="'.$prod_id.'">'
             . '<input type="hidden" name="line_desc[]" value="'.htmlspecialchars($existing_line_text, ENT_QUOTES).'">';
    $f_text  = htm_Field('', '', 'line_text[]', $prod_id, 'sele', $prod_opt, 'onchange="updateVatRate(this, '.$i.')"', '100%', '', '', '', false) . $f_prod;
    if ($existing_line_text !== '') {
        $f_text .= '<div style="font-size:0.8em; color:var(--text-muted); padding:2px 4px;">' . htmlspecialchars($existing_line_text) . '</div>';
    }
    $f_qty   = htm_Field('', '', 'quantity[]',   $line_qty,   'number', null, 'step="any" onchange="checkStockWarning('.$i.')"', '100%', '', '', '', false)
             . '<div id="stock_warn_'.$i.'" style="font-size:0.8em; color:var(--color-warning); padding:2px 4px; display:none;"></div>';
    $f_price = htm_Field('', '', 'price_each[]', $line_price, 'number', null, 'step="any"', '100%', '', '', '', false);
    $f_vat   = htm_Field('', '', 'line_vat[]',   $line_vat,   'number', null, '', '100%', '', '', '', false);
    $row = [$f_text, $f_qty, $f_price, $f_vat];
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfrit rabat-
    // felt (%) pr. linje - $line_discount er null (ikke bare 0) når kolonnen
    // slet ikke er migreret endnu, så denne celle helt kan udelades fra
    // tabellen (samme "stille degradering"-mønster som andre nyere kolonner,
    // her anvendt på selve KOLONNEN i tabellen, ikke kun et enkelt felt).
    if ($line_discount !== null) {
        $row[] = htm_Field('', '', 'line_discount[]', $line_discount, 'number', null, 'step="any" min="0" max="100" onchange="calculateInvoiceTotals()"', '100%', '', '', '', false);
    }
    return $row;
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): findes discount_pct
// (db-setup/migrate_invoice_line_discount.php)? Beregnes her (ikke kun i
// gem-logikken ovenfor), da denne del af siden kører uafhængigt af POST.
$has_discount_col_display = false;
if (DB::is_sqlite()) {
    $dcc_disp = DB::query($conn, "PRAGMA table_info(invoice_lines)");
    if ($dcc_disp) { while ($dccr_disp = DB::fetch_assoc($dcc_disp)) { if ($dccr_disp['name'] === 'discount_pct') { $has_discount_col_display = true; break; } } }
} else {
    $dcc_disp = DB::query($conn, "SHOW COLUMNS FROM invoice_lines LIKE 'discount_pct'");
    $has_discount_col_display = ($dcc_disp && DB::num_rows($dcc_disp) > 0);
}

$line_render_count = max(5, count($inv_lines));
$tbl_data = [];
for ($i = 0; $i < $line_render_count; $i++) {
    $prod_id    = isset($inv_lines[$i]['prod_id'])       ? (int)$inv_lines[$i]['prod_id']       : 0;
    $line_qty   = isset($inv_lines[$i]['quantity'])      ? $inv_lines[$i]['quantity']            : '';
    $line_price = isset($inv_lines[$i]['price_each'])    ? $inv_lines[$i]['price_each']          : '';
    $line_vat   = isset($inv_lines[$i]['line_vat_rate']) ? $inv_lines[$i]['line_vat_rate']       : 25;
    $existing_line_text = ($prod_id <= 0 && isset($inv_lines[$i]['line_text'])) ? $inv_lines[$i]['line_text'] : '';
    $line_discount = $has_discount_col_display ? ((isset($inv_lines[$i]['discount_pct']) && $inv_lines[$i]['discount_pct'] !== null) ? $inv_lines[$i]['discount_pct'] : 0) : null;
    $tbl_data[] = build_invoice_line_row($i, $prod_id, $line_qty, $line_price, $line_vat, $existing_line_text, $prod_opt, $line_discount);
}

$line_headers = ['@Description', '@Qty', '@Price', '@VAT %'];
$line_widths  = ['width:47%;', 'width:13%;', 'width:13%;', 'width:12%;'];
if ($has_discount_col_display) { $line_headers[] = '@Discount %'; $line_widths[] = 'width:15%;'; }
htm_Table($line_headers, $tbl_data, 'line_tbl', 25, '', true, $line_widths);

echo '<div style="margin:-45px 0 20px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
echo '<button type="button" onclick="addInvoiceLine()" style="display:inline-block; text-align:center; background-color:var(--color-secondary); color:var(--text-light); padding:6px 14px; border-radius:4px; border:none; cursor:pointer; font-size:13px; font-weight:600;">';
echo '<i class="fa-solid fa-plus"></i> ' . lang('@Add Line');
echo '</button>';

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 32):
// fakturaskabeloner - gem det nuværende linjesæt som en navngivet, genbrugelig
// skabelon, eller indsæt en tidligere gemt skabelons linjer i den aktuelle
// faktura. Rører aldrig selve fakturaen direkte - indsætter blot flere
// "Tilføj linje"-rækker (samme addInvoiceLine(), se dens egen kommentar for
// hvorfor det er trygt), som brugeren stadig kan redigere/fjerne bagefter.
echo '<select id="invTplSelect" style="padding:6px; border-radius:4px; font-size:13px;"><option value="">' . lang('@Select a template...') . '</option></select>';
echo '<button type="button" onclick="insertInvoiceTemplate()" style="padding:6px 12px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-panel); cursor:pointer; font-size:13px;"><i class="fa-solid fa-file-import"></i> ' . lang('@Insert Template') . '</button>';
echo '<button type="button" onclick="saveInvoiceTemplate()" style="padding:6px 12px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-panel); cursor:pointer; font-size:13px;"><i class="fa-solid fa-floppy-disk"></i> ' . lang('@Save Lines as Template') . '</button>';
echo '</div>';

// Skabelon til en frisk, tom linje - indekset 'IDX' erstattes med det
// reelle rækkenummer i JS, når en ny linje reelt tilføjes (se addInvoiceLine()
// nedenfor). Bygget med nøjagtig samme funktion som de rigtige rækker
// ovenfor, så en tilføjet linje aldrig kan komme til at afvige visuelt eller
// strukturelt fra dem.
$template_row = build_invoice_line_row('IDX', 0, '', '', 25, '', $prod_opt, $has_discount_col_display ? 0 : null);
$template_row_html = '<tr><td style="width:47%;">'.$template_row[0].'</td><td style="width:13%;">'.$template_row[1]
    .'</td><td style="width:13%;">'.$template_row[2].'</td><td style="width:12%;">'.$template_row[3].'</td>'
    . ($has_discount_col_display ? '<td style="width:15%;">'.$template_row[4].'</td>' : '') . '</tr>';

// Totaler
$cur_display = $orig_currency ?: $cur;
echo '<div style="margin:20px 0; padding:15px; background:#f8f9fa; border-radius:6px; max-width:350px; margin-left:auto;">
    <table style="width:100%; font-size:1.05em; border-collapse:collapse;">
        <tr><td style="padding:4px 0; color:#666;">'.lang('@Subtotal').':</td><td style="padding:4px 0; text-align:right; font-weight:bold;" id="total_sub">0,00</td><td style="padding:4px 0 4px 8px; color:#666; width:40px;" id="cur-label">'.$cur_display.'</td></tr>
        <tr><td style="padding:4px 0; color:#666;">'.lang('@VAT Total').':</td><td style="padding:4px 0; text-align:right; font-weight:bold; color:#7f8c8d;" id="total_vat">0,00</td><td style="padding:4px 0 4px 8px; color:#666;" id="cur-label-vat">'.$cur_display.'</td></tr>
        <tr style="border-top:2px solid #ddd; font-size:1.2em;"><td style="padding:10px 0 0 0; font-weight:bold; color:#2c3e50;">'.lang('@Total').':</td><td style="padding:10px 0 0 0; text-align:right; font-weight:bold; color:#27ae60;" id="total_grand">0,00</td><td style="padding:10px 0 0 8px; font-weight:bold; color:#2c3e50;" id="cur-label-total">'.$cur_display.'</td></tr>
    </table>
</div>';

htm_Field(icon:'fa-sticky-note', labl:'@Note', name:'inv_note', valu:$inv['inv_note'], type:'textarea', echo:true);
htm_Button(icon:'fa-save', labl:'@Save Invoice', type:'success', attr:'name="save_invoice" data-hint="'.lang('@Save this invoice as a draft').'"', cont:'<div style="margin-top:30px; text-align:right;"></div>');
htm_Card_end();
?>
<script>
const vatMap   = <?php echo json_encode($prod_vat_map); ?>;
const priceMap = <?php echo json_encode($prod_price_map); ?>;
const stockMap = <?php echo json_encode($prod_stock_map); ?>;
const lineRowTemplate = <?php echo json_encode($template_row_html); ?>;

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): rent visuelt
// lagerlavarsel pr. linje, opdateret live ved skift af produkt/antal.
// Blokerer aldrig - se PHP-kommentaren ved $prod_stock_map ovenfor.
function checkStockWarning(rowIndex) {
    const warnEl = document.getElementById('stock_warn_' + rowIndex);
    if (!warnEl) return;
    const pidF = document.getElementsByName('prod_id[]')[rowIndex];
    const qtyF = document.getElementsByName('quantity[]')[rowIndex];
    if (!pidF || !qtyF) return;
    const pid   = pidF.value;
    const stock = stockMap[pid];
    const qty   = parseFloat(qtyF.value.toString().replace(',', '.')) || 0;
    if (stock === null || stock === undefined || qty <= stock) {
        warnEl.style.display = 'none';
        return;
    }
    warnEl.textContent = <?php echo json_encode(lang('@Only %d in stock')); ?>.replace('%d', stock);
    warnEl.style.display = 'block';
}
function checkAllStockWarnings() {
    document.getElementsByName('quantity[]').forEach(function(_, i) { checkStockWarning(i); });
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): kalder
// customer_balance_check.php ved skift i kunde-dropdownen, da en helt ny
// faktura (inv_id=0) ikke kender kunden server-side før formularen sendes -
// se serverside-forudberegningen for en eksisterende kladde lige ovenfor.
function checkCustomerBalance(custId) {
    var box = document.getElementById('cust_balance_warning');
    if (!box) return;
    if (!custId) { box.style.display = 'none'; return; }
    fetch('customer_balance_check.php?cust_id=' + encodeURIComponent(custId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"):
            // kreditgrænse-advarslen er uafhængig af "forfalden saldo" -
            // begge kan være sande samtidig, vises derfor som to separate
            // linjer i samme boks, ikke et enten-eller.
            var html = '';
            if (data.overdue) {
                html += '<div><i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> '
                    + <?php echo json_encode(lang('@This customer already has')); ?> + ' ' + data.overdue_count + ' '
                    + <?php echo json_encode(lang('@overdue invoice(s) totaling')); ?> + ' '
                    + Number(data.balance).toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + data.currency + '.</div>';
            }
            if (data.over_limit) {
                html += '<div' + (data.overdue ? ' style="margin-top:6px;"' : '') + '><i class="fa-solid fa-shield-halved" style="color:var(--color-danger);"></i> '
                    + <?php echo json_encode(lang('@This customer\'s outstanding balance')); ?> + ' ('
                    + Number(data.balance).toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + data.currency + ') '
                    + <?php echo json_encode(lang('@already exceeds their credit limit')); ?> + ' ('
                    + Number(data.credit_limit).toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + data.currency + ').</div>';
            }
            if (html) { box.innerHTML = html; box.style.display = 'block'; }
            else { box.style.display = 'none'; }
        })
        .catch(function() { box.style.display = 'none'; });
}

<?php if ($currency_module): ?>
// ── Valuta ────────────────────────────────────────────────────────────────────
// RETTET (§currency-setting-is-cosmetic-label): kursen blev altid regnet til
// den hardkodede streng 'DKK' (via ECB/frankfurter.app's rates-objekt), uanset
// firmaets faktisk konfigurerede bogføringsvaluta. En SEK-baseret virksomhed
// fik derfor en EUR->DKK-kurs gemt på en "fremmed" EUR-faktura, ikke EUR->SEK.
var _fcRates = {}, _fcBase = 'EUR';
var _fcBaseCcy = <?php echo json_encode($default_currency); ?>;

function toggleFc(on) {
    document.getElementById('fc-fields').style.display = on ? 'grid' : 'none';
    if (on && Object.keys(_fcRates).length === 0) fetchRate();
    if (!on) clearFc();
}

function fetchRate() {
    var currency = document.getElementById('fc-currency').value;
    fetch('currency_proxy.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            _fcRates = data.rates; _fcBase = data.base;
            document.getElementById('fc-rate-date').textContent = '(' + data.date + ')';
            setRate(currency);
        })
        .catch(function() {
            document.getElementById('fc-rate').placeholder = '<?php echo lang('@Could not load rates'); ?>';
        });
}

function setRate(currency) {
    var rate = 0;
    if (currency === _fcBaseCcy) {
        rate = 1; // fakturaens valuta er allerede firmaets base - ingen omregning
    } else if (currency === _fcBase) {
        rate = _fcRates[_fcBaseCcy] || 0;
    } else {
        var toEur = _fcRates[currency] ? (1 / _fcRates[currency]) : 0;
        // Hvis basen selv er ECB-pivotvalutaen (EUR), findes den ikke som egen
        // nøgle i rates-objektet - "|| 1" giver da korrekt toEur*1 = toEur.
        rate = toEur * (_fcRates[_fcBaseCcy] || 1);
    }
    if (rate > 0) {
        document.getElementById('fc-rate').value = rate.toFixed(4).replace('.', ',');
        updateCurrencyHidden();
    }
}

function updateCurrencyHidden() {
    var currency = document.getElementById('fc-currency').value;
    document.getElementById('fc-currency-hidden').value = currency;
    // Opdater valuta-label i totaler
    ['cur-label','cur-label-vat','cur-label-total'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = currency;
    });
}

function clearFc() {
    document.getElementById('fc-rate').value = '';
    document.getElementById('fc-currency-hidden').value = _fcBaseCcy;
    ['cur-label','cur-label-vat','cur-label-total'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.textContent = _fcBaseCcy;
    });
}

document.getElementById('fc-currency').addEventListener('change', function() {
    updateCurrencyHidden();
    if (Object.keys(_fcRates).length > 0) setRate(this.value); else fetchRate();
});

<?php if ($fc_checked): ?>
document.addEventListener('DOMContentLoaded', function() { toggleFc(true); });
<?php endif; ?>
<?php endif; /* currency_module */ ?>

// ── Linjer ────────────────────────────────────────────────────────────────────
// Tilføjer en frisk, tom linje-række nederst i tabellen. Skabelonen ('IDX' i
// stedet for et fast tal) er renderet server-side af nøjagtig samme funktion
// som de eksisterende rækker (se build_invoice_line_row() i PHP'en ovenfor),
// så en tilføjet linje aldrig kan afvige visuelt/strukturelt fra dem. Selve
// gem-håndteringen kræver ingen ændring - den itererer allerede generisk
// over hele $_POST['line_text'], uanset hvor mange rækker der reelt findes.
function addInvoiceLine() {
    const newIndex = document.getElementsByName('line_text[]').length;
    const wrapper  = document.createElement('tbody');
    wrapper.innerHTML = lineRowTemplate.replace(/IDX/g, newIndex);
    document.querySelector('#line_tbl tbody').appendChild(wrapper.firstElementChild);
    calculateInvoiceTotals();
}

// NYT (runde 32, fakturaskabeloner): egen CSRF-token til dette AJAX-kald -
// siden bygger allerede sin formular via csrf_field(), men den er ikke
// tilgængelig i JS uden en eksplicit kopi her.
const CSRF_TOKEN_INVOICE = <?php echo json_encode(csrf_token()); ?>;

// NYT (runde 32, fakturaskabeloner): udfylder dropdown'en ved sideindlæsning.
function loadInvoiceTemplateList() {
    fetch('invoice_template_action.php?action=list')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const sel = document.getElementById('invTplSelect');
            data.templates.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.template_id;
                opt.textContent = t.name;
                sel.appendChild(opt);
            });
        })
        .catch(() => {});
}
document.addEventListener('DOMContentLoaded', loadInvoiceTemplateList);

// Indsætter en gemt skabelons linjer NEDERST i den aktuelle fakturas
// linjetabel - genbruger addInvoiceLine() for hver linje (samme "aldrig
// afvig strukturelt fra en rigtig række"-garanti som den funktion selv har),
// udfylder derefter blot de nyoprettede felters værdier.
function insertInvoiceTemplate() {
    const tid = document.getElementById('invTplSelect').value;
    if (!tid) { sysAlert("<?php echo lang('@Please select a template first.'); ?>"); return; }
    fetch('invoice_template_action.php?action=get&template_id=' + encodeURIComponent(tid))
        .then(r => r.json())
        .then(data => {
            if (!data.success) { sysAlert(data.error || "<?php echo lang('@Could not load the template.'); ?>"); return; }
            data.items.forEach(item => {
                addInvoiceLine();
                const i = document.getElementsByName('line_text[]').length - 1;
                // RETTET: (1) tal blev sat med komma ("12,5") i felter af typen number - browseren afviser det, feltet
                // blev TOMT, og linjen blev kasseret ved gem (antal 0) eller fik pris 0; (2) "line_text[]" er
                // produktvalglisten - en fritekstlinje blev sat som værdi i den (intet match) og gik tabt. En
                // fritekstlinje bærer nu sin tekst i line_desc[] (som timer / faste fakturaer) og vises under feltet.
                const sel = document.getElementsByName('line_text[]')[i];
                if (item.prod_id) {
                    sel.value = String(item.prod_id);
                    document.getElementsByName('prod_id[]')[i].value = item.prod_id;
                } else {
                    document.getElementsByName('line_desc[]')[i].value = item.line_text || '';
                    const note = document.createElement('div');
                    note.style.cssText = 'font-size:0.8em; color:var(--text-muted); padding:2px 4px;';
                    note.textContent = item.line_text || '';
                    sel.parentNode.appendChild(note);
                }
                document.getElementsByName('quantity[]')[i].value    = String(item.quantity);
                document.getElementsByName('price_each[]')[i].value  = String(item.price_each);
                document.getElementsByName('line_vat[]')[i].value    = item.line_vat_rate;
            });
            calculateInvoiceTotals();
        })
        .catch(() => sysAlert("<?php echo lang('@Could not load the template.'); ?>"));
}

// Gemmer det NUVÆRENDE linjesæt (alle rækker med en udfyldt beskrivelse) som
// en ny, navngivet skabelon - rører ikke selve fakturaen.
function saveInvoiceTemplate() {
    sysPrompt("<?php echo lang('@Name for this template:'); ?>", function(name) {
        if (!name || !name.trim()) return;
        const textF = document.getElementsByName('line_text[]');
        const qtyF  = document.getElementsByName('quantity[]');
        const priF  = document.getElementsByName('price_each[]');
        const vatF  = document.getElementsByName('line_vat[]');
        const pidF  = document.getElementsByName('prod_id[]');
        const descF = document.getElementsByName('line_desc[]');
        const lines = [];
        for (let i = 0; i < textF.length; i++) {
            // RETTET: textF er produktvalglisten - dens VÆRDI er produktets id, så skabelonen gemte "12" som
            // linjetekst, og en fritekstlinje (ingen produkt valgt) blev sprunget helt over
            const pidSel = parseInt(textF[i].value) || 0;
            const txt = pidSel > 0 ? (textF[i].options[textF[i].selectedIndex] ? textF[i].options[textF[i].selectedIndex].text : '').trim()
                                   : ((descF[i] && descF[i].value) || '').trim();
            if (!txt) continue;
            lines.push({
                line_text: txt,
                quantity: parseFloat((qtyF[i].value || '0').toString().replace(',', '.')) || 0,
                price_each: parseFloat((priF[i].value || '0').toString().replace(',', '.')) || 0,
                line_vat_rate: parseFloat((vatF[i].value || '0').toString().replace(',', '.')) || 0,
                prod_id: pidSel || (pidF[i] ? (parseInt(pidF[i].value) || 0) : 0),
            });
        }
        if (lines.length === 0) { sysAlert("<?php echo lang('@Add at least one line with a description before saving a template.'); ?>"); return; }
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('name', name.trim());
        fd.append('lines', JSON.stringify(lines));
        fd.append('csrf_token', CSRF_TOKEN_INVOICE);
        fetch('invoice_template_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const sel = document.getElementById('invTplSelect');
                    const opt = document.createElement('option');
                    opt.value = data.template_id;
                    opt.textContent = name.trim();
                    sel.appendChild(opt);
                    sysAlert("<?php echo lang('@Template saved.'); ?>");
                } else {
                    sysAlert(data.error || "<?php echo lang('@Could not save the template.'); ?>");
                }
            })
            .catch(() => sysAlert("<?php echo lang('@Could not save the template.'); ?>"));
    });
}

function updateVatRate(selectElement, rowIndex) {
    const pid = selectElement.value;
    document.getElementsByName('prod_id[]')[rowIndex].value       = pid;
    document.getElementsByName('line_vat[]')[rowIndex].value      = vatMap[pid]   !== undefined ? vatMap[pid]   : 25;
    document.getElementsByName('price_each[]')[rowIndex].value    = priceMap[pid] !== undefined ? priceMap[pid] : 0.00;
    calculateInvoiceTotals();
    checkStockWarning(rowIndex);
}

function calculateInvoiceTotals() {
    const qtyF = document.getElementsByName('quantity[]');
    const priF = document.getElementsByName('price_each[]');
    const vatF = document.getElementsByName('line_vat[]');
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfrit
    // rabat-felt (%) pr. linje - findes slet ikke i DOM'en, hvis kolonnen
    // ikke er migreret endnu (samme "stille degradering"-mønster som PHP-
    // siden af denne feature), discF.length er da 0 og discPct falder
    // korrekt tilbage til 0 (ingen rabat) for enhver linje.
    const discF = document.getElementsByName('line_discount[]');
    let sub = 0, vat = 0;
    for (let i = 0; i < qtyF.length; i++) {
        let q = parseFloat(qtyF[i].value.toString().replace(',','.')) || 0;
        let p = parseFloat(priF[i].value.toString().replace(',','.')) || 0;
        let v = parseFloat(vatF[i].value.toString().replace(',','.')) || 0;
        let discPct = (discF[i] && discF[i].value !== '') ? (parseFloat(discF[i].value.toString().replace(',','.')) || 0) : 0;
        if (discPct < 0 || discPct > 100) discPct = 0;
        let lineNet = q * p * (100 - discPct) / 100;
        sub += lineNet;
        vat += lineNet * (v / 100);
    }
    const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    document.getElementById('total_sub').innerText   = sub.toLocaleString('da-DK', fmt);
    document.getElementById('total_vat').innerText   = vat.toLocaleString('da-DK', fmt);
    document.getElementById('total_grand').innerText = (sub + vat).toLocaleString('da-DK', fmt);
}

document.addEventListener('input', function(e) {
    if (['quantity[]','price_each[]','line_vat[]','line_discount[]'].includes(e.target.name)) calculateInvoiceTotals();
    if (e.target.name === 'quantity[]') {
        const rowIndex = Array.prototype.indexOf.call(document.getElementsByName('quantity[]'), e.target);
        checkStockWarning(rowIndex);
    }
});
document.addEventListener('DOMContentLoaded', function() {
    calculateInvoiceTotals();
    checkAllStockWarnings();
});
</script>
<?php htm_Footer(); ?>
