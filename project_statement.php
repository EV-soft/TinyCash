<?php # /project_statement.php v:1.3.1 d:2026-09-15 i:evs
# Projekt-kontokort: en kronologisk oversigt pr. projekt/sag over bogførte
# fakturaer, fakturabetalinger, udlæg/omkostninger og andre indtægter, med
# løbende saldo - det klassiske "kontokort"-princip fra advokat-/
# konsulentbranchens sagsøkonomi. Forbillede: en "Sagers kontokort"-rapport
# fra et advokatsystem. Samme arkitektur
# som customer_statement.php - se inc/project_statement.inc.php for
# beregningslogikken og dens begrundelse for fortegns-konventionen (udlæg
# er en debet her, modsat project_view.php's rentabilitets-perspektiv).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/project_statement.inc.php';

$proj_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($proj_id <= 0) { header("Location: project_view.php"); exit; }

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to   = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : '';

$stmt = get_project_statement_data($conn, $proj_id, $from, $to);
if ($stmt === null) { header("Location: project_view.php"); exit; }
$proj             = $stmt['proj'];
$visible_events   = $stmt['visible_events'];
$opening_balance  = $stmt['opening_balance'];
$total_balance    = $stmt['total_balance'];

$title = lang('@Account Card') . ': ' . $proj['proj_no'];
htm_Header($title, 1100);
showMenu();

// --- Projektinfo ---
// RETTET (bruger-anmodet: "udskrivning af projektkort skal kun omfatte
// kortet samt nr./navn øverst") - hele dette kort (datoer/status/knapper)
// samt menuen (begge visningstilstande - topmenu ELLER sidebar) skjules nu
// ved print, se @media print-blokken i filens bund. Et separat, minimalt
// print-only-overskrift (kun sagsnummer + kundenavn) indsættes i stedet
// direkte i selve Kontokort-kortet nedenfor, synligt KUN ved print.
echo '<div class="no-print" id="projInfoCard">';
htm_Card_(capt: '@Project Information', wdth: 1100);
echo '<div style="display:flex; flex-wrap:wrap; gap:25px; align-items:flex-start;">';
echo '<div style="flex:1; min-width:220px;">';
echo '<strong style="font-size:1.1em;">' . htmlspecialchars($proj['proj_no']) . '</strong><br>';
echo htmlspecialchars($proj['cust_name'] ?? '—') . '<br>';
echo '</div>';
echo '<div style="flex:1; min-width:220px;">';
if ($proj['proj_start']) echo lang('@Start') . ': ' . date(CONF_DATE_FORMAT, strtotime($proj['proj_start'])) . '<br>';
if ($proj['proj_stop'])  echo lang('@End')   . ': ' . date(CONF_DATE_FORMAT, strtotime($proj['proj_stop']))  . '<br>';
echo htm_Badge($proj['is_active'] ? '@Active' : '@Inactive', $proj['is_active'] ? 'success' : 'secondary', false);
echo '</div>';
echo '<div class="no-print" style="display:flex; flex-direction:column; gap:8px;">';
    htm_Button(icon: 'fa-pencil', labl: '@Edit Project', type: 'secondary', link: 'project_edit.php?id=' . $proj_id, attr: 'data-hint="'.lang('@Edit this project\'s details').'"');
    htm_Button(icon: 'fa-print',  labl: '@Print',        type: 'primary',   link: '', attr: 'onclick="window.print()" data-hint="'.lang('@Print this statement').'"');
    htm_Button(icon: 'fa-arrow-left', labl: '@Back',     type: 'secondary', link: 'project_view.php?id=' . $proj_id, attr: 'data-hint="'.lang('@Back to the project').'"');
echo '</div>';
echo '</div>';
htm_Card_end();
echo '</div>'; // #projInfoCard

// --- Periodefilter ---
// RETTET (bruger-anmodet: "flyt filter ind i kontokortets tool") - fandtes
// FØR som sit eget kort over selve kontokortet - flyttet ind i selve
// kontokort-kortets tool-område (samme lille værktøjslinje ved siden af
// kort-titlen, som fx time_list.php/quote_list.php allerede bruger til en
// enkelt knap) - her i stedet en kompakt, inline periodevælger, da den hører
// naturligt sammen med netop DETTE kort, ikke som et selvstændigt filter-kort
// for sig. htm_Field() er for "tung" (hel fieldset/legend-boks pr. felt) til
// en værktøjslinje - håndbygget i stedet, samme tema-CSS-variabler som
// resten af appen allerede bruger konsekvent.
// RETTET (bruger-rapporteret: "datofilteret er for småt") - padding/
// skriftstørrelse forøget, og en min-width tilføjet paa selve datofelterne,
// saa den fulde dato (dd-mm-åååå) altid er synlig uden at blive klippet af.
$filter_tool = '<form method="get" action="project_statement.php" class="no-print" style="display:flex; gap:8px; align-items:center;">'
    . '<input type="hidden" name="id" value="' . $proj_id . '">'
    . '<input type="date" name="from" value="' . htmlspecialchars($from) . '" style="padding:7px 10px; min-width:150px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main); font-size:1em;" title="' . htmlspecialchars(lang('@From'), ENT_QUOTES) . '">'
    . '<span style="color:var(--text-muted);">–</span>'
    . '<input type="date" name="to" value="' . htmlspecialchars($to) . '" style="padding:7px 10px; min-width:150px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main); font-size:1em;" title="' . htmlspecialchars(lang('@To'), ENT_QUOTES) . '">'
    . '<button type="submit" style="background:var(--color-primary); color:var(--text-light); border:none; border-radius:4px; padding:8px 14px; cursor:pointer; font-size:1em;" data-hint="' . htmlspecialchars(lang('@Filter the statement to the selected date range'), ENT_QUOTES) . '"><i class="fa fa-filter"></i></button>';
if ($from !== '' || $to !== '') {
    $filter_tool .= '<a href="project_statement.php?id=' . $proj_id . '" style="color:var(--text-muted); padding:8px; font-size:1.1em;" data-hint="' . htmlspecialchars(lang('@Clear the date filter and show all history'), ENT_QUOTES) . '"><i class="fa fa-times"></i></a>';
}
$filter_tool .= '</form>';

// --- Kontokort (tabel + sammendrag i ÉT kort) ---
// RETTET (bruger-anmodet: "flyt resume ind i kortes bund") - "@Summary"
// fandtes FØR som sit eget kort ovenfor selve kontokortet - saldoen hører
// naturligt til EFTER selve tabellen (en slutsum under de linjer, den
// summerer), flyttet til bunden af dette kort i stedet, se nedenfor efter
// htm_Table()-kaldet.
htm_Card_(capt: '@Account Card', wdth: 1100, tool: $filter_tool);

// Print-only overskrift (sagsnummer + kundenavn) - se begrundelsen ved
// Projektinfo-kortet ovenfor. Skjult på skærm, vist KUN ved print.
echo '<div class="print-only" style="display:none; margin-bottom:12px;">'
    . '<strong style="font-size:1.2em;">' . htmlspecialchars($proj['proj_no']) . '</strong>'
    . ($proj['cust_name'] ? ' — ' . htmlspecialchars($proj['cust_name']) : '')
    . '</div>';

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
        '600px', 'kontokort_' . $proj_id . '.csv');
}

// --- Sammendrag (saldo) - se begrundelsen for flytningen ovenfor. ---
$bal_color = $total_balance > 0.01 ? 'var(--color-danger)' : ($total_balance < -0.01 ? 'var(--color-success)' : 'var(--text-main)');
echo '<div style="margin-top:15px; padding-top:15px; border-top:1px solid var(--border-subtle); display:flex; justify-content:flex-end; align-items:center; gap:10px;">'
    . '<span style="font-size:0.85em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Balance') . '</span>'
    . '<span style="font-size:1.4em; font-weight:bold; color:' . $bal_color . ';">' . number_format($total_balance, 2, ',', '.') . ' ' . $cur . '</span>'
    . '</div>';

htm_Card_end();

// RETTET (bruger-anmodet: "udskrivning af projektkort skal kun omfatte
// kortet samt nr./navn øverst") - udvidet fra kun .no-print/.floating-
// action-bar til også at skjule selve menuen (#top-nav i topmenu-tilstand,
// #tc-sidebar i sidebar-tilstand - se inc/menu.inc.php) og toolbaren/
// zoom-knapperne i htm_Header(), så KUN kontokort-kortet (med print-only-
// overskriften sat ind ovenfor) er synligt ved print.
// RETTET (bruger-anmodet: "rulleknap og csvknap skal heller ikke med") -
// CSV-eksportknappen (htm_Table()'s egen, ID-løse knap - matches via dens
// onclick-indhold, kun denne sides eget print-CSS, påvirker ikke andre
// sider der også bruger htm_Table()) kan skjules med almindelig CSS.
// RETTET (bruger-rapporteret: "jeg ser rulleknappen" - selvom #fab-scroll-top
// allerede ligger inde i .floating-action-bar, som allerede var skjult, og
// derfor IKKE var den reelle årsag): "rulleknappen" var tabellens egen
// scrollbar - htm_Table()'s ID-løse scroll-wrapper (margin-bottom:60px;
// max-height:'.$vhgh.'; overflow:auto;, se inc/php2htm.lib.php) klipper
// tabellen til 600px på skærmen, og samme klipning (med synlig scrollbar)
// gjaldt hidtil også ved print - en udskrevet kontoudskrift skal vise HELE
// tabellen hen over flere sider, ikke kun det synlige scroll-udsnit. Fjernet
// via JS ved selve print-tidspunktet (wrapperen har ingen class/id at ramme
// med almindelig CSS - den er en anonym div umiddelbart før #statementTbl).
echo '<style>@media print {
    .no-print, .floating-action-bar, #top-nav, #tc-sidebar, #projInfoCard,
    #tc-hint, button[onclick*="exportCsv"] { display:none !important; }
    .print-only { display:block !important; }
    body.sidebar-mode { margin-left:0 !important; margin-top:0 !important; }
}</style>';
?>
<script>
(function() {
    var _printHidden = [];
    window.onbeforeprint = function() {
        var tbl = document.getElementById('statementTbl');
        var wrap = tbl ? tbl.parentElement : null;
        if (wrap) {
            _printHidden.push({el: wrap, maxHeight: wrap.style.maxHeight, overflow: wrap.style.overflow});
            wrap.style.setProperty('max-height', 'none', 'important');
            wrap.style.setProperty('overflow', 'visible', 'important');
        }
    };
    window.onafterprint = function() {
        _printHidden.forEach(function(item) {
            item.el.style.removeProperty('max-height');
            item.el.style.removeProperty('overflow');
            if (item.maxHeight) item.el.style.maxHeight = item.maxHeight;
            if (item.overflow) item.el.style.overflow = item.overflow;
        });
        _printHidden = [];
    };
})();
</script>
<?php
htm_Footer();
ob_end_flush();
?>
