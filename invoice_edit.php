<?php # invoice_edit.php v:1.1.0 d:2026-07-05 i:evs
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

// 1. HÅNDTER SLETNING
if (isset($_GET['del']) && $_GET['del'] == 1) {
    $inv_id = (int)$_GET['id'];
    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    DB::query($conn, "DELETE FROM invoices WHERE inv_id=$inv_id");
    header("Location: sales_hub.php?msg=deleted"); exit;
}

// 2. HÅNDTER GEM / OPDATER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    $inv_id = (int)$_POST['inv_id'];
    $cust_id = (int)$_POST['cust_id']; 
    $inv_date = DB::real_escape_string($conn, $_POST['inv_date']);
    $inv_due_date = DB::real_escape_string($conn, $_POST['inv_due_date']);
    $inv_status = DB::real_escape_string($conn, $_POST['inv_status']);
    $inv_note = DB::real_escape_string($conn, $_POST['inv_note']);
    $deliv_addr = DB::real_escape_string($conn, $_POST['delivery_address']);

    if ($inv_id > 0) {
        DB::query($conn, "UPDATE invoices SET inv_date='$inv_date', inv_due_date='$inv_due_date', inv_status='$inv_status', inv_note='$inv_note', delivery_address='$deliv_addr' WHERE inv_id=$inv_id");
    } else {
        DB::query($conn, "INSERT INTO invoices (cust_id, inv_date, inv_due_date, inv_status, inv_note, delivery_address) VALUES ($cust_id, '$inv_date', '$inv_due_date', '$inv_status', '$inv_note', '$deliv_addr')");
        $inv_id = DB::insert_id($conn);
    }

    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    if (isset($_POST['line_text']) && is_array($_POST['line_text'])) {
        foreach ($_POST['line_text'] as $k => $v) {
            $pid = isset($_POST['prod_id'][$k]) ? (int)$_POST['prod_id'][$k] : 0;
            $qty = (float)$_POST['quantity'][$k];
            $prc = (float)$_POST['price_each'][$k];
            $vat = (float)$_POST['line_vat'][$k];
            
            // Hvis der gemmes rå tekst, henter vi den - ellers trækker vi værdien fra dropdownen
            $txt = DB::real_escape_string($conn, $_POST['line_text'][$k]);

            // =========================================================================
            // SKAN OG AFVIS: Raw data filter (Uafhængig af sprog og oversættelser)
            // =========================================================================
            if ($pid <= 0 || $qty <= 0) {
                continue; // Springer tomme rækker/placeholders over, så de ikke gemmes
            }

            DB::query($conn, "INSERT INTO invoice_lines (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id) VALUES ($inv_id, '$txt', $qty, $prc, $vat, $pid)");
        }
    }
    header("Location: invoice_edit.php?id=$inv_id&msg=saved"); exit;
}

// 3. HENT DATA
$inv_id = (int)($_GET['id'] ?? 0);
$inv_lines = [];

if ($inv_id > 0) {
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT * FROM invoices WHERE inv_id=$inv_id"));
    if (!$inv) die(lang('@Invoice not found'));
    
    // Hent eksisterende linjer hvis vi redigerer
    $lines_res = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id=$inv_id ORDER BY line_id");
    while ($l = DB::fetch_assoc($lines_res)) {
        $inv_lines[] = $l;
    }
} else {
    $inv = [
        'inv_id' => 0, 'cust_id' => 0, 'inv_date' => date('Y-m-d'), 
        'inv_due_date' => date('Y-m-d', strtotime('+14 days')), 
        'inv_status' => 'DRAFT', 'delivery_address' => '', 'inv_note' => ''
    ];
}

// 5. RENDER SIDE
htm_Header(($inv_id > 0 ? lang('@Edit Invoice')." #$inv_id" : lang('@Create New Invoice')));
showMenu();

$tools = htm_Button('fa-list', '@Back to Hub', 'secondary', 'sales_hub.php', '', '', '<div style="display:flex; gap:10px;"></div>', false);

htm_Card_($inv_id > 0 ? '@Edit Invoice' : '@New Invoice', 1000, (isset($_GET['msg']) ? htm_Alert(lang('@Changes saved successfully'), 'success', 700, false) : ''), 'edit_form', true, $tools);
echo '<input type="hidden" name="inv_id" value="'.$inv_id.'">';

// Kunde-valg
$cust_res = DB::query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name");
$cust_opt = [];
if ($cust_res && DB::num_rows($cust_res) > 0) {
    while($c = DB::fetch_assoc($cust_res)) {
        $cust_opt[$c['cust_id']] = $c['cust_name'];
    }
} else {
    $cust_opt[0] = '-- ' . lang('@No customers created yet') . ' --';
}

htm_InputGroup(icon: 'fa-user', labl: '@Customer', name: 'cust_id', valu: $inv['cust_id'], type: 'sele', opti: $cust_opt, extr: ($inv_id > 0 ? 'disabled' : ''), echo: true);
if ($inv_id > 0) echo '<input type="hidden" name="cust_id" value="'.$inv['cust_id'].'">';

echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_InputGroup(icon: 'fa-calendar', labl: '@Invoice Date', name: 'inv_date', valu: $inv['inv_date'], type: 'date', echo: true);
htm_InputGroup(icon: 'fa-calendar-check', labl: '@Due Date', name: 'inv_due_date', valu: $inv['inv_due_date'], type: 'date', echo: true);

htm_InputGroup(icon: 'fa-toggle-on', labl: '@Status', name: 'inv_status', valu: $inv['inv_status'], type: 'sele', opti: ['DRAFT'=>lang('@Draft'),'SENT'=>lang('@Sent'),'PAID'=>lang('@Paid'),'VOID'=>lang('@Void')], echo: true);
echo '</div>';

htm_InputGroup(icon: 'fa-comment', labl: '@Delivery Address', name: 'delivery_address', valu: $inv['delivery_address'], type: 'textarea', echo: true);

echo '<h3 style="margin:20px 0 10px 0;">'.lang('@Invoice Lines').'</h3>';

// Hent produkter til dropdown-opslag - nu inklusiv salgspris
$prod_res = DB::query($conn, "
    SELECT p.prod_id, p.prod_name, p.prod_price, a.vat_rate 
    FROM products p
    LEFT JOIN accounts a ON p.acc_id = a.acc_id
    ORDER BY p.prod_name
");

if (!$prod_res) {
    die("<div style='color:red; font-weight:bold; padding:20px; background:#fff; border:2px solid red;'>
        SQL Fejl: " . DB::error($conn) . "
    </div>");
}

$prod_opt = [0 => '-- ' . lang('@Select Product/Description') . ' --'];
$prod_vat_map = [0 => 25]; 
$prod_price_map = [0 => 0.00]; 

if (DB::num_rows($prod_res) > 0) {
    while($p = DB::fetch_assoc($prod_res)) {
        $prod_opt[$p['prod_id']] = $p['prod_name'];
        $prod_vat_map[$p['prod_id']] = ($p['vat_rate'] !== null) ? (float)$p['vat_rate'] : 25.00;
        $prod_price_map[$p['prod_id']] = ($p['prod_price'] !== null) ? (float)$p['prod_price'] : 0.00;
    }
}

// BYG TABEL-DATA VIA LØKKE (Sikret mod PHP 8 crash)
$tbl_data = [];
$cur = $_SESSION['currency'] ?? 'DKK';

for ($i = 0; $i < 5; $i++) {
    $prod_id    = isset($inv_lines[$i]['prod_id'])    ? (int)$inv_lines[$i]['prod_id'] : 0;
    $line_qty   = isset($inv_lines[$i]['quantity'])   ? $inv_lines[$i]['quantity']  : '';
    $line_price = isset($inv_lines[$i]['price_each']) ? $inv_lines[$i]['price_each'] : '';
    $line_vat   = isset($inv_lines[$i]['line_vat_rate']) ? $inv_lines[$i]['line_vat_rate'] : 25;

    $f_prod  = '<input type="hidden" name="prod_id[]" id="prod_id_'.$i.'" value="'.$prod_id.'">';

    // Overfør værdien som $prod_id, så dropdown vælger det gemte produkt korrekt ved redigering
    $f_text  = htm_InputGroup('', '', 'line_text[]', $prod_id, 'sele', $prod_opt, 'onchange="updateVatRate(this, '.$i.')"', '100%', '', '', '', false) . $f_prod;
    
    $f_qty   = htm_InputGroup('', '', 'quantity[]', $line_qty, 'number', null, 'step="any"', '100%', '', '', '', false);
    $f_price = htm_InputGroup('', '', 'price_each[]', $line_price, 'number', null, 'step="any"', '100%', '', '', '', false);
    $f_vat   = htm_InputGroup('', '', 'line_vat[]', $line_vat, 'number', null, 'id="line_vat_'.$i.'"', '100%', '', '', '', false);

    $tbl_data[] = [$f_text, $f_qty, $f_price, $f_vat];
}

$col_settings = ['width:55%;', 'width:15%;', 'width:15%;', 'width:15%;'];
htm_Table(['@Description', '@Qty', '@Price', '@VAT %'], $tbl_data, 'line_tbl', 25, '', true, $col_settings);

// Visning af faktura-totaler
echo '<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 6px; border: 1px solid #eee; max-width: 350px; margin-left: auto;">
        <table style="width: 100%; font-size: 1.05em; border-collapse: collapse;">
            <tr>
                <td style="padding: 4px 0; color: #666;">'.lang('@Subtotal').':</td>
                <td style="padding: 4px 0; text-align: right; font-weight: bold;" id="total_sub">0,00</td>
                <td style="padding: 4px 0 4px 8px; color: #666; width: 40px;">'.$cur.'</td>
            </tr>
            <tr>
                <td style="padding: 4px 0; color: #666;">'.lang('@VAT Total').':</td>
                <td style="padding: 4px 0; text-align: right; font-weight: bold; color: #7f8c8d;" id="total_vat">0,00</td>
                <td style="padding: 4px 0 4px 8px; color: #666;">'.$cur.'</td>
            </tr>
            <tr style="border-top: 2px solid #ddd; font-size: 1.2em;">
                <td style="padding: 10px 0 0 0; font-weight: bold; color: #2c3e50;">'.lang('@Total').':</td>
                <td style="padding: 10px 0 0 0; text-align: right; font-weight: bold; color: #27ae60;" id="total_grand">0,00</td>
                <td style="padding: 10px 0 0 8px; font-weight: bold; color: #2c3e50;">'.$cur.'</td>
            </tr>
        </table>
      </div>';
      
htm_InputGroup(icon: 'fa-sticky-note', labl: '@Note', name: 'inv_note', valu: $inv['inv_note'], type: 'textarea', echo: true);

htm_Button(icon: 'fa-save', labl: '@Save Invoice', type: 'success', attr: 'name="save_invoice"', cont: '<div style="margin-top:30px; text-align:right;"></div>');

htm_Card_end();
?>

<script>
const vatMap = <?php echo json_encode($prod_vat_map); ?>;
const priceMap = <?php echo json_encode($prod_price_map); ?>;

function updateVatRate(selectElement, rowIndex) {
    const selectedProdId = selectElement.value;
    
    const prodHiddenFields = document.getElementsByName('prod_id[]');
    if (prodHiddenFields[rowIndex]) {
        prodHiddenFields[rowIndex].value = selectedProdId;
    }
    
    const correctVat = vatMap[selectedProdId] !== undefined ? vatMap[selectedProdId] : 25;
    const vatFields = document.getElementsByName('line_vat[]');
    if (vatFields[rowIndex]) {
        vatFields[rowIndex].value = correctVat;
    }

    const correctPrice = priceMap[selectedProdId] !== undefined ? priceMap[selectedProdId] : 0.00;
    const priceFields = document.getElementsByName('price_each[]');
    if (priceFields[rowIndex]) {
        priceFields[rowIndex].value = correctPrice;
    }

    calculateInvoiceTotals();
}

function calculateInvoiceTotals() {
    const qtyFields = document.getElementsByName('quantity[]');
    const priceFields = document.getElementsByName('price_each[]');
    const vatFields = document.getElementsByName('line_vat[]');

    let subtotal = 0;
    let totalVat = 0;

    for (let i = 0; i < qtyFields.length; i++) {
        let qty = parseFloat(qtyFields[i].value.toString().replace(',', '.')) || 0;
        let price = parseFloat(priceFields[i].value.toString().replace(',', '.')) || 0;
        let vatRate = parseFloat(vatFields[i].value.toString().replace(',', '.')) || 0;

        let lineSub = qty * price;
        let lineVat = lineSub * (vatRate / 100);

        subtotal += lineSub;
        totalVat += lineVat;
    }

    let grandTotal = subtotal + totalVat;

    const formatConfig = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    document.getElementById('total_sub').innerText = subtotal.toLocaleString('da-DK', formatConfig);
    document.getElementById('total_vat').innerText = totalVat.toLocaleString('da-DK', formatConfig);
    document.getElementById('total_grand').innerText = grandTotal.toLocaleString('da-DK', formatConfig);
}

document.addEventListener('input', function(e) {
    if (e.target.name === 'quantity[]' || e.target.name === 'price_each[]' || e.target.name === 'line_vat[]') {
        calculateInvoiceTotals();
    }
});

document.addEventListener('DOMContentLoaded', calculateInvoiceTotals);
</script>

<?php
htm_Footer(); 
?>