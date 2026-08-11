<?php # /project_view.php v:1.2.0 d:2026-08-11 i:evs 
# (Andre indtægter via expenses+exp_type; notefelter pr. sektion)
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$id  = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';

// --- GEM NOTER via POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes']) && $id > 0) {
    $n_exp = DB::escape($conn, trim($_POST['note_expenses'] ?? ''));
    $n_inc = DB::escape($conn, trim($_POST['note_income']   ?? ''));
    $n_gen = DB::escape($conn, trim($_POST['note_general']  ?? ''));
    DB::query($conn, "UPDATE projects SET
        note_expenses = '$n_exp',
        note_income   = '$n_inc',
        note_general  = '$n_gen'
        WHERE proj_id = $id");
    header("Location: project_view.php?id=$id&msg=saved");
    exit;
}

htm_Header('@Projects');
showMenu();

if ($msg === 'saved')   htm_Alert(lang('@Changes saved successfully'), 'success');
if ($msg === 'created') htm_Alert(lang('@Project created'), 'success');
if ($msg === 'deleted') htm_Alert(lang('@Project deleted'), 'success');

// =========================================================
// ØVERST: Oversigtstabel
// =========================================================
$res_all = DB::query($conn, "
    SELECT p.proj_id, p.proj_no, p.is_active, p.proj_start, p.proj_stop,
           c.cust_name,
           (SELECT COALESCE(SUM(e2.amount),0) FROM expenses e2
            WHERE e2.proj_id = p.proj_id AND e2.is_cancelled = 0 AND e2.exp_type = 'expense') AS total_exp,
           (SELECT COALESCE(SUM(e3.amount),0) FROM expenses e3
            WHERE e3.proj_id = p.proj_id AND e3.is_cancelled = 0 AND e3.exp_type = 'income')  AS total_other_inc,
           (SELECT COALESCE(SUM(il.quantity * il.price_each),0) FROM invoice_lines il
            WHERE il.proj_id = p.proj_id) AS total_inv
    FROM projects p
    LEFT JOIN customers c ON p.cust_id = c.cust_id
    ORDER BY p.is_active DESC, p.proj_no ASC
");

$tbl_rows = [];
while ($p = DB::fetch_assoc($res_all)) {
    $active_badge = htm_Badge($p['is_active'] ? '@Active' : '@Inactive',
                              $p['is_active'] ? 'success' : 'secondary', false);
    $actions = htm_ActionButtons([
        ['icon' => 'fa-eye',  'type' => 'primary',  'link' => 'project_view.php?id='.$p['proj_id'], 'hint' => '@View'],
        ['icon' => 'fa-edit', 'type' => 'secondary', 'link' => 'project_edit.php?id='.$p['proj_id'], 'hint' => '@Edit'],
    ], false);

    $total_inc = (float)$p['total_inv'] + (float)$p['total_other_inc'];
    $balance   = $total_inc - (float)$p['total_exp'];
    $bal_col   = $balance >= 0 ? 'var(--color-success)' : 'var(--color-danger)';

    $tbl_rows[] = [
        '<a href="project_view.php?id='.$p['proj_id'].'" style="font-weight:bold;">'.htmlspecialchars($p['proj_no']).'</a>',
        htmlspecialchars($p['cust_name'] ?? '—'),
        $active_badge,
        $p['proj_start'] ? date('d.m.Y', strtotime($p['proj_start'])) : '—',
        $p['proj_stop']  ? date('d.m.Y', strtotime($p['proj_stop']))  : '—',
        number_format((float)$p['total_exp'], 2, ',', '.') . ' ' . $cur,
        number_format($total_inc, 2, ',', '.') . ' ' . $cur,
        '<span style="font-weight:bold; color:'.$bal_col.';">' . number_format($balance, 2, ',', '.') . ' ' . $cur . '</span>',
        $actions,
    ];
}

$new_btn = htm_Button('fa-plus', '@New Project', 'success', 'project_edit.php?id=0', '', '', '', false);
htm_Card_('@Projects', 1200, tool: $new_btn);
htm_Table(
    ['@Code', '@Customer', '@Status', '@Start', '@End', '@Expenses', '@Income', '@Balance', ''],
    $tbl_rows, 'proj_tbl', 0, '', true,
    ['width:10%','width:20%','width:8%','width:9%','width:9%',
     'width:12%; text-align:right','width:12%; text-align:right',
     'width:12%; text-align:right','width:8%; text-align:right']
);
htm_Card_end();

// =========================================================
// DETALJEVISNING
// =========================================================
if ($id > 0):

$proj = DB::fetch_assoc(DB::query($conn, "
    SELECT p.*, c.cust_name
    FROM projects p
    LEFT JOIN customers c ON p.cust_id = c.cust_id
    WHERE p.proj_id = $id
"));
if (!$proj) { echo htm_Alert(lang('@Project not found'), 'error'); htm_Footer(); exit; }

// Udgifter
$exp_res = DB::query($conn, "
    SELECT e.exp_id, e.exp_date, e.supplier, e.description, e.amount, a.acc_name
    FROM expenses e
    LEFT JOIN accounts a ON e.account_id = a.acc_id
    WHERE e.proj_id = $id AND e.is_cancelled = 0 AND e.exp_type = 'expense'
    ORDER BY e.exp_date DESC
");
$exp_rows = []; $exp_total = 0;
while ($e = DB::fetch_assoc($exp_res)) {
    $exp_total += (float)$e['amount'];
    $exp_rows[] = [
        date('d.m.Y', strtotime($e['exp_date'])),
        htmlspecialchars($e['supplier']),
        htmlspecialchars($e['description'] ?? ''),
        htmlspecialchars($e['acc_name'] ?? ''),
        number_format((float)$e['amount'], 2, ',', '.') . ' ' . $cur,
        htm_ActionButtons([['icon'=>'fa-edit','type'=>'secondary','link'=>'expense_edit.php?id='.$e['exp_id']]], false),
    ];
}

// Andre indtægter (exp_type = 'income')
$inc_res = DB::query($conn, "
    SELECT e.exp_id, e.exp_date, e.supplier, e.description, e.amount, a.acc_name
    FROM expenses e
    LEFT JOIN accounts a ON e.account_id = a.acc_id
    WHERE e.proj_id = $id AND e.is_cancelled = 0 AND e.exp_type = 'income'
    ORDER BY e.exp_date DESC
");
$inc_rows = []; $inc_total = 0;
while ($e = DB::fetch_assoc($inc_res)) {
    $inc_total += (float)$e['amount'];
    $inc_rows[] = [
        date('d.m.Y', strtotime($e['exp_date'])),
        htmlspecialchars($e['supplier']),
        htmlspecialchars($e['description'] ?? ''),
        htmlspecialchars($e['acc_name'] ?? ''),
        number_format((float)$e['amount'], 2, ',', '.') . ' ' . $cur,
        htm_ActionButtons([['icon'=>'fa-edit','type'=>'secondary','link'=>'expense_edit.php?id='.$e['exp_id']]], false),
    ];
}

// Fakturaer
$inv_res = DB::query($conn, "
    SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_status, i.inv_due_date,
           COALESCE((SELECT SUM(il.quantity * il.price_each * (1 + il.line_vat_rate/100))
                     FROM invoice_lines il WHERE il.inv_id = i.inv_id), 0) AS total
    FROM invoices i WHERE i.proj_id = $id ORDER BY i.inv_date DESC
");
$inv_rows = []; $inv_total = 0;
while ($i = DB::fetch_assoc($inv_res)) {
    $inv_total += (float)$i['total'];
    $status_map = ['DRAFT'=>'secondary','SENT'=>'primary','PAID'=>'success','VOID'=>'danger'];
    $inv_rows[] = [
        '#' . $i['invoice_no'],
        date('d.m.Y', strtotime($i['inv_date'])),
        date('d.m.Y', strtotime($i['inv_due_date'])),
        htm_Badge('@'.$i['inv_status'], $status_map[$i['inv_status']] ?? 'secondary', false),
        number_format((float)$i['total'], 2, ',', '.') . ' ' . $cur,
        htm_ActionButtons([['icon'=>'fa-edit','type'=>'secondary','link'=>'invoice_edit.php?id='.$i['inv_id']]], false),
    ];
}

$total_income = $inv_total + $inc_total;
$balance      = $total_income - $exp_total;
$bal_type     = $balance >= 0 ? 'success' : 'danger';

// --- Projekthoved-kort START ---
$edit_btn = htm_Button('fa-edit', '@Edit Project', 'secondary', 'project_edit.php?id='.$id, '', '', '', false);
htm_Card_(htmlspecialchars($proj['proj_no']) . ' — ' . htmlspecialchars($proj['cust_name'] ?? ''), 1200, tool: $edit_btn);

echo '<div style="display:flex; gap:30px; flex-wrap:wrap; font-size:0.95em; color:var(--text-muted); margin-bottom:10px;">';
if ($proj['proj_start']) echo '<span><i class="fa fa-calendar"></i> ' . lang('@Start') . ': <b>' . date('d.m.Y', strtotime($proj['proj_start'])) . '</b></span>';
if ($proj['proj_stop'])  echo '<span><i class="fa fa-calendar-check"></i> ' . lang('@End') . ': <b>' . date('d.m.Y', strtotime($proj['proj_stop'])) . '</b></span>';
echo '<span>' . htm_Badge($proj['is_active'] ? '@Active' : '@Inactive', $proj['is_active'] ? 'success' : 'secondary', false) . '</span>';
echo '</div>';
if (!empty($proj['proj_description'])) echo '<p style="margin:0 0 10px 0;">'.nl2br(htmlspecialchars($proj['proj_description'])).'</p>';
if (!empty($proj['proj_concept'])) {
    echo '<div style="margin-bottom:15px; padding:10px; background:var(--bg-panel); border-radius:4px; font-size:0.9em;">';
    echo '<b>' . lang('@Invoice Concept') . ':</b> ' . nl2br(htmlspecialchars($proj['proj_concept']));
    echo '</div>';
}

// Note-formular (én POST for alle tre noter)
echo '<form method="post" action="project_view.php?id='.$id.'" id="notes_form"><input type="hidden" name="save_notes" value="1">';

// Helper: sammenklappeligt notefelt (lukket som standard)
function render_note_field($name, $value, $label) {
    $has = !empty(trim($value ?? ''));
    echo '<details style="margin-top:8px; margin-bottom:4px;">';
    echo '<summary style="cursor:pointer; color:var(--text-muted); user-select:none; list-style:none; display:flex; align-items:center; gap:6px;">';
    echo '<i class="fa fa-sticky-note" style="color:var(--color-warning);"></i> ';
    echo '<span>' . lang($label) . ($has ? ' ✎' : '') . '</span>';
    echo '</summary>';
    echo '<textarea name="'.$name.'" form="notes_form" style="width:100%; min-height:60px; margin-top:6px; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main); font-size:0.9em; resize:vertical; box-sizing:border-box;">'.
            htmlspecialchars($value ?? '').
        '</textarea>';
    echo '</details>';
}

// Funktion til at generere HTML-knapperne til tool-parameteren
function render_section_tools() {
    return '<button type="button" onclick="toggleAllSections(true)" title="' . lang('@Expand All') . '" style="background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1em; padding:0;"><i class="fa fa-angle-double-down"></i></button>'
         . '<button type="button" onclick="toggleAllSections(false)" title="' . lang('@Collapse All') . '" style="background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1em; padding:0;"><i class="fa fa-angle-double-up"></i></button>';
}

// --- Sektion: Udgifter ---
htm_SectionStart('fa-receipt', '@Expenses / Receipts', false, show_toggle: true);
if (empty($exp_rows)) {
    echo '<p style="color:var(--text-muted);">' . lang('@No expenses registered for this project') . '</p>';
} else {
    htm_Table(['@Date','@Supplier','@Description','@Account','@Amount',''], $exp_rows, 'exp_tbl', 0, '', true,
        ['width:10%','width:20%','width:25%','width:20%','width:15%; text-align:right','width:10%']);
    echo '<div style="text-align:right; font-weight:bold; padding:8px 0;">'
        . lang('@Total') . ': ' . number_format($exp_total, 2, ',', '.') . ' ' . $cur . '</div>';
}
echo '<div style="margin-top:10px;">';
htm_Button('fa-plus', '@Register Expense', 'primary', 'expense_edit.php?id=0');
echo '</div>';
render_note_field('note_expenses', $proj['note_expenses'] ?? '', '@Notes on expenses');
htm_SectionEnd();

// --- Sektion: Indtægter / Fakturaer ---
$income_rows  = [];
$income_total = 0;

foreach ($inv_rows as $row) {
    array_unshift($row, htm_Badge('@Invoice', 'primary', false));
    $income_rows[] = $row;
}
$income_total += $inv_total;

foreach ($inc_rows as $row) {
    array_unshift($row, htm_Badge('@Income', 'success', false));
    $income_rows[] = $row;
}
$income_total = $inv_total + $inc_total;

htm_SectionStart('fa-coins', '@Income / Invoices', false, show_toggle: true);
if (empty($income_rows)) {
    echo '<p style="color:var(--text-muted);">' . lang('@No income registered for this project') . '</p>';
} else {
    if (!empty($inv_rows)) {
        echo '<p style="font-weight:bold; margin:0 0 4px 0; font-size:0.9em; color:var(--text-muted);">📄 ' . lang('@Invoices') . '</p>';
        htm_Table(['@Invoice','@Date','@Due','@Status','@Amount',''], $inv_rows, 'inv_tbl', 0, '', true,
            ['width:10%','width:12%','width:12%','width:12%','width:15%; text-align:right','width:10%']);
        echo '<div style="text-align:right; font-size:0.9em; padding:4px 0; color:var(--text-muted);">'
            . lang('@Subtotal') . ': ' . number_format($inv_total, 2, ',', '.') . ' ' . $cur . '</div>';
    }
    if (!empty($inc_rows)) {
        echo '<p style="font-weight:bold; margin:10px 0 4px 0; font-size:0.9em; color:var(--text-muted);">💰 ' . lang('@Other Income') . '</p>';
        htm_Table(['@Date','@Source','@Description','@Account','@Amount',''], $inc_rows, 'inc_tbl', 0, '', true,
            ['width:10%','width:20%','width:25%','width:20%','width:15%; text-align:right','width:10%']);
        echo '<div style="text-align:right; font-size:0.9em; padding:4px 0; color:var(--text-muted);">'
            . lang('@Subtotal') . ': ' . number_format($inc_total, 2, ',', '.') . ' ' . $cur . '</div>';
    }
    echo '<div style="text-align:right; font-weight:bold; padding:8px 0; border-top:1px solid var(--border-subtle); margin-top:4px;">'
        . lang('@Total Income') . ': ' . number_format($income_total, 2, ',', '.') . ' ' . $cur . '</div>';
}
echo '<div style="margin-top:10px; display:flex; gap:8px;">';
htm_Button('fa-plus', '@Create Invoice', 'primary', 'invoice_edit.php?id=0');
htm_Button('fa-plus', '@Register Income', 'success', 'expense_edit.php?id=0&type=income');
echo '</div>';
render_note_field('note_income', $proj['note_income'] ?? '', '@Notes on income');
htm_SectionEnd();

// --- Sektion: Økonomi-sammendrag ---
htm_SectionStart('fa-chart-bar', '@Financial Summary', false, show_toggle: true);
echo '<div style="display:grid; grid-template-columns: repeat(4,1fr); gap:20px; text-align:center;">';
foreach ([
    ['@Invoiced',      $inv_total,    'primary'],
    ['@Other Income',  $inc_total,    'info'],
    ['@Total Expenses',$exp_total,    'danger'],
    ['@Balance',       $balance,      $bal_type],
] as [$lbl, $val, $type]) {
    echo '<div style="background:var(--bg-panel); padding:20px; border-radius:8px;">';
    echo '<div style="font-size:0.85em; color:var(--text-muted); margin-bottom:6px;">' . lang($lbl) . '</div>';
    echo '<div style="font-size:1.6em; font-weight:bold; color:var(--color-'.$type.');">'
        . number_format($val, 2, ',', '.') . ' ' . $cur . '</div>';
    echo '</div>';
}
echo '</div>';
render_note_field('note_general', $proj['note_general'] ?? '', '@General financial notes');
htm_SectionEnd();

// Gem-knap for noter
echo '<div style="margin-top:15px; text-align:right;">';
htm_Button('fa-save', '@Save Notes', 'success', '', '', 'type="submit" form="notes_form"');
echo '</div>';
echo '</form>';

htm_Card_end();

endif;

htm_Footer();
?>