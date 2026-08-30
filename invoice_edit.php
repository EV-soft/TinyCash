<?php # /invoice_edit.php v:1.3.0 d:2026-08-30 i:evs
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
    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    if (DB::query($conn, "DELETE FROM invoices WHERE inv_id=$inv_id") && $del_row) {
        log_action($conn, 'DELETE_DRAFT_INVOICE', 'invoices', $inv_id, $del_row, null);
    }
    header("Location: sales_hub.php?msg=deleted"); exit;
}

// 2. HÅNDTER GEM / OPDATER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    $inv_id       = (int)$_POST['inv_id'];

    // Uforanderlighed: en allerede bogført faktura må ikke kunne få sine
    // finansielle felter (dato/linjer/beløb/valuta) ændret "on the spot" -
    // ledger-posteringen fra invoice_post_action.php ville ellers stille og
    // roligt glide fra det fakturaen faktisk viser. Samme princip som blev
    // lukket for expense_edit.php samme dag. Rettelse skal ske via
    // invoice_credit.php (kreditnota), som allerede har sine egne tjek.
    if ($inv_id > 0) {
        $existing_inv = DB::fetch_assoc(DB::query($conn, "SELECT inv_status FROM invoices WHERE inv_id = $inv_id"));
        if ($existing_inv && strtolower($existing_inv['inv_status']) !== 'draft') {
            die(lang('@This invoice is already posted. Its details cannot be changed here — use Create Credit Note to correct it.'));
        }
    }
    $cust_id      = (int)$_POST['cust_id'];
    $inv_date     = DB::escape($conn, $_POST['inv_date']);
    $inv_due_date = DB::escape($conn, $_POST['inv_due_date']);
    // KRITISK (§bugs-batch-17-review): inv_status blev gemt direkte fra
    // POST'et Status-dropdown (draft/sent/paid/void), uden nogen server-side
    // kontrol. Dette gemme-endpoint bruges KUN til stadig-ikke-bogførte
    // fakturaer (linje 57-61 ovenfor blokerer allerede enhver anden sti), så
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
    } else {
        $currency          = $post_currency ?: $base_currency;
        $orig_currency_sql = 'NULL';
        $exch_rate_sql     = 'NULL';
    }
    $db_currency = DB::escape($conn, $currency);

    if ($inv_id > 0) {
        $save_ok = DB::query($conn, "UPDATE invoices SET
            inv_date='$inv_date', inv_due_date='$inv_due_date', inv_status='$inv_status',
            cust_reference='$cust_ref', inv_note='$inv_note', delivery_address='$deliv_addr',
            currency='$db_currency', proj_id=$proj_sql,
            orig_currency=$orig_currency_sql, exch_rate=$exch_rate_sql
            WHERE inv_id=$inv_id");
    } else {
        $save_ok = DB::query($conn, "INSERT INTO invoices
            (cust_id, inv_date, inv_due_date, inv_status, cust_reference, inv_note,
             delivery_address, currency, proj_id, orig_currency, exch_rate)
            VALUES ($cust_id, '$inv_date', '$inv_due_date', '$inv_status', '$cust_ref', '$inv_note',
             '$deliv_addr', '$db_currency', $proj_sql, $orig_currency_sql, $exch_rate_sql)");
        if ($save_ok) {
            $inv_id = DB::insert_id($conn);
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

    // Linjer
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
    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
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
            $prod_row = DB::fetch_assoc(DB::query($conn, "SELECT prod_name FROM products WHERE prod_id=$pid"));
            $existing_desc = trim($_POST['line_desc'][$k] ?? '');
            if ($prod_row) {
                $txt = DB::escape($conn, $prod_row['prod_name']);
            } elseif ($existing_desc !== '') {
                $txt = DB::escape($conn, $existing_desc);
            } else {
                $txt = DB::escape($conn, $_POST['line_text'][$k]);
            }
            if ($qty <= 0 || ($pid <= 0 && $existing_desc === '')) continue;
            DB::query($conn, "INSERT INTO invoice_lines
                (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id)
                VALUES ($inv_id, '$txt', $qty, $prc, $vat, $pid, $proj_sql)");
        }
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
if (isset($_GET['msg'])) {
    $card_msg = htm_Alert(lang('@Changes saved successfully'), 'success', 700, false);
} elseif (isset($_GET['err']) && $_GET['err'] === 'posted_no_delete') {
    $card_msg = htm_Alert(lang('@This invoice is already posted and cannot be deleted. Use Create Credit Note to correct it.'), 'error', 700, false);
}
htm_Card_($inv_id > 0 ? '@Edit Invoice' : '@New Invoice', 1000, $card_msg, 'edit_form', true, $tools);

echo '<input type="hidden" name="inv_id" value="'.$inv_id.'">';

// Kunde + projekt
$cust_res = DB::query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name");
$cust_opt = [];
while ($c = DB::fetch_assoc($cust_res)) { $cust_opt[$c['cust_id']] = $c['cust_name']; }
htm_Field(icon:'fa-user', labl:'@Customer', name:'cust_id', valu:$inv['cust_id'], type:'sele',
    opti:$cust_opt, extr:($inv_id > 0 ? 'disabled required' : ''), wdth:'70%', echo:true);
htm_ProjektCodeField($conn, $proj_id ?: null, '30%');
if ($inv_id > 0) echo '<input type="hidden" name="cust_id" value="'.$inv['cust_id'].'">';

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

echo '<h3 style="margin:20px 0 10px 0;">'.lang('@Invoice Lines').'</h3>';

// Linjer-tabel (uændret)
$prod_res = DB::query($conn, "SELECT p.prod_id, p.prod_name, p.prod_price, a.vat_rate FROM products p LEFT JOIN accounts a ON p.acc_id = a.acc_id ORDER BY p.prod_name");
$prod_opt = [0 => '-- '.lang('@Select Product/Description').' --'];
$prod_vat_map = [0 => 25]; $prod_price_map = [0 => 0.00];
while ($p = DB::fetch_assoc($prod_res)) {
    $prod_opt[$p['prod_id']] = $p['prod_name'];
    $prod_vat_map[$p['prod_id']]   = ($p['vat_rate']   !== null) ? (float)$p['vat_rate']   : 25.00;
    $prod_price_map[$p['prod_id']] = ($p['prod_price'] !== null) ? (float)$p['prod_price'] : 0.00;
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
function build_invoice_line_row($i, $prod_id, $line_qty, $line_price, $line_vat, $existing_line_text, $prod_opt) {
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
    $f_qty   = htm_Field('', '', 'quantity[]',   $line_qty,   'number', null, 'step="any"', '100%', '', '', '', false);
    $f_price = htm_Field('', '', 'price_each[]', $line_price, 'number', null, 'step="any"', '100%', '', '', '', false);
    $f_vat   = htm_Field('', '', 'line_vat[]',   $line_vat,   'number', null, '', '100%', '', '', '', false);
    return [$f_text, $f_qty, $f_price, $f_vat];
}

$line_render_count = max(5, count($inv_lines));
$tbl_data = [];
for ($i = 0; $i < $line_render_count; $i++) {
    $prod_id    = isset($inv_lines[$i]['prod_id'])       ? (int)$inv_lines[$i]['prod_id']       : 0;
    $line_qty   = isset($inv_lines[$i]['quantity'])      ? $inv_lines[$i]['quantity']            : '';
    $line_price = isset($inv_lines[$i]['price_each'])    ? $inv_lines[$i]['price_each']          : '';
    $line_vat   = isset($inv_lines[$i]['line_vat_rate']) ? $inv_lines[$i]['line_vat_rate']       : 25;
    $existing_line_text = ($prod_id <= 0 && isset($inv_lines[$i]['line_text'])) ? $inv_lines[$i]['line_text'] : '';
    $tbl_data[] = build_invoice_line_row($i, $prod_id, $line_qty, $line_price, $line_vat, $existing_line_text, $prod_opt);
}

htm_Table(['@Description', '@Qty', '@Price', '@VAT %'], $tbl_data, 'line_tbl', 25, '', true,
    ['width:55%;', 'width:15%;', 'width:15%;', 'width:15%;']);

echo '<div style="margin:-45px 0 20px 0;">';
echo '<button type="button" onclick="addInvoiceLine()" style="display:inline-block; text-align:center; background-color:var(--color-secondary); color:var(--text-light); padding:6px 14px; border-radius:4px; border:none; cursor:pointer; font-size:13px; font-weight:600;">';
echo '<i class="fa-solid fa-plus"></i> ' . lang('@Add Line');
echo '</button></div>';

// Skabelon til en frisk, tom linje - indekset 'IDX' erstattes med det
// reelle rækkenummer i JS, når en ny linje reelt tilføjes (se addInvoiceLine()
// nedenfor). Bygget med nøjagtig samme funktion som de rigtige rækker
// ovenfor, så en tilføjet linje aldrig kan komme til at afvige visuelt eller
// strukturelt fra dem.
$template_row = build_invoice_line_row('IDX', 0, '', '', 25, '', $prod_opt);
$template_row_html = '<tr><td style="width:55%;">'.$template_row[0].'</td><td style="width:15%;">'.$template_row[1]
    .'</td><td style="width:15%;">'.$template_row[2].'</td><td style="width:15%;">'.$template_row[3].'</td></tr>';

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
const lineRowTemplate = <?php echo json_encode($template_row_html); ?>;

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

function updateVatRate(selectElement, rowIndex) {
    const pid = selectElement.value;
    document.getElementsByName('prod_id[]')[rowIndex].value       = pid;
    document.getElementsByName('line_vat[]')[rowIndex].value      = vatMap[pid]   !== undefined ? vatMap[pid]   : 25;
    document.getElementsByName('price_each[]')[rowIndex].value    = priceMap[pid] !== undefined ? priceMap[pid] : 0.00;
    calculateInvoiceTotals();
}

function calculateInvoiceTotals() {
    const qtyF = document.getElementsByName('quantity[]');
    const priF = document.getElementsByName('price_each[]');
    const vatF = document.getElementsByName('line_vat[]');
    let sub = 0, vat = 0;
    for (let i = 0; i < qtyF.length; i++) {
        let q = parseFloat(qtyF[i].value.toString().replace(',','.')) || 0;
        let p = parseFloat(priF[i].value.toString().replace(',','.')) || 0;
        let v = parseFloat(vatF[i].value.toString().replace(',','.')) || 0;
        sub += q * p;
        vat += q * p * (v / 100);
    }
    const fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    document.getElementById('total_sub').innerText   = sub.toLocaleString('da-DK', fmt);
    document.getElementById('total_vat').innerText   = vat.toLocaleString('da-DK', fmt);
    document.getElementById('total_grand').innerText = (sub + vat).toLocaleString('da-DK', fmt);
}

document.addEventListener('input', function(e) {
    if (['quantity[]','price_each[]','line_vat[]'].includes(e.target.name)) calculateInvoiceTotals();
});
document.addEventListener('DOMContentLoaded', calculateInvoiceTotals);
</script>
<?php htm_Footer(); ?>
