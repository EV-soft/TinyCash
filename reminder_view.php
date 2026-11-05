<?php # /reminder_view.php v:1.0.0 d:2026-11-04 i:evs
# Rykkerblanket (bruger-anmodet): rykkeren for en forfalden faktura som et A4-papir - til udskrift/brev eller PDF,
# med designtilstand og egne rykkerlayouts (invoice_layouts.doc_type = 'reminder'; det første er en kopi af
# fakturaens standardlayout). Tekst, restbeløb og gebyr kommer fra inc/reminder.inc.php - de samme som rykkermailen.
# Viser den næste rykker (eller den der er sendt i dag); ?no=N viser rykker nr. N.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/doc_paper.inc.php';
require_once 'inc/doc_design.inc.php';
require_once 'inc/reminder.inc.php';

$inv_id = (int)($_GET['id'] ?? 0);
$inv = reminder_fetch($conn, $inv_id);
if (!$inv || strtolower((string)$inv['inv_status']) === 'draft') redirect('reminders.php', '@Not found - it may already have been deleted.', 'error');

$s = get_settings($conn);
$sent_count  = (int)$inv['reminder_count'];
$sent_today  = !empty($inv['reminder_sent_at']) && substr((string)$inv['reminder_sent_at'], 0, 10) === date('Y-m-d');
$reminder_no = (int)($_GET['no'] ?? 0);
// Kun de sendte rykkere og den næste ("rykker nr. 99" fandtes ikke)
if ($reminder_no < 1 || $reminder_no > $sent_count + 1) $reminder_no = ($sent_count > 0 && ($sent_today || !reminder_due($inv))) ? $sent_count : $sent_count + 1;
$is_next = ($reminder_no === $sent_count + 1);
// Den næste rykker findes kun for en faktura der kan rykkes for - en betalt faktura fik et brev om, at den ikke var betalt
if ($is_next && !reminder_due($inv)) redirect('invoice_view.php?id=' . $inv_id, '@This invoice is not overdue or has been paid - there is nothing to remind about right now.', 'info');
// Gebyret: den næste rykker regnes fra den seneste. En sendt rykker vises med gebyret fra afsendelsen (revisionsloggen;
// ukendt = intet gebyr) - før fik den altid et gebyr, også når det ikke var opkrævet
$sent_log     = $is_next ? [] : reminder_sent_log($conn, $inv_id);
$prev_sent_at = $is_next ? ($inv['reminder_sent_at'] ?: null) : ($sent_log[$reminder_no - 1]['at'] ?? null);
$sent_at      = $is_next ? null : ($sent_log[$reminder_no]['at'] ?? null);
$sent_fee     = $is_next ? null : ($sent_log[$reminder_no]['fee'] ?? ($sent_at && $prev_sent_at ? null : 0.0));

$lay = doc_layout_setup($conn, 'reminder');      // (?layout= viser og designer et andet rykkerlayout)

$rm_head = reminder_compose($s, $inv, $reminder_no, $prev_sent_at, $sent_at, $sent_fee);      // (på bogholderens sprog - til sidens titel)
htm_Header($rm_head['title'] . ' ' . $rm_head['f_no']);
showMenu();
echo doc_design_css();

doc_profile_banner($s);
if (!reminder_due($inv)) {
    echo '<div class="no-print" style="width:210mm; margin:10px auto;">';
    htm_Banner('<i class="fa-solid fa-circle-info"></i> ' . lang('@This invoice is not overdue or has been paid - there is nothing to remind about right now.'), 'info');
    echo '</div>';
}

// Knaprækken
doc_toolbar_();
if ($is_next && reminder_due($inv) && !empty($inv['cust_email']) && !$sent_today) {      // (sender den NÆSTE rykker - kun når det er den, der vises)
    htm_Button(icon: 'fa-envelope', labl: '@Send Reminder', type: 'info', link: 'reminder_action.php?id=' . $inv_id,
        attr: 'onclick="' . htm_confirm_js(sprintf(lang('@Send a payment reminder to %s?'), $inv['cust_email'])) . '" data-hint="' . lang('@Email a reminder for the invoice to the customer (you are asked first)') . '"');
}
if ($lay['on']) {
    htm_Button(icon: 'fa-pencil-ruler', labl: '@Design Mode', type: 'secondary', attr: 'onclick="docToggleDesign()" id="rDesignBtn" data-hint="' . lang('@Move, resize and hide the blocks of the reminder - saved in the reminder layout shown') . '"');
}
htm_Button(icon: 'fa-file-pdf', labl: '@Download PDF', type: 'secondary', attr: 'onclick="docPdfSave(\'reminder-page\', \'rykker_' . preg_replace('/\D/', '', $rm_head['f_no']) . '_' . $reminder_no . '.pdf\')" data-hint="' . lang('@Save the reminder as a PDF file - e.g. to send it by post or attach it yourself') . '"');
htm_Button(icon: 'fa-print', labl: '@Print', type: 'primary', attr: 'onclick="window.print()"');
htm_Button(icon: 'fa-file-invoice', labl: '@Invoice', type: 'secondary', link: 'invoice_view.php?id=' . $inv_id);
htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'reminders.php');
echo '</div>';

if ($lay['on']) doc_layout_bar($lay['list'], $lay['id'], lang('@Show the reminder with another layout - Design Mode moves the blocks of the layout shown here'));

// Selve papiret - på kundens sprog (som fakturaen og rykkermailen); knapperne ovenfor er på bogholderens sprog
doc_lang_push((string)($inv['cust_language'] ?? ''));
$co = doc_company($s);
$rm = reminder_compose($s, $inv, $reminder_no, $prev_sent_at, $sent_at, $sent_fee);
$layouts = $lay['blocks'];
echo '<div class="doc-paper" id="reminder-page">';
doc_head_blocks($layouts, $co, $inv['cust_name'], $inv['cust_address'] ?? '');

// Titel + fakturanummer, dato, oprindeligt forfald
doc_block('block-inv-no', '<div style="font-size:20px; font-weight:bold; text-transform:uppercase;">' . htmlspecialchars($rm['title']) . '</div>'
    . '<span style="font-size:14px;">' . lang('@Invoice') . ' ' . htmlspecialchars($rm['f_no']) . '</span>', $layouts);
doc_block('block-inv-date', '<strong>' . lang('@Date') . ':</strong><br>' . date(CONF_DATE_FORMAT, $sent_at ? strtotime(db_ts_local($sent_at)) : time()), $layouts);      // (en sendt rykker: afsendelsesdagen)
doc_block('block-inv-due', '<strong>' . lang('@Due Date') . ':</strong><br>' . $rm['due_date_fmt'] . ' (' . sprintf(lang('@%d days overdue'), $rm['days_overdue']) . ')', $layouts);

// Brevteksten (samme tekst som rykkermailen)
doc_block('block-lines', '<div style="font-size:14px; line-height:1.5;">' . nl2br(htmlspecialchars($rm['body_text'])) . '</div>', $layouts);

// Beløb: restbeløb, evt. gebyr, at betale (gebyret i bogføringsvalutaen)
$tot = '<table style="width:98%; margin-left:2%; border-collapse:collapse;">'
     . '<tr><td>' . lang('@Amount due') . ':</td><td align="right">' . htmlspecialchars($rm['amount_fmt']) . '</td></tr>';
if ($rm['fee'] > 0) {
    $tot .= '<tr><td>' . lang('@Reminder fee') . ':</td><td align="right">' . htmlspecialchars($rm['fee_fmt']) . '</td></tr>';
    if ($rm['show_cur'] === $rm['cur']) {
        $tot .= '<tr style="font-size:1.15em; font-weight:bold; border-top:2px solid #333;"><td>' . lang('@Total to pay') . ':</td><td align="right">'
              . fmt_dk($rm['show_amount'] + $rm['fee']) . ' ' . htmlspecialchars($rm['cur']) . '</td></tr>';
    }
}
$tot .= '</table>';
doc_block('block-totals', $tot, $layouts);

doc_foot_blocks($s, $layouts, $co);
echo '</div>'; // .doc-paper
doc_lang_pop();
$GLOBALS['no_floating_menu'] = true;
echo doc_pdf_js();
if ($lay['on']) echo doc_design_js(['paper' => 'reminder-page', 'layout' => $lay['id'], 'doc' => 'reminder', 'btn' => 'rDesignBtn',
    'url' => 'reminder_view.php?id=' . $inv_id . '&no=' . $reminder_no, 'names' => array_map(fn($l) => $l['name'], $lay['list'])]);
htm_Footer();
