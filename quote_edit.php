<?php # /quote_edit.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Tilbud/Ordrebekræftelse (bruger-anmodet). Opret/redigér et
# tilbud - samme linje-redigerings-mønster som invoice_edit.php (produkt-
# vælger, moms-beregning, samme JS), men skriver til de helt separate
# quotes/quote_lines-tabeller (se db-setup/migrate_quotes.php for hvorfor).
# Uforanderlighed: kun en KLADDE ('draft') kan redigeres/slettes her - er
# tilbuddet først sendt, låses det (samme princip som en bogført faktura),
# rettelser sker ved at genåbne det til kladde igen (quote_actions.php?
# action=reopen) eller ved at oprette et nyt tilbud.
# RETTET: linje-tabellen var hårdkodet til nøjagtigt 5 rækker (samme fund som
# invoice_edit.php, se [[invoice-line-add-row-fix]]) - et tilbud med >5
# reelle linjer kunne miste linje 6+ permanent ved et helt almindeligt Gem.
# Viser nu altid alle eksisterende linjer + en dynamisk "Tilføj linje"-knap.
# RETTET: fik samme fritekst-fallback som invoice_edit.php (line_desc[]) - en
# linje uden tilknyttet produkt mistes ikke længere ved gem.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';

$table_exists = @DB::query($conn, "SELECT 1 FROM quotes LIMIT 1") !== false;
if (!$table_exists) {
    htm_Header('@Quotes');
    showMenu();
    echo "<div style='max-width:700px; margin:20px auto;'>";
    htm_Card_(capt: '@Quotes');
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The quotes database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    echo "</div>";
    htm_Footer();
    exit;
}

// 1. HÅNDTER SLETNING - kun en kladde må hård-slettes, samme princip som
//    invoice_edit.php's egen ?del=1 (§bogforingslov-compliance for fakturaer,
//    genbrugt her selvom et tilbud ikke selv er et regnskabsdokument - et
//    SENDT tilbud er stadig noget kunden har set/reageret på, og bør ikke
//    kunne forsvinde sporløst).
if (isset($_GET['del']) && $_GET['del'] == 1) {
    $quote_id = (int)$_GET['id'];
    $del_row = DB::fetch_assoc(DB::query($conn, "SELECT status, cust_id, quote_date FROM quotes WHERE quote_id = $quote_id"));
    if ($del_row && $del_row['status'] !== 'draft') {
        header("Location: quote_edit.php?id=$quote_id&err=posted_no_delete"); exit;
    }
    DB::query($conn, "DELETE FROM quote_lines WHERE quote_id=$quote_id");
    if (DB::query($conn, "DELETE FROM quotes WHERE quote_id=$quote_id") && $del_row) {
        log_action($conn, 'DELETE_DRAFT_QUOTE', 'quotes', $quote_id, $del_row, null);
    }
    header("Location: quote_list.php?msg=deleted"); exit;
}

// 2. HÅNDTER GEM / OPDATER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quote'])) {
    $quote_id = (int)$_POST['quote_id'];

    if ($quote_id > 0) {
        $existing = DB::fetch_assoc(DB::query($conn, "SELECT status FROM quotes WHERE quote_id = $quote_id"));
        if ($existing && $existing['status'] !== 'draft') {
            die(lang('@This quote has already been sent and can no longer be edited here. Reopen it to draft first, or create a new quote.'));
        }
    }

    $cust_id      = (int)$_POST['cust_id'];
    $quote_date   = DB::escape($conn, $_POST['quote_date']);
    $valid_until  = DB::escape($conn, $_POST['valid_until']);
    $cust_ref     = DB::escape($conn, $_POST['cust_reference'] ?? '');
    $quote_note   = DB::escape($conn, $_POST['quote_note'] ?? '');
    $deliv_addr   = DB::escape($conn, $_POST['delivery_address'] ?? '');
    $proj_id      = (int)($_POST['proj_id'] ?? 0);
    $proj_sql     = ($proj_id > 0) ? $proj_id : 'NULL';

    if ($quote_id > 0) {
        $save_ok = DB::query($conn, "UPDATE quotes SET
            cust_id=$cust_id, quote_date='$quote_date', valid_until='$valid_until',
            cust_reference='$cust_ref', quote_note='$quote_note', delivery_address='$deliv_addr',
            proj_id=$proj_sql
            WHERE quote_id=$quote_id");
    } else {
        $quote_no = next_quote_no($conn);
        $save_ok = DB::query($conn, "INSERT INTO quotes
            (quote_no, cust_id, quote_date, valid_until, status, cust_reference, quote_note,
             delivery_address, proj_id, created_by)
            VALUES ($quote_no, $cust_id, '$quote_date', '$valid_until', 'draft', '$cust_ref', '$quote_note',
             '$deliv_addr', $proj_sql, " . (int)($_SESSION['user_id'] ?? 0) . ")");
        if ($save_ok) { $quote_id = DB::insert_id($conn); }
    }

    if (!$save_ok) {
        die(lang('@Error saving invoice: ') . DB::error($conn)); // samme fejltekst-nøgle som invoice_edit.php, generisk nok til begge
    }

    // Linjer - ryd og genskriv, samme mønster som invoice_edit.php
    // RETTET (bruger-anmodet: "fritekst-fallback ligesom fakturaer" - se
    // [[invoice-line-add-row-fix]]): denne løkke krævede FØR altid et gyldigt
    // prod_id ("if ($pid <= 0 || $qty <= 0) continue;") - en fritekst-linje
    // (intet tilknyttet produkt, fx fra en fremtidig batch-oprettelse svarende
    // til time_actions.php's "Opret faktura af timer") ville blive sprunget
    // helt over, og linjens EGEN tekst ville aldrig kunne bevares ved et
    // almindeligt Gem her, selvom den findes i databasen. line_desc[] (et
    // skjult felt uafhængigt af select'ens værdi, se build_quote_line_row()
    // nedenfor) bærer nu linjens faktisk gemte tekst, så en fritekst-linje
    // hverken mistes eller ombyttes til selve select-værdien ved gem.
    DB::query($conn, "DELETE FROM quote_lines WHERE quote_id=$quote_id");
    if (isset($_POST['line_text']) && is_array($_POST['line_text'])) {
        foreach ($_POST['line_text'] as $k => $v) {
            $pid = isset($_POST['prod_id'][$k]) ? (int)$_POST['prod_id'][$k] : 0;
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
            DB::query($conn, "INSERT INTO quote_lines
                (quote_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id)
                VALUES ($quote_id, '$txt', $qty, $prc, $vat, $pid, $proj_sql)");
        }
    }
    header("Location: quote_edit.php?id=$quote_id&msg=saved"); exit;
}

// 3. HENT DATA
$quote_id = (int)($_GET['id'] ?? 0);
$s = get_settings($conn);
$module_projects = !empty($s['module_projects']) && $s['module_projects'] == '1';
// RETTET (§currency-setting-is-cosmetic-label): totalerne herunder viste
// altid en hardkodet "DKK"-etiket, uanset firmaets faktisk konfigurerede
// bogføringsvaluta (tilbud understøtter ikke fremmed valuta, men SKAL vise
// den rigtige base-valuta, ikke altid dansk).
$default_currency = $s['currency'] ?? 'DKK';

if ($quote_id > 0) {
    $q = DB::fetch_assoc(DB::query($conn, "SELECT * FROM quotes WHERE quote_id=$quote_id"));
    if (!$q) die(lang('@Invoice not found')); // delt nøgle med invoice_edit.php ("Faktura ikke fundet" - dækker begge her)
    $quote_lines = [];
    $lines_res = DB::query($conn, "SELECT * FROM quote_lines WHERE quote_id=$quote_id ORDER BY line_id");
    while ($l = DB::fetch_assoc($lines_res)) { $quote_lines[] = $l; }
} else {
    $q = [
        'quote_id' => 0, 'cust_id' => 0, 'quote_date' => date('Y-m-d'),
        'valid_until' => date('Y-m-d', strtotime('+30 days')),
        'status' => 'draft', 'delivery_address' => '', 'cust_reference' => '',
        'quote_note' => '', 'proj_id' => (int)($_GET['proj_id'] ?? 0),
    ];
    // RETTET (samme fund som invoice_edit.php, se [[invoice-line-add-row-fix]]):
    // $quote_lines blev aldrig sat for et NYT tilbud (kun i grenen ovenfor) -
    // virkede tidligere kun ved en tilfældighed, fordi den hårdkodede
    // 5-linjers-løkke udelukkende læste den via isset(), som tåler en helt
    // udefineret variabel. Et direkte count($quote_lines) (se
    // $line_render_count nedenfor) gør ikke det samme.
    $quote_lines = [];
}
$is_locked = ($q['status'] !== 'draft');
$proj_id   = (int)($q['proj_id'] ?? 0);

// 4. RENDER SIDE
htm_Header($quote_id > 0 ? lang('@Edit Quote')." #$quote_id" : lang('@Create New Quote'));
showMenu();

$tools = htm_Button('fa-list', '@Back', 'secondary', 'quote_list.php', '', 'data-hint="'.lang('@Return to the quote list without saving').'"', '', false);
$card_msg = '';
if (isset($_GET['msg'])) {
    $card_msg = htm_Alert(lang('@Changes saved successfully'), 'success', 700, false);
} elseif (isset($_GET['err']) && $_GET['err'] === 'posted_no_delete') {
    $card_msg = htm_Alert(lang('@This quote has already been sent and cannot be deleted. Reopen it to draft first if you need to remove it.'), 'error', 700, false);
}
htm_Card_($quote_id > 0 ? '@Edit Quote' : '@New Quote', 1000, $card_msg, 'edit_form', true, $tools);

echo '<input type="hidden" name="quote_id" value="'.$quote_id.'">';

if ($is_locked) {
    htm_Banner('<i class="fa fa-lock"></i> ' . lang('@This quote has already been sent and its details are locked. Use Reopen to Draft from the quote list to make changes.'), 'info');
}

// Kunde + projekt
$cust_res = DB::query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name");
$cust_opt = [];
while ($c = DB::fetch_assoc($cust_res)) { $cust_opt[$c['cust_id']] = $c['cust_name']; }
htm_Field(icon:'fa-user', labl:'@Customer', name:'cust_id', valu:$q['cust_id'], type:'sele',
    opti:$cust_opt, extr:($is_locked ? 'disabled required' : 'required'), wdth:'70%', echo:true);
if ($module_projects) htm_ProjektCodeField($conn, $proj_id ?: null, '30%');
if ($is_locked) echo '<input type="hidden" name="cust_id" value="'.$q['cust_id'].'">';

// Datoer
echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_Field(icon:'fa-calendar',       labl:'@Quote Date',  name:'quote_date',  valu:$q['quote_date'],  type:'date', extr:($is_locked ? 'readonly' : ''), echo:true);
htm_Field(icon:'fa-calendar-times', labl:'@Valid Until',  name:'valid_until', valu:$q['valid_until'], type:'date', extr:($is_locked ? 'readonly' : ''), hint:'@The quote is no longer considered valid after this date - shown as expired on the quote list.', echo:true);
echo '</div>';

htm_Field(icon:'fa-id-badge', labl:'@Customer Reference', name:'cust_reference', valu:$q['cust_reference'] ?? '', type:'text', extr:($is_locked ? 'readonly' : ''), hint:'@E.g. order number or contact person', echo:true);
htm_Field(icon:'fa-comment',  labl:'@Delivery Address',   name:'delivery_address', valu:$q['delivery_address'], type:'textarea', extr:($is_locked ? 'readonly' : ''), echo:true);

echo '<h3 style="margin:20px 0 10px 0;">'.lang('@Quote Lines').'</h3>';

$prod_res = DB::query($conn, "SELECT p.prod_id, p.prod_name, p.prod_price, a.vat_rate FROM products p LEFT JOIN accounts a ON p.acc_id = a.acc_id ORDER BY p.prod_name");
$prod_opt = [0 => '-- '.lang('@Select Product/Description').' --'];
$prod_vat_map = [0 => 25]; $prod_price_map = [0 => 0.00];
while ($p = DB::fetch_assoc($prod_res)) {
    $prod_opt[$p['prod_id']] = $p['prod_name'];
    $prod_vat_map[$p['prod_id']]   = ($p['vat_rate']   !== null) ? (float)$p['vat_rate']   : 25.00;
    $prod_price_map[$p['prod_id']] = ($p['prod_price'] !== null) ? (float)$p['prod_price'] : 0.00;
}

// RETTET (bruger-anmodet, samme fund/rettelse som invoice_edit.php - se
// [[invoice-line-add-row-fix]]): tabellen var hårdkodet til NØJAGTIGT 5
// linjer, uden nogen "Tilføj linje"-knap OG uden at vise en 6. linje, hvis
// tilbuddet af en anden vej allerede havde fået flere. Gem-håndteringen
// sletter altid alle linjer og genopretter dem udelukkende fra det,
// formularen indsender - et tilbud med 6+ linjer kunne derfor miste linje
// 6+ permanent ved et helt almindeligt Gem. Rettet på samme måde: vis altid
// MINDST lige så mange rækker som der reelt er linjer, og en JS-baseret
// "Tilføj linje"-knap til at tilføje flere. Gem-logikken krævede ingen
// ændring - den itererer allerede generisk over hele $_POST['line_text'].
$row_extr = $is_locked ? 'disabled' : '';
// RETTET (bruger-anmodet: "fritekst-fallback ligesom fakturaer" - se
// [[invoice-line-add-row-fix]]): line_text[] er reelt en produkt-<select>,
// ikke et frit tekstfelt - dens indsendte værdi er altid et prod_id. Et
// separat, skjult line_desc[]-felt bærer nu linjens EGEN, faktisk gemte
// tekst uafhængigt heraf, så en fritekst-linje (intet prod_id) hverken
// mistes eller ombyttes til "0" ved et almindeligt Gem - se save-
// håndteringen ovenfor. Vist som en lille note under feltet, når linjen
// reelt er fritekst, så brugeren kan se hvad der faktisk står der, selv om
// selve dropdown'en nødvendigvis viser "-- Vælg produkt --".
function build_quote_line_row($i, $prod_id, $line_qty, $line_price, $line_vat, $existing_line_text, $prod_opt, $row_extr) {
    $f_prod  = '<input type="hidden" name="prod_id[]" value="'.$prod_id.'">'
             . '<input type="hidden" name="line_desc[]" value="'.htmlspecialchars($existing_line_text, ENT_QUOTES).'">';
    $f_text  = htm_Field('', '', 'line_text[]', $prod_id, 'sele', $prod_opt, 'onchange="updateVatRate(this, '.$i.')" '.$row_extr, '100%', '', '', '', false) . $f_prod;
    if ($existing_line_text !== '') {
        $f_text .= '<div style="font-size:0.8em; color:var(--text-muted); padding:2px 4px;">' . htmlspecialchars($existing_line_text) . '</div>';
    }
    $f_qty   = htm_Field('', '', 'quantity[]',   $line_qty,   'number', null, 'step="any" '.$row_extr, '100%', '', '', '', false);
    $f_price = htm_Field('', '', 'price_each[]', $line_price, 'number', null, 'step="any" '.$row_extr, '100%', '', '', '', false);
    $f_vat   = htm_Field('', '', 'line_vat[]',   $line_vat,   'number', null, $row_extr, '100%', '', '', '', false);
    return [$f_text, $f_qty, $f_price, $f_vat];
}

$line_render_count = max(5, count($quote_lines));
$tbl_data = [];
for ($i = 0; $i < $line_render_count; $i++) {
    $prod_id    = isset($quote_lines[$i]['prod_id'])       ? (int)$quote_lines[$i]['prod_id']       : 0;
    $line_qty   = isset($quote_lines[$i]['quantity'])      ? $quote_lines[$i]['quantity']            : '';
    $line_price = isset($quote_lines[$i]['price_each'])    ? $quote_lines[$i]['price_each']          : '';
    $line_vat   = isset($quote_lines[$i]['line_vat_rate']) ? $quote_lines[$i]['line_vat_rate']       : 25;
    $existing_line_text = ($prod_id <= 0 && isset($quote_lines[$i]['line_text'])) ? $quote_lines[$i]['line_text'] : '';
    $tbl_data[] = build_quote_line_row($i, $prod_id, $line_qty, $line_price, $line_vat, $existing_line_text, $prod_opt, $row_extr);
}

htm_Table(['@Description', '@Qty', '@Price', '@VAT %'], $tbl_data, 'line_tbl', 25, '', true,
    ['width:55%;', 'width:15%;', 'width:15%;', 'width:15%;']);

if (!$is_locked) {
    echo '<div style="margin:-45px 0 20px 0;">';
    echo '<button type="button" onclick="addQuoteLine()" style="display:inline-block; text-align:center; background-color:var(--color-secondary); color:var(--text-light); padding:6px 14px; border-radius:4px; border:none; cursor:pointer; font-size:13px; font-weight:600;">';
    echo '<i class="fa-solid fa-plus"></i> ' . lang('@Add Line');
    echo '</button></div>';
}

// Skabelon til en frisk, tom linje - 'IDX' erstattes med det reelle
// rækkenummer i JS (se addQuoteLine() nedenfor). Bygget med nøjagtig samme
// funktion som de rigtige rækker ovenfor.
$template_row = build_quote_line_row('IDX', 0, '', '', 25, '', $prod_opt, $row_extr);
$template_row_html = '<tr><td style="width:55%;">'.$template_row[0].'</td><td style="width:15%;">'.$template_row[1]
    .'</td><td style="width:15%;">'.$template_row[2].'</td><td style="width:15%;">'.$template_row[3].'</td></tr>';

echo '<div style="margin:20px 0; padding:15px; background:#f8f9fa; border-radius:6px; max-width:350px; margin-left:auto;">
    <table style="width:100%; font-size:1.05em; border-collapse:collapse;">
        <tr><td style="padding:4px 0; color:#666;">'.lang('@Subtotal').':</td><td style="padding:4px 0; text-align:right; font-weight:bold;" id="total_sub">0,00</td><td style="padding:4px 0 4px 8px; color:#666; width:40px;">'.htmlspecialchars($default_currency).'</td></tr>
        <tr><td style="padding:4px 0; color:#666;">'.lang('@VAT Total').':</td><td style="padding:4px 0; text-align:right; font-weight:bold; color:#7f8c8d;" id="total_vat">0,00</td><td style="padding:4px 0 4px 8px; color:#666;">'.htmlspecialchars($default_currency).'</td></tr>
        <tr style="border-top:2px solid #ddd; font-size:1.2em;"><td style="padding:10px 0 0 0; font-weight:bold; color:#2c3e50;">'.lang('@Total').':</td><td style="padding:10px 0 0 0; text-align:right; font-weight:bold; color:#27ae60;" id="total_grand">0,00</td><td style="padding:10px 0 0 8px; font-weight:bold; color:#2c3e50;">'.htmlspecialchars($default_currency).'</td></tr>
    </table>
</div>';

htm_Field(icon:'fa-sticky-note', labl:'@Note', name:'quote_note', valu:$q['quote_note'], type:'textarea', extr:($is_locked ? 'readonly' : ''), echo:true);

if (!$is_locked) {
    htm_Button(icon:'fa-save', labl:'@Save Quote', type:'success', attr:'name="save_quote" data-hint="'.lang('@Save this quote as a draft').'"', cont:'<div style="margin-top:30px; text-align:right;"></div>');
}
htm_Card_end();
?>
<script>
const vatMap   = <?php echo json_encode($prod_vat_map); ?>;
const priceMap = <?php echo json_encode($prod_price_map); ?>;
const lineRowTemplate = <?php echo json_encode($template_row_html); ?>;

// Tilføjer en frisk, tom linje-række nederst i tabellen - se
// build_quote_line_row() i PHP'en ovenfor. Gem-håndteringen kræver ingen
// ændring, den itererer allerede generisk over hele $_POST['line_text'].
function addQuoteLine() {
    const newIndex = document.getElementsByName('line_text[]').length;
    const wrapper  = document.createElement('tbody');
    wrapper.innerHTML = lineRowTemplate.replace(/IDX/g, newIndex);
    document.querySelector('#line_tbl tbody').appendChild(wrapper.firstElementChild);
    calculateQuoteTotals();
}

function updateVatRate(selectElement, rowIndex) {
    const pid = selectElement.value;
    document.getElementsByName('prod_id[]')[rowIndex].value       = pid;
    document.getElementsByName('line_vat[]')[rowIndex].value      = vatMap[pid]   !== undefined ? vatMap[pid]   : 25;
    document.getElementsByName('price_each[]')[rowIndex].value    = priceMap[pid] !== undefined ? priceMap[pid] : 0.00;
    calculateQuoteTotals();
}

function calculateQuoteTotals() {
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
    if (['quantity[]','price_each[]','line_vat[]'].includes(e.target.name)) calculateQuoteTotals();
});
document.addEventListener('DOMContentLoaded', calculateQuoteTotals);
</script>
<?php htm_Footer(); ?>
