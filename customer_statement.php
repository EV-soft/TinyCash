<?php # /customer_statement.php v:1.3.0 d:2026-08-30 i:evs
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

$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cust_id <= 0) { header("Location: sales_hub.php"); exit; }

$res_c = DB::query($conn, "SELECT * FROM customers WHERE cust_id = $cust_id");
$cust  = DB::fetch_assoc($res_c);
if (!$cust) { header("Location: sales_hub.php"); exit; }

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to   = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : '';

// --- Hent alle bogførte fakturaer (kladder udelades - ingen fakturanummer,
// ingen reel transaktion endnu) ---
$res_i = DB::query($conn, "SELECT inv_id, invoice_no, inv_date, inv_due_date, credit_ref, inv_status
                            FROM invoices
                            WHERE cust_id = $cust_id AND LOWER(inv_status) != 'draft'
                            ORDER BY inv_date ASC, inv_id ASC");
$invoices = [];
while ($row = DB::fetch_assoc($res_i)) { $invoices[] = $row; }

// --- Byg den fulde, kronologiske begivenhedsliste (hele historikken,
// uanset periodefilter - saldoen skal altid være korrekt, se "Saldo primo"
// nedenfor) ---
$events           = [];
$open_invoices    = 0;
$overdue_invoices = 0;
$today            = date('Y-m-d');

foreach ($invoices as $inv) {
    // RETTET (§reel-multi-valuta-bogforing, §bugs-batch-32-review): brugte
    // FØR fakturaens EGEN valuta (fx EUR) uden gange med exch_rate, men
    // blandede den ind i en ÉN samlet løbende DKK-saldo sammen med rigtige
    // bankbetalinger nedenfor - kundekontoudtoget (ofte sendt direkte til
    // kunden, eller brugt internt til rykning) viste derfor en helt forkert
    // saldo for enhver udenlandsk faktura. Samme fejlklasse fundet og rettet
    // samme runde i reconcile_action.php/invoice_view.php/sales_hub.php/
    // reminders.php/aging_report.php/reconcile_list.php - bruger her den
    // fælles invoice_dkk_totals() (inc/db_connect.inc.php).
    $inv_total = invoice_dkk_totals($conn, $inv['inv_id'])['incl'];

    $is_credit = !empty($inv['credit_ref']);
    $inv_label = ($is_credit ? lang('@Credit Note') : lang('@Invoice')) . ' #' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT);

    $events[] = [
        'date'   => $inv['inv_date'],
        'label'  => $inv_label,
        'link'   => 'invoice_view.php?id=' . $inv['inv_id'],
        'amount' => $inv_total,
    ];

    $paid_row = DB::fetch_assoc(DB::query($conn,
        "SELECT COALESCE(SUM(amount), 0) AS paid FROM invoice_payments WHERE inv_id = " . $inv['inv_id']));
    $paid = round((float)($paid_row['paid'] ?? 0), 2);
    $remaining = round($inv_total - $paid, 2);

    // RETTET (se [[bugs-batch-10-review]]): $remaining tjekkede kun
    // invoice_payments - en faktura der er blevet krediteret (afskrevet via
    // en kreditnota i stedet for en rigtig betaling) beholdt sit fulde
    // oprindelige beløb som "resterende" her, og talte fejlagtigt med i
    // "Åbne fakturaer"/"Forfaldne" ovenfor, selvom den reelt er afsluttet -
    // kreditnotaen optræder ganske vist også som sin egen begivenhed og
    // nulstiller den samlede saldo korrekt, men denne tælling kigger kun på
    // den enkelte faktura isoleret.
    $is_credited = (strtolower($inv['inv_status'] ?? '') === 'credited');
    if (!$is_credit && !$is_credited && $remaining > 0.01) {
        $open_invoices++;
        if (!empty($inv['inv_due_date']) && $inv['inv_due_date'] < $today) $overdue_invoices++;
    }

    $pay_res = DB::query($conn, "SELECT payment_date, amount FROM invoice_payments
                                  WHERE inv_id = " . $inv['inv_id'] . " ORDER BY payment_date ASC, payment_id ASC");
    while ($p = DB::fetch_assoc($pay_res)) {
        $events[] = [
            'date'   => $p['payment_date'],
            'label'  => lang('@Payment for invoice') . ' #' . str_pad((string)$inv['invoice_no'], 6, '0', STR_PAD_LEFT),
            'link'   => 'invoice_view.php?id=' . $inv['inv_id'],
            'amount' => round((float)$p['amount'], 2) * -1,
        ];
    }
}

// Kronologisk rækkefølge - stabil sortering, samme dato beholder
// indsættelsesrækkefølgen (faktura før dens egne betalinger).
usort($events, function ($a, $b) { return strcmp($a['date'], $b['date']); });

// Løbende saldo over HELE historikken.
$running = 0;
foreach ($events as &$e) { $running += $e['amount']; $e['balance'] = round($running, 2); }
unset($e);
$total_outstanding = round($running, 2);

// --- Periodefilter: saldoen bevares korrekt via en "Saldo primo"-linje,
// selv når kun en del af historikken vises. ---
$opening_balance = 0;
$visible_events  = [];
foreach ($events as $e) {
    if ($from !== '' && $e['date'] < $from) { $opening_balance = $e['balance']; continue; }
    if ($to   !== '' && $e['date'] > $to)   { continue; }
    $visible_events[] = $e;
}

$title = lang('@Account Statement') . ': ' . $cust['cust_name'];
htm_Header($title, 1100);
showMenu();

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
    . '<div style="font-size:1.6em; font-weight:bold; color:' . $bal_color . ';">' . number_format($total_outstanding, 2, ',', '.') . ' ' . $cur . '</div></div>';
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
