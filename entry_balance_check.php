<?php # /entry_balance_check.php v:1.3.1 d:2026-09-15 i:evs
# Bilagsbalance-kontrol: verificerer at HVERT ENKELT bilag (jou_id) selv
# balancerer til 0 - en mere finkornet kontrol end trial_balance.php's
# aggregerede primo/ultimo-tjek. To ubalancerede bilag kan sagtens udligne
# hinanden i totalen (og dermed sno sig forbi saldobalancens integritetstjek)
# uden at være reelle, gyldige dobbelt-bogholderi-posteringer hver for sig -
# det er netop den klasse fejl dette værktøj er bygget til at fange.
#
# Fanger to distinkte problemtyper:
#  1) Et bilag med linjer hvis SUM(amount) ikke er 0 (afrundet til 2 decimaler
#     for at undgå falske positiver fra flydende-komma-støj).
#  2) Et "spøgelses-bilag" - en journal-post der slet INGEN ledger-linjer har
#     (fx hvis en posteringsproces fejlede halvvejs uden om transaktions-
#     beskyttelsen) - fanges via LEFT JOIN, ikke kun INNER JOIN.
#
# Rent læse-værktøj, ingen skrivning/rettelse - retter man et fundet problem,
# sker det via en Diverse Postering (journal_entry.php) eller manuel DB-
# indgriben, aldrig herfra.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$total_row = DB::fetch_assoc(DB::query($conn, "SELECT COUNT(*) AS c FROM journal"));
$total_entries = (int)($total_row['c'] ?? 0);

$bad_res = DB::query($conn, "
    SELECT j.jou_id, j.jou_date, j.jou_text, j.voucher_no, j.trans_type,
           COALESCE(ROUND(SUM(l.amount), 2), 0) AS net,
           COUNT(l.led_id) AS line_count
    FROM journal j
    LEFT JOIN ledger l ON l.jou_id = j.jou_id
    GROUP BY j.jou_id, j.jou_date, j.jou_text, j.voucher_no, j.trans_type
    HAVING line_count = 0 OR ABS(ROUND(net, 2)) > 0.01
    ORDER BY j.jou_date ASC, j.jou_id ASC
");

$bad_entries = [];
if ($bad_res) {
    while ($r = DB::fetch_assoc($bad_res)) {
        $lines = [];
        if ((int)$r['line_count'] > 0) {
            // RETTET: LEFT JOIN - også linjer på en konto der ikke findes i kontoplanen vises
            $lres = DB::query($conn, "SELECT l.acc_id, a.acc_name, l.amount
                FROM ledger l LEFT JOIN accounts a ON l.acc_id = a.acc_id
                WHERE l.jou_id = " . (int)$r['jou_id'] . " ORDER BY l.led_id ASC");
            if ($lres) { while ($lr = DB::fetch_assoc($lres)) $lines[] = $lr; }
        }
        $r['lines'] = $lines;
        $bad_entries[] = $r;
    }
}

htm_Header('@Journal Entry Balance Check');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<p style="color:var(--text-muted); margin-bottom:20px;">'
    . lang('@Checks that every single journal entry balances to zero on its own (debit equals credit), not just the grand total across all entries. Two individually-broken entries can cancel each other out in the aggregate and slip past the trial balance\'s own integrity check unnoticed.')
    . '</p>';

htm_Card_('@Journal Entry Balance Check', 1000);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:0;">' . sprintf(lang('@%d journal entries checked in total.'), $total_entries) . '</p>';

if (empty($bad_entries)) {
    htm_Alert(lang('@All checked journal entries balance individually - debit equals credit on every single entry.'), 'success', 900, true);
} else {
    htm_Alert(sprintf(lang('@%d of %d journal entries do NOT balance individually:'), count($bad_entries), $total_entries), 'error', 900, true);

    foreach ($bad_entries as $e) {
        $is_orphan = ((int)$e['line_count'] === 0);
        echo '<div style="border:1px solid var(--border-color); border-radius:6px; margin-bottom:15px; overflow:hidden;">';
        echo '<div style="background:var(--bg-panel); padding:10px 15px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">';
        echo '<div>'
            . '<strong>#' . (int)$e['jou_id'] . '</strong>'
            . (!empty($e['voucher_no']) ? ' (' . lang('@Voucher') . ' ' . (int)$e['voucher_no'] . ')' : ' <em>(' . lang('@Not posted') . ')</em>')
            . ' — ' . date(CONF_DATE_FORMAT, strtotime($e['jou_date']))
            . ' — ' . htmlspecialchars($e['jou_text'])
            . '</div>';
        if ($is_orphan) {
            echo '<span style="color:var(--color-danger); font-weight:bold;">' . lang('@No ledger lines at all') . '</span>';
        } else {
            echo '<span style="color:var(--color-danger); font-weight:bold;">' . lang('@Imbalance') . ': ' . number_format((float)$e['net'], 2, ',', '.') . '</span>';
        }
        echo '</div>';

        if (!$is_orphan) {
            $rows = [];
            foreach ($e['lines'] as $l) {
                $debit  = ($l['amount'] > 0) ? number_format($l['amount'], 2, ',', '.') : '';
                $credit = ($l['amount'] < 0) ? number_format(abs($l['amount']), 2, ',', '.') : '';
                $rows[] = [
                    $l['acc_id'] . ' - ' . htmlspecialchars($l['acc_name']),
                    '<div style="text-align:right; color:green;">' . $debit . '</div>',
                    '<div style="text-align:right; color:red;">' . $credit . '</div>',
                ];
            }
            echo '<table style="width:100%; border-collapse:collapse;">';
            foreach ($rows as $row) {
                echo '<tr><td style="padding:6px 15px; border-top:1px solid var(--border-subtle);">' . $row[0] . '</td>'
                    . '<td style="padding:6px 15px; border-top:1px solid var(--border-subtle); width:120px;">' . $row[1] . '</td>'
                    . '<td style="padding:6px 15px; border-top:1px solid var(--border-subtle); width:120px;">' . $row[2] . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }
}

htm_Card_end();
echo "</div>";
htm_Footer();
