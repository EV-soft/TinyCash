<?php # /customer_edit.php v:1.2.0 d:2026-08-11 i:evs 
# (Opdateret til at bruge htm_ConfirmLink)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ""; $err = "";

// -------------------------------------------------------------------------
// NYT: HÅNDTER SLET-ANMODNING (Kører før gem eller hent)
// -------------------------------------------------------------------------
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $del_id = (int)$_GET['del'];
    
    // Sikkerhedstjek: Hent fakturaer der er tilknyttet kunden
    $check_inv = DB::query($conn, "SELECT invoice_id FROM invoices WHERE cust_id = $del_id");
    if (DB::num_rows($check_inv) > 0) {
        $invoices = [];
        while ($inv = DB::fetch_assoc($check_inv)) {
            $invoices[] = $inv['invoice_id'];
        }
        $inv_list = implode(', ', $invoices);
        $err = lang('@Cannot delete customer: The following invoice ID(s) are linked:') . ' ' . $inv_list;
    } else {
        // Kunden har ingen fakturaer – slet rækken
        if (DB::query($conn, "DELETE FROM customers WHERE cust_id = $del_id")) {
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

    if ($cust_id > 0) {
        $sql = "UPDATE customers SET 
                cust_name = '$name', cust_email = '$email', cust_phone = '$phone', 
                cust_address = '$address', cust_cvr = '$cvr', cust_contact_person = '$contact', 
                cust_notes = '$notes', cust_payment_days = $days
                WHERE cust_id = $cust_id";
    } else {
        $sql = "INSERT INTO customers (cust_name, cust_email, cust_phone, cust_address, cust_cvr, cust_contact_person, cust_notes, cust_payment_days) 
                VALUES ('$name', '$email', '$phone', '$address', '$cvr', '$contact', '$notes', $days)";
    }

    if (DB::query($conn, $sql)) {
        if ($cust_id == 0) $cust_id = DB::insert_id($conn);
        $msg = lang('@Customer saved successfully');
    } else {
        $err = lang('@SQL Error:') . " " . DB::error($conn);
    }
}

// 2. HENT DATA ELLER FORBERED TOM
if ($cust_id > 0) {
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
        htm_InputGroup(icon: 'fa-id-badge', labl: '@ID', name: 'cust_id_display', valu: $cust_id > 0 ? $cust_id : '-', type: 'view', extr: 'align-center', wdth: '15%');
        htm_InputGroup(icon: 'fa-user', labl: '@Customer Name', name: 'cust_name', valu: $cust['cust_name'], extr: 'align-left', wdth: '85%');
    echo "</div>";
    htm_InputGroup(icon: 'fa-map-marker-alt', labl: '@Address', name: 'cust_address', valu: $cust['cust_address'], type: 'textarea', extr: 'align-left', wdth: '100%');
    
    echo "<div style='display:flex; width:100%;'>";
        htm_InputGroup(icon: 'fa-id-card', labl: '@CVR', name: 'cust_cvr', valu: $cust['cust_cvr'], extr: 'align-left', wdth: '33%');
        htm_InputGroup(icon: 'fa-phone', labl: '@Phone', name: 'cust_phone', valu: $cust['cust_phone'], extr: 'align-left', wdth: '33%');
        htm_InputGroup(icon: 'fa-calendar-check', labl: '@Payment Days', name: 'cust_payment_days', valu: $cust['cust_payment_days'], type: 'number', extr: 'min="0" align-left', wdth: '34%');
    echo "</div>";
    
    echo "<div style='display:flex; width:100%;'>";
        htm_InputGroup(icon: 'fa-envelope', labl: '@Email', name: 'cust_email', valu: $cust['cust_email'], type: 'email', extr: 'align-left', wdth: '50%');
        htm_InputGroup(icon: 'fa-user-tie', labl: '@Contact Person', name: 'cust_contact_person', valu: $cust['cust_contact_person'], extr: 'align-left', wdth: '50%');
    echo "</div>";
    
    htm_InputGroup(icon: 'fa-sticky-note', labl: '@Notes', name: 'cust_notes', valu: $cust['cust_notes'], type: 'textarea', extr: 'align-left', wdth: '100%');
    
    echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
        htm_Button(icon: 'fa-save', labl: '@Save Changes', type: 'success', link: '', styl: 'flex:2;', attr: 'onclick="document.getElementById(\'edit_customer_form\').submit();"');
        
        // Erstattet htm_Button+confirmDelete()-JS med htm_ConfirmLink, som
        // escaper bekræftelsesteksten korrekt centralt i php2htm.lib.php.
        if ($cust_id > 0) {
            htm_ConfirmLink(
                icon: 'fa-trash',
                labl: '@Delete',
                link: 'customer_edit.php?id='.$cust_id.'&del='.$cust_id,
                mess: '@Are you sure you want to delete this customer? This cannot be undone.',
                type: 'danger',
                styl: 'flex:1; text-align:center;'
            );
        }
        
        htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'sales_hub.php', styl: 'flex:1;');
    echo "</div>";
    
htm_Card_end();

echo "</div>";

htm_Footer(); 
ob_end_flush();
?>
