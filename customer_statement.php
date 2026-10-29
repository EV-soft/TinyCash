<?php # /customer_statement.php v:1.3.1 d:2026-09-15 i:evs
# Kundekontoudtog: en kronologisk oversigt pr. kunde over bogførte fakturaer
# og registrerede indbetalinger, med løbende saldo - det klassiske
# "kontoudtog"-princip. Kladder tæller ikke med (ikke reelle transaktioner
# endnu), kreditnotaer tæller naturligt med som negative beløb. "Åbne
# fakturaer"/"Forfaldne"-tællerne udelader fakturaer der er fuldt betalt
# ELLER afskrevet via en kreditnota (inv_status='credited') - en krediteret
# faktura skal ikke tælle som åben/forfalden selvom den ikke har nogen
# registreret indbetaling.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/customer_statement.inc.php';

$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cust_id <= 0) { header("Location: sales_hub.php"); exit; }

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to   = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : '';
// RETTET: periodedatoerne blev ikke kontrolleret - en ugyldig dato ("31-06-2026", "abc") blev sammenlignet som tekst
// og vist som 01-01-1970, og udtoget viste stille den forkerte periode. En ugyldig dato ignoreres nu.
foreach (['from', 'to'] as $cs_k) if ($$cs_k !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$cs_k) || !checkdate((int)substr($$cs_k, 5, 2), (int)substr($$cs_k, 8, 2), (int)substr($$cs_k, 0, 4)))) $$cs_k = '';

// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): al beregnings-
// logik (kronologisk begivenhedsliste, løbende saldo, "Saldo primo",
// åbne/forfaldne fakturaer) udtrukket til get_customer_statement_data() -
// se inc/customer_statement.inc.php for baggrunden. customer_statement_send.php
// (den nye "Send på mail"-handling) genbruger nøjagtig samme funktion.
$stmt = get_customer_statement_data($conn, $cust_id, $from, $to);
if ($stmt === null) { header("Location: sales_hub.php"); exit; }
$cust              = $stmt['cust'];
$invoices          = $stmt['invoices'];
$events            = $stmt['events'];
$visible_events    = $stmt['visible_events'];
$opening_balance   = $stmt['opening_balance'];
$total_outstanding = $stmt['total_outstanding'];
$open_invoices     = $stmt['open_invoices'];
$overdue_invoices  = $stmt['overdue_invoices'];

$title = lang('@Account Statement') . ': ' . $cust['cust_name'];
htm_Header($title, 1100);
showMenu();

if (isset($_GET['msg']) && $_GET['msg'] === 'sent') {
    htm_Alert(lang('@Account statement sent successfully.'), 'success');
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'error') {
    htm_Alert(lang('@Could not send the account statement. Check your mail settings.'), 'error');
}

// --- Kundeinfo ---
htm_Card_(capt: '@Customer Information', wdth: 1100);
echo '<div style="display:flex; flex-wrap:wrap; gap:25px; align-items:flex-start;">';
echo '<div style="flex:1; min-width:220px;">';
echo '<strong style="font-size:1.1em;">' . htmlspecialchars($cust['cust_name']) . '</strong><br>';
echo nl2br(htmlspecialchars($cust['cust_address'] ?? '')) . '<br>';
if (!empty($cust['cust_cvr']))   echo lang('@CVR') . ': ' . htmlspecialchars($cust['cust_cvr']) . '<br>';
echo '</div>';
echo '<div style="flex:1; min-width:220px;">';
if (!empty($cust['cust_email'])) echo lang('@Email') . ': ' . htmlspecialchars($cust['cust_email']) . '<br>';
if (!empty($cust['cust_phone'])) echo lang('@Phone') . ': ' . htmlspecialchars($cust['cust_phone']) . '<br>';
if (!empty($cust['cust_contact_person'])) echo lang('@Contact Person') . ': ' . htmlspecialchars($cust['cust_contact_person']) . '<br>';
echo '</div>';
echo '<div class="no-print" style="display:flex; flex-direction:column; gap:8px;">';
    htm_Button(icon: 'fa-pencil', labl: '@Edit Customer', type: 'secondary', link: 'customer_edit.php?id=' . $cust_id, attr: 'data-hint="'.lang('@Edit this customer\'s details').'"');
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): "Send på
    // mail" - kun tilgængelig når kunden reelt har en registreret
    // mailadresse (matcher hvordan reminders.php/send_invoice_action.php
    // allerede stiltiende kræver en mailadresse). Sender netop den periode
    // der lige nu er vist ($from/$to følger med i linket), så en bogholder
    // der har filtreret til fx "denne måned" sender præcis det, de kan se.
    if (!empty($cust['cust_email'])) {
        $send_confirm = addslashes(sprintf(lang('@Send this account statement to %s?'), $cust['cust_email']));
        htm_Button(icon: 'fa-envelope', labl: '@Send by Mail', type: 'info', attr: 'onclick="if(confirm(\''.$send_confirm.'\')) window.location.href=\'customer_statement_send.php?id='.$cust_id.'&from='.urlencode($from).'&to='.urlencode($to).'\'" data-hint="'.lang('@Email this account statement to the customer').'"');
    }
    htm_Button(icon: 'fa-print',  labl: '@Print',         type: 'primary',   link: '', attr: 'onclick="window.print()" data-hint="'.lang('@Print this statement').'"');
    htm_Button(icon: 'fa-arrow-left', labl: '@Back',      type: 'secondary', link: 'sales_hub.php', attr: 'data-hint="'.lang('@Return to the sales hub').'"');
echo '</div>';
echo '</div>';
htm_Card_end();

// --- Sammendrag ---
htm_Card_(capt: '@Summary', wdth: 1100);
echo '<div style="display:flex; gap:20px; flex-wrap:wrap;">';
$bal_color = $total_outstanding > 0.01 ? 'var(--color-danger)' : ($total_outstanding < -0.01 ? 'var(--color-success)' : 'var(--text-main)');
echo '<div style="flex:1; min-width:180px; text-align:center; padding:10px;">'
    . '<div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Total Outstanding') . '</div>'
    . '<div style="font-size:1.6em; font-weight:bold; color:' . $bal_color . ';">' . number_format($total_outstanding, 2, ',', '.') . ' ' . $cur . '</div>'
    . ($to !== '' ? '<div style="font-size:0.85em; color:var(--text-muted);">' . lang('@Closing Balance') . ' ' . htmlspecialchars(date(CONF_DATE_FORMAT, strtotime($to))) . ': ' . number_format($stmt['closing_balance'], 2, ',', '.') . ' ' . $cur . '</div>' : '')      // RETTET: saldoen ved periodens slutning
    . '</div>';
echo '<div style="flex:1; min-width:180px; text-align:center; padding:10px;">'
    . '<div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Open Invoices') . '</div>'
    . '<div style="font-size:1.6em; font-weight:bold;">' . $open_invoices . '</div></div>';
echo '<div style="flex:1; min-width:180px; text-align:center; padding:10px;">'
    . '<div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Overdue') . '</div>'
    . '<div style="font-size:1.6em; font-weight:bold; color:' . ($overdue_invoices > 0 ? 'var(--color-danger)' : 'var(--text-main)') . ';">' . $overdue_invoices . '</div></div>';
echo '</div>';
htm_Card_end();

// --- Periodefilter ---
echo '<div class="no-print">';
htm_Card_(capt: '@Filter', wdth: 1100);
echo '<form method="get" action="customer_statement.php" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">';
echo '<input type="hidden" name="id" value="' . $cust_id . '">';
htm_Field(icon: 'fa-calendar', labl: '@From', name: 'from', valu: $from, type: 'date', wdth: '180px');
htm_Field(icon: 'fa-calendar', labl: '@To',   name: 'to',   valu: $to,   type: 'date', wdth: '180px');
echo '<div>';
htm_Button(icon: 'fa-filter', labl: '@Show Period', type: 'primary', link: '', attr: 'data-hint="'.lang('@Filter the statement to the selected date range').'"');
if ($from !== '' || $to !== '') {
    htm_Button(icon: 'fa-times', labl: '@Clear', type: 'secondary', link: 'customer_statement.php?id=' . $cust_id, attr: 'data-hint="'.lang('@Clear the date filter and show all history').'"');
}
echo '</div>';
echo '</form>';
htm_Card_end();
echo '</div>';

// --- Kontoudtog ---
htm_Card_(capt: '@Account Statement', wdth: 1100);

$headers = ['@Date', '@Description', '@Amount', '@Balance'];
$data    = [];

if ($from !== '' && !empty($opening_balance)) {
    $data[] = [
        date(CONF_DATE_FORMAT, strtotime($from)) . ' ' . lang('@or earlier'),
        '<em>' . lang('@Opening Balance') . '</em>',
        '',
        '<strong>' . number_format($opening_balance, 2, ',', '.') . ' ' . $cur . '</strong>',
    ];
}

foreach ($visible_events as $e) {
    $amount_color = $e['amount'] > 0 ? 'var(--color-danger)' : ($e['amount'] < 0 ? 'var(--color-success)' : 'var(--text-main)');
    $data[] = [
        date(CONF_DATE_FORMAT, strtotime($e['date'])),
        '<a href="' . $e['link'] . '">' . htmlspecialchars($e['label']) . '</a>',
        '<span style="color:' . $amount_color . ';">' . number_format($e['amount'], 2, ',', '.') . ' ' . $cur . '</span>',
        number_format($e['balance'], 2, ',', '.') . ' ' . $cur,
    ];
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No transactions in this period.') . "</p>";
} else {
    htm_Table($headers, $data, 'statementTbl', 200, '', true,
        ['width:120px;', '', 'width:160px; text-align:right;', 'width:160px; text-align:right;'],
        '600px', 'kontoudtog_' . $cust_id . '.csv');
}
htm_Card_end();

echo '<style>@media print { .no-print, .floating-action-bar { display:none !important; } }</style>';

htm_Footer();
ob_end_flush();
?>
