<?php # /supplier_statement.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Leverandørkontoudtog - kronologisk oversigt over en
# leverandørs bogførte udgifter (via supplier_id), med betalingsstatus.
# Simplere end customer_statement.php's rigtige "løbende saldo"-udtog: en
# udgift har ikke delvise betalinger (se db-setup/migrate_suppliers.php),
# kun binært betalt/ikke betalt, så en løbende saldo giver ikke samme mening.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($supplier_id <= 0) { header("Location: supplier_list.php"); exit; }

$res_s = DB::query($conn, "SELECT * FROM suppliers WHERE supplier_id = $supplier_id");
$sup   = $res_s ? DB::fetch_assoc($res_s) : null;
if (!$sup) { header("Location: supplier_list.php"); exit; }

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$title = lang('@Supplier Statement') . ': ' . $sup['supplier_name'];
htm_Header($title, 1000);
showMenu();

// --- Leverandørinfo ---
htm_Card_(capt: '@Supplier Information', wdth: 1000);
echo '<div style="display:flex; flex-wrap:wrap; gap:25px; align-items:flex-start;">';
echo '<div style="flex:1; min-width:220px;">';
echo '<strong style="font-size:1.1em;">' . htmlspecialchars($sup['supplier_name']) . '</strong><br>';
echo nl2br(htmlspecialchars($sup['address'] ?? '')) . '<br>';
if (!empty($sup['cvr'])) echo lang('@CVR') . ': ' . htmlspecialchars($sup['cvr']) . '<br>';
echo '</div>';
echo '<div style="flex:1; min-width:220px;">';
if (!empty($sup['email'])) echo lang('@Email') . ': ' . htmlspecialchars($sup['email']) . '<br>';
if (!empty($sup['phone'])) echo lang('@Phone') . ': ' . htmlspecialchars($sup['phone']) . '<br>';
if (!empty($sup['contact_person'])) echo lang('@Contact Person') . ': ' . htmlspecialchars($sup['contact_person']) . '<br>';
echo '</div>';
echo '<div class="no-print" style="display:flex; flex-direction:column; gap:8px;">';
    htm_Button(icon: 'fa-pencil', labl: '@Edit Supplier', type: 'secondary', link: 'supplier_edit.php?id=' . $supplier_id, attr: 'data-hint="'.lang('@Edit this supplier\'s details').'"');
    htm_Button(icon: 'fa-print',  labl: '@Print',         type: 'primary',   link: '', attr: 'onclick="window.print()" data-hint="'.lang('@Print this statement').'"');
    htm_Button(icon: 'fa-arrow-left', labl: '@Back',      type: 'secondary', link: 'supplier_list.php', attr: 'data-hint="'.lang('@Return to the supplier list').'"');
echo '</div>';
echo '</div>';
htm_Card_end();

// --- Data ---
$res_e = DB::query($conn, "SELECT exp_id, exp_date, voucher_no, description, amount, due_date, paid_date
                            FROM expenses
                            WHERE supplier_id = $supplier_id AND is_cancelled = 0
                            ORDER BY exp_date DESC, exp_id DESC");
$expenses = [];
$total_spend = 0.0; $total_owed = 0.0; $overdue_count = 0;
$today = date('Y-m-d');
if ($res_e) {
    while ($row = DB::fetch_assoc($res_e)) {
        $expenses[] = $row;
        $total_spend += (float)$row['amount'];
        if (!empty($row['due_date']) && empty($row['paid_date'])) {
            $total_owed += (float)$row['amount'];
            if ($row['due_date'] < $today) $overdue_count++;
        }
    }
}

// --- Sammendrag ---
htm_Card_(capt: '@Summary', wdth: 1000);
echo '<div style="display:flex; gap:20px; flex-wrap:wrap;">';
echo '<div style="flex:1; min-width:180px; text-align:center; padding:10px;">'
    . '<div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Total Spend') . '</div>'
    . '<div style="font-size:1.6em; font-weight:bold;">' . number_format($total_spend, 2, ',', '.') . ' ' . $cur . '</div></div>';
echo '<div style="flex:1; min-width:180px; text-align:center; padding:10px;">'
    . '<div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Amount Owed') . '</div>'
    . '<div style="font-size:1.6em; font-weight:bold; color:' . ($total_owed > 0.01 ? 'var(--color-warning)' : 'var(--text-main)') . ';">' . number_format($total_owed, 2, ',', '.') . ' ' . $cur . '</div></div>';
echo '<div style="flex:1; min-width:180px; text-align:center; padding:10px;">'
    . '<div style="font-size:0.8em; color:var(--text-muted); text-transform:uppercase;">' . lang('@Overdue') . '</div>'
    . '<div style="font-size:1.6em; font-weight:bold; color:' . ($overdue_count > 0 ? 'var(--color-danger)' : 'var(--text-main)') . ';">' . $overdue_count . '</div></div>';
echo '</div>';
htm_Card_end();

// --- Udgiftsliste ---
htm_Card_(capt: '@Expenses', wdth: 1000);

$headers = ['@Date', '@Voucher', '@Description', '@Amount', '@Status', '@Actions'];
$data = [];

foreach ($expenses as $e) {
    $status_html = '---';
    $action_html = '';
    if (!empty($e['paid_date'])) {
        $status_html = '<span style="color:var(--color-success);"><i class="fa-solid fa-circle-check"></i> ' . sprintf(lang('@Paid %s'), date(CONF_DATE_FORMAT, strtotime($e['paid_date']))) . '</span>';
    } elseif (!empty($e['due_date'])) {
        $overdue = ($e['due_date'] < $today);
        $status_html = '<span style="color:'.($overdue ? 'var(--color-danger)' : 'var(--color-warning)').';"><i class="fa-solid fa-clock"></i> ' . sprintf(lang('@Due %s'), date(CONF_DATE_FORMAT, strtotime($e['due_date']))) . '</span>';
        $confirm = addslashes(lang('@Register that this expense has now been paid from the bank account?'));
        $action_html = '<a href="expense_actions.php?action=mark_paid&id=' . (int)$e['exp_id'] . '" onclick="return confirm(\''.$confirm.'\');" '
            . 'class="no-print" style="background:var(--color-success); color:#fff; padding:4px 10px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em;">'
            . '<i class="fa-solid fa-money-bill-wave"></i> ' . lang('@Pay Now') . '</a>';
    }

    $data[] = [
        date(CONF_DATE_FORMAT, strtotime($e['exp_date'])),
        '<a href="expense_edit.php?id=' . (int)$e['exp_id'] . '">#' . htmlspecialchars((string)$e['voucher_no']) . '</a>',
        htmlspecialchars($e['description'] ?? ''),
        number_format((float)$e['amount'], 2, ',', '.') . ' ' . $cur,
        $status_html,
        $action_html,
    ];
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No expenses registered for this supplier yet.') . "</p>";
} else {
    htm_Table($headers, $data, 'supplierStatementTbl', 100, '', true,
        ['width:110px;', 'width:90px;', '', 'width:140px; text-align:right;', 'width:170px;', 'width:120px;']);
}
htm_Card_end();

echo '<style>@media print { .no-print, .floating-action-bar { display:none !important; } }</style>';

htm_Footer();
ob_end_flush();
?>
