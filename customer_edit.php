<?php # /customer_edit.php v:1.3.0 d:2026-08-30 i:evs
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

    if (!empty($linked_invoices)) {
        $err = lang('@Cannot delete customer: The following invoice ID(s) are linked:') . ' ' . implode(', ', $linked_invoices);
    } elseif (!empty($linked_projects)) {
        $err = lang('@Cannot delete customer: The following project ID(s) are linked:') . ' ' . implode(', ', $linked_projects);
    } elseif (!empty($linked_quotes)) {
        $err = lang('@Cannot delete customer: The following quote ID(s) are linked:') . ' ' . implode(', ', $linked_quotes);
    } else {
        // Hent kundens data FØR sletning, til revisionssporet.
        $old_res = DB::query($conn, "SELECT cust_id, cust_name, cust_email, cust_cvr FROM customers WHERE cust_id = $del_id");
        $old_row = $old_res ? DB::fetch_assoc($old_res) : null;

        // Kunden har ingen fakturaer eller projekter – slet rækken
        if (DB::query($conn, "DELETE FROM customers WHERE cust_id = $del_id")) {
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
    $days    = (int)$_POST['cust_payment_days'];

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
                cust_notes = '$notes', cust_payment_days = $days
                WHERE cust_id = $cust_id";
    } else {
        $sql = "INSERT INTO customers (cust_name, cust_email, cust_phone, cust_address, cust_cvr, cust_contact_person, cust_notes, cust_payment_days) 
                VALUES ('$name', '$email', '$phone', '$address', '$cvr', '$contact', '$notes', $days)";
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
        'cust_notes' => $_POST['cust_notes'] ?? '', 'cust_payment_days' => (int)($_POST['cust_payment_days'] ?? 8),
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
        'cust_cvr' => '', 'cust_contact_person' => '', 'cust_notes' => '', 'cust_payment_days' => 8
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

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

echo "<div style='margin: 20px auto; width: fit-content;'>";
htm_Card_(capt: '@Customer Information', wdth: 550, form: 'edit_customer_form');
    echo "<div style='display:flex; width:100%; gap:10px;'>";
        htm_Field(icon: 'fa-id-badge', labl: '@ID', name: 'cust_id_display', valu: $cust_id > 0 ? $cust_id : '-', type: 'view', extr: 'align-center', wdth: '15%');
        htm_Field(icon: 'fa-user', labl: '@Customer Name', name: 'cust_name', valu: $cust['cust_name'], extr: 'align-left', wdth: '85%');
    echo "</div>";
    htm_Field(icon: 'fa-map-marker-alt', labl: '@Address', name: 'cust_address', valu: $cust['cust_address'], type: 'textarea', extr: 'align-left', wdth: '100%');
    
    echo "<div style='display:flex; width:100%; gap:6px; align-items:flex-end;'>";
        echo '<div style="width:33%; display:flex; gap:4px; align-items:flex-end;">';
        echo '<div style="flex:1;">';
        htm_Field(icon: 'fa-id-card', labl: '@CVR', name: 'cust_cvr', valu: $cust['cust_cvr'], extr: 'align-left', wdth: '100%');
        echo '</div>';
        // NYT (bruger-anmodet, CVR-opslag): slår CVR-nummeret op via
        // cvr_proxy.php (cvrapi.dk) og udfylder navn/adresse/telefon/email
        // automatisk - se cvrLookup() nedenfor. type="button" (ikke
        // htm_Button(), som kun kan bygge type="submit" når $link er tom -
        // ville sende hele formularen af sted ved et klik her, se samme
        // fravalg i htm-table-button-refactor.md for invoice_edit.php).
        echo '<button type="button" onclick="cvrLookup(this)" style="flex-shrink:0; margin-bottom:2px; padding:9px 10px; background:var(--color-info); color:var(--text-light); border:none; border-radius:4px; cursor:pointer;" data-hint="'.lang('@Look up company name, address, phone and email from the CVR number').'"><i class="fa-solid fa-magnifying-glass"></i></button>';
        echo '</div>';
        htm_Field(icon: 'fa-phone', labl: '@Phone', name: 'cust_phone', valu: $cust['cust_phone'], extr: 'align-left', wdth: '33%');
        htm_Field(icon: 'fa-calendar-check', labl: '@Payment Days', name: 'cust_payment_days', valu: $cust['cust_payment_days'], type: 'number', extr: 'min="0" align-left', wdth: '34%');
    echo "</div>";
    
    echo "<div style='display:flex; width:100%;'>";
        htm_Field(icon: 'fa-envelope', labl: '@Email', name: 'cust_email', valu: $cust['cust_email'], type: 'email', extr: 'align-left', wdth: '50%');
        htm_Field(icon: 'fa-user-tie', labl: '@Contact Person', name: 'cust_contact_person', valu: $cust['cust_contact_person'], extr: 'align-left', wdth: '50%');
    echo "</div>";
    
    htm_Field(icon: 'fa-sticky-note', labl: '@Notes', name: 'cust_notes', valu: $cust['cust_notes'], type: 'textarea', extr: 'align-left', wdth: '100%');
    
    echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
        htm_Button(icon: 'fa-save', labl: '@Save Changes', type: 'success', link: '', styl: 'flex:2;', attr: 'onclick="document.getElementById(\'edit_customer_form\').submit();" data-hint="'.lang('@Save this customer').'"');

        if ($cust_id > 0) {
            htm_Button(icon: 'fa-file-invoice-dollar', labl: '@Account Statement', type: 'info', link: 'customer_statement.php?id='.$cust_id, styl: 'flex:1;', attr: 'data-hint="'.lang('@View this customer\'s invoice and payment history').'"');
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

echo "</div>";
?>
<script>
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
