<?php # /aging_report.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Aldersfordelt restanceliste (bruger-anmodet, "byg
# leverandørmodul og aldersfordelt restanceliste"). To sektioner:
#  1) Debitorer - ubetalte, bogførte fakturaer (samme grundlag som
#     reminders.php/customer_statement.php), bucket-opdelt efter dage
#     overskredet forfaldsdato.
#  2) Kreditorer - udgifter registreret som "Ikke betalt endnu" (se
#     db-setup/migrate_suppliers.php), samme bucket-opdeling. Kræver
#     leverandørmodulets due_date/paid_date-kolonner - degraderer stille til
#     kun debitor-sektionen på en ikke-migreret installation.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$today = date('Y-m-d');

// Fælles bucket-inddeling for begge sektioner - "Ikke forfalden" er bevidst
// en selvstændig bucket, ikke bare en negativ dagværdi, så en fremtidig
// forfaldsdato ikke fejlagtigt kan blande sig ind i "0-30 dage".
// RETTET (§bugs-batch-24-review): "<= 0" ikke "< 0" - en faktura/udgift der
// forfalder PRÆCIS i dag blev før talt som "1-30 dage overskredet", i strid
// med den etablerede konvention andre steder i appen (reminders.php,
// customer_statement.php bruger begge en streng "< $today"-sammenligning,
// dvs. forfaldsdagen selv tæller IKKE som overskredet endnu).
function aging_bucket(int $days_overdue): string {
    if ($days_overdue <= 0)  return 'not_due';
    if ($days_overdue <= 30) return 'd30';
    if ($days_overdue <= 60) return 'd60';
    if ($days_overdue <= 90) return 'd90';
    return 'd90p';
}
$bucket_labels = [
    'not_due' => '@Not Yet Due', 'd30' => '@1-30 Days', 'd60' => '@31-60 Days',
    'd90' => '@61-90 Days', 'd90p' => '@90+ Days',
];

htm_Header('@Aging Report', 1300);
showMenu();

htm_Card_(capt: '@Aging Report', wdth: 1300);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Outstanding amounts grouped by how many days past due they are - the classic "restanceliste" used to prioritize follow-up.') .
    '</p>';
htm_Card_end();

// =============================================================================
// 1. DEBITORER (kunder / ubetalte fakturaer)
// =============================================================================
// RETTET (§reel-multi-valuta-bogforing, §bugs-batch-32-review): "total"
// ganges nu med exch_rate, ellers sammenlignes en udenlandsk fakturas EGEN
// valuta (fx EUR) direkte mod "paid" (altid DKK) - samme fejlklasse fundet
// og rettet samme runde i reconcile_action.php/invoice_view.php/sales_hub.
// php/reminders.php.
$res_i = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_due_date, c.cust_id, c.cust_name,
        (SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0)
         FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) AS total,
        (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
    FROM invoices i JOIN customers c ON i.cust_id = c.cust_id
    WHERE LOWER(i.inv_status) = 'sent' AND i.inv_due_date IS NOT NULL");

$debtor_rows = []; // keyed by cust_id
$debtor_totals = ['not_due' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0];

if ($res_i) {
    while ($row = DB::fetch_assoc($res_i)) {
        $due = (float)$row['total'] - (float)$row['paid'];
        if ($due <= 0.01) continue; // reelt allerede betalt (afrundingsrest)

        $days = (int)((strtotime($today) - strtotime($row['inv_due_date'])) / 86400);
        $bucket = aging_bucket($days);

        $cid = (int)$row['cust_id'];
        if (!isset($debtor_rows[$cid])) {
            $debtor_rows[$cid] = ['name' => $row['cust_name'], 'buckets' => ['not_due' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0], 'link' => 'customer_statement.php?id=' . $cid];
        }
        $debtor_rows[$cid]['buckets'][$bucket] += $due;
        $debtor_totals[$bucket] += $due;
    }
}

htm_Card_(capt: '@Accounts Receivable (Customers)', wdth: 1300);
echo '<div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px;">';
foreach ($bucket_labels as $bkey => $blabel) {
    $color = ($bkey === 'd90p' || $bkey === 'd90') ? 'var(--color-danger)' : (($bkey === 'd60') ? 'var(--color-warning)' : 'var(--text-main)');
    echo '<div style="flex:1; min-width:140px; background:var(--bg-panel); border-radius:6px; padding:12px; text-align:center;">'
        . '<div style="font-size:0.75em; color:var(--text-muted); text-transform:uppercase; font-weight:bold;">' . lang($blabel) . '</div>'
        . '<div style="font-size:1.2em; font-weight:bold; color:' . $color . ';">' . number_format($debtor_totals[$bkey], 0, ',', '.') . ' ' . $cur . '</div></div>';
}
$grand_total_debtor = array_sum($debtor_totals);
echo '<div style="flex:1; min-width:140px; background:var(--color-primary); border-radius:6px; padding:12px; text-align:center; color:#fff;">'
    . '<div style="font-size:0.75em; text-transform:uppercase; font-weight:bold; opacity:0.9;">' . lang('@Total Outstanding') . '</div>'
    . '<div style="font-size:1.2em; font-weight:bold;">' . number_format($grand_total_debtor, 0, ',', '.') . ' ' . $cur . '</div></div>';
echo '</div>';

$headers_d = ['@Customer', '@Not Yet Due', '@1-30 Days', '@31-60 Days', '@61-90 Days', '@90+ Days', '@Total'];
$data_d = [];
uasort($debtor_rows, fn($a, $b) => array_sum($b['buckets']) <=> array_sum($a['buckets']));
foreach ($debtor_rows as $r) {
    $row_total = array_sum($r['buckets']);
    $data_d[] = [
        '<a href="' . $r['link'] . '">' . htmlspecialchars($r['name']) . '</a>',
        number_format($r['buckets']['not_due'], 2, ',', '.'),
        $r['buckets']['d30']  > 0 ? '<span style="color:var(--text-main);">'    . number_format($r['buckets']['d30'],  2, ',', '.') . '</span>' : '-',
        $r['buckets']['d60']  > 0 ? '<span style="color:var(--color-warning);">' . number_format($r['buckets']['d60'],  2, ',', '.') . '</span>' : '-',
        $r['buckets']['d90']  > 0 ? '<span style="color:var(--color-danger);">'  . number_format($r['buckets']['d90'],  2, ',', '.') . '</span>' : '-',
        $r['buckets']['d90p'] > 0 ? '<strong style="color:var(--color-danger);">' . number_format($r['buckets']['d90p'], 2, ',', '.') . '</strong>' : '-',
        '<strong>' . number_format($row_total, 2, ',', '.') . ' ' . $cur . '</strong>',
    ];
}

if (empty($data_d)) {
    echo "<p style='padding:20px; text-align:center; color:var(--text-muted);'>" . lang('@No outstanding customer invoices.') . "</p>";
} else {
    htm_Table($headers_d, $data_d, 'debtorAgingTbl', 100, '', true,
        ['', 'text-align:right;', 'text-align:right;', 'text-align:right;', 'text-align:right;', 'text-align:right;', 'text-align:right;'],
        '500px', 'restanceliste_debitorer.csv');
}
htm_Card_end();

// =============================================================================
// 2. KREDITORER (leverandører / ikke betalte udgifter)
// =============================================================================
// @ - degraderer stille hvis migrate_suppliers.php ikke er kørt endnu
// (due_date/paid_date findes ikke), i stedet for at fejle hele siden.
$res_e = @DB::query($conn, "SELECT e.exp_id, e.supplier, e.supplier_id, e.due_date, e.amount, s.supplier_name
    FROM expenses e LEFT JOIN suppliers s ON e.supplier_id = s.supplier_id
    WHERE e.is_cancelled = 0 AND e.due_date IS NOT NULL AND e.paid_date IS NULL");

if ($res_e === false) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The supplier module database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration to enable the creditor side of this report.'), 'warning');
} else {
    $creditor_rows = []; // keyed by supplier_id, or 'txt:name' when unlinked
    $creditor_totals = ['not_due' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0];

    while ($row = DB::fetch_assoc($res_e)) {
        $days = (int)((strtotime($today) - strtotime($row['due_date'])) / 86400);
        $bucket = aging_bucket($days);
        $amount = (float)$row['amount'];

        $sid = !empty($row['supplier_id']) ? (int)$row['supplier_id'] : null;
        $key = $sid ? "sid_$sid" : 'txt_' . strtolower(trim($row['supplier'] ?? lang('@Unknown supplier')));
        $label = $sid ? $row['supplier_name'] : ($row['supplier'] ?: lang('@Unknown supplier'));
        $link = $sid ? ('supplier_statement.php?id=' . $sid) : '';

        if (!isset($creditor_rows[$key])) {
            $creditor_rows[$key] = ['name' => $label, 'buckets' => ['not_due' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0], 'link' => $link];
        }
        $creditor_rows[$key]['buckets'][$bucket] += $amount;
        $creditor_totals[$bucket] += $amount;
    }

    htm_Card_(capt: '@Accounts Payable (Suppliers)', wdth: 1300);
    echo '<div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:20px;">';
    foreach ($bucket_labels as $bkey => $blabel) {
        $color = ($bkey === 'd90p' || $bkey === 'd90') ? 'var(--color-danger)' : (($bkey === 'd60') ? 'var(--color-warning)' : 'var(--text-main)');
        echo '<div style="flex:1; min-width:140px; background:var(--bg-panel); border-radius:6px; padding:12px; text-align:center;">'
            . '<div style="font-size:0.75em; color:var(--text-muted); text-transform:uppercase; font-weight:bold;">' . lang($blabel) . '</div>'
            . '<div style="font-size:1.2em; font-weight:bold; color:' . $color . ';">' . number_format($creditor_totals[$bkey], 0, ',', '.') . ' ' . $cur . '</div></div>';
    }
    $grand_total_creditor = array_sum($creditor_totals);
    echo '<div style="flex:1; min-width:140px; background:var(--color-dark); border-radius:6px; padding:12px; text-align:center; color:#fff;">'
        . '<div style="font-size:0.75em; text-transform:uppercase; font-weight:bold; opacity:0.9;">' . lang('@Total Owed') . '</div>'
        . '<div style="font-size:1.2em; font-weight:bold;">' . number_format($grand_total_creditor, 0, ',', '.') . ' ' . $cur . '</div></div>';
    echo '</div>';

    $headers_c = ['@Supplier', '@Not Yet Due', '@1-30 Days', '@31-60 Days', '@61-90 Days', '@90+ Days', '@Total'];
    $data_c = [];
    uasort($creditor_rows, fn($a, $b) => array_sum($b['buckets']) <=> array_sum($a['buckets']));
    foreach ($creditor_rows as $r) {
        $row_total = array_sum($r['buckets']);
        $name_cell = $r['link'] !== '' ? ('<a href="' . $r['link'] . '">' . htmlspecialchars($r['name']) . '</a>') : htmlspecialchars($r['name']);
        $data_c[] = [
            $name_cell,
            number_format($r['buckets']['not_due'], 2, ',', '.'),
            $r['buckets']['d30']  > 0 ? '<span style="color:var(--text-main);">'    . number_format($r['buckets']['d30'],  2, ',', '.') . '</span>' : '-',
            $r['buckets']['d60']  > 0 ? '<span style="color:var(--color-warning);">' . number_format($r['buckets']['d60'],  2, ',', '.') . '</span>' : '-',
            $r['buckets']['d90']  > 0 ? '<span style="color:var(--color-danger);">'  . number_format($r['buckets']['d90'],  2, ',', '.') . '</span>' : '-',
            $r['buckets']['d90p'] > 0 ? '<strong style="color:var(--color-danger);">' . number_format($r['buckets']['d90p'], 2, ',', '.') . '</strong>' : '-',
            '<strong>' . number_format($row_total, 2, ',', '.') . ' ' . $cur . '</strong>',
        ];
    }

    if (empty($data_c)) {
        echo "<p style='padding:20px; text-align:center; color:var(--text-muted);'>" . lang('@No outstanding supplier bills.') . "</p>";
    } else {
        htm_Table($headers_c, $data_c, 'creditorAgingTbl', 100, '', true,
            ['', 'text-align:right;', 'text-align:right;', 'text-align:right;', 'text-align:right;', 'text-align:right;', 'text-align:right;'],
            '500px', 'restanceliste_kreditorer.csv');
    }
    htm_Card_end();
}

htm_Footer();
ob_end_flush();
?>
