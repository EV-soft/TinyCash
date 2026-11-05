<?php # /customer_statement_view.php v:1.0.0 d:2026-11-05 i:evs
# Kontoudtog som blanket (bruger-anmodet): kundens kontoudtog som et A4-papir med firmahoved - til udskrift/brev eller
# PDF, med designtilstand og egne kontoudtogslayouts (invoice_layouts.doc_type = 'statement'; det første er en kopi af
# fakturaens standardlayout). Tallene kommer fra get_customer_statement_data() - de samme som customer_statement.php
# og mailen. ?from=/?to= giver samme periode som siden.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/doc_paper.inc.php';
require_once 'inc/doc_design.inc.php';
require_once 'inc/customer_statement.inc.php';

$cust_id = (int)($_GET['id'] ?? 0);
$from = (string)($_GET['from'] ?? '');
$to   = (string)($_GET['to'] ?? '');
foreach (['from', 'to'] as $cs_k) if ($$cs_k !== '' && !tc_is_date($$cs_k)) $$cs_k = '';
if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];      // (en omvendt periode gav et tomt udtog med "saldo primo" fra slutdatoen)
$period_qs = '&from=' . urlencode($from) . '&to=' . urlencode($to);

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$lay = doc_layout_setup($conn, 'statement');      // (?layout= viser og designer et andet kontoudtogslayout)

// Papiret er på kundens sprog - også rækketeksterne, som get_customer_statement_data() bygger med lang()
$cust_lang = doc_customer_lang($conn, $cust_id);
doc_lang_push($cust_lang);
$stmt = get_customer_statement_data($conn, $cust_id, $from, $to);
doc_lang_pop();
if ($stmt === null) redirect('sales_hub.php', '@Not found - it may already have been deleted.', 'error');
$cust = $stmt['cust'];

htm_Header(lang('@Account Statement') . ': ' . $cust['cust_name']);
showMenu();
echo doc_design_css();

doc_profile_banner($s);

// Knaprækken
doc_toolbar_();
if (!empty($cust['cust_email'])) {
    htm_Button(icon: 'fa-envelope', labl: '@Send by Mail', type: 'info',
        attr: 'onclick="' . htm_confirm_js(sprintf(lang('@Send this account statement to %s?'), $cust['cust_email']), "tcPost('customer_statement_send.php?id=$cust_id$period_qs')") . '" data-hint="' . lang('@Email this account statement to the customer') . '"');
}
if ($lay['on']) {
    htm_Button(icon: 'fa-pencil-ruler', labl: '@Design Mode', type: 'secondary', attr: 'onclick="docToggleDesign()" id="sDesignBtn" data-hint="' . lang('@Move, resize and hide the blocks of the account statement - saved in the layout shown') . '"');
}
htm_Button(icon: 'fa-file-pdf', labl: '@Download PDF', type: 'secondary', attr: 'onclick="docPdfSave(\'statement-page\', \'kontoudtog_' . $cust_id . '_' . date('Y-m-d') . '.pdf\')" data-hint="' . lang('@Save the account statement as a PDF file - e.g. to send it by post or attach it yourself') . '"');
htm_Button(icon: 'fa-print', labl: '@Print', type: 'primary', attr: 'onclick="window.print()"');
htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'customer_statement.php?id=' . $cust_id . $period_qs);
echo '</div>';

if ($lay['on']) doc_layout_bar($lay['list'], $lay['id'], lang('@Show the account statement with another layout - Design Mode moves the blocks of the layout shown here'));

// Selve papiret - på kundens sprog; knapperne ovenfor er på bogholderens sprog
doc_lang_push($cust_lang);
$co = doc_company($s);
$layouts = $lay['blocks'];
echo '<div class="doc-paper" id="statement-page">';
doc_head_blocks($layouts, $co, $cust['cust_name'], $cust['cust_address'] ?? '',
    !empty($cust['cust_cvr']) ? '<br>' . lang('@CVR') . ': ' . htmlspecialchars($cust['cust_cvr']) : '');

// Titel + kundenummer, dato, periode
doc_block('block-inv-no', '<div style="font-size:20px; font-weight:bold; text-transform:uppercase;">' . lang('@Account Statement') . '</div>'
    . '<span style="font-size:14px;">' . lang('@Customer No.') . ' ' . $cust_id . '</span>', $layouts);
doc_block('block-inv-date', '<strong>' . lang('@Date') . ':</strong><br>' . date(CONF_DATE_FORMAT), $layouts);
$period = ($from === '' && $to === '')
    ? sprintf(lang('@All transactions up to %s'), date(CONF_DATE_FORMAT))
    : ($from !== '' ? date(CONF_DATE_FORMAT, strtotime($from)) : '...') . ' - ' . ($to !== '' ? date(CONF_DATE_FORMAT, strtotime($to)) : date(CONF_DATE_FORMAT));
doc_block('block-inv-due', '<strong>' . lang('@Period') . ':</strong><br>' . $period, $layouts);

// Posteringerne med løbende saldo (variabel længde - doc_flow_js() skubber blokkene under den ned)
$td = 'padding:6px 8px; border-bottom:1px solid #eee; font-size:13px; color:#222;';
$tdr = $td . ' text-align:right; white-space:nowrap;';
$rows = '';
if ($from !== '' && !empty($stmt['opening_balance'])) {
    $rows .= '<tr><td style="' . $td . '">' . date(CONF_DATE_FORMAT, strtotime($from)) . '</td><td style="' . $td . '"><em>' . lang('@Opening Balance') . '</em></td>'
           . '<td style="' . $tdr . '"></td><td style="' . $tdr . '">' . fmt_dk($stmt['opening_balance']) . '</td></tr>';
}
foreach ($stmt['visible_events'] as $e) {
    $rows .= '<tr><td style="' . $td . ' white-space:nowrap;">' . date(CONF_DATE_FORMAT, strtotime($e['date'])) . '</td><td style="' . $td . '">' . htmlspecialchars($e['label']) . '</td>'
           . '<td style="' . $tdr . '">' . fmt_dk($e['amount']) . '</td><td style="' . $tdr . '">' . fmt_dk($e['balance']) . '</td></tr>';
}
if ($rows === '') $rows = '<tr><td colspan="4" style="' . $td . ' text-align:center; color:#888;">' . lang('@No transactions in this period.') . '</td></tr>';
$th = 'background:#f8f9fa; border-bottom:2px solid #333; padding:8px; font-size:13px; color:#222;';
doc_block('block-lines', '<table style="width:100%; border-collapse:collapse;"><tr>'
    . '<th style="' . $th . ' text-align:left; width:22%;">' . lang('@Date') . '</th><th style="' . $th . ' text-align:left;">' . lang('@Description') . '</th>'
    . '<th style="' . $th . ' text-align:right; width:20%;">' . lang('@Amount') . ' (' . htmlspecialchars($cur) . ')</th><th style="' . $th . ' text-align:right; width:20%;">' . lang('@Running Balance') . ' (' . htmlspecialchars($cur) . ')</th>'
    . '</tr>' . $rows . '</table>', $layouts);

// Saldo: ved periodens slutning (til-dato) - ellers den samlede saldo i dag
$bal_label = $to !== '' ? lang('@Closing Balance') . ' ' . date(CONF_DATE_FORMAT, strtotime($to)) : lang('@Total Outstanding');
$bal       = $to !== '' ? $stmt['closing_balance'] : $stmt['total_outstanding'];
doc_block('block-totals', '<table style="width:98%; margin-left:2%; border-collapse:collapse;">'
    . '<tr style="font-size:1.15em; font-weight:bold; border-top:2px solid #333;"><td style="padding-top:6px;">' . $bal_label . ':</td>'
    . '<td align="right" style="padding-top:6px; white-space:nowrap;">' . fmt_dk($bal) . ' ' . htmlspecialchars($cur) . '</td></tr></table>', $layouts);
doc_block('block-notes', '<div style="font-size:12px; color:#555;">' . lang('@Please contact us if the balance does not match your own records.') . '</div>', $layouts);

doc_foot_blocks($s, $layouts, $co);
echo '</div>'; // .doc-paper
doc_lang_pop();
$GLOBALS['no_floating_menu'] = true;
echo doc_flow_js('statement-page', 'block-lines');
echo doc_pdf_js();
if ($lay['on']) echo doc_design_js(['paper' => 'statement-page', 'layout' => $lay['id'], 'doc' => 'statement', 'btn' => 'sDesignBtn',
    'url' => 'customer_statement_view.php?id=' . $cust_id . $period_qs, 'names' => array_map(fn($l) => $l['name'], $lay['list'])]);
htm_Footer();
