<?php # /invoice_credit.php v:1.3.1 d:2026-09-15 i:evs
# Kreditnota-flow - opretter en ny kreditnota-faktura + modpostering til en
# allerede bogført faktura. Kaldet fra sales_hub.php med ?credit_ref=INV_ID.
# Opfylder bogføringsloven § 16: bogførte fakturaer må ikke ændres, fejl
# korrigeres i stedet med en kreditnota der nulstiller den originale faktura.
# Journalposten får sit eget voucher_no fra next_voucher_no(); beløb
# omregnes til DKK med den ORIGINALE fakturas gemte exch_rate, og
# orig_currency/exch_rate kopieres til den nye kreditnota-faktura; varen
# lægges tilbage på lager (modpost til lagertrækket i invoice_post_action.php).
# En kreditnota kan ikke selv krediteres igen (server-side spærret).
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/invoice_post.inc.php';

$credit_ref = (int)($_GET['credit_ref'] ?? 0);

if ($credit_ref <= 0) {
    header("Location: sales_hub.php?err=invalid_credit_ref"); exit;
}

// Hent original faktura
$orig = DB::fetch_assoc(DB::query($conn,
    "SELECT i.*, c.cust_name FROM invoices i
     JOIN customers c ON i.cust_id = c.cust_id
     WHERE i.inv_id = $credit_ref"));

if (!$orig) {
    header("Location: sales_hub.php?err=not_found"); exit;
}

// Kun bogførte fakturaer kan krediteres
if (strtolower($orig['inv_status']) === 'draft') {
    header("Location: invoice_edit.php?id=$credit_ref&err=draft_no_credit"); exit;
}

// RETTET (se [[bugs-batch-10-review]]): manglede et tjek for at "originalen"
// der skal krediteres ikke selv ER en kreditnota - sales_hub.php viste
// "Kreditér denne faktura"-knappen på alle ikke-kladde-fakturaer inkl.
// kreditnota-rækkerne selv, og intet her stoppede det. En kreditnota af en
// kreditnota giver ingen mening bogføringsmæssigt.
if (!empty($orig['credit_ref'])) {
    header("Location: sales_hub.php?err=cannot_credit_a_credit_note"); exit;
}

// Allerede krediteret?
$existing_credit = DB::fetch_assoc(DB::query($conn,
    "SELECT inv_id FROM invoices WHERE credit_ref = $credit_ref LIMIT 1"));
if ($existing_credit) {
    header("Location: sales_hub.php?err=already_credited&existing=" . $existing_credit['inv_id']); exit;
}

// RETTET: en faktura hvis EGEN dato ligger i en låst periode, kunne slet ikke krediteres - men en kreditnota
// i den åbne periode er netop den lovlige måde at rette en gammel faktura på. Kreditnotaens egen dato
// tjekkes (is_date_locked() nedenfor og i invoice_create_credit()).

// Hent originale linjer
$orig_lines = [];
$lines_res  = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $credit_ref ORDER BY line_id");
while ($l = DB::fetch_assoc($lines_res)) { $orig_lines[] = $l; }

// ── HÅNDTER BEKRÆFTELSE ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_credit'])) {

    $credit_date = (string)($_POST['credit_date'] ?? date('Y-m-d'));

    if (is_date_locked($conn, $credit_date)) {
        $err = lang('@Credit date is in a locked accounting period.');
    } else {
        DB::begin_transaction($conn);
        try {
            // Selve kreditnotaen: invoice_create_credit() (inc/invoice_post.inc.php) - samme kode som returneringer fra en webbutik
            $new_inv_id = invoice_create_credit($conn, $credit_ref, (array)($_POST['credit_qty'] ?? []), $credit_date, (string)($_POST['credit_note'] ?? ''));
            DB::commit($conn);
            header("Location: invoice_view.php?id=$new_inv_id&msg=credit_created"); exit;

        } catch (Exception $e) {
            DB::rollback($conn);
            $err = lang('@Error creating credit note:') . ' ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── RENDER BEKRÆFTELSESSIDE ───────────────────────────────────────────────────
htm_Header(lang('@Create Credit Note'));
showMenu();

// Beregn totaler til visning
$sub = 0; $vat = 0;
foreach ($orig_lines as $l) {
    $lt   = (float)$l['quantity'] * (float)$l['price_each'] * (100 - (float)($l['discount_pct'] ?? 0)) / 100.0;
    $sub += $lt;
    $vat += $lt * ((float)$l['line_vat_rate'] / 100);
}

$tools = htm_Button('fa-times', '@Cancel', 'secondary', 'sales_hub.php', '', 'data-hint="'.lang('@Discard the credit note and return to the sales hub').'"', '<div></div>', false);
htm_Card_(lang('@Create Credit Note') . ' — ' . lang('@Invoice') . ' #' . $credit_ref, 900, '', '', true, $tools);

if (isset($err)) htm_Alert($err, 'error');

// Info-boks om hvad der sker
$credit_notice = '<strong><i class="fa fa-info-circle"></i> ' . lang('@What happens when you create a credit note:') . '</strong><ul style="margin:8px 0 0 0; padding-left:20px; line-height:1.8;">'
    . '<li>' . lang('@A new credit invoice is created with negative quantities') . '</li>'
    . '<li>' . lang('@You can adjust or zero out the quantity per line below to credit only part of the invoice') . '</li>'
    . '<li>' . lang('@The original invoice is marked as credited and can no longer be edited - only ONE credit note can be created per invoice, so choose the quantities carefully') . '</li>'
    . '<li>' . lang('@A reversal journal entry is posted to the ledger, matching only what you actually credit') . '</li>'
    . '<li>' . lang('@The credit note can be sent to the customer from the invoice view') . '</li>'
    . '</ul>';
htm_Banner($credit_notice, 'warning');

// Formular
// RETTET (runde 32, delvis kreditnota): formularen skal starte FØR selve
// linje-oversigten nedenfor, da "Antal at kreditere"-felterne (indsat i
// selve tabellen) ellers ville ligge UDENFOR <form> og aldrig blive
// indsendt - oversigten var oprindeligt rent informativ og lå bevidst uden
// for formularen, men er nu selv en del af den.
echo '<form method="POST">';
csrf_field();

// Original faktura — oversigt
echo '<div style="margin-bottom:20px; padding:14px; background:var(--bg-panel); border-radius:6px; border:1px solid var(--border-color);">';
echo '<strong>' . lang('@Original invoice') . ':</strong><br>';
// RETTET (§bugs-batch-22-review, del b): erstattet den håndrullede <table>
// med htm_Table() (se csrf-protection-added.md/htm-alert-banner-refactor.md
// for baggrunden).
// RETTET (runde 32, delvis kreditnota): "Antal"-kolonnen er nu et redigerbart
// felt (forudfyldt til hele den oprindelige mængde, uændret standardopførsel
// hvis intet ændres) i stedet for ren visningstekst - lader brugeren
// kreditere en delmængde, eller nulstille en linje helt (0 = udelades).
$credit_line_rows = [];
$credit_has_discount = false;
foreach ($orig_lines as $l) { if (!empty($l['discount_pct'])) $credit_has_discount = true; }
foreach ($orig_lines as $l) {
    $disc = (float)($l['discount_pct'] ?? 0);
    $qty_abs = abs((float)$l['quantity']);
    $lt = $qty_abs * (float)$l['price_each'] * (100 - $disc) / 100.0;
    $line_id = (int)$l['line_id'];
    $row = [
        htmlspecialchars($l['line_text']),
        '<input type="text" class="credit-qty-input" data-price="' . (float)$l['price_each'] . '" data-vat="' . (float)$l['line_vat_rate'] . '" data-disc="' . $disc . '" name="credit_qty[' . $line_id . ']" value="' . number_format($qty_abs, 2, ',', '') . '" style="width:80px; text-align:right;" oninput="recalcCreditTotals()" data-hint="' . htmlspecialchars(lang('@Max') . ': ' . number_format($qty_abs, 2, ',', '.')) . '">',
        number_format($l['price_each'], 2, ',', '.'),
        $l['line_vat_rate'] . '%',
    ];
    if ($credit_has_discount) $row[] = ($disc > 0 ? number_format($disc, 2, ',', '.') . '%' : '—');
    $row[] = '<div class="credit-line-total" style="text-align:right; font-weight:500;">' . number_format($lt, 2, ',', '.') . '</div>';
    $credit_line_rows[] = $row;
}
$credit_headers = ['@Description', '@Qty to Credit', '@Price', '@VAT%'];
if ($credit_has_discount) $credit_headers[] = '@Discount %';
$credit_headers[] = '@Line total';
htm_Table($credit_headers, $credit_line_rows, 'credit_line_tbl', 100);
echo '<div style="text-align:right; margin-top:10px; font-size:13px;">';
echo lang('@Subtotal') . ': <strong id="credit_sub_display">' . number_format($sub, 2, ',', '.') . '</strong> | ';
echo lang('@VAT') . ': <strong id="credit_vat_display">' . number_format($vat, 2, ',', '.') . '</strong> | ';
echo lang('@Total') . ': <strong id="credit_total_display" style="font-size:1.1em; color:var(--color-danger);">'
    . number_format($sub + $vat, 2, ',', '.') . ' ' . $orig['currency'] . '</strong>';
echo '</div></div>';

echo '<div style="display:flex; gap:15px; margin-bottom:15px;">';
htm_Field(icon:'fa-calendar', labl:'@Credit date', name:'credit_date',
    valu:date('Y-m-d'), type:'date', echo:true);
echo '</div>';
htm_Field(icon:'fa-comment', labl:'@Credit note reason', name:'credit_note',
    valu:lang('@Credit note for invoice') . ' #' . ($orig['invoice_no'] ?: $credit_ref),      // RETTET: kunden ser fakturanummeret, ikke det interne id
    type:'text', hint:'@This text appears on the credit note sent to the customer', echo:true);

echo '<div style="margin-top:20px; display:flex; gap:10px; border-top:1px solid var(--border-color); padding-top:20px;">';
echo '<button type="submit" name="confirm_credit" value="1"
    style="flex:2; background:var(--color-danger); color:white; border:none; padding:12px;
    border-radius:4px; font-weight:bold; cursor:pointer; font-size:14px;">
    <i class="fa fa-undo"></i> ' . lang('@Confirm — Create Credit Note') . '</button>';
echo '<a href="sales_hub.php" data-hint="' . lang('@Return to sales without creating a credit note') . '" style="flex:1; background:var(--bg-panel); color:var(--text-main);
    border:1px solid var(--border-color); padding:12px; border-radius:4px; font-weight:bold;
    text-align:center; text-decoration:none; display:flex; align-items:center; justify-content:center;">
    ' . lang('@Cancel') . '</a>';
echo '</div>';
echo '</form>';
?>
<script>
// NYT (runde 32, delvis kreditnota): live-genberegning af Subtotal/Moms/
// Total ud fra de faktisk indtastede "Antal at kreditere"-værdier, samme
// tal-parsing-konvention (komma som decimaltegn) som resten af sitets
// danske talfelter.
function parseDkNum(v) {
    var n = parseFloat(String(v || '0').replace(/\./g, '').replace(',', '.'));
    return isNaN(n) ? 0 : n;
}
function recalcCreditTotals() {
    var sub = 0, vat = 0;
    document.querySelectorAll('.credit-qty-input').forEach(function(inp) {
        var qty   = Math.max(0, parseDkNum(inp.value));
        var price = parseFloat(inp.getAttribute('data-price')) || 0;
        var disc  = parseFloat(inp.getAttribute('data-disc')) || 0;
        var rate  = parseFloat(inp.getAttribute('data-vat')) || 0;
        var lt    = qty * price * (100 - disc) / 100;
        sub += lt;
        vat += lt * (rate / 100);
        var totalCell = inp.closest('tr').querySelector('.credit-line-total');
        if (totalCell) totalCell.textContent = lt.toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2});
    });
    document.getElementById('credit_sub_display').textContent = sub.toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('credit_vat_display').textContent = vat.toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('credit_total_display').textContent = (sub + vat).toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' <?php echo htmlspecialchars($orig['currency'], ENT_QUOTES); ?>';
}
</script>
<?php
htm_Card_end();
htm_Footer();
?>
