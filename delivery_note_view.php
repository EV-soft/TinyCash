<?php # /delivery_note_view.php v:1.0.0 d:2026-11-05 i:evs
# Følgeseddel (bruger-anmodet): det papir der følger med varerne - afsender, leveringsadresse, varenr./tekst/antal UDEN
# priser, en "pakket"-kolonne og en kvittering for modtagelsen. Dannes fra en faktura (?inv=) eller en ordrebekræftelse
# (et accepteret tilbud, ?quote=). Designtilstand og egne layouts (invoice_layouts.doc_type = 'delivery'; det første er
# en kopi af fakturaens). Ingen egen nummerserie: følgesedlen bærer fakturaens/ordrens nummer.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/doc_paper.inc.php';
require_once 'inc/doc_design.inc.php';

$inv_id   = (int)($_GET['inv'] ?? 0);
$quote_id = (int)($_GET['quote'] ?? 0);
$s = get_settings($conn);

// Kilden: fakturaen (ikke en kreditnota) eller en accepteret/konverteret ordre
if ($inv_id > 0) {
    $src = DB::fetch_assoc(DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_date AS doc_date, i.inv_status, i.credit_ref, i.cust_id, i.cust_reference,
            i.delivery_address, i.inv_note AS note, c.cust_name, c.cust_address, c.cust_phone
        FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id"));
    if (!$src) redirect('sales_hub.php', '@Not found - it may already have been deleted.', 'error');
    if (!empty($src['credit_ref'])) redirect("invoice_view.php?id=$inv_id", '@A credit note has no delivery note.', 'error');
    $lines_sql = "SELECT l.line_text, l.quantity, p.prod_sku FROM invoice_lines l LEFT JOIN products p ON p.prod_id = l.prod_id WHERE l.inv_id = $inv_id ORDER BY l.line_id";
    $back      = "invoice_view.php?id=$inv_id";
    $self      = "delivery_note_view.php?inv=$inv_id";
    $file_no   = $src['invoice_no'] ?: 'kladde' . $inv_id;
} else {
    $src = $quote_id > 0 ? DB::fetch_assoc(DB::query($conn, "SELECT q.quote_id, q.quote_no, q.quote_date AS doc_date, q.status, q.cust_id, q.cust_reference,
            q.delivery_address, q.quote_note AS note, c.cust_name, c.cust_address, c.cust_phone
        FROM quotes q JOIN customers c ON q.cust_id = c.cust_id WHERE q.quote_id = $quote_id")) : null;
    if (!$src) redirect('quote_list.php', '@Not found - it may already have been deleted.', 'error');
    if (!in_array($src['status'], ['accepted', 'converted'], true)) redirect("quote_view.php?id=$quote_id", '@A delivery note can only be made for an accepted order.', 'error');
    $lines_sql = "SELECT l.line_text, l.quantity, p.prod_sku FROM quote_lines l LEFT JOIN products p ON p.prod_id = l.prod_id WHERE l.quote_id = $quote_id ORDER BY l.line_id";
    $back      = "quote_view.php?id=$quote_id";
    $self      = "delivery_note_view.php?quote=$quote_id";
    $file_no   = 'T' . $src['quote_no'];
}
$lines = [];
$res = DB::query($conn, $lines_sql);
while ($res && ($l = DB::fetch_assoc($res))) if ((float)$l['quantity'] > 0) $lines[] = $l;      // (kun det der leveres)

$lay = doc_layout_setup($conn, 'delivery');      // (?layout= viser og designer et andet følgeseddellayout)
$cust_lang = doc_customer_lang($conn, (int)$src['cust_id']);

htm_Header(lang('@Delivery Note') . ' ' . $file_no);
showMenu();
echo doc_design_css();
doc_profile_banner($s);

doc_toolbar_();
if ($lay['on']) {
    htm_Button(icon: 'fa-pencil-ruler', labl: '@Design Mode', type: 'secondary', attr: 'onclick="docToggleDesign()" id="dDesignBtn" data-hint="' . lang('@Move, resize and hide the blocks of the delivery note - saved in the layout shown') . '"');
}
htm_Button(icon: 'fa-file-pdf', labl: '@Download PDF', type: 'secondary', attr: 'onclick="docPdfSave(\'delivery-page\', \'' . doc_file_label('foelgeseddel', '@Delivery Note') . '_' . preg_replace('/[^A-Za-z0-9]/', '', (string)$file_no) . '.pdf\')" data-hint="' . lang('@Save the delivery note as a PDF file') . '"');
htm_Button(icon: 'fa-print', labl: '@Print', type: 'primary', attr: 'onclick="window.print()"');
htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: $back);
echo '</div>';

if ($lay['on']) doc_layout_bar($lay['list'], $lay['id'], lang('@Show the delivery note with another layout - Design Mode moves the blocks of the layout shown here'));

// Selve papiret - på kundens sprog
doc_lang_push($cust_lang);
$co = doc_company($s);
$layouts = $lay['blocks'];
echo '<div class="doc-paper" id="delivery-page">';
echo doc_shapes_html(il_shapes($conn, (int)$lay['id']));      // (layoutets grafik - bag teksten)

// Modtager = leveringsadressen, når den er udfyldt (ellers kundens adresse); telefon til chaufføren
$deliv = trim((string)($src['delivery_address'] ?? ''));
doc_head_blocks($layouts, $co, $src['cust_name'], $deliv !== '' ? $deliv : (string)($src['cust_address'] ?? ''),
    !empty($src['cust_phone']) ? '<br>' . lang('@Phone') . ': ' . htmlspecialchars($src['cust_phone']) : '');
$ref = trim((string)($src['cust_reference'] ?? ''));
doc_block('block-cust-ref', '<div style="font-size:13px;"><small style="color:#888;">' . lang('@Your Reference') . ':</small> '
    . ($ref !== '' ? htmlspecialchars($ref) : '<span class="design-placeholder">(' . lang('@Your Reference') . ')</span>') . '</div>', $layouts, $ref !== '' ? '' : 'block-empty');

$src_label = $inv_id > 0
    ? (!empty($src['invoice_no']) ? lang('@Invoice') . ' #' . str_pad((string)$src['invoice_no'], 6, '0', STR_PAD_LEFT) : lang('@Draft'))
    : lang('@Order') . ' T-' . str_pad((string)$src['quote_no'], 6, '0', STR_PAD_LEFT);
doc_block('block-inv-no', '<div style="font-size:20px; font-weight:bold; text-transform:uppercase;">' . lang('@Delivery Note') . '</div>'
    . '<span style="font-size:14px;">' . htmlspecialchars($src_label) . '</span>', $layouts);
doc_block('block-inv-date', '<strong>' . lang('@Date') . ':</strong><br>' . date(CONF_DATE_FORMAT), $layouts);
doc_block('block-inv-due', '<strong>' . lang('@Order date') . ':</strong><br>' . date(CONF_DATE_FORMAT, strtotime((string)$src['doc_date'])), $layouts);

// Varerne - uden priser; en tom "pakket"-boks pr. linje til lageret
$td  = 'padding:7px 8px; border-bottom:1px solid #eee; font-size:13px; color:#222;';
$th  = 'background:#f8f9fa; border-bottom:2px solid #333; padding:8px; font-size:13px; color:#222;';
$box = '<span style="display:inline-block; width:12px; height:12px; border:1px solid #555;"></span>';
$rows = '';  $qty_total = 0.0;
foreach ($lines as $l) {
    $qty_total += (float)$l['quantity'];
    $rows .= '<tr><td style="' . $td . ' white-space:nowrap;">' . htmlspecialchars((string)($l['prod_sku'] ?? '')) . '</td><td style="' . $td . '">' . htmlspecialchars((string)$l['line_text']) . '</td>'
           . '<td style="' . $td . ' text-align:right; white-space:nowrap;">' . rtrim(rtrim(fmt_dk((float)$l['quantity'], 3), '0'), ',') . '</td><td style="' . $td . ' text-align:center;">' . $box . '</td></tr>';
}
if ($rows === '') $rows = '<tr><td colspan="4" style="' . $td . ' text-align:center; color:#888;">' . lang('@No items to deliver.') . '</td></tr>';
doc_block('block-lines', '<table style="width:100%; border-collapse:collapse;"><tr>'
    . '<th style="' . $th . ' text-align:left; width:18%;">' . lang('@Item No.') . '</th><th style="' . $th . ' text-align:left;">' . lang('@Description') . '</th>'
    . '<th style="' . $th . ' text-align:right; width:12%;">' . lang('@Qty') . '</th><th style="' . $th . ' text-align:center; width:12%;">' . lang('@Packed') . '</th>'
    . '</tr>' . $rows . '</table>', $layouts);

// Antal og kvittering for modtagelsen
$sign = '<td style="padding-top:28px; border-bottom:1px solid #333;"></td>';
doc_block('block-totals', '<div style="font-size:13px; margin-bottom:8px;"><strong>' . sprintf(lang('@%d line(s), %s item(s) in total'), count($lines), rtrim(rtrim(fmt_dk($qty_total, 3), '0'), ',')) . '</strong></div>'
    . '<table style="width:100%; border-collapse:collapse; font-size:12px;"><tr><td colspan="3"><strong>' . lang('@Received in good condition') . '</strong></td></tr>'
    . '<tr>' . $sign . '<td style="width:6%;"></td>' . $sign . '</tr>'
    . '<tr><td style="color:#666;">' . lang('@Date') . '</td><td></td><td style="color:#666;">' . lang('@Signature') . '</td></tr></table>', $layouts);

$note = trim((string)($src['note'] ?? ''));
doc_block('block-notes', '<div style="border-top:1px solid #eee; padding-top:5px; font-size:13px;"><small style="color:#888;">' . lang('@Notes') . ':</small><br>'
    . ($note !== '' ? nl2br(htmlspecialchars($note)) : '<span class="design-placeholder">(' . lang('@Notes') . ')</span>') . '</div>', $layouts, $note !== '' ? '' : 'block-empty');

doc_foot_blocks($s, $layouts, $co, false);      // (ingen betalingsblok - en følgeseddel skal ikke betales)
echo '</div>'; // .doc-paper
doc_lang_pop();
echo doc_flow_js('delivery-page', 'block-lines');      // (mange linjer skubber kvitteringen og foden ned)
$GLOBALS['no_floating_menu'] = true;
echo doc_pdf_js();
if ($lay['on']) echo doc_design_js(['paper' => 'delivery-page', 'layout' => $lay['id'], 'doc' => 'delivery', 'btn' => 'dDesignBtn', 'shapes' => il_shapes_ready($conn),
    'url' => $self, 'names' => array_map(fn($l) => $l['name'], $lay['list'])]);
htm_Footer();
