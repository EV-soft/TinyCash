<?php # /account_card.php v:1.3.1 d:2026-09-15 i:evs
# Kontokort: alle posteringer på ÉN konto i kronologisk rækkefølge med en
# løbende saldo, forud for perioden opsummeret til en primo-linje. Det
# naturlige "drill-down" fra saldobalancen (trial_balance.php) og fra
# kontoplanen (chart_of_accounts.php) - typisk brugt til stikprøvekontrol
# ("vis mig alle bevægelser på konto X i perioden").
#
# I modsætning til trial_balance.php's periode-kolonner udelukkes
# year_end_close-lukkeposteringen IKKE her - den er en reel, enkeltstående
# postering på kontoen, og en revisor der sporer en konto hen over en
# årsafslutning skal netop kunne SE den linje (og se saldoen falde til 0
# efter den), ikke få den skjult. Se trial_balance.php's header for hvorfor
# den modsatte regel gælder DÉR (aggregeret periode-sum, ikke en linjeliste).
#
# BEVIDST ingen filtrering på is_cancelled - en annulleret postering og dens
# modpostering skal begge vises, så den løbende saldo korrekt reflekterer at
# de netter hinanden ud (samme regel som ledger_view.php/annual_report.lib.php).
# Annullerede linjer vises nedtonet/gennemstreget, samme visuelle mønster som
# ledger_view.php, men TÆLLER stadig med i saldoen.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s = get_settings($conn);
$currency = $s['currency'] ?? 'DKK';

$default_from = date('Y-01-01');
$default_to   = date('Y-m-d');
$from = $_GET['from'] ?? $default_from;
$to   = $_GET['to']   ?? $default_to;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $default_to;

// Hent kontoplanen til vælgeren
$acc_options = [];
require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: kontokortet viser ALLE konti (også slukkede - historikken), mærket
$acc_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts ORDER BY acc_id ASC");
if ($acc_res) {
    while ($a = DB::fetch_assoc($acc_res)) {
        $acc_options[$a['acc_id']] = $a['acc_id'] . ' - ' . acc_label($a['acc_name'], $a['acc_status'] ?? null);
    }
}
$first_acc_id = !empty($acc_options) ? array_key_first($acc_options) : 0;
$acc_id = isset($_GET['acc_id']) ? (int)$_GET['acc_id'] : $first_acc_id;

htm_Header('@Account Card');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<form method="get" style="margin-bottom:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">';
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@Account') . ':</label>';
if (empty($acc_options)) {
    htm_Alert(lang('@No accounts found'), 'info');
} else {
    htm_Select('acc_id', $acc_options, (string)$acc_id, 'padding:6px; min-width:280px;', 'onchange="this.form.submit()"');
}
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@From') . ':</label>';
echo '<input type="date" name="from" value="' . htmlspecialchars($from) . '" style="padding:6px; border:1px solid var(--border-color); border-radius:4px;">';
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@To') . ':</label>';
echo '<input type="date" name="to" value="' . htmlspecialchars($to) . '" style="padding:6px; border:1px solid var(--border-color); border-radius:4px;">';
htm_Button('fa-filter', '@Filter', 'primary', '', '', 'type="submit"');
echo '</form>';

if (!empty($acc_options) && $acc_id > 0) {
    $ps = DB::escape($conn, $from);
    $pe = DB::escape($conn, $to);

    $acc_row = DB::fetch_assoc(DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts WHERE acc_id = $acc_id"));

    if (!$acc_row) {
        htm_Alert(lang('@Account not found'), 'error');
    } else {
        // Primo: saldo af ALLE posteringer på kontoen før periodens start -
        // ingen trans_type-udelukkelse, skal afspejle den faktiske saldo.
        $primo_row = DB::fetch_assoc(DB::query($conn, "
            SELECT SUM(l.amount) AS primo
            FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
            WHERE l.acc_id = $acc_id AND j.jou_date < '$ps'"));
        $primo = (float)($primo_row['primo'] ?? 0);

        $lines_sql = "SELECT j.jou_id, j.jou_date, j.jou_text, j.voucher_no, j.is_cancelled, l.amount
                       FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
                       WHERE l.acc_id = $acc_id AND j.jou_date BETWEEN '$ps' AND '$pe'
                       ORDER BY j.jou_date ASC, j.jou_id ASC, l.led_id ASC";
        $lines_res = DB::query($conn, $lines_sql);

        $lines = [];
        if ($lines_res) { while ($lr = DB::fetch_assoc($lines_res)) { $lines[] = $lr; } }

        htm_Card_(lang('@Account Card') . ': ' . $acc_row['acc_id'] . ' - ' . htmlspecialchars($acc_row['acc_name']), 1000);

        $headers = ['@Date', '@Journal #', '@Description', '@Debit', '@Credit', '@Balance'];
        $data = [];

        // Primo-linje øverst, visuelt adskilt
        $data[] = [
            '', '', '<em>' . lang('@Opening balance carried forward') . '</em>', '', '',
            '<div style="text-align:right; font-weight:bold;">' . number_format($primo, 2, ',', '.') . '</div>'
        ];

        $running = $primo;
        foreach ($lines as $l) {
            $running += (float)$l['amount'];
            $is_cancelled = ((int)($l['is_cancelled'] ?? 0) === 1);
            $rowStyle = $is_cancelled ? 'text-decoration:line-through; opacity:0.55;' : '';

            $debit  = ($l['amount'] > 0) ? number_format($l['amount'], 2, ',', '.') : '';
            $credit = ($l['amount'] < 0) ? number_format(abs($l['amount']), 2, ',', '.') : '';

            $data[] = [
                "<span style='$rowStyle'>" . date(CONF_DATE_FORMAT, strtotime($l['jou_date'])) . '</span>',
                "<span style='$rowStyle'>#" . $l['jou_id'] . (!empty($l['voucher_no']) ? ' (' . lang('@Voucher') . ' ' . $l['voucher_no'] . ')' : '') . '</span>',
                "<span style='$rowStyle'>" . htmlspecialchars($l['jou_text']) . '</span>',
                "<div style='text-align:right; color:green; $rowStyle'>$debit</div>",
                "<div style='text-align:right; color:red; $rowStyle'>$credit</div>",
                "<div style='text-align:right; font-weight:bold; $rowStyle'>" . number_format($running, 2, ',', '.') . '</div>',
            ];
        }

        if (empty($lines)) {
            $data[] = ['', '', '<em style="color:var(--text-muted);">' . lang('@No transactions found for this account in the selected period.') . '</em>', '', '', ''];
        }

        htm_Table($headers, $data, 'account_card_tbl', 200, '', true, [], '600px', 'kontokort_' . $acc_row['acc_id'] . '.csv');

        echo '<div style="display:flex; justify-content:space-between; padding:14px 0; margin-top:8px; border-top:2px solid var(--color-primary); font-weight:bold;">';
        echo '<span>' . lang('@Closing Balance') . '</span><span>' . number_format($running, 2, ',', '.') . ' ' . $currency . '</span>';
        echo '</div>';

        htm_Card_end();
    }
}

echo "</div>";
htm_Footer();
