<?php # invoice_edit.php v:0.9.1 d:2026-05-08 i:evs
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

// 1. HÅNDTER SLETNING (Fra Sales Hub)
if (isset($_GET['del']) && $_GET['del'] == 1) {
    $inv_id = (int)$_GET['id'];
    mysqli_query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    mysqli_query($conn, "DELETE FROM invoices WHERE inv_id=$inv_id");
    header("Location: sales_hub.php?msg=deleted"); exit;
}

// 2. HÅNDTER GEM / OPDATER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    $inv_id = (int)$_POST['inv_id'];
    $cust_id = (int)$_POST['cust_id']; // Tilføjet så vi kan gemme kunden
    $inv_date = mysqli_real_escape_string($conn, $_POST['inv_date']);
    $inv_due_date = mysqli_real_escape_string($conn, $_POST['inv_due_date']);
    $inv_status = mysqli_real_escape_string($conn, $_POST['inv_status']);
    $inv_note = mysqli_real_escape_string($conn, $_POST['inv_note']);
    $deliv_addr = mysqli_real_escape_string($conn, $_POST['delivery_address']);

    if ($inv_id > 0) { // Opdater eksisterende
        mysqli_query($conn, "UPDATE invoices SET inv_date='$inv_date', inv_due_date='$inv_due_date', inv_status='$inv_status', inv_note='$inv_note', delivery_address='$deliv_addr' WHERE inv_id=$inv_id");
    } else { // Opret ny
        mysqli_query($conn, "INSERT INTO invoices (cust_id, inv_date, inv_due_date, inv_status, inv_note, delivery_address) VALUES ($cust_id, '$inv_date', '$inv_due_date', '$inv_status', '$inv_note', '$deliv_addr')");
        $inv_id = mysqli_insert_id($conn);
    }

    // Gem linjer
    mysqli_query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    if (isset($_POST['line_text']) && is_array($_POST['line_text'])) {
        foreach ($_POST['line_text'] as $k => $v) {
            $txt = mysqli_real_escape_string($conn, $_POST['line_text'][$k]);
            $qty = (float)$_POST['quantity'][$k];
            $prc = (float)$_POST['price_each'][$k];
            $vat = (float)$_POST['line_vat'][$k];
            $pid = (int)$_POST['prod_id'][$k];
            if (!empty($txt)) {
                mysqli_query($conn, "INSERT INTO invoice_lines (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id) VALUES ($inv_id, '$txt', $qty, $prc, $vat, $pid)");
            }
        }
    }
    header("Location: invoice_edit.php?id=$inv_id&msg=saved"); exit;
}

// 3. HENT DATA
$inv_id = (int)($_GET['id'] ?? 0);
if ($inv_id > 0) {
    $inv = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM invoices WHERE inv_id=$inv_id"));
    if (!$inv) die(lang('@Invoice not found'));
} else {
    // Standardværdier for ny faktura
    $inv = [
        'inv_id' => 0, 'cust_id' => 0, 'inv_date' => date('Y-m-d'), 
        'inv_due_date' => date('Y-m-d', strtotime('+14 days')), 
        'inv_status' => 'DRAFT', 'delivery_address' => '', 'inv_note' => ''
    ];
}

// 4. FORBERED TABEL MED LINJER
$res_lines = mysqli_query($conn, "SELECT * FROM invoice_lines WHERE inv_id=$inv_id ORDER BY line_id ASC");
$tbl_data = [];
while ($l = mysqli_fetch_assoc($res_lines)) {
    $tbl_data[] = [
        htm_InputGroup('', '', 'line_text[]', $l['line_text'], 'text', null, '', '100%', '', '', false),
        htm_InputGroup('', '', 'quantity[]', $l['quantity'], 'number', null, 'step="0.01"', '100%', '', '', false),
        htm_InputGroup('', '', 'price_each[]', $l['price_each'], 'number', null, 'step="0.01"', '100%', '', '', false),
        htm_InputGroup('', '', 'line_vat[]', $l['line_vat_rate'], 'number', null, '', '100%', '', '', false) . '<input type="hidden" name="prod_id[]" value="'.$l['prod_id'].'">'
    ];
}
// Tilføj 3 tomme linjer til nye indtastninger
for ($i=0; $i<3; $i++) {
    $tbl_data[] = [
        htm_InputGroup('', '', 'line_text[]', '', 'text', null, '', '100%', '', '', false),
        htm_InputGroup('', '', 'quantity[]', '1', 'number', null, 'step="0.01"', '100%', '', '', false),
        htm_InputGroup('', '', 'price_each[]', '0.00', 'number', null, 'step="0.01"', '100%', '', '', false),
        htm_InputGroup('', '', 'line_vat[]', '25', 'number', null, '', '100%', '', '', false) . '<input type="hidden" name="prod_id[]" value="0">'
    ];
}

// 5. RENDER SIDE
htm_Header(($inv_id > 0 ? lang('@Edit Invoice')." #$inv_id" : lang('@Create New Invoice')));
showMenu();

$tools = htm_Button('fa-list', '@Back to Hub', 'secondary', 'sales_hub.php', '', '', '<div style="display:flex; gap:10px;"></div>', false);

htm_Card_($inv_id > 0 ? '@Edit Invoice' : '@New Invoice', 1000, (isset($_GET['msg']) ? htm_alert(lang('@Changes saved successfully'), 'success', 700, false) : ''), 'edit_form', true, $tools);
echo '<input type="hidden" name="inv_id" value="'.$inv_id.'">';

// Kunde-valg (nødvendigt for nye fakturaer)
$cust_res = mysqli_query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name");
$cust_opt = [];
while($c = mysqli_fetch_assoc($cust_res)) $cust_opt[$c['cust_id']] = $c['cust_name'];
htm_InputGroup('fa-user', '@Customer', 'cust_id', $inv['cust_id'], 'sele', $cust_opt, ($inv_id > 0 ? 'disabled' : ''), '100%');
if ($inv_id > 0) echo '<input type="hidden" name="cust_id" value="'.$inv['cust_id'].'">';

echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_InputGroup('fa-calendar', '@Date', 'inv_date', $inv['inv_date'], 'date', null, '', '33%');
htm_InputGroup('fa-calendar-check', '@Due Date', 'inv_due_date', $inv['inv_due_date'], 'date', null, '', '33%');
htm_InputGroup('fa-info-circle', '@Status', 'inv_status', $inv['inv_status'], 'sele', ['DRAFT'=>lang('@Draft'),'SENT'=>lang('@Sent'),'PAID'=>lang('@Paid'),'VOID'=>lang('@Void')], '', '33%');
echo '</div>';

htm_InputGroup('fa-truck', '@Delivery Address', 'delivery_address', $inv['delivery_address'], 'textarea', null, '', '100%');

echo '<h3 style="margin:20px 0 10px 0;">'.lang('@Invoice Lines').'</h3>';
htm_Table(['@Description', '@Qty', '@Price', '@VAT %'], $tbl_data, 'line_tbl');

htm_InputGroup('fa-sticky-note', '@Internal Note', 'inv_note', $inv['inv_note'], 'textarea', null, '', '100%');

htm_Button('fa-save', '@Save Invoice', 'success', '', '', 'name="save_invoice"', '<div style="margin-top:30px; text-align:right;"></div>');

htm_Card_end();

// if ($inv_id > 0) htm_V2_Nav($inv_id); // Navigationsmenu (View, PDF, etc.)

htm_Footer(); ?>