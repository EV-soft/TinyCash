<?php # /time_list.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Timeregistrering (bruger-anmodet, sidste punkt fra "hvilke
# funktioner mangler TinyCash"-gennemgangen). Oversigt + filtrering + "Opret
# faktura af timer"-handlingen. Kræver Projekt-modulet aktivt - se
# db-setup/migrate_time_tracking.php for hvorfor (hver time skal kunne
# spores til en kunde via sit projekt for at kunne faktureres).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$module_projects = !empty($s['module_projects']) && $s['module_projects'] == '1';

htm_Header('@Time Tracking', 1300);
showMenu();

if (!$module_projects) {
    echo "<div style='max-width:700px; margin:20px auto;'>";
    htm_Card_(capt: '@Time Tracking');
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@Time tracking requires the Project module to be active, since every logged hour needs to be traceable to a customer through its project. Activate it under Project Settings.'), 'warning');
    htm_Button(icon: 'fa-cog', labl: '@Project Settings', type: 'primary', link: 'project_edit.php?id=0', attr: 'data-hint="'.lang('@Go to project settings to activate the module').'"');
    htm_Card_end();
    echo "</div>";
    htm_Footer();
    ob_end_flush();
    exit;
}

$table_exists = @DB::query($conn, "SELECT 1 FROM time_entries LIMIT 1") !== false;
if (!$table_exists) {
    echo "<div style='max-width:700px; margin:20px auto;'>";
    htm_Card_(capt: '@Time Tracking');
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The time tracking database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    echo "</div>";
    htm_Footer();
    ob_end_flush();
    exit;
}

if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if ($m === 'deleted')   htm_Alert(lang('@Deleted successfully'), 'success');
    elseif ($m === 'cannot_delete') htm_Alert(lang('@This time entry has already been invoiced and cannot be deleted.'), 'error');
    elseif ($m === 'invoiced') htm_Alert(lang('@Invoice created from the selected hours. Review and post it from the Sales Hub.'), 'success');
    elseif ($m === 'nothing_to_invoice') htm_Alert(lang('@No unbilled, billable hours found for this project.'), 'error');
}

// --- Filter (projekt) ---
$filter_proj = (int)($_GET['proj_id'] ?? 0);
$proj_opts = ['0' => lang('@All projects')];
$pres = DB::query($conn, "SELECT proj_id, proj_no FROM projects WHERE is_active = 1 ORDER BY proj_no ASC");
while ($p = DB::fetch_assoc($pres)) { $proj_opts[$p['proj_id']] = $p['proj_no']; }

$tools = htm_Button(icon: 'fa-plus', labl: '@Log Time', type: 'success', link: 'time_entry_edit.php?id=0'.($filter_proj ? '&proj_id='.$filter_proj : ''), attr: 'data-hint="'.lang('@Log a new time entry').'"', echo: false);
htm_Card_(capt: '@Time Tracking', wdth: 1300, tool: $tools);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Logged hours per project. A time entry never affects the ledger or VAT return - only "Create Invoice from Hours" below does, by generating a normal draft invoice.') .
    '</p>';

echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:15px;">';
htm_Field(icon: 'fa-folder-open', labl: '@Project', name: 'proj_id', valu: $filter_proj, type: 'sele', opti: $proj_opts, extr: 'onchange="this.form.submit()" bare', wdth: '260px');
echo '</form>';

// --- "Opret faktura af timer" ---
if ($filter_proj > 0) {
    $unbilled_row = DB::fetch_assoc(DB::query($conn,
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(hours * hourly_rate),0) AS total
         FROM time_entries WHERE proj_id = $filter_proj AND is_billable = 1 AND is_invoiced = 0"));
    $unbilled_cnt   = (int)($unbilled_row['cnt'] ?? 0);
    $unbilled_total = (float)($unbilled_row['total'] ?? 0);

    if ($unbilled_cnt > 0) {
        echo '<div style="margin-bottom:15px; padding:12px 15px; background:var(--bg-panel); border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">';
        echo '<div>' . sprintf(lang('@%d unbilled, billable hour entries for this project, totaling %s.'), $unbilled_cnt, number_format($unbilled_total, 2, ',', '.') . ' ' . $cur) . '</div>';
        htm_Button(icon: 'fa-file-invoice', labl: '@Create Invoice from Hours', type: 'success',
            link: 'time_actions.php?action=invoice&proj_id='.$filter_proj,
            attr: 'onclick="return confirm(\''.addslashes(lang('@Create a draft invoice with one line per unbilled hour entry for this project?')).'\');" data-hint="'.lang('@Generate a draft invoice from all unbilled hours on this project').'"');
        echo '</div>';
        // NYT: fakturaers redigeringsformular understøtter kun 5 linjer i alt
        // (samme grænse som recurring_invoice_edit.php/quote_edit.php) - alle
        // linjer oprettes og vises korrekt på selve fakturaen/PDF'en uanset
        // antal, men et EFTERFØLGENDE "Gem" i redigeringsformularen ville kun
        // beholde de første 5. Advarer proaktivt i stedet for at brugeren
        // opdager det ved en stille datamistet efter et helt normalt gem.
        if ($unbilled_cnt > 5) {
            htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . sprintf(lang('@This will create %d invoice lines, but the invoice editing form only supports editing up to 5 lines. All %d lines will be correctly created and shown on the invoice/PDF - but if you open and re-save the draft in the edit form afterwards, only the first 5 will be kept. Consider invoicing more often (e.g. weekly) to stay under this limit, or review the invoice without re-saving it.'), $unbilled_cnt, $unbilled_cnt), 'warning');
        }
    }
}

// --- Tabel ---
$where = $filter_proj > 0 ? "WHERE t.proj_id = $filter_proj" : "";
$res = DB::query($conn, "SELECT t.*, p.proj_no, u.username
    FROM time_entries t
    LEFT JOIN projects p ON t.proj_id = p.proj_id
    LEFT JOIN users u ON t.user_id = u.user_id
    $where
    ORDER BY t.entry_date DESC, t.entry_id DESC");

$headers = ['@Date', '@Project', '@Description', '@User', '@Hours', '@Rate', '@Amount', '@Billable', '@Status', '@Actions'];
$data = [];
$grand_total_hours = 0;

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $eid    = (int)$row['entry_id'];
        $amount = (float)$row['hours'] * (float)$row['hourly_rate'];
        $grand_total_hours += (float)$row['hours'];

        if ($row['is_invoiced']) {
            $status_html = '<a href="invoice_edit.php?id='.(int)$row['inv_id'].'" style="color:var(--color-primary); font-weight:bold;"><i class="fa-solid fa-file-invoice"></i> ' . lang('@Invoiced') . '</a>';
        } elseif (!$row['is_billable']) {
            $status_html = '<span style="color:var(--text-muted);">' . lang('@Non-billable') . '</span>';
        } else {
            $status_html = '<span style="color:var(--color-warning);">' . lang('@Unbilled') . '</span>';
        }

        $actions = '';
        if (!$row['is_invoiced']) {
            $actions = htm_ActionButtons([
                ['icon' => 'fa-pencil', 'link' => 'time_entry_edit.php?id='.$eid, 'hint' => '@Edit', 'type' => 'primary'],
            ], false);
            $actions .= htm_ConfirmLink(icon: 'fa-trash', labl: '', link: 'time_entry_edit.php?id='.$eid.'&del=1',
                mess: '@Are you sure you want to delete this time entry?',
                type: 'danger', styl: 'display:inline-block; margin-left:4px; padding:4px 8px;', echo: false);
        }

        $data[] = [
            date(CONF_DATE_FORMAT, strtotime($row['entry_date'])),
            $row['proj_no'] ? htmlspecialchars($row['proj_no']) : '<span style="color:var(--text-muted);">—</span>',
            htmlspecialchars($row['description'] ?? ''),
            htmlspecialchars($row['username'] ?? '—'),
            number_format((float)$row['hours'], 2, ',', '.'),
            number_format((float)$row['hourly_rate'], 2, ',', '.') . ' ' . $cur,
            number_format($amount, 2, ',', '.') . ' ' . $cur,
            $row['is_billable'] ? lang('@Yes') : lang('@No'),
            $status_html,
            $actions,
        ];
    }
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No time entries logged yet.') . "</p>";
} else {
    htm_Table($headers, $data, 'timeTbl', 100, '', true,
        ['width:100px;', 'width:100px;', '', 'width:110px;', 'width:80px; text-align:right;', 'width:110px; text-align:right;', 'width:120px; text-align:right;', 'width:80px;', 'width:120px;', 'width:90px;'],
        '500px', 'time_entries.csv');
    echo '<div style="text-align:right; font-weight:bold; padding:10px 0;">' . lang('@Total Hours') . ': ' . number_format($grand_total_hours, 2, ',', '.') . '</div>';
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
