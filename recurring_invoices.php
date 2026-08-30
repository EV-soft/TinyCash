<?php # /recurring_invoices.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Gentagne/faste fakturaer
# Fra forslagslisten, næste punkt efter kundekontoudtog. Liste over faste
# fakturaskabeloner (recurring_invoices) - oprettelse/redigering sker i
# recurring_invoice_edit.php, selve den automatiske generering i
# inc/recurring_invoices.inc.php (hooket ind i htm_Footer()).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/recurring_invoices.inc.php';

$msg = ''; $err = '';

// --- Slet ---
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $del_id = (int)$_GET['del'];
    $old = DB::fetch_assoc(DB::query($conn, "SELECT * FROM recurring_invoices WHERE recur_id = $del_id"));
    DB::query($conn, "DELETE FROM recurring_invoice_lines WHERE recur_id = $del_id");
    if (DB::query($conn, "DELETE FROM recurring_invoices WHERE recur_id = $del_id")) {
        if ($old) log_action($conn, 'DELETE_RECURRING_INVOICE', 'recurring_invoices', $del_id, $old, null);
        header("Location: recurring_invoices.php?msg=deleted"); exit;
    }
}

// --- Pause/genoptag ---
if (isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $tid = (int)$_GET['toggle'];
    $cur = DB::fetch_assoc(DB::query($conn, "SELECT is_active FROM recurring_invoices WHERE recur_id = $tid"));
    if ($cur) {
        $new_active = $cur['is_active'] ? 0 : 1;
        DB::query($conn, "UPDATE recurring_invoices SET is_active = $new_active WHERE recur_id = $tid");
        log_action($conn, 'TOGGLE_RECURRING_INVOICE', 'recurring_invoices', $tid, ['is_active' => $cur['is_active']], ['is_active' => $new_active]);
    }
    header("Location: recurring_invoices.php?msg=toggled"); exit;
}

// --- Generér nu ---
if (isset($_GET['gen']) && (int)$_GET['gen'] > 0) {
    $gid = (int)$_GET['gen'];
    $new_inv_id = generate_recurring_invoice($conn, $gid);
    if ($new_inv_id) {
        header("Location: invoice_edit.php?id=$new_inv_id&msg=saved"); exit;
    } else {
        header("Location: recurring_invoices.php?msg=generr"); exit;
    }
}

htm_Header('@Recurring Invoices', 1200);
showMenu();

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') htm_Alert(lang('@Deleted successfully'), 'success');
    elseif ($_GET['msg'] === 'toggled') htm_Alert(lang('@Status updated'), 'success');
    elseif ($_GET['msg'] === 'generr') htm_Alert(lang('@Could not generate an invoice from this template'), 'error');
}

$tools = htm_Button(icon: 'fa-plus', labl: '@New Recurring Invoice', type: 'success', link: 'recurring_invoice_edit.php?id=0', attr: 'data-hint="'.lang('@Create a new recurring invoice template').'"', echo: false);
htm_Card_(capt: '@Recurring Invoices', wdth: 1200, tool: $tools);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Templates for invoices that repeat automatically (e.g. monthly rent or a subscription). A new draft invoice is generated on the scheduled date - it is never posted automatically, you still review and post it yourself like any other invoice.') .
    '</p>';

$res = DB::query($conn, "SELECT r.*, c.cust_name FROM recurring_invoices r
                          LEFT JOIN customers c ON r.cust_id = c.cust_id
                          ORDER BY r.is_active DESC, r.next_run_date ASC");

$interval_labels = ['monthly' => '@Monthly', 'quarterly' => '@Quarterly', 'yearly' => '@Yearly'];
$headers = ['@Customer', '@Interval', '@Next Run', '@Last Run', '@Status', '@Actions'];
$data = [];

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $tot_row = DB::fetch_assoc(DB::query($conn,
            "SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0) AS total
             FROM recurring_invoice_lines WHERE recur_id = " . $row['recur_id']));
        $total = (float)($tot_row['total'] ?? 0);

        $status_html = $row['is_active']
            ? '<span style="color:var(--color-success); font-weight:bold;">' . lang('@Active') . '</span>'
            : '<span style="color:var(--text-muted);">' . lang('@Paused') . '</span>';

        $actions = [
            ['icon' => 'fa-pencil', 'link' => 'recurring_invoice_edit.php?id='.$row['recur_id'], 'hint' => '@Edit', 'type' => 'primary'],
            ['icon' => 'fa-bolt',   'link' => 'recurring_invoices.php?gen='.$row['recur_id'], 'hint' => '@Generate Now', 'type' => 'info'],
            ['icon' => $row['is_active'] ? 'fa-pause' : 'fa-play',
             'link' => 'recurring_invoices.php?toggle='.$row['recur_id'],
             'hint' => $row['is_active'] ? '@Pause' : '@Resume', 'type' => 'warning'],
        ];

        $data[] = [
            htmlspecialchars($row['cust_name'] ?? '---') . '<br><small style="color:var(--text-muted);">' . number_format($total, 2, ',', '.') . ' DKK/' . lang($interval_labels[$row['interval_type']] ?? $row['interval_type']) . '</small>',
            lang($interval_labels[$row['interval_type']] ?? $row['interval_type']),
            $row['next_run_date'] ? date(CONF_DATE_FORMAT, strtotime($row['next_run_date'])) : '-',
            $row['last_run_date'] ? date(CONF_DATE_FORMAT, strtotime($row['last_run_date'])) : lang('@Never'),
            $status_html,
            htm_ActionButtons($actions, false) . htm_ConfirmLink(
                icon: 'fa-trash', labl: '', link: 'recurring_invoices.php?del='.$row['recur_id'],
                mess: '@Are you sure you want to delete this recurring invoice template? This cannot be undone.',
                type: 'danger', styl: 'display:inline-block; margin-left:4px; padding:4px 8px;',
                attr: 'data-hint="'.lang('@Delete this recurring invoice template').'"', echo: false
            ),
        ];
    }
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No recurring invoices set up yet.') . "</p>";
} else {
    htm_Table($headers, $data, 'recurTbl', 100, '', true,
        ['width:220px;', 'width:100px;', 'width:110px;', 'width:110px;', 'width:90px;', 'width:150px; text-align:left;']);
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
