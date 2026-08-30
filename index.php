<?php # /index.php v:1.3.0 d:2026-08-30 i:evs
# v1.3.0: ALVORLIGT FUND - pengestrøms-grafen ("Financial Performance") viste
# ALTID en kunstig, hårdkodet procentvis opdeling af alt-tids-totalsalget/
# -udgiften, aldrig rigtige periodetal, uanset om databasen indeholdt rigtige
# data. Erstattet med rigtige, dato-afgrænsede summer pr. bucket. Fandt
# samtidig at "NOW"-referencen for begge grafer var hårdkodet til en fast
# dato (2026-06-24) i stedet for den faktiske aktuelle dato, og at salgs-KPI'et
# (og nu også pengestrøms-grafen) inkluderede ubogførte kladdefakturaer som
# rigtigt salg. Alle tre rettet samtidig, fra forslagslisten.
# (Tvinger $is_demo_data til true hvis tabeller er tomme eller tabellerne ikke findes)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header(capt: '@Dashboard Overview', mwidth: 1000);
showMenu();

// --- 1. HÅNDTER VALG AF PERIODE FOR BEGGE GRAFER ---
$months_selected = isset($_POST['period_months']) ? (int)$_POST['period_months'] : 3;
if (!in_array($months_selected, [1, 3, 6, 12])) $months_selected = 3;

$flow_months_selected = isset($_POST['flow_period_months']) ? (int)$_POST['flow_period_months'] : 12;
if (!in_array($flow_months_selected, [3, 6, 12])) $flow_months_selected = 12;

// --- 2. HENT STATISTIK TIL KPI KORT ---
$total_sales = 0.0;
$total_expenses = 0.0;
$is_demo_data = false;

if (isset($conn) && $conn) {
    // RETTET: inkluderede før kladder ('draft') som rigtigt salg - kun
    // 'void' blev udelukket. En ubogført kladde er ikke en reel handel
    // endnu (intet fakturanummer, ingen postering) og skal ikke tælle med
    // i "Total Sales". Fundet ved gennemgangen af pengestrøms-grafen.
    $sales_query = "SELECT SUM(l.quantity * l.price_each) FROM invoices i JOIN invoice_lines l ON i.inv_id = l.inv_id WHERE LOWER(i.inv_status) NOT IN ('void', 'draft')";
    $res = @DB::query($conn, $sales_query);
    if ($res) {
        $row = DB::fetch_row($res);
        if ($row && isset($row[0]) && $row[0] !== null) {
            $total_sales = (float)$row[0];
        }
    }

    $expenses_query = "SELECT SUM(amount) FROM expenses WHERE is_cancelled = 0";
    $res2 = @DB::query($conn, $expenses_query);
    if ($res2) {
        $row2 = DB::fetch_row($res2);
        if ($row2 && isset($row2[0]) && $row2[0] !== null) {
            $total_expenses = (float)$row2[0];
        }
    }
}

// Vis demo hvis ingen reel omsætning (under 100 kr. total)
if ($total_sales < 100.0) {
    $total_sales = 45000.00;
    $total_expenses = 18500.00;
    $is_demo_data = true;
}

$net_profit = $total_sales - $total_expenses;
$profit_color = $net_profit >= 0 ? 'var(--theme-success, #2ecc71)' : 'var(--theme-danger, #e74c3c)';

// --- 3. HENT DATA TIL GRAF 1 (SALDO-KURVE) ---
$chart_dates = [];
$chart_balance = [];

if (isset($conn) && $conn && !$is_demo_data) {
    // RETTET: "NOW" var hårdkodet til en fast dato (2026-06-24) i stedet for
    // at bruge den faktiske aktuelle dato - grafens "sidste N måneder" endte
    // derfor et stykke i fortiden i stedet for i dag, uanset hvornår siden
    // reelt blev besøgt. Fundet ved gennemgangen af pengestrøms-grafen.
    $target_date = date('Y-m-d', strtotime("-$months_selected months"));
    $bank_query = "SELECT trans_date, amount FROM bank_statement_temp WHERE trans_date >= '$target_date' ORDER BY trans_date ASC";
    $res3 = @DB::query($conn, $bank_query);
    if ($res3 && DB::num_rows($res3) > 0) {
        $running_balance = 0;
        while ($row3 = DB::fetch_assoc($res3)) {
            $running_balance += (float)$row3['amount'];
            $chart_dates[] = date("d. M", strtotime($row3['trans_date']));
            $chart_balance[] = $running_balance;
        }
    }
}

if (empty($chart_dates)) {
    $is_demo_data = true; // Ingen banktransaktioner — vis demo-info
    $start_ts = strtotime("-$months_selected months");
    $end_ts = time();
    $step = ($end_ts - $start_ts) / 9;

    $fake_trends = [
        1  => [25000, 28000, 24000, 31000, 29000, 35000, 32000, 41000, 39000, 45000],
        3  => [15000, 19000, 12000, 22000, 18000, 29000, 24000, 34000, 31000, 38500],
        6  => [10000, 14000, 9000,  18000, 15000, 24000, 21000, 31000, 28000, 42000],
        12 => [5000,  12000, 8000,  19000, 14000, 26000, 22000, 35000, 31000, 55000]
    ];

    for ($i = 0; $i < 10; $i++) {
        $chart_dates[] = date("d. M", $start_ts + ($i * $step));
        $chart_balance[] = $fake_trends[$months_selected][$i];
    }
}

// --- 4. HENT DATA TIL GRAF 2 (PENGESTRØM) - RIGTIGE PERIODE-TAL ---
// RETTET: viste FØR altid en kunstig procentvis opdeling af den ALT-TIDS
// totalsalg/-udgift (fx "salesBase * 0.2, * 0.3, * 0.25, * 0.25" for
// kvartaler) - simulerede tal, aldrig hvad der reelt skete i den valgte
// periode, uanset om databasen indeholdt rigtige data eller ej. Erstattet
// med rigtige, dato-afgrænsede summer pr. bucket. Samme "ekskludér kladder"-
// rettelse som KPI-kortet ovenfor er anvendt her.
$flow_labels = [];
$flow_sales = [];
$flow_expenses = [];

if (isset($conn) && $conn) {
    $num_buckets  = ($flow_months_selected == 12) ? 4 : 3;
    $span_months  = intdiv($flow_months_selected, $num_buckets);

    // Bucket-grænser, ældste til nyeste - grænse[0] = periodens start,
    // grænse[num_buckets] = i morgen (så dagens dato altid er med i sidste
    // bucket, uden overlap ved bucket-skel).
    $boundaries = [];
    for ($k = 0; $k <= $num_buckets; $k++) {
        $boundaries[$k] = ($k == $num_buckets)
            ? date('Y-m-d', strtotime('+1 day'))
            : date('Y-m-d', strtotime('-' . ($flow_months_selected - $k * $span_months) . ' months'));
    }

    for ($i = 0; $i < $num_buckets; $i++) {
        $from = $boundaries[$i];
        $to   = $boundaries[$i + 1];

        $label_start = date('M', strtotime($from));
        $label_end   = date('M', strtotime($to . ' -1 day'));
        $flow_labels[] = ($label_start === $label_end) ? $label_start : "$label_start-$label_end";

        $sales_row = DB::fetch_row(@DB::query($conn,
            "SELECT SUM(l.quantity * l.price_each) FROM invoices i JOIN invoice_lines l ON i.inv_id = l.inv_id
             WHERE LOWER(i.inv_status) NOT IN ('void', 'draft') AND i.inv_date >= '$from' AND i.inv_date < '$to'"));
        $flow_sales[] = ($sales_row && $sales_row[0] !== null) ? (float)$sales_row[0] : 0.0;

        $exp_row = DB::fetch_row(@DB::query($conn,
            "SELECT SUM(amount) FROM expenses WHERE is_cancelled = 0 AND exp_date >= '$from' AND exp_date < '$to'"));
        $flow_expenses[] = ($exp_row && $exp_row[0] !== null) ? (float)$exp_row[0] : 0.0;
    }
}

// Samme demo-fallback-princip som graf 1 - kun når der reelt ingen data er
// (ikke bare fordi $is_demo_data blev sat af graf 1's egen, uafhængige tjek).
$flow_is_demo = (array_sum($flow_sales) < 1.0 && array_sum($flow_expenses) < 1.0);
if ($flow_is_demo) {
    $demo_flow_trends = [
        3  => [0.3, 0.4, 0.3],
        6  => [0.35, 0.3, 0.35],
        12 => [0.2, 0.3, 0.25, 0.25],
    ];
    $weights = $demo_flow_trends[$flow_months_selected] ?? $demo_flow_trends[3];
    $demo_sales_base = 45000.00;
    $demo_expense_base = 18500.00;
    foreach ($weights as $i => $w) {
        $flow_sales[$i] = round($demo_sales_base * $w, 2);
        $flow_expenses[$i] = round($demo_expense_base * $w, 2);
    }
}
?>

<style>
    .kpi-card { background: var(--theme-card-bg, #ffffff); color: var(--theme-text, #2c3e50); }
    .kpi-title { color: var(--theme-text-muted, #7f8c8d); }
    .period-select { border: 1px solid var(--theme-border, #ced4da); background: var(--theme-field-bg, #ffffff); color: var(--theme-text, #495057); }

    [data-theme="dark"] .kpi-card { background: #1e272e !important; color: #f5f6fa !important; box-shadow: 0 4px 6px rgba(0,0,0,0.3) !important; }
    [data-theme="dark"] .kpi-title { color: #a4b0be !important; }
    [data-theme="dark"] .period-select { background: #2f3542 !important; color: #f5f6fa !important; border-color: #57606f !important; }

    [data-theme="dark"] [class*="card"], [data-theme="dark"] [class*="panel"],
    [data-theme="dark"] .card-header, [data-theme="dark"] .panel-heading, [data-theme="dark"] .card-title,
    [data-theme="dark"] h1, [data-theme="dark"] h2, [data-theme="dark"] h3, [data-theme="dark"] h4 {
        color: #ffffff !important;
    }
</style>

<?php if ($is_demo_data): ?>
    <div style="background: var(--theme-info-bg, #eaf2f8); border-left: 5px dotted var(--theme-primary, #3498db); color: var(--theme-info-text, #2980b9); padding: 12px; margin-bottom: 20px; font-size: 13px; border-radius: 4px; width: 1000px; box-sizing: border-box;">
        ℹ️ <b><?php echo lang('@Information:'); ?></b> <?php echo lang('@The database is empty. Simulated demo data is displayed.'); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px; width: 1000px; box-sizing: border-box;">
    <div class="kpi-card" style="padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 8px dotted #2ecc71;">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold;"><?php echo lang('@Total Sales'); ?></div>
        <div style="font-size: 24px; font-weight: bold; margin-top: 5px;"><?php echo number_format($total_sales, 2, ',', '.'); ?> kr.</div>
    </div>
    <div class="kpi-card" style="padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 8px dotted #e74c3c;">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold;"><?php echo lang('@Total Expenses'); ?></div>
        <div style="font-size: 24px; font-weight: bold; margin-top: 5px;"><?php echo number_format($total_expenses, 2, ',', '.'); ?> kr.</div>
    </div>
    <div class="kpi-card" style="padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 8px dotted <?php echo $profit_color; ?>;">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold;"><?php echo lang('@Net Profit / Balance'); ?></div>
        <div style="font-size: 24px; font-weight: bold; color: <?php echo $profit_color; ?>; margin-top: 5px;"><?php echo number_format($net_profit, 2, ',', '.'); ?> kr.</div>
    </div>
</div>

<?php
$s = (isset($conn) && $conn) ? get_settings($conn) : [];
$db_date_format = (!empty($s['date_format'])) ? $s['date_format'] : "d.m.Y";

$date_start_balance = date($db_date_format, strtotime("-$months_selected months"));
$date_start_flow = date($db_date_format, strtotime("-$flow_months_selected months"));

$hint_raw = "@The baseline is NOW. The chart displays the period from %s up until NOW.";
$hint_text_balance = sprintf(lang($hint_raw), $date_start_balance);
$hint_text_flow = sprintf(lang($hint_raw), $date_start_flow);

function optiRow($var, $nbr = 1, $period = 'month') {
    $label_key = ($nbr == 1) ? "@{$nbr} {$period}" : "@{$nbr} " . $period . "s";
    return '<option value="' . $nbr . '" ' . ($var == $nbr ? 'selected' : '') . '>' . lang($label_key) . '</option>';
}

$period_selector_balance = '
<form method="post" style="margin: 0; display: inline-block;">
    '.csrf_field(false).'
    <select name="period_months" onchange="this.form.submit();" data-hint="'.htmlspecialchars($hint_text_balance, ENT_QUOTES).'" class="period-select" style="padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold;">
        ' . implode('', array_map(fn($n) => optiRow($months_selected, $n), [1, 3, 6, 12])) . '
    </select>
    <input type="hidden" name="flow_period_months" value="'.$flow_months_selected.'">
</form>';

$period_selector_flow = '
<form method="post" style="margin: 0; display: inline-block;">
    '.csrf_field(false).'
    <select name="flow_period_months" onchange="this.form.submit();" data-hint="'.htmlspecialchars($hint_text_flow, ENT_QUOTES).'" class="period-select" style="padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold;">
        ' . implode('', array_map(fn($n) => optiRow($flow_months_selected, $n), [3, 6, 12])) . '
    </select>
    <input type="hidden" name="period_months" value="'.$months_selected.'">
</form>';

htm_Card_(capt: '@Account Balance Trend', wdth: 1000, info: '', form: false, echo: true, tool: $period_selector_balance);
?>
<div style="width: 100%; height: 250px; position: relative; padding: 5px 0;"><canvas id="balanceTrendChart"></canvas></div>
<?php
htm_Card_end();
echo '<div style="margin-top: 20px;"></div>';
htm_Card_(capt: '@Financial Performance (Cash Flow)', wdth: 1000, info: '', form: false, echo: true, tool: $period_selector_flow);
if ($flow_is_demo && !$is_demo_data) {
    // Kun vist når DENNE graf specifikt falder tilbage til demo-tal, mens
    // resten af siden (KPI-kort, saldo-graf) viser rigtige data - fx hvis
    // der findes bogførte fakturaer/udgifter, men ingen af dem falder inden
    // for den valgte periode. Den fælles banner øverst dækker allerede
    // tilfældet hvor HELE siden er tom.
    echo "<div style='background: var(--theme-info-bg, #eaf2f8); border-left: 5px dotted var(--theme-primary, #3498db); color: var(--theme-info-text, #2980b9); padding: 10px 12px; margin-bottom: 12px; font-size: 12px; border-radius: 4px;'>"
        . "ℹ️ " . lang('@No posted sales or expenses in this period - simulated demo data is shown instead.') . "</div>";
}
?>
<div style="width: 100%; height: 250px; position: relative; padding: 5px 0;"><canvas id="cashFlowChart"></canvas></div>
<?php htm_Card_end(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var isDark = (document.documentElement.getAttribute("data-theme") || "light") === "dark";
    var gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
    var textColor = isDark ? '#e0e0e0' : '#666666';
    var chartScales = { x: { grid: { color: gridColor }, ticks: { color: textColor } }, y: { grid: { color: gridColor }, ticks: { color: textColor } } };
    var chartPlugins = { legend: { labels: { color: textColor } } };

    new Chart(document.getElementById('balanceTrendChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_dates); ?>,
            datasets: [{
                label: '<?php echo lang("@Running Balance"); ?>',
                data: <?php echo json_encode($chart_balance); ?>,
                borderColor: '#3498db',
                backgroundColor: isDark ? 'rgba(52, 152, 219, 0.15)' : 'rgba(52, 152, 219, 0.05)',
                fill: true, tension: 0.15, borderWidth: 2, pointRadius: 2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: chartScales, plugins: chartPlugins }
    });

    // RETTET: viste før en kunstig procentvis opdeling af alt-tids-totalen,
    // udregnet her i JS - aldrig hvad der reelt skete i den valgte periode.
    // $flow_labels/$flow_sales/$flow_expenses er nu rigtige, dato-afgrænsede
    // summer pr. bucket, udregnet server-side (se PHP-blokken "GRAF 2").
    new Chart(document.getElementById('cashFlowChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($flow_labels); ?>,
            datasets: [
                { label: '<?php echo lang("@Incomes (Sales)"); ?>', data: <?php echo json_encode($flow_sales); ?>, backgroundColor: isDark ? '#26de81' : '#2ecc71', borderRadius: 4 },
                { label: '<?php echo lang("@Outcomes (Purchases)"); ?>', data: <?php echo json_encode($flow_expenses); ?>, backgroundColor: isDark ? '#ff5252' : '#e74c3c', borderRadius: 4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: chartScales, plugins: chartPlugins }
    });
});
</script>

<?php
htm_Footer();
ob_end_flush();
?>