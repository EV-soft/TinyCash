<?php # /customer_edit.php v:1.3.1 d:2026-09-15 i:evs
# NY: knap til det nye kundekontoudtog, customer_statement.php
# (Opdateret til at bruge htm_ConfirmLink)
# v1.3.0: KRITISK - sletnings-sikkerhedstjekket forespurgte en ikke-
# eksisterende kolonne (invoice_id, skal være inv_id) og fejlede derfor
# altid tavst - enhver kunde kunne slettes uanset tilknyttede fakturaer.
# Rettet + udvidet til også at tjekke tilknyttede projekter.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/field_visibility.inc.php';

$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ""; $err = "";

// -------------------------------------------------------------------------
// NYT: HÅNDTER SLET-ANMODNING (Kører før gem eller hent)
// -------------------------------------------------------------------------
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $del_id = (int)$_GET['del'];

    // Sikkerhedstjek: Hent fakturaer der er tilknyttet kunden.
    // RETTET 2026-08-19: forespurgte før en kolonne "invoice_id", som ikke
    // findes i invoices (den rigtige hedder inv_id) - forespørgslen fejlede
    // derfor ALTID, og DB::num_rows() på det fejlede resultat returnerede
    // stille 0 (efter en tidligere robusthedsrettelse denne session, som
    // forhindrer crashes ved fejlede forespørgsler generelt). Tjekket troede
    // dermed ALTID at ingen fakturaer var tilknyttet, uanset det reelle
    // billede - enhver kunde kunne slettes, og deres fakturaer fik en
    // kunde-reference der pegede på ingenting. Fundet ved en projekt-/
    // kundefunktions-gennemgang.
    $check_inv = DB::query($conn, "SELECT inv_id FROM invoices WHERE cust_id = $del_id");
    $linked_invoices = [];
    if ($check_inv) {
        while ($inv = DB::fetch_assoc($check_inv)) { $linked_invoices[] = $inv['inv_id']; }
    }

    // Samme problem gjaldt projekter (projects.cust_id) - ikke tjekket
    // overhovedet før. En kunde tilknyttet et projekt kunne slettes, og
    // projektet fik en dinglende kunde-reference.
    $check_proj = DB::query($conn, "SELECT proj_id FROM projects WHERE cust_id = $del_id");
    $linked_projects = [];
    if ($check_proj) {
        while ($p = DB::fetch_assoc($check_proj)) { $linked_projects[] = $p['proj_id']; }
    }

    // NYT (§bugs-batch-30-review): samme fejlklasse igen - quotes (tilbud/
    // ordrebekræftelser) har også en cust_id, men blev tilføjet EFTER dette
    // tjek blev skrevet og var derfor aldrig med. quote_list.php joiner
    // customers med et INNER JOIN, så en kunde kunne slettes og efterlade et
    // tilbud der ikke bare fik en løs reference, men blev fuldstændig
    // usynligt/uopnåeligt overalt i UI'et (ingen fejl, bare væk).
    $linked_quotes = [];
    $check_quotes = @DB::query($conn, "SELECT quote_id FROM quotes WHERE cust_id = $del_id");
    if ($check_quotes) {
        while ($q = DB::fetch_assoc($check_quotes)) { $linked_quotes[] = $q['quote_id']; }
    }

    // RETTET: en fast faktura (recurring_invoices) på kunden blev ikke tjekket - kunden kunne lægges i
    // papirkurven, og skabelonen blev ved med at danne nye fakturakladder til en "slettet" kunde
    $linked_recurring = [];
    $check_rec = @DB::query($conn, "SELECT recur_id FROM recurring_invoices WHERE cust_id = $del_id");
    if ($check_rec) {
        while ($rr = DB::fetch_assoc($check_rec)) { $linked_recurring[] = $rr['recur_id']; }
    }

    if (!empty($linked_invoices)) {
        $err = lang('@Cannot delete customer: The following invoice ID(s) are linked:') . ' ' . implode(', ', $linked_invoices);
    } elseif (!empty($linked_projects)) {
        $err = lang('@Cannot delete customer: The following project ID(s) are linked:') . ' ' . implode(', ', $linked_projects);
    } elseif (!empty($linked_quotes)) {
        $err = lang('@Cannot delete customer: The following quote ID(s) are linked:') . ' ' . implode(', ', $linked_quotes);
    } elseif (!empty($linked_recurring)) {
        $err = lang('@Cannot delete customer: The following recurring invoice(s) are linked - delete them first:') . ' ' . implode(', ', $linked_recurring);
    } else {
        // Hent kundens data FØR sletning, til revisionssporet.
        $old_res = DB::query($conn, "SELECT cust_id, cust_name, cust_email, cust_cvr FROM customers WHERE cust_id = $del_id");
        $old_row = $old_res ? DB::fetch_assoc($old_res) : null;

        // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere", runde
        // 31): "Slet" var permanent og uigenkaldeligt - kun beskyttet mod at
        // slette en kunde med fakturaer/projekter/tilbud (tjekket ovenfor,
        // uændret). En slettet, ubrugt testkunde var tabt for evigt ved et
        // uheld. Sætter nu blot deleted_at i stedet for at fjerne rækken -
        // se trash_bin.php for gendannelse. Stille degraderet til en RIGTIG
        // sletning, hvis migrationen ikke er kørt endnu (uændret adfærd).
        $has_trash_col = false;
        if (DB::is_sqlite()) {
            $tc = DB::query($conn, "PRAGMA table_info(customers)");
            if ($tc) { while ($tcr = DB::fetch_assoc($tc)) { if ($tcr['name'] === 'deleted_at') { $has_trash_col = true; break; } } }
        } else {
            $tc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'deleted_at'");
            $has_trash_col = ($tc && DB::num_rows($tc) > 0);
        }
        $del_sql = $has_trash_col
            ? "UPDATE customers SET deleted_at = '" . date('Y-m-d H:i:s') . "' WHERE cust_id = $del_id"
            : "DELETE FROM customers WHERE cust_id = $del_id";

        if (DB::query($conn, $del_sql)) {
            if ($old_row) log_action($conn, 'DELETE_CUSTOMER', 'customers', $del_id, $old_row, null);
            header("Location: sales_hub.php");
            exit;
        } else {
            $err = lang('@SQL Error:') . " " . DB::error($conn);
        }
    }
}

// 1. HÅNDTER GEM (Både ny og opdatering)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = DB::escape($conn, $_POST['cust_name']);
    $email   = DB::escape($conn, $_POST['cust_email']);
    $phone   = DB::escape($conn, $_POST['cust_phone']);
    $address = DB::escape($conn, $_POST['cust_address']);
    $cvr     = DB::escape($conn, $_POST['cust_cvr']);
    $contact = DB::escape($conn, $_POST['cust_contact_person']);
    $notes   = DB::escape($conn, $_POST['cust_notes']);
    $days    = max(0, (int)$_POST['cust_payment_days']);      // RETTET: negative dage gav en forfaldsdato FØR fakturadatoen

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfrit
    // fravalg af automatiske rykkere - samme "stille degradering hvis ikke
    // migreret endnu"-mønster som andre nyere kolonner. cust_no_reminders
    // findes kun efter db-setup/migrate_customer_no_reminders.php er kørt.
    $has_no_rem_col = false;
    if (DB::is_sqlite()) {
        $nrc = DB::query($conn, "PRAGMA table_info(customers)");
        if ($nrc) { while ($nrcr = DB::fetch_assoc($nrc)) { if ($nrcr['name'] === 'cust_no_reminders') { $has_no_rem_col = true; break; } } }
    } else {
        $nrc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_no_reminders'");
        $has_no_rem_col = ($nrc && DB::num_rows($nrc) > 0);
    }
    $no_reminders = !empty($_POST['cust_no_reminders']) ? 1 : 0;
    $has_no_rem_col = $has_no_rem_col && field_visible_to_user($conn, 'customer_edit.cust_no_reminders');     // Skjult felt: den gemte værdi bevares

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfri
    // kreditgrænse - samme "stille degradering hvis ikke migreret endnu"-
    // mønster. Tomt felt gemmes som NULL (ingen grænse sat), ikke som en
    // falsk 0-kr-grænse, der ville blokere enhver ny faktura.
    $has_credit_limit_col = false;
    if (DB::is_sqlite()) {
        $clc = DB::query($conn, "PRAGMA table_info(customers)");
        if ($clc) { while ($clcr = DB::fetch_assoc($clc)) { if ($clcr['name'] === 'credit_limit') { $has_credit_limit_col = true; break; } } }
    } else {
        $clc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit'");
        $has_credit_limit_col = ($clc && DB::num_rows($clc) > 0);
    }
    $has_credit_limit_col = $has_credit_limit_col && field_visible_to_user($conn, 'customer_edit.credit_limit');
    $credit_limit_raw = trim($_POST['credit_limit'] ?? '');
    $credit_limit_sql = $credit_limit_raw !== '' ? parse_dk_number($credit_limit_raw) : 'NULL';

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
    // valgfrit fakturasprog - samme "stille degradering hvis ikke migreret
    // endnu"-mønster. Tomt = brug systemets standardsprog (uændret adfærd).
    $has_language_col = false;
    if (DB::is_sqlite()) {
        $lgc = DB::query($conn, "PRAGMA table_info(customers)");
        if ($lgc) { while ($lgcr = DB::fetch_assoc($lgc)) { if ($lgcr['name'] === 'cust_language') { $has_language_col = true; break; } } }
    } else {
        $lgc = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_language'");
        $has_language_col = ($lgc && DB::num_rows($lgc) > 0);
    }
    $has_language_col = $has_language_col && field_visible_to_user($conn, 'customer_edit.cust_language');
    $cust_language_raw = trim($_POST['cust_language'] ?? '');
    $cust_language_sql = $cust_language_raw !== '' ? "'" . DB::escape($conn, $cust_language_raw) . "'" : 'NULL';

    // Flere fakturalayouts: kundens layout (tomt = standardlayoutet) - kun når migreret
    require_once 'inc/invoice_layouts.inc.php';
    $has_layout_col = il_ready($conn) && field_visible_to_user($conn, 'customer_edit.cust_layout_id');
    $cust_layout_sql = ($has_layout_col && ($cl_id = il_valid_id($conn, $_POST['cust_layout_id'] ?? 0)) > 0) ? $cl_id : 'NULL';

    // RETTET (§bugs-batch-22-review): e-mailfeltet blev kun tjekket klient-
    // side (type:'email' er blot et HTML5-hint, trivielt at omgå eller
    // simpelthen ikke understøttet ensartet af browseren). En forkert
    // indtastet adresse blev derfor gemt uændret og lå og ventede - den
    // dukkede først op som en fejl langt senere, hver gang send_invoice_
    // action.php/reminder_action.php forsøgte at sende noget til kunden
    // (og kunne i praksis let overses, da PHPMailer's fejl kun vises i en
    // AJAX-boks, ikke noget der aktivt får en bruger til at rette stamdata).
    // Tomt felt er stadig tilladt (nogle kunder får kun papirfaktura).
    $raw_email = trim($_POST['cust_email'] ?? '');
    if ($raw_email !== '' && filter_var($raw_email, FILTER_VALIDATE_EMAIL) === false) {
        $err = lang('@Please enter a valid email address, or leave the field empty.');
    } elseif ($cust_id > 0) {
        $sql = "UPDATE customers SET
                cust_name = '$name', cust_email = '$email', cust_phone = '$phone',
                cust_address = '$address', cust_cvr = '$cvr', cust_contact_person = '$contact',
                cust_notes = '$notes', cust_payment_days = $days"
                . ($has_no_rem_col ? ", cust_no_reminders = $no_reminders" : "")
                . ($has_credit_limit_col ? ", credit_limit = $credit_limit_sql" : "")
                . ($has_language_col ? ", cust_language = $cust_language_sql" : "")
                . ($has_layout_col ? ", cust_layout_id = $cust_layout_sql" : "") . "
                WHERE cust_id = $cust_id";
    } else {
        $insert_cols = "cust_name, cust_email, cust_phone, cust_address, cust_cvr, cust_contact_person, cust_notes, cust_payment_days";
        $insert_vals = "'$name', '$email', '$phone', '$address', '$cvr', '$contact', '$notes', $days";
        if ($has_no_rem_col)       { $insert_cols .= ", cust_no_reminders"; $insert_vals .= ", $no_reminders"; }
        if ($has_credit_limit_col) { $insert_cols .= ", credit_limit";      $insert_vals .= ", $credit_limit_sql"; }
        if ($has_language_col)     { $insert_cols .= ", cust_language";     $insert_vals .= ", $cust_language_sql"; }
        if ($has_layout_col)       { $insert_cols .= ", cust_layout_id";    $insert_vals .= ", $cust_layout_sql"; }
        $sql = "INSERT INTO customers ($insert_cols) VALUES ($insert_vals)";
    }

    if (isset($sql) && DB::query($conn, $sql)) {
        if ($cust_id == 0) $cust_id = DB::insert_id($conn);
        $msg = lang('@Customer saved successfully');
    } elseif (!isset($err)) {
        $err = lang('@SQL Error:') . " " . DB::error($conn);
    }
}

// 2. HENT DATA ELLER FORBERED TOM
// RETTET (§bugs-batch-22-review): når e-mailtjekket ovenfor afviser gemningen,
// blev formularen tidligere genindlæst med de GAMLE, ugemte databaseværdier -
// alle brugerens øvrige rettelser (navn, telefon, adresse osv.), ikke kun
// e-mailen, gik dermed tabt, og brugeren skulle taste det hele om igen for
// blot at rette én forkert e-mailadresse. Genbruger nu det indsendte POST-
// data i stedet for at slå op igen, når fejlen kom fra selve valideringen.
if (isset($err) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cust_name'])) {
    $cust = [
        'cust_name' => $_POST['cust_name'], 'cust_email' => $_POST['cust_email'] ?? '',
        'cust_phone' => $_POST['cust_phone'] ?? '', 'cust_address' => $_POST['cust_address'] ?? '',
        'cust_cvr' => $_POST['cust_cvr'] ?? '', 'cust_contact_person' => $_POST['cust_contact_person'] ?? '',
        'cust_notes' => $_POST['cust_notes'] ?? '', 'cust_payment_days' => max(0, (int)($_POST['cust_payment_days'] ?? 8)),
        'cust_no_reminders' => !empty($_POST['cust_no_reminders']) ? 1 : 0,
        'credit_limit' => $_POST['credit_limit'] ?? '',
        'cust_language' => $_POST['cust_language'] ?? '',
        'cust_layout_id' => $_POST['cust_layout_id'] ?? '',
    ];
} elseif ($cust_id > 0) {
    $res = DB::query($conn, "SELECT * FROM customers WHERE cust_id = $cust_id");
    $cust = DB::fetch_assoc($res);
    // Hvis kunden ikke findes (f.eks. lige slettet), stopper vi ikke med "die", men sender til hubben
    if (!$cust) {
        header("Location: sales_hub.php");
        exit;
    }
} else {
    $cust = [
        'cust_name' => '', 'cust_email' => '', 'cust_phone' => '', 'cust_address' => '',
        'cust_cvr' => '', 'cust_contact_person' => '', 'cust_notes' => '', 'cust_payment_days' => 8, 'cust_no_reminders' => 0,
        'credit_limit' => null, 'cust_language' => null, 'cust_layout_id' => null,
    ];
}

// Hvis navnet er tomt i databasen, giv det en synlig tekst i titlen, så du ved det er den tomme række
/* $display_name = !empty($cust['cust_name']) ? $cust['cust_name'] : '[Empty Row / Ghost Customer]';
$title = $cust_id > 0 ? lang('@Edit Customer') . ": " . $display_name : lang('@Add New Customer');
 */
$display_name = !empty($cust['cust_name']) ? $cust['cust_name'] : '[Empty Row / Ghost Customer]';
$title = $cust_id > 0 ? lang('@Edit Customer') . ": " . $display_name .
            " (ID: " . $cust_id . ")" : lang('@Add New Customer');
htm_Header($title);
showMenu();

// NYT (bruger-anmodet): tillader en admin at højreklikke og skjule
// "tillægsfelter" (credit_limit/cust_no_reminders herunder) for L1/L2 -
// samme mønster som menuens egen højreklik-skjul-funktion, se inc/field_
// visibility.inc.php.
$fv_overrides = get_field_visibility_overrides($conn);
$fv_is_admin  = (($_SESSION['user_role'] ?? '') === 'admin');
$fv_uLev      = (int)($_SESSION['user_level'] ?? 1);

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): kundekortet viste
// FØR kun stamdata - intet om kundens økonomiske historik. Man skulle ind på
// det separate kontoudtog (customer_statement.php) for overhovedet at se
// saldo/omsætning. Genbruger den allerede udtrukne
// get_customer_statement_data() (inc/customer_statement.inc.php) i stedet
// for at genopfinde saldo-/forfaldsberegningen endnu en gang et tredje sted
// (se invoice_credit.php's egen rettelse samme runde for hvorfor det
// undgås). Skjules helt for en kunde uden fakturahistorik endnu, for ikke at
// vise fire tomme 0-kr-felter på en frisk oprettet kunde.
if ($cust_id > 0) {
    require_once 'inc/customer_statement.inc.php';
    $stmt_data = get_customer_statement_data($conn, $cust_id);
    if ($stmt_data && !empty($stmt_data['invoices'])) {
        $this_year = date('Y');
        $revenue_this_year = 0.0;
        foreach ($stmt_data['invoices'] as $si) {
            if (substr($si['inv_date'], 0, 4) === $this_year) {
                $revenue_this_year += invoice_dkk_totals($conn, $si['inv_id'])['incl'];
            }
        }
        $last_inv = end($stmt_data['invoices']);
        $bal_type = $stmt_data['total_outstanding'] > 0.01 ? 'warning' : 'success';

        echo '<div style="max-width:650px; margin:0 auto 15px auto; display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:12px; text-align:center;">';
        foreach ([
            ['@Outstanding Balance', number_format($stmt_data['total_outstanding'], 2, ',', '.') . ' ' . ($global_settings['currency'] ?? 'DKK'), $bal_type],
            ['@Overdue Invoices',    $stmt_data['overdue_invoices'], $stmt_data['overdue_invoices'] > 0 ? 'danger' : 'success'],
            ['@Revenue This Year',   number_format($revenue_this_year, 2, ',', '.') . ' ' . ($global_settings['currency'] ?? 'DKK'), 'primary'],
            ['@Last Invoice',        date(CONF_DATE_FORMAT, strtotime($last_inv['inv_date'])), 'secondary'],
        ] as [$lbl, $val, $type]) {
            echo '<div style="background:var(--bg-panel); padding:12px; border-radius:8px;">';
            echo '<div style="font-size:0.75em; color:var(--text-muted); margin-bottom:4px;">' . lang($lbl) . '</div>';
            echo '<div style="font-size:1.1em; font-weight:bold; color:var(--color-'.$type.');">' . $val . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
}

echo "<div style='margin: 20px auto; width: fit-content;'>";
htm_Card_(capt: '@Customer Information', wdth: 650, form: 'edit_customer_form');
    echo "<div style='display:flex; width:100%; gap:10px;'>";
        htm_Field(icon: 'fa-id-badge', labl: '@ID', name: 'cust_id_display', valu: $cust_id > 0 ? $cust_id : '-', type: 'view', extr: 'align-center', wdth: '15%');
        htm_Field(icon: 'fa-user', labl: '@Customer Name', name: 'cust_name', valu: $cust['cust_name'], extr: 'align-left', wdth: '85%');
    echo "</div>";
    htm_Field(icon: 'fa-map-marker-alt', labl: '@Address', name: 'cust_address', valu: $cust['cust_address'], type: 'textarea', extr: 'align-left', wdth: '100%');
    
    echo "<div style='display:flex; width:100%; gap:6px; align-items:flex-end;'>";
        echo '<div style="width:33%; display:flex; gap:4px; align-items:flex-end;">';
        echo '<div style="flex:1;">';
        htm_Field(icon: 'fa-id-card', labl: '@CVR', name: 'cust_cvr', valu: $cust['cust_cvr'], extr: 'align-left onblur="checkCvrDuplicate(this.value, \'customer\', '.$cust_id.')"', wdth: '100%');
        echo '</div>';
        // NYT (bruger-anmodet, CVR-opslag): slår CVR-nummeret op via
        // cvr_proxy.php (cvrapi.dk) og udfylder navn/adresse/telefon/email
        // automatisk - se cvrLookup() nedenfor. type="button" (ikke
        // htm_Button(), som kun kan bygge type="submit" når $link er tom -
        // ville sende hele formularen af sted ved et klik her, se samme
        // fravalg i htm-table-button-refactor.md for invoice_edit.php).
        // "tc-info-fill" (htm_page.lib.php): mørkere tekst/ikon i dark-temaet,
        // hvor var(--color-info) er lysegrå og ellers gør ikonet ulæseligt.
        echo '<button type="button" onclick="cvrLookup(this)" class="tc-info-fill" style="flex-shrink:0; margin-bottom:2px; padding:9px 10px; background:var(--color-info); color:var(--text-light); border:none; border-radius:4px; cursor:pointer;" data-hint="'.lang('@Look up company name, address, phone and email from the CVR number').'"><i class="fa-solid fa-magnifying-glass"></i></button>';
        echo '</div>';
        htm_Field(icon: 'fa-phone', labl: '@Phone', name: 'cust_phone', valu: $cust['cust_phone'], extr: 'align-left', wdth: '33%');
        htm_Field(icon: 'fa-calendar-check', labl: '@Payment Days', name: 'cust_payment_days', valu: $cust['cust_payment_days'], type: 'number', extr: 'min="0" align-left', wdth: '34%');
    echo "</div>";
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): advarer - ikke
    // blokerende - hvis CVR-nummeret allerede er registreret på en ANDEN
    // kunde eller leverandør. Se cvr_duplicate_check.php for baggrunden.
    echo '<div id="cvr_dup_warning" style="display:none; margin:8px 0; padding:8px 12px; background:var(--bg-panel); border-left:4px solid var(--color-warning); border-radius:4px; font-size:0.85em;"></div>';
    
    echo "<div style='display:flex; width:100%;'>";
        htm_Field(icon: 'fa-envelope', labl: '@Email', name: 'cust_email', valu: $cust['cust_email'], type: 'email', extr: 'align-left', wdth: '50%');
        htm_Field(icon: 'fa-user-tie', labl: '@Contact Person', name: 'cust_contact_person', valu: $cust['cust_contact_person'], extr: 'align-left', wdth: '50%');
    echo "</div>";
    
    htm_Field(icon: 'fa-sticky-note', labl: '@Notes', name: 'cust_notes', valu: $cust['cust_notes'], type: 'textarea', extr: 'align-left', wdth: '100%');

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
    // valgfrit fakturasprog - stille degraderet hvis migrationen ikke er
    // kørt endnu. Bruges af invoice_view.php til at vise/sende selve
    // faktura-papiret på kundens sprog, uden at påvirke bogholderens egen
    // session/UI-sprog (se dens egen kommentar for den ikke-mutérende metode).
    $has_language_col_disp = false;
    if (DB::is_sqlite()) {
        $lgc2 = DB::query($conn, "PRAGMA table_info(customers)");
        if ($lgc2) { while ($lgcr2 = DB::fetch_assoc($lgc2)) { if ($lgcr2['name'] === 'cust_language') { $has_language_col_disp = true; break; } } }
    } else {
        $lgc2 = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_language'");
        $has_language_col_disp = ($lgc2 && DB::num_rows($lgc2) > 0);
    }
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfri
    // kreditgrænse - der findes allerede en advarsel om FORFALDEN saldo ved
    // fakturaoprettelse (customer_balance_check.php), men ingen grænse for
    // den samlede udestående saldo. Tomt felt = ingen grænse sat (viser
    // ingen advarsel, uanset saldo) - kun vist hvis migrationen er kørt.
    $show_credit_limit_field = false;
    if (DB::is_sqlite()) {
        $clc2 = DB::query($conn, "PRAGMA table_info(customers)");
        if ($clc2) { while ($clcr2 = DB::fetch_assoc($clc2)) { if ($clcr2['name'] === 'credit_limit') { $show_credit_limit_field = true; break; } } }
    } else {
        $clc2 = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit'");
        $show_credit_limit_field = ($clc2 && DB::num_rows($clc2) > 0);
    }

    // RETTET (bruger-anmodet, runde 28-opfølgning #2): Fakturasprog og
    // Kreditgrænse deler nu linje i stedet for hver sin fulde linje - kortet
    // er samtidig gjort bredere (550→650) for at give dem rigelig plads.
    // Flere fakturalayouts: kundens layout - kun når migreret (inc/invoice_layouts.inc.php)
    require_once 'inc/invoice_layouts.inc.php';
    $show_layout_field = il_ready($conn);
    if ($has_language_col_disp || $show_credit_limit_field || $show_layout_field) {
        echo "<div style='display:flex; gap:15px; align-items:flex-start; flex-wrap:wrap;'>";
    }
    if ($has_language_col_disp) {
        render_hideable_field($fv_overrides, 'customer_edit.cust_language', $fv_is_admin, $fv_uLev, function() use ($cust) {
            $lang_json = json_decode(file_get_contents(__DIR__ . '/json-data/languages.json'), true);
            $lang_opts = ['' => lang('@Use system default')];
            if ($lang_json && isset($lang_json['language'])) {
                foreach ($lang_json['language'] as $l) { $lang_opts[$l['code']] = $l['name'] ?? $l['code']; }
            }
            htm_Field(
                icon: 'fa-language',
                labl: '@Invoice Language',
                name: 'cust_language',
                valu: $cust['cust_language'] ?? '',
                type: 'sele',
                opti: $lang_opts,
                wdth: '260px',
                hint: '@The invoice document (view/print/PDF/email) will be shown in this language instead of your own, when set.'
            );
        }, true); // has_own_box: htm_Field() tegner allerede sin egen fieldset-kant
    }
    if ($show_layout_field) {
        render_hideable_field($fv_overrides, 'customer_edit.cust_layout_id', $fv_is_admin, $fv_uLev, function() use ($cust, $conn) {
            $il_opts = ['' => lang('@Use the default layout')];
            foreach (il_list($conn) as $lid => $l) $il_opts[$lid] = $l['name'] . ((int)$l['is_default'] === 1 ? ' ★' : '');
            htm_Field(
                icon: 'fa-table-cells-large',
                labl: '@Invoice Layout',
                name: 'cust_layout_id',
                valu: (string)($cust['cust_layout_id'] ?? ''),
                type: 'sele',
                opti: $il_opts,
                wdth: '260px',
                hint: '@The layout of the invoices of this customer (view/print/PDF/email), unless an invoice has its own. Layouts are made in Design Mode on the invoice.'
            );
        }, true); // has_own_box: htm_Field() tegner allerede sin egen fieldset-kant
    }
    if ($show_credit_limit_field) {
        render_hideable_field($fv_overrides, 'customer_edit.credit_limit', $fv_is_admin, $fv_uLev, function() use ($cust) {
            htm_Field(icon: 'fa-shield-halved', labl: '@Credit Limit', name: 'credit_limit',
                valu: isset($cust['credit_limit']) && $cust['credit_limit'] !== null && $cust['credit_limit'] !== ''
                    ? number_format((float)$cust['credit_limit'], 2, ',', '') : '',
                extr: 'align-left', wdth: '260px',
                hint: '@Optional. Warns when creating a new invoice would push this customer\'s total outstanding balance above this amount. Leave empty for no limit.');
        }, true); // has_own_box: htm_Field() tegner allerede sin egen fieldset-kant
    }
    if ($has_language_col_disp || $show_credit_limit_field || $show_layout_field) {
        echo "</div>";
    }

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): fravalg af
    // automatiske rykkere - relevant for følsomme kunderelationer, hvor
    // bogholderen hellere vil rykke manuelt/telefonisk. Kun vist hvis
    // migrationen er kørt.
    $show_no_rem_field = false;
    if (DB::is_sqlite()) {
        $nrc2 = DB::query($conn, "PRAGMA table_info(customers)");
        if ($nrc2) { while ($nrcr2 = DB::fetch_assoc($nrc2)) { if ($nrcr2['name'] === 'cust_no_reminders') { $show_no_rem_field = true; break; } } }
    } else {
        $nrc2 = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_no_reminders'");
        $show_no_rem_field = ($nrc2 && DB::num_rows($nrc2) > 0);
    }
    if ($show_no_rem_field) {
        // RETTET (bruger-anmodet: "Fravælg mangler label"): feltet havde
        // ingen synlig feltnavn-label (kun selve beskrivelsesteksten ved
        // siden af afkrydsningsfeltet) - i modsætning til alle andre felter
        // på siden, der har en <legend> via htm_Field(). Tilføjet samme
        // fieldset+legend-stil manuelt (htm_Field() understøtter ikke type
        // 'checkbox'), så feltet nu har et rigtigt navn ("Restancerykker"),
        // ligesom Kreditgrænse/Fakturasprog ovenfor.
        render_hideable_field($fv_overrides, 'customer_edit.cust_no_reminders', $fv_is_admin, $fv_uLev, function() use ($cust) {
            echo '<div style="display:inline-block; width:260px; min-width:0;">'
                . '<fieldset style="border-radius:8px; margin:2px; border:1px solid var(--border-fieldset); background-color: var(--bg-card); min-width:0; box-sizing:border-box; padding:5px 10px;">'
                . '<legend style="font-size:0.85rem; padding:2px 5px; color:var(--text-muted); margin:0; text-align:left;"><i class="fa fa-bell-slash" style="margin-right:5px; color: var(--color-primary);"></i> ' . lang('@Overdue Reminders') . '</legend>'
                . '<label style="display:flex; align-items:center; gap:8px; margin:2px 0; cursor:pointer; font-size:0.9em;">'
                . '<input type="checkbox" name="cust_no_reminders" value="1" ' . (!empty($cust['cust_no_reminders']) ? 'checked' : '') . ' style="width:16px; height:16px; flex-shrink:0;">'
                . '<span>' . lang('@Exclude this customer from automatic payment reminders') . '</span>'
                . '</label>'
                . '</fieldset>'
                . '</div>';
        }, true); // has_own_box: tegner nu selv sin fieldset-kant, ligesom de andre felter
    }

    echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
        htm_Button(icon: 'fa-save', labl: '@Save Changes', type: 'success', link: '', styl: 'flex:2;', attr: 'onclick="document.getElementById(\'edit_customer_form\').submit();" data-hint="'.lang('@Save this customer').'"');

        if ($cust_id > 0) {
            htm_Button(icon: 'fa-file-invoice-dollar', labl: '@Account Statement', type: 'info', link: 'customer_statement.php?id='.$cust_id, styl: 'flex:1;', attr: 'data-hint="'.lang('@View this customer\'s invoice and payment history').'"');
        }
        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
        // direkte link til netop DENNE kundes historik.
        if ($cust_id > 0 && ($_SESSION['user_role'] ?? '') === 'admin') {
            htm_Button(icon: 'fa-clock-rotate-left', labl: '@View History', type: 'secondary', link: 'audit_log.php?table_name=customers&row_id='.$cust_id, styl: 'flex:1;', attr: 'data-hint="'.lang('@See who created or edited this customer').'"');
        }

        // Erstattet htm_Button+confirmDelete()-JS med htm_ConfirmLink, som
        // escaper bekræftelsesteksten korrekt centralt i php2htm.lib.php.
        if ($cust_id > 0) {
            htm_ConfirmLink(
                icon: 'fa-trash',
                labl: '@Delete',
                link: 'customer_edit.php?id='.$cust_id.'&del='.$cust_id,
                mess: '@Are you sure you want to delete this customer? This cannot be undone.',
                type: 'danger',
                styl: 'flex:1; text-align:center;',
                attr: 'data-hint="'.lang('@Delete this customer - only possible if no invoices or projects are linked').'"'
            );
        }

        htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'sales_hub.php', styl: 'flex:1;', attr: 'data-hint="'.lang('@Return to the sales hub without saving').'"');
    echo "</div>";
    
htm_Card_end();

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33): kunden
// havde kun ét fladt sæt kontaktfelter (cust_contact_person/cust_email/
// cust_phone) ovenfor - en kunde med fx en separat fakturamodtager og en
// anden daglig kontaktperson kunne ikke registreres. Ny, separat
// customer_contacts-tabel, vist/redigeret her. Kun for allerede oprettede
// kunder (en kontaktperson skal knyttes til et rigtigt cust_id).
if ($cust_id > 0) {
    $has_contacts_table = false;
    if (DB::is_sqlite()) {
        $cct = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='customer_contacts'");
        $has_contacts_table = ($cct && DB::fetch_assoc($cct));
    } else {
        $cct = DB::query($conn, "SHOW TABLES LIKE 'customer_contacts'");
        $has_contacts_table = ($cct && DB::num_rows($cct) > 0);
    }
    if ($has_contacts_table) {
        echo '<div id="contacts" style="margin-top:20px;">';
        htm_Card_(capt: '@Contact Persons', wdth: 650);
        echo '<p style="color:var(--text-muted); font-size:12px; margin-top:0;">' . lang('@Use this for a customer with more than one relevant contact - e.g. a separate invoice recipient and a general contact person.') . '</p>';

        $res_contacts = DB::query($conn, "SELECT * FROM customer_contacts WHERE cust_id = $cust_id ORDER BY contact_id ASC");
        $has_any_contact = false;
        if ($res_contacts) {
            while ($cc = DB::fetch_assoc($res_contacts)) {
                $has_any_contact = true;
                echo '<div style="display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color); flex-wrap:wrap;">';
                echo '<div style="flex:1; min-width:150px;"><strong>' . htmlspecialchars($cc['contact_name'] ?: '-') . '</strong>';
                if (!empty($cc['contact_note'])) echo ' <span style="color:var(--text-muted); font-size:11px;">(' . htmlspecialchars($cc['contact_note']) . ')</span>';
                echo '</div>';
                echo '<div style="flex:1; min-width:150px; font-size:13px; color:var(--text-main);">' . htmlspecialchars($cc['contact_email'] ?: '-') . '</div>';
                echo '<div style="width:120px; font-size:13px; color:var(--text-main);">' . htmlspecialchars($cc['contact_phone'] ?: '-') . '</div>';
                echo '<form method="post" action="customer_contact_action.php" style="margin:0;" onsubmit="return confirm(' . json_encode(lang('@Are you sure?')) . ');">'
                    . csrf_field(false)
                    . '<input type="hidden" name="action" value="delete">'
                    . '<input type="hidden" name="cust_id" value="' . $cust_id . '">'
                    . '<input type="hidden" name="contact_id" value="' . (int)$cc['contact_id'] . '">'
                    . '<button type="submit" style="background:transparent; border:none; color:var(--color-danger); cursor:pointer; font-size:16px;" data-hint="' . htmlspecialchars(lang('@Delete this contact')) . '"><i class="fa fa-trash"></i></button></form>';
                echo '</div>';
            }
        }
        if (!$has_any_contact) {
            echo '<p style="color:var(--text-muted);">' . lang('@No contact persons added yet.') . '</p>';
        }

        echo '<form method="post" action="customer_contact_action.php" style="display:flex; gap:8px; align-items:flex-end; margin-top:15px; flex-wrap:wrap;">';
        csrf_field();
        echo '<input type="hidden" name="action" value="add">';
        echo '<input type="hidden" name="cust_id" value="' . $cust_id . '">';
        echo '<div><label style="font-size:11px; color:var(--text-muted); display:block;">' . lang('@Name') . '</label><input type="text" name="contact_name" style="padding:6px; width:150px;"></div>';
        echo '<div><label style="font-size:11px; color:var(--text-muted); display:block;">' . lang('@Email') . '</label><input type="email" name="contact_email" style="padding:6px; width:170px;"></div>';
        echo '<div><label style="font-size:11px; color:var(--text-muted); display:block;">' . lang('@Phone') . '</label><input type="text" name="contact_phone" style="padding:6px; width:100px;"></div>';
        echo '<div><label style="font-size:11px; color:var(--text-muted); display:block;">' . lang('@Role/Note') . '</label><input type="text" name="contact_note" placeholder="' . htmlspecialchars(lang('@e.g. Invoicing')) . '" style="padding:6px; width:130px;"></div>';
        echo '<button type="submit" class="btn-success" style="padding:8px 16px;">' . lang('@Add Contact') . '</button>';
        echo '</form>';

        htm_Card_end();
        echo '</div>';
    }
}

echo "</div>";
?>
<script>
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): kalder
// cvr_duplicate_check.php ved blur på CVR-feltet - advarer, blokerer ikke,
// se dens egen header for baggrunden.
function checkCvrDuplicate(cvrRaw, type, excludeId) {
    var box = document.getElementById('cvr_dup_warning');
    if (!box) return;
    var cvr = (cvrRaw || '').replace(/\D/g, '');
    if (cvr.length !== 8) { box.style.display = 'none'; return; }
    var params = new URLSearchParams({ type: type, cvr: cvr, exclude_id: excludeId || 0 });
    fetch('cvr_duplicate_check.php?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.duplicate && data.matches && data.matches.length > 0) {
                var names = data.matches.map(function(m) { return m.name; }).join(', ');
                box.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> '
                    + <?php echo json_encode(lang('@This CVR number is already registered on:')); ?> + ' <strong>' + names.replace(/</g, '&lt;') + '</strong>.';
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
            }
        })
        .catch(function() { box.style.display = 'none'; });
}
// NYT (bruger-anmodet, CVR-opslag): kalder cvr_proxy.php (cvrapi.dk) og
// udfylder navn/adresse (altid - det er selve formålet med et klik her) samt
// telefon/email (kun hvis de endnu er tomme, så et allerede udfyldt,
// kundespecifikt kontaktfelt ikke tavst overskrives af CVR-registrets
// officielle stamdata).
function cvrLookup(btn) {
    var cvrField = document.querySelector('[name="cust_cvr"]');
    var cvr = (cvrField.value || '').replace(/\D/g, '');
    if (cvr.length !== 8) {
        alert(<?php echo json_encode(lang('@Please enter a valid 8-digit CVR number first.')); ?>);
        return;
    }
    var icon = btn.querySelector('i');
    var origClass = icon.className;
    icon.className = 'fa-solid fa-spinner fa-spin';
    btn.disabled = true;

    fetch('cvr_proxy.php?cvr=' + cvr)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.error || <?php echo json_encode(lang('@CVR lookup failed.')); ?>);
                return;
            }
            var nameField  = document.querySelector('[name="cust_name"]');
            var addrField  = document.querySelector('[name="cust_address"]');
            var phoneField = document.querySelector('[name="cust_phone"]');
            var emailField = document.querySelector('[name="cust_email"]');
            if (nameField && data.name) nameField.value = data.name;
            if (addrField && data.address) addrField.value = data.address;
            if (phoneField && !phoneField.value && data.phone) phoneField.value = data.phone;
            if (emailField && !emailField.value && data.email) emailField.value = data.email;
        })
        .catch(function() {
            alert(<?php echo json_encode(lang('@Could not reach the CVR lookup service.')); ?>);
        })
        .finally(function() {
            icon.className = origClass;
            btn.disabled = false;
        });
}
</script>
<?php
htm_Footer();
ob_end_flush();
?>
