<?php # invoice_edit.php v:1.2.0 d:2026-08-11 i:evs 
# (Valuta-sektion gates af module_currency; bevarer data når slået fra)
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
    $inv_id       = (int)$_POST['inv_id'];
    $cust_id      = (int)$_POST['cust_id'];
    $inv_date     = DB::escape($conn, $_POST['inv_date']);
    $inv_due_date = DB::escape($conn, $_POST['inv_due_date']);
    $inv_status   = DB::escape($conn, $_POST['inv_status']);
    $cust_ref     = DB::escape($conn, $_POST['cust_reference'] ?? '');
    $inv_note     = DB::escape($conn, $_POST['inv_note']);
    $deliv_addr   = DB::escape($conn, $_POST['delivery_address']);
    $proj_id      = (int)($_POST['proj_id'] ?? 0);
    $proj_sql     = ($proj_id > 0) ? $proj_id : 'NULL';

    // Valuta — fremmed valuta med kurs
    $post_currency      = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['currency'] ?? 'DKK'));
    $post_orig_currency = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['orig_currency'] ?? ''));
    $post_exch_rate     = (float)str_replace(',', '.', $_POST['exch_rate'] ?? '');

    if ($post_orig_currency !== '' && $post_orig_currency !== 'DKK' && $post_exch_rate > 0) {
        $currency     = $post_orig_currency; // fakturaen vises i original valuta
        $orig_currency_sql = "'".DB::escape($conn, $post_orig_currency)."'";
        $exch_rate_sql     = $post_exch_rate;
    } else {
        $currency          = $post_currency ?: 'DKK';
        $orig_currency_sql = 'NULL';
        $exch_rate_sql     = 'NULL';
    }
    $db_currency = DB::escape($conn, $currency);

    if ($inv_id > 0) {
        DB::query($conn, "UPDATE invoices SET
            inv_date='$inv_date', inv_due_date='$inv_due_date', inv_status='$inv_status',
            cust_reference='$cust_ref', inv_note='$inv_note', delivery_address='$deliv_addr',
            currency='$db_currency', proj_id=$proj_sql,
            orig_currency=$orig_currency_sql, exch_rate=$exch_rate_sql
            WHERE inv_id=$inv_id");
    } else {
        DB::query($conn, "INSERT INTO invoices
            (cust_id, inv_date, inv_due_date, inv_status, cust_reference, inv_note,
             delivery_address, currency, proj_id, orig_currency, exch_rate)
            VALUES ($cust_id, '$inv_date', '$inv_due_date', '$inv_status', '$cust_ref', '$inv_note',
             '$deliv_addr', '$db_currency', $proj_sql, $orig_currency_sql, $exch_rate_sql)");
        $inv_id = DB::insert_id($conn);
    }

    // Linjer
    DB::query($conn, "DELETE FROM invoice_lines WHERE inv_id=$inv_id");
    if (isset($_POST['line_text']) && is_array($_POST['line_text'])) {
        foreach ($_POST['line_text'] as $k => $v) {
            $pid = isset($_POST['prod_id'][$k]) ? (int)$_POST['prod_id'][$k] : 0;
            $qty = (float)$_POST['quantity'][$k];
            $prc = (float)$_POST['price_each'][$k];
            $vat = (float)$_POST['line_vat'][$k];
            $prod_row = DB::fetch_assoc(DB::query($conn, "SELECT prod_name FROM products WHERE prod_id=$pid"));
            $txt = DB::escape($conn, $prod_row['prod_name'] ?? $_POST['line_text'][$k]);
            if ($pid <= 0 || $qty <= 0) continue;
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
        'inv_status' => 'DRAFT', 'delivery_address' => '', 'cust_reference' => '',
        'inv_note' => '', 'currency' => $default_currency, 'proj_id' => 0,
        'orig_currency' => null, 'exch_rate' => null
    ];
}

$cur          = $inv['currency']      ?? $default_currency;
$proj_id      = (int)($inv['proj_id']  ?? 0);
$orig_currency = $inv['orig_currency'] ?? null;
$exch_rate     = $inv['exch_rate']    ?? null;

// 4. RENDER SIDE
htm_Header($inv_id > 0 ? lang('@Edit Invoice')." #$inv_id" : lang('@Create New Invoice'));
showMenu();

$tools = htm_Button('fa-list', '@Back to Hub', 'secondary', 'sales_hub.php', '', '', '<div style="display:flex; gap:10px;"></div>', false);
htm_Card_($inv_id > 0 ? '@Edit Invoice' : '@New Invoice', 1000,
    (isset($_GET['msg']) ? htm_Alert(lang('@Changes saved successfully'), 'success', 700, false) : ''),
    'edit_form', true, $tools);

echo '<input type="hidden" name="inv_id" value="'.$inv_id.'">';

// Kunde + projekt
$cust_res = DB::query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name");
$cust_opt = [];
while ($c = DB::fetch_assoc($cust_res)) { $cust_opt[$c['cust_id']] = $c['cust_name']; }
htm_InputGroup(icon:'fa-user', labl:'@Customer', name:'cust_id', valu:$inv['cust_id'], type:'sele',
    opti:$cust_opt, extr:($inv_id > 0 ? 'disabled' : ''), wdth:'70%', echo:true);
htm_ProjektCodeField($conn, $proj_id ?: null, '30%');
if ($inv_id > 0) echo '<input type="hidden" name="cust_id" value="'.$inv['cust_id'].'">';

// Datoer + status
echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_InputGroup(icon:'fa-calendar',       labl:'@Invoice Date', name:'inv_date',     valu:$inv['inv_date'],     type:'date', echo:true);
htm_InputGroup(icon:'fa-calendar-check', labl:'@Due Date',     name:'inv_due_date', valu:$inv['inv_due_date'], type:'date', echo:true);
htm_InputGroup(icon:'fa-toggle-on',      labl:'@Status',       name:'inv_status',   valu:$inv['inv_status'],   type:'sele',
    opti:['DRAFT'=>lang('@Draft'),'SENT'=>lang('@Sent'),'PAID'=>lang('@Paid'),'VOID'=>lang('@Void')], echo:true);
echo '</div>';

// ── Valuta-sektion (kun når valuta-modulet er aktivt) ───────────────────────────
$fc_checked = ($orig_currency && $orig_currency !== 'DKK') ? 'checked' : '';
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
$currencies = ['EUR','USD','GBP','SEK','NOK','CHF','JPY','CAD','AUD','PLN','CZK','HUF','RON','ISK'];
foreach ($currencies as $c) {
    $sel = ($orig_currency === $c || $cur === $c) ? ' selected' : '';
    echo '<option value="'.$c.'"'.$sel.'>'.$c.'</option>';
}
echo '</select></div>';

// Kurs
echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Exchange rate to DKK').' <span id="fc-rate-date" style="font-weight:normal; font-size:10px; color:var(--text-muted);"></span></label>';
echo '<div style="display:flex; gap:4px;">';
echo '<input type="text" name="exch_rate" id="fc-rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'"
    placeholder="0,0000" oninput="updateCurrencyHidden()"
    style="flex:1; padding:7px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); text-align:right;">';
echo '<button type="button" onclick="fetchRate()" title="'.lang('@Fetch current rate').'"
    style="padding:7px 10px; background:var(--color-primary); color:white; border:none; border-radius:4px; cursor:pointer;">↻</button>';
echo '</div></div>';

// Info
echo '<div style="font-size:11px; color:var(--text-muted); padding-bottom:4px;">'.lang('@Invoice lines are entered in foreign currency. DKK equivalent is calculated on posting.').'</div>';

// Nulstil
echo '<div><button type="button" onclick="clearFc()" style="padding:7px 12px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; cursor:pointer; color:var(--text-muted); font-size:12px;">'.lang('@Clear').'</button></div>';
echo '</div>'; // fc-fields

// Gem kurs-info ved eksisterende faktura
if ($exch_rate && $orig_currency) {
    echo '<div style="margin-top:6px; font-size:11px; color:var(--text-muted);">';
    echo '<i class="fa fa-info-circle" style="color:var(--color-primary);"></i> ';
    echo lang('@Saved rate').': 1 '.htmlspecialchars($orig_currency).' = '.number_format($exch_rate, 4, ',', '').' DKK';
    echo '</div>';
}
echo '</div>'; // valuta-sektion
} else {
    // Modul deaktiveret: bevar eksisterende valuta-værdier, så de ikke tabes ved gem
    echo '<input type="hidden" name="currency" value="'.htmlspecialchars($cur).'">';
    if ($orig_currency && $orig_currency !== 'DKK') {
        echo '<input type="hidden" name="orig_currency" value="'.htmlspecialchars($orig_currency).'">';
        echo '<input type="hidden" name="exch_rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'">';
    }
}
// ── Slut valuta-sektion ───────────────────────────────────────────────────────

htm_InputGroup(icon:'fa-id-badge', labl:'@Customer Reference', name:'cust_reference', valu:$inv['cust_reference'] ?? '', type:'text', hint:'@E.g. order number, contact person or EAN', echo:true);
htm_InputGroup(icon:'fa-comment',  labl:'@Delivery Address',   name:'delivery_address', valu:$inv['delivery_address'], type:'textarea', echo:true);

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

$tbl_data = [];
for ($i = 0; $i < 5; $i++) {
    $prod_id    = isset($inv_lines[$i]['prod_id'])       ? (int)$inv_lines[$i]['prod_id']       : 0;
    $line_qty   = isset($inv_lines[$i]['quantity'])      ? $inv_lines[$i]['quantity']            : '';
    $line_price = isset($inv_lines[$i]['price_each'])    ? $inv_lines[$i]['price_each']          : '';
    $line_vat   = isset($inv_lines[$i]['line_vat_rate']) ? $inv_lines[$i]['line_vat_rate']       : 25;

    $f_prod  = '<input type="hidden" name="prod_id[]" id="prod_id_'.$i.'" value="'.$prod_id.'">';
    $f_text  = htm_InputGroup('', '', 'line_text[]', $prod_id, 'sele', $prod_opt, 'onchange="updateVatRate(this, '.$i.')"', '100%', '', '', '', false) . $f_prod;
    $f_qty   = htm_InputGroup('', '', 'quantity[]',   $line_qty,   'number', null, 'step="any"', '100%', '', '', '', false);
    $f_price = htm_InputGroup('', '', 'price_each[]', $line_price, 'number', null, 'step="any"', '100%', '', '', '', false);
    $f_vat   = htm_InputGroup('', '', 'line_vat[]',   $line_vat,   'number', null, 'id="line_vat_'.$i.'"', '100%', '', '', '', false);
    $tbl_data[] = [$f_text, $f_qty, $f_price, $f_vat];
}

htm_Table(['@Description', '@Qty', '@Price', '@VAT %'], $tbl_data, 'line_tbl', 25, '', true,
    ['width:55%;', 'width:15%;', 'width:15%;', 'width:15%;']);

// Totaler
$cur_display = $orig_currency ?: $cur;
echo '<div style="margin:20px 0; padding:15px; background:#f8f9fa; border-radius:6px; max-width:350px; margin-left:auto;">
    <table style="width:100%; font-size:1.05em; border-collapse:collapse;">
        <tr><td style="padding:4px 0; color:#666;">'.lang('@Subtotal').':</td><td style="padding:4px 0; text-align:right; font-weight:bold;" id="total_sub">0,00</td><td style="padding:4px 0 4px 8px; color:#666; width:40px;" id="cur-label">'.$cur_display.'</td></tr>
        <tr><td style="padding:4px 0; color:#666;">'.lang('@VAT Total').':</td><td style="padding:4px 0; text-align:right; font-weight:bold; color:#7f8c8d;" id="total_vat">0,00</td><td style="padding:4px 0 4px 8px; color:#666;" id="cur-label-vat">'.$cur_display.'</td></tr>
        <tr style="border-top:2px solid #ddd; font-size:1.2em;"><td style="padding:10px 0 0 0; font-weight:bold; color:#2c3e50;">'.lang('@Total').':</td><td style="padding:10px 0 0 0; text-align:right; font-weight:bold; color:#27ae60;" id="total_grand">0,00</td><td style="padding:10px 0 0 8px; font-weight:bold; color:#2c3e50;" id="cur-label-total">'.$cur_display.'</td></tr>
    </table>
</div>';

htm_InputGroup(icon:'fa-sticky-note', labl:'@Note', name:'inv_note', valu:$inv['inv_note'], type:'textarea', echo:true);
htm_Button(icon:'fa-save', labl:'@Save Invoice', type:'success', attr:'name="save_invoice"', cont:'<div style="margin-top:30px; text-align:right;"></div>');
htm_Card_end();
?>
<script>
const vatMap   = <?php echo json_encode($prod_vat_map); ?>;
const priceMap = <?php echo json_encode($prod_price_map); ?>;

<?php if ($currency_module): ?>
// ── Valuta ────────────────────────────────────────────────────────────────────
var _fcRates = {}, _fcBase = 'EUR';

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
    if (currency === _fcBase) {
        rate = _fcRates['DKK'] || 0;
    } else {
        var toEur = _fcRates[currency] ? (1 / _fcRates[currency]) : 0;
        rate = toEur * (_fcRates['DKK'] || 1);
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
    document.getElementById('fc-currency-hidden').value = 'DKK';
    ['cur-label','cur-label-vat','cur-label-total'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.textContent = 'DKK';
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
