<?php # /recurring_invoice_edit.php v:1.3.0 d:2026-08-30 i:evs
# Opret/redigér en fast fakturaskabelon (Gentagne/faste fakturaer). Linje-
# editoren (fast 5-rækkers tabel) genbruger nøjagtig samme mønster som
# invoice_edit.php's linjer, for konsistens og for at undgå at genopfinde
# produkt-/moms-opslagslogikken. quantity/price_each/line_vat parses med
# parse_dk_number() (dansk tal-/decimalformat), ikke en naiv (float)-cast.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$recur_id = (int)($_GET['id'] ?? 0);

// --- GEM ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recur'])) {
    $cust_id       = (int)$_POST['cust_id'];
    $interval_type = in_array($_POST['interval_type'], ['monthly', 'quarterly', 'yearly'], true) ? $_POST['interval_type'] : 'monthly';
    $next_run      = DB::escape($conn, $_POST['next_run_date']);
    $due_days      = (int)$_POST['inv_due_days'];
    $is_active     = isset($_POST['is_active']) ? 1 : 0;
    $cust_ref      = DB::escape($conn, $_POST['cust_reference'] ?? '');
    $inv_note      = DB::escape($conn, $_POST['inv_note'] ?? '');
    $deliv         = DB::escape($conn, $_POST['delivery_address'] ?? '');
    $proj_id       = (int)($_POST['proj_id'] ?? 0);
    $proj_sql      = ($proj_id > 0) ? $proj_id : 'NULL';

    if ($recur_id > 0) {
        $save_ok = DB::query($conn, "UPDATE recurring_invoices SET
            cust_id=$cust_id, interval_type='$interval_type', next_run_date='$next_run',
            inv_due_days=$due_days, is_active=$is_active, cust_reference='$cust_ref',
            inv_note='$inv_note', delivery_address='$deliv', proj_id=$proj_sql
            WHERE recur_id=$recur_id");
    } else {
        $save_ok = DB::query($conn, "INSERT INTO recurring_invoices
            (cust_id, interval_type, next_run_date, inv_due_days, is_active, cust_reference, inv_note, delivery_address, proj_id, created_by)
            VALUES ($cust_id, '$interval_type', '$next_run', $due_days, $is_active, '$cust_ref', '$inv_note', '$deliv', $proj_sql, " . (int)($_SESSION['user_id'] ?? 0) . ")");
        if ($save_ok) $recur_id = DB::insert_id($conn);
    }

    if (!$save_ok) {
        die(lang('@Error saving invoice: ') . DB::error($conn));
    }

    DB::query($conn, "DELETE FROM recurring_invoice_lines WHERE recur_id=$recur_id");
    if (isset($_POST['line_text']) && is_array($_POST['line_text'])) {
        foreach ($_POST['line_text'] as $k => $v) {
            $pid = isset($_POST['prod_id'][$k]) ? (int)$_POST['prod_id'][$k] : 0;
            // RETTET (se [[bugs-batch-10-review]]): samme manglende
            // parse_dk_number()-beskyttelse som [[invoice-line-comma-amount-fix]]
            // allerede rettede i invoice_edit.php/expense_edit.php/settings_fees.php.
            $qty = parse_dk_number($_POST['quantity'][$k]);
            $prc = parse_dk_number($_POST['price_each'][$k]);
            $vat = parse_dk_number($_POST['line_vat'][$k]);
            $prod_row = DB::fetch_assoc(DB::query($conn, "SELECT prod_name FROM products WHERE prod_id=$pid"));
            $txt = DB::escape($conn, $prod_row['prod_name'] ?? $_POST['line_text'][$k]);
            if ($pid <= 0 || $qty <= 0) continue;
            DB::query($conn, "INSERT INTO recurring_invoice_lines
                (recur_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id)
                VALUES ($recur_id, '$txt', $qty, $prc, $vat, $pid, $proj_sql)");
        }
    }
    header("Location: recurring_invoice_edit.php?id=$recur_id&msg=saved"); exit;
}

// --- HENT DATA ---
if ($recur_id > 0) {
    $tmpl = DB::fetch_assoc(DB::query($conn, "SELECT * FROM recurring_invoices WHERE recur_id=$recur_id"));
    if (!$tmpl) die(lang('@Invoice not found'));
    $tmpl_lines = [];
    $lines_res = DB::query($conn, "SELECT * FROM recurring_invoice_lines WHERE recur_id=$recur_id ORDER BY rline_id");
    while ($l = DB::fetch_assoc($lines_res)) { $tmpl_lines[] = $l; }
} else {
    $tmpl = [
        'recur_id' => 0, 'cust_id' => 0, 'interval_type' => 'monthly',
        'next_run_date' => date('Y-m-d'), 'inv_due_days' => 8, 'is_active' => 1,
        'cust_reference' => '', 'inv_note' => '', 'delivery_address' => '', 'proj_id' => 0,
    ];
    $tmpl_lines = [];
}

htm_Header($recur_id > 0 ? lang('@Edit Recurring Invoice') : lang('@New Recurring Invoice'));
showMenu();

$tools = htm_Button('fa-list', '@Back to List', 'secondary', 'recurring_invoices.php', '', 'data-hint="'.lang('@Return to the recurring invoices list').'"', '', false);
$card_msg = isset($_GET['msg']) ? htm_Alert(lang('@Changes saved successfully'), 'success', 700, false) : '';
htm_Card_($recur_id > 0 ? '@Edit Recurring Invoice' : '@New Recurring Invoice', 1000, $card_msg, 'edit_form', true, $tools);

echo '<input type="hidden" name="recur_id" value="'.$recur_id.'">';

// Kunde + projekt
$cust_res = DB::query($conn, "SELECT cust_id, cust_name, cust_payment_days FROM customers ORDER BY cust_name");
$cust_opt = []; $cust_days_map = [];
while ($c = DB::fetch_assoc($cust_res)) {
    $cust_opt[$c['cust_id']] = $c['cust_name'];
    $cust_days_map[$c['cust_id']] = (int)($c['cust_payment_days'] ?? 8);
}
htm_Field(icon:'fa-user', labl:'@Customer', name:'cust_id', valu:$tmpl['cust_id'], type:'sele',
    opti:$cust_opt, extr:'id="cust_sel" onchange="updateDueDays()"', wdth:'70%', echo:true);
htm_ProjektCodeField($conn, (int)($tmpl['proj_id'] ?: 0) ?: null, '30%');

// Interval + næste kørsel + betalingsfrist + aktiv
echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_Field(icon:'fa-sync', labl:'@Repeat Interval', name:'interval_type', valu:$tmpl['interval_type'], type:'sele',
    opti:['monthly'=>lang('@Monthly'),'quarterly'=>lang('@Quarterly'),'yearly'=>lang('@Yearly')], echo:true);
htm_Field(icon:'fa-calendar-plus', labl:'@Next Run Date', name:'next_run_date', valu:$tmpl['next_run_date'], type:'date',
    hint:'@The date the first (or next) draft invoice will be generated', echo:true);
htm_Field(icon:'fa-calendar-check', labl:'@Payment Days', name:'inv_due_days', valu:$tmpl['inv_due_days'], type:'number', extr:'id="due_days_field" min="0"', echo:true);
echo '</div>';

echo '<label style="display:flex; align-items:center; gap:8px; margin:10px 0 20px; cursor:pointer;">';
echo '<input type="checkbox" name="is_active" value="1" '.($tmpl['is_active'] ? 'checked' : '').' style="width:18px; height:18px;">';
echo '<span>'.lang('@Active - generate invoices automatically on schedule').'</span></label>';

htm_Field(icon:'fa-id-badge', labl:'@Customer Reference', name:'cust_reference', valu:$tmpl['cust_reference'] ?? '', type:'text', hint:'@E.g. order number, contact person or EAN', echo:true);
htm_Field(icon:'fa-comment',  labl:'@Delivery Address',   name:'delivery_address', valu:$tmpl['delivery_address'], type:'textarea', echo:true);

echo '<h3 style="margin:20px 0 10px 0;">'.lang('@Invoice Lines').'</h3>';

$prod_res = DB::query($conn, "SELECT p.prod_id, p.prod_name, p.prod_price, a.vat_rate FROM products p LEFT JOIN accounts a ON p.acc_id = a.acc_id ORDER BY p.prod_name");
$prod_opt = [0 => '-- '.lang('@Select Product/Description').' --'];
$prod_vat_map = [0 => 25]; $prod_price_map = [0 => 0.00];
while ($p = DB::fetch_assoc($prod_res)) {
    $prod_opt[$p['prod_id']] = $p['prod_name'];
    $prod_vat_map[$p['prod_id']]   = ($p['vat_rate']   !== null) ? (float)$p['vat_rate']   : 25.00;
    $prod_price_map[$p['prod_id']] = ($p['prod_price'] !== null) ? (float)$p['prod_price'] : 0.00;
}

$tbl_data = [];
for ($i = 0; $i < 5; $i++) {
    $prod_id    = isset($tmpl_lines[$i]['prod_id'])       ? (int)$tmpl_lines[$i]['prod_id']       : 0;
    $line_qty   = isset($tmpl_lines[$i]['quantity'])      ? $tmpl_lines[$i]['quantity']            : '';
    $line_price = isset($tmpl_lines[$i]['price_each'])    ? $tmpl_lines[$i]['price_each']          : '';
    $line_vat   = isset($tmpl_lines[$i]['line_vat_rate']) ? $tmpl_lines[$i]['line_vat_rate']       : 25;

    $f_prod  = '<input type="hidden" name="prod_id[]" id="prod_id_'.$i.'" value="'.$prod_id.'">';
    $f_text  = htm_Field('', '', 'line_text[]', $prod_id, 'sele', $prod_opt, 'onchange="updateVatRate(this, '.$i.')"', '100%', '', '', '', false) . $f_prod;
    $f_qty   = htm_Field('', '', 'quantity[]',   $line_qty,   'number', null, 'step="any"', '100%', '', '', '', false);
    $f_price = htm_Field('', '', 'price_each[]', $line_price, 'number', null, 'step="any"', '100%', '', '', '', false);
    $f_vat   = htm_Field('', '', 'line_vat[]',   $line_vat,   'number', null, 'id="line_vat_'.$i.'"', '100%', '', '', '', false);
    $tbl_data[] = [$f_text, $f_qty, $f_price, $f_vat];
}

htm_Table(['@Description', '@Qty', '@Price', '@VAT %'], $tbl_data, 'line_tbl', 25, '', true,
    ['width:55%;', 'width:15%;', 'width:15%;', 'width:15%;']);

// Totaler (kun firmaets bogføringsvaluta - fremmed valuta er ikke
// understøttet for faste fakturaer i denne version, se recurring-invoices)
// RETTET (§currency-setting-is-cosmetic-label): etiketten var hardkodet
// "DKK" uanset firmaets faktisk konfigurerede valuta.
$default_currency = $global_settings['currency'] ?? 'DKK';
echo '<div style="margin:20px 0; padding:15px; background:#f8f9fa; border-radius:6px; max-width:350px; margin-left:auto;">
    <table style="width:100%; font-size:1.05em; border-collapse:collapse;">
        <tr><td style="padding:4px 0; color:#666;">'.lang('@Subtotal').':</td><td style="padding:4px 0; text-align:right; font-weight:bold;" id="total_sub">0,00</td><td style="padding:4px 0 4px 8px; color:#666; width:40px;">'.htmlspecialchars($default_currency).'</td></tr>
        <tr><td style="padding:4px 0; color:#666;">'.lang('@VAT Total').':</td><td style="padding:4px 0; text-align:right; font-weight:bold; color:#7f8c8d;" id="total_vat">0,00</td><td style="padding:4px 0 4px 8px; color:#666;">'.htmlspecialchars($default_currency).'</td></tr>
        <tr style="border-top:2px solid #ddd; font-size:1.2em;"><td style="padding:10px 0 0 0; font-weight:bold; color:#2c3e50;">'.lang('@Total').':</td><td style="padding:10px 0 0 0; text-align:right; font-weight:bold; color:#27ae60;" id="total_grand">0,00</td><td style="padding:10px 0 0 8px; font-weight:bold; color:#2c3e50;">'.htmlspecialchars($default_currency).'</td></tr>
    </table>
</div>';

htm_Field(icon:'fa-sticky-note', labl:'@Note', name:'inv_note', valu:$tmpl['inv_note'], type:'textarea', echo:true);
htm_Button(icon:'fa-save', labl:'@Save Invoice', type:'success', attr:'name="save_recur" data-hint="'.lang('@Save this recurring invoice template').'"', cont:'<div style="margin-top:30px; text-align:right;"></div>');
htm_Card_end();
?>
<script>
const vatMap      = <?php echo json_encode($prod_vat_map); ?>;
const priceMap    = <?php echo json_encode($prod_price_map); ?>;
const custDaysMap = <?php echo json_encode($cust_days_map); ?>;

function updateDueDays() {
    const sel = document.getElementById('cust_sel');
    const days = custDaysMap[sel.value];
    const field = document.getElementById('due_days_field');
    if (days !== undefined && field && field.value == '') field.value = days;
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
