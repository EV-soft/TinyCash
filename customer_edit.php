<?php # /customer_edit.php v:0.9.2 d:2026-06-07 i:evs
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
    
    // Sikkerhedstjek: Har kunden aktive fakturaer?
    $check_inv = DB::query($conn, "SELECT COUNT(*) as total FROM invoices WHERE cust_id = $del_id");
    $inv_row = DB::fetch_assoc($check_inv);
    
    if ($inv_row['total'] > 0) {
        $err = lang('@Cannot delete customer: This customer has active invoices linked to them.');
    } else {
        // Kunden har ingen fakturaer – slet rækken
        if (DB::query($conn, "DELETE FROM customers WHERE cust_id = $del_id")) {
            // STOP SCRIPTET HER OG SKIFT SIDE
            header("Location: sales_hub.php");
            exit; // <-- SIKRER AT PHP IKKE LÆSER VIDERE OG GEMMER EN NY TOM KUNDE
        } else {
            $err = lang('@SQL Error:') . " " . DB::error($conn);
        }
    }
}

// 1. HÅNDTER GEM (Både ny og opdatering)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = DB::real_escape_string($conn, $_POST['cust_name']);
    $email   = DB::real_escape_string($conn, $_POST['cust_email']);
    $phone   = DB::real_escape_string($conn, $_POST['cust_phone']);
    $address = DB::real_escape_string($conn, $_POST['cust_address']);
    $cvr     = DB::real_escape_string($conn, $_POST['cust_cvr']);
    $contact = DB::real_escape_string($conn, $_POST['cust_contact_person']);
    $notes   = DB::real_escape_string($conn, $_POST['cust_notes']);
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
$display_name = !empty($cust['cust_name']) ? $cust['cust_name'] : '[Empty Row / Ghost Customer]';
$title = $cust_id > 0 ? lang('@Edit Customer') . ": " . $display_name : lang('@Add New Customer');
htm_Header($title);
showMenu();

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

echo "<div style='margin: 20px auto; width: fit-content;'>";
htm_Card_(capt: '@Customer Information', wdth: 550, form: 'edit_customer_form');
    
    htm_InputGroup(icon: 'fa-user', labl: '@Customer Name', name: 'cust_name', valu: $cust['cust_name'], extr: 'align-left', wdth: '100%');
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
        
        // RETTET ATTR: type="button" er flyttet frem, så den blokerer for form-submission
        if ($cust_id > 0) {
            htm_Button(icon: 'fa-trash', labl: '@Delete', type: 'danger', link: '', styl: 'flex:1;', attr: 'type="button" onclick="confirmDelete('.$cust_id.');"');
        }
        
        htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'sales_hub.php', styl: 'flex:1;');
    echo "</div>";
    
htm_Card_end();

echo "</div>";

// JavaScript til slet-bekræftelse med engelske parametre til sproghåndtering
echo '<script>
function confirmDelete(id) {
    if (confirm("' . lang('@Are you sure you want to delete this customer? This cannot be undone.') . '")) {
        window.location.href = "customer_edit.php?id=" + id + "&del=" + id;
    }
}
</script>';

htm_Footer(); 
ob_end_flush();
?>
