<?php # /quote_view.php v:1.3.1 d:2026-09-15 i:evs
# Tilbud/Ordrebekræftelse - visning til kunden (også det papir der bliver til PDF'en i mailen). Titlen skifter
# mellem "TILBUD" og "ORDREBEKRÆFTELSE" efter status (ét dokument, ikke to skabeloner).
# Designtilstand (bruger-anmodet): papiret består af blokke der kan flyttes, gøres bredere og skjules, gemt pr.
# tilbudslayout (invoice_layouts.doc_type = 'quote' - egne layouts, uafhængige af fakturaens). Det første
# tilbudslayout er en kopi af fakturaens standardlayout. Se inc/doc_design.inc.php og inc/invoice_layouts.inc.php.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$quote_id = (int)($_GET['id'] ?? 0);
$sql = "SELECT q.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr
        FROM quotes q JOIN customers c ON q.cust_id = c.cust_id WHERE q.quote_id = $quote_id";
$q = DB::fetch_assoc(DB::query($conn, $sql));
if (!$q) redirect('quote_list.php', '@Not found - it may already have been deleted.', 'error');      // RETTET: hvid side med teksten "Faktura ikke fundet" for et tilbud

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

require_once __DIR__ . '/inc/doc_paper.inc.php';      // (firmaoplysninger: tydelige pladsholdere, aldrig opdigtede)

// Titel afhænger af status - se filens header-kommentar.
$doc_title = in_array($q['status'], ['accepted', 'converted'], true) ? lang('@Order Confirmation') : lang('@Quote');
$quote_no_display = 'T-' . str_pad((string)$q['quote_no'], 6, '0', STR_PAD_LEFT);

require_once __DIR__ . '/inc/doc_design.inc.php';
$lay = doc_layout_setup($conn, 'quote');      // (?layout= viser og designer et andet tilbudslayout)

htm_Header($doc_title . ' ' . $quote_no_display);
showMenu();
echo doc_design_css();
doc_profile_banner($s);
doc_toolbar_();
$status = $q['status'];
if ($status === 'draft' || $status === 'sent') {
    htm_Button(icon:'fa-envelope', labl:'@Send via Mail', type:'info', attr:'onclick="generateAndSendQuote('.$quote_id.')" id="mailBtn" data-hint="'.lang('@Email this quote to the customer').'"');
}
if ($status === 'draft') {
    htm_Button(icon:'fa-check', labl:'@Mark as Sent', type:'secondary', link:'quote_actions.php?action=mark_sent&id='.$quote_id, attr:'data-hint="'.lang('@Mark as sent without emailing (e.g. handed over in person)').'"');
}
if ($status === 'draft' || $status === 'sent') {
    htm_Button(icon:'fa-thumbs-up', labl:'@Mark Accepted', type:'success', link:'quote_actions.php?action=mark_accepted&id='.$quote_id, attr:'data-hint="'.lang('@Record that the customer has accepted this quote').'"');
    htm_Button(icon:'fa-thumbs-down', labl:'@Mark Rejected', type:'danger', link:'quote_actions.php?action=mark_rejected&id='.$quote_id, attr:'data-hint="'.lang('@Record that the customer has declined this quote').'"');
}
if ($status === 'accepted') {
    htm_Button(icon:'fa-file-invoice', labl:'@Convert to Invoice', type:'success', link:'quote_actions.php?action=convert&id='.$quote_id, attr:'onclick="' . htm_confirm_js('@Convert this quote to a draft invoice? You can still review it before posting.') . '" data-hint="'.lang('@Create a draft invoice from this quote').'"');
}
if (in_array($status, ['sent','rejected'], true)) {
    htm_Button(icon:'fa-rotate-left', labl:'@Reopen to Draft', type:'secondary', link:'quote_actions.php?action=reopen&id='.$quote_id, attr:'data-hint="'.lang('@Undo and make this quote editable again').'"');
}
// NYT: "Dupliker tilbud" - altid tilgængelig uanset status, samme princip som "Dupliker faktura": en accepteret,
// afvist eller udløbet tilbud er en lige så gyldig kilde til genbrug som en kladde.
htm_Button(icon:'fa-copy', labl:'@Duplicate Quote', type:'secondary', attr:'onclick="' . htm_confirm_js('@Create a new draft quote with the same customer and lines?', "tcPost('quote_duplicate.php?id=$quote_id')") . '" data-hint="'.lang('@Create a new draft with the same customer, lines and settings - useful for repeat quoting').'"');
if ($lay['on']) {
    htm_Button(icon:'fa-pencil-ruler', labl:'@Design Mode', type:'secondary', attr:'onclick="docToggleDesign()" id="qDesignBtn" data-hint="'.lang('@Move, resize and hide the blocks of the quote - saved in the quote layout shown').'"');
}
htm_Button(icon:'fa-print', labl:'@Print', type:'primary', attr:'onclick="window.print()" data-hint="'.lang('@Print this quote').'"');
htm_Button(icon:'fa-arrow-left', labl:'@Back', type:'secondary', link:'quote_list.php', attr:'data-hint="'.lang('@Return to the quote list').'"');
echo '</div>';
if ($lay['on']) doc_layout_bar($lay['list'], $lay['id'], lang('@Show the quote with another layout - Design Mode moves the blocks of the layout shown here'));

// RETTET: selve tilbuddet (papiret, som også bliver til PDF'en der mailes) blev altid vist på bogholderens sprog,
// mens fakturaen følger kundens sprog (kundekortet). Samme midlertidige sprogskift som invoice_view.php - kun
// papiret; knapperne ovenfor forbliver på bogholderens sprog. Gendannes straks efter papiret.
$qv_lang = doc_customer_lang($conn, (int)$q['cust_id']);
doc_lang_push($qv_lang);
if ($qv_lang !== '') $doc_title = in_array($q['status'], ['accepted', 'converted'], true) ? lang('@Order Confirmation') : lang('@Quote');
$co = doc_company($s);
$layouts = $lay['blocks'];
echo '<div class="doc-paper" id="quote-page">';

// Stempel (accepteret / afvist / konverteret) - nøglerne '@Accepted', '@Rejected', '@Converted'
if (in_array($status, ['accepted', 'rejected', 'converted'], true)) {
    $st_col = ($status === 'rejected') ? '#e74c3c' : '#2ecc71';
    doc_block('block-stamp', '<span class="doc-stamp" style="color:' . $st_col . '; border-color:' . $st_col . ';">' . lang('@' . ucfirst($status)) . '</span>', $layouts);
} else {
    doc_block('block-stamp', '<span class="design-placeholder">(' . lang('@Status') . ')</span>', $layouts, 'block-empty');
}

doc_head_blocks($layouts, $co, $q['cust_name'], $q['cust_address'] ?? '');

$q_ref = trim($q['cust_reference'] ?? '');
doc_block('block-cust-ref', '<div style="font-size:13px;"><small style="color:#888;">' . lang('@Your Reference') . ':</small> '
    . ($q_ref !== '' ? htmlspecialchars($q_ref) : '<span class="design-placeholder">(' . lang('@Your Reference') . ')</span>') . '</div>', $layouts, $q_ref !== '' ? '' : 'block-empty');

$q_deliv = trim($q['delivery_address'] ?? '');
$q_deliv_shown = ($q_deliv !== '' && $q_deliv !== trim($q['cust_address'] ?? ''));
doc_block('block-delivery', '<div style="font-size:13px;"><small style="color:#888;">' . lang('@Delivery Address') . ':</small><br>'
    . ($q_deliv_shown ? nl2br(htmlspecialchars($q_deliv)) : '<span class="design-placeholder">(' . lang('@Delivery Address') . ')</span>') . '</div>', $layouts, $q_deliv_shown ? '' : 'block-empty');

// Titel + nummer, dato, gyldighed (samme blokke som fakturaens nummer / dato / forfald)
doc_block('block-inv-no', '<div style="font-size:20px; font-weight:bold; text-transform:uppercase;">' . $doc_title . '</div><span style="font-size:16px;">' . $quote_no_display . '</span>', $layouts);
doc_block('block-inv-date', '<strong>' . lang('@Quote Date') . ':</strong><br>' . date(CONF_DATE_FORMAT, strtotime($q['quote_date'])), $layouts);
doc_block('block-inv-due', !empty($q['valid_until'])
    ? '<strong>' . lang('@Valid Until') . ':</strong><br>' . date(CONF_DATE_FORMAT, strtotime($q['valid_until']))
    : '<span class="design-placeholder">(' . lang('@Valid Until') . ')</span>', $layouts, !empty($q['valid_until']) ? '' : 'block-empty');

// Linjer + totaler
$lines_html = '<table class="line-table"><thead><tr><th>' . lang('@Description') . '</th><th style="text-align:right;">' . lang('@Qty') . '</th><th style="text-align:right;">'
    . lang('@Price') . '</th><th style="text-align:right;">' . lang('@Line total') . '</th></tr></thead><tbody>';
$res_l = DB::query($conn, "SELECT * FROM quote_lines WHERE quote_id = $quote_id ORDER BY line_id ASC");
$sub = 0; $vat = 0;
while ($l = DB::fetch_assoc($res_l)) {
    $lt   = (float)$l['quantity'] * (float)$l['price_each'];
    $sub += $lt;
    $vat += $lt * ((float)$l['line_vat_rate'] / 100);
    $lines_html .= '<tr><td>' . htmlspecialchars($l['line_text']) . '</td><td align="right">' . fmt_dk((float)$l['quantity']) . '</td>'
        . '<td align="right">' . fmt_dk((float)$l['price_each']) . '</td><td align="right" style="font-weight:bold;">' . fmt_dk($lt) . '</td></tr>';
}
$lines_html .= '</tbody></table>';
doc_block('block-lines', $lines_html, $layouts);

doc_block('block-totals', '<table style="width:98%; margin-left:2%; border-collapse:collapse;">'
    . '<tr><td>' . lang('@Subtotal') . ':</td><td align="right">' . fmt_dk($sub) . '</td></tr>'
    . '<tr><td>' . lang('@VAT') . ':</td><td align="right">' . fmt_dk($vat) . '</td></tr>'
    . '<tr style="font-size:1.15em; font-weight:bold; border-top:2px solid #333;"><td>' . lang('@Total') . ':</td><td align="right">' . fmt_dk($sub + $vat) . ' ' . htmlspecialchars($cur) . '</td></tr></table>', $layouts);

$q_note = trim($q['quote_note'] ?? '');
doc_block('block-notes', '<div style="border-top:1px solid #eee; padding-top:5px; font-size:13px;"><small style="color:#888;">' . lang('@Notes') . ':</small><br>'
    . ($q_note !== '' ? nl2br(htmlspecialchars($q_note)) : '<span class="design-placeholder">(' . lang('@Notes') . ')</span>') . '</div>', $layouts, $q_note !== '' ? '' : 'block-empty');

// Fod (ingen betalingsblok) - og "ikke en faktura"-teksten (kun på et tilbud, ikke en ordrebekræftelse)
doc_foot_blocks($s, $layouts, $co, false);
if ($status === 'draft' || $status === 'sent') {
    doc_block('block-foot-message', lang('@This is a quote, not an invoice. No goods or services have been delivered and no payment is due.'), $layouts, '', ' font-size:11px; color:#888; text-align:center; font-style:italic;');
}
echo '</div>'; // .doc-paper
?>
<?php
doc_lang_pop();  // gendan bogholderens sprog straks efter papiret
echo doc_flow_js('quote-page', 'block-lines');      // (mange linjer skubber totaler og fod ned)
$GLOBALS['no_floating_menu'] = true;
?>
<script>
const CSRF_TOKEN_Q = <?php echo json_encode(csrf_token()); ?>;

function generateAndSendQuote(quoteId) {
    const element = document.getElementById('quote-page');
    const mailBtn = document.getElementById('mailBtn');
    if (!element) { alert("<?php echo lang('@Could not find quote element.'); ?>"); return; }
    if (typeof html2pdf === 'undefined') { alert("<?php echo lang('@PDF library not loaded.'); ?>"); return; }
    if (mailBtn) { mailBtn.disabled = true; mailBtn.innerText = "<?php echo lang('@Sending...'); ?>"; }
    docPdf(element, 'quote_' + quoteId + '.pdf').outputPdf('blob').then(function(pdfBlob) {
        const formData = new FormData();
        formData.append('pdf_file', pdfBlob, 'quote_' + quoteId + '.pdf');
        formData.append('csrf_token', CSRF_TOKEN_Q);
        fetch('send_quote_action.php?id=' + quoteId, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = 'quote_view.php?id=' + quoteId;
                } else {
                    alert("<?php echo lang('@Error during dispatch:'); ?> " + data.error);
                    if (mailBtn) { mailBtn.disabled = false; mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>"; }
                }
            })
            .catch(function(err) {
                alert("<?php echo lang('@Network error: Server rejected data. Check console (F12) for details.'); ?>");
                if (mailBtn) { mailBtn.disabled = false; mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>"; }
            });
    });
}
</script>
<?php
echo doc_pdf_js();
if ($lay['on']) echo doc_design_js(['paper' => 'quote-page', 'layout' => $lay['id'], 'doc' => 'quote', 'btn' => 'qDesignBtn',
    'url' => 'quote_view.php?id=' . $quote_id, 'names' => array_map(fn($l) => $l['name'], $lay['list'])]);
htm_Footer(); ?>
