<?php # /recurring_expense_edit.php v:1.3.1 d:2026-09-15 i:evs
# Opret/redigér en fast udgiftsskabelon (Gentagne/faste udgifter). Samme
# felt-/kolonnemønster som expense_edit.php's opret-flow (supplier/
# supplier_id/account_id/exp_type), men uden bilag/AI-scanning/betalings-
# metode-valg - en skabelon har intet bilag endnu, og selve genereringen
# opretter altid en ubogført kladde (se inc/recurring_expenses.inc.php).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$recur_id = (int)($_GET['id'] ?? 0);

// --- GEM ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recur'])) {
    $supplier      = DB::escape($conn, trim($_POST['supplier'] ?? ''));
    $account_id    = (int)$_POST['account_id'];
    $amount        = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
    $description   = DB::escape($conn, trim($_POST['description'] ?? ''));
    $exp_type      = in_array($_POST['exp_type'] ?? '', ['expense', 'income'], true) ? $_POST['exp_type'] : 'expense';
    $interval_type = in_array($_POST['interval_type'] ?? '', ['monthly', 'quarterly', 'yearly'], true) ? $_POST['interval_type'] : 'monthly';
    $next_run      = DB::escape($conn, $_POST['next_run_date'] ?? date('Y-m-d'));
    // RETTET (ALVORLIGT): datoen blev ikke kontrolleret - et tomt felt gemte '' som næste kørsel. '' er "før" enhver
    // dato, så det automatiske tjek dannede en kladde ved HVER sidevisning og regnede videre fra 1970 (strtotime('')):
    // hundredvis af kladder. Nu skal datoen være gyldig.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['next_run_date'] ?? '')) || !strtotime((string)$_POST['next_run_date'])) {
        die(lang('@Please enter a valid next run date.'));
    }
    $is_active     = isset($_POST['is_active']) ? 1 : 0;
    $proj_id       = (int)($_POST['proj_id'] ?? 0);
    $proj_sql      = ($proj_id > 0) ? $proj_id : 'NULL';
    $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
    $supplier_id_sql = ($supplier_id > 0) ? $supplier_id : 'NULL';

    if ($recur_id > 0) {
        $save_ok = DB::query($conn, "UPDATE recurring_expenses SET
            supplier='$supplier', supplier_id=$supplier_id_sql, account_id=$account_id, amount=$amount,
            description='$description', exp_type='$exp_type', interval_type='$interval_type',
            next_run_date='$next_run', is_active=$is_active, proj_id=$proj_sql
            WHERE recur_id=$recur_id");
    } else {
        $save_ok = DB::query($conn, "INSERT INTO recurring_expenses
            (supplier, supplier_id, account_id, amount, description, exp_type, interval_type, next_run_date, is_active, proj_id, created_by)
            VALUES ('$supplier', $supplier_id_sql, $account_id, $amount, '$description', '$exp_type', '$interval_type',
                    '$next_run', $is_active, $proj_sql, " . (int)($_SESSION['user_id'] ?? 0) . ")");
        if ($save_ok) $recur_id = DB::insert_id($conn);
    }

    if (!$save_ok) {
        die(lang('@Error saving expense: ') . DB::error($conn));
    }
    header("Location: recurring_expense_edit.php?id=$recur_id&msg=saved"); exit;
}

// --- HENT DATA ---
if ($recur_id > 0) {
    $tmpl = DB::fetch_assoc(DB::query($conn, "SELECT * FROM recurring_expenses WHERE recur_id=$recur_id"));
    if (!$tmpl) die(lang('@Expense not found'));
} else {
    $tmpl = [
        'recur_id' => 0, 'supplier' => '', 'supplier_id' => 0, 'account_id' => 0, 'amount' => '',
        'description' => '', 'exp_type' => 'expense', 'interval_type' => 'monthly',
        'next_run_date' => date('Y-m-d'), 'is_active' => 1, 'proj_id' => 0,
    ];
}

htm_Header($recur_id > 0 ? lang('@Edit Recurring Expense') : lang('@New Recurring Expense'));
showMenu();

$tools = htm_Button('fa-list', '@Back to List', 'secondary', 'recurring_expenses.php', '', 'data-hint="'.lang('@Return to the recurring expenses list').'"', '', false);
$card_msg = isset($_GET['msg']) ? htm_Alert(lang('@Changes saved successfully'), 'success', 700, false) : '';
htm_Card_($recur_id > 0 ? '@Edit Recurring Expense' : '@New Recurring Expense', 700, $card_msg, 'edit_form', true, $tools);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Every scheduled run creates an unposted draft expense - it never posts itself. Open it in the expense list and save it (like any other draft) once you have confirmed the amount and attached a receipt if required.') .
    '</p>';

echo '<input type="hidden" name="recur_id" value="'.$recur_id.'">';

// Leverandør (fritekst, samme mønster som expense_edit.php) + valgfri
// stamdata-kobling hvis leverandørmodulet er sat op.
htm_Field(icon:'fa-truck', labl:'@Supplier', name:'supplier', valu:$tmpl['supplier'], type:'text', extr:'required leg:left', wdth:'70%');

$supplier_module_ready = false;
try {
    $chk = DB::query($conn, "SELECT 1 FROM suppliers LIMIT 1");
    $supplier_module_ready = ($chk !== false);
} catch (\Throwable $e) { /* leverandørmodul ikke migreret endnu */ }

if ($supplier_module_ready) {
    $supplier_options = ['' => lang('@-- Not linked --')];
    // RETTET: leverandører i papirkurven kunne vælges (den allerede valgte beholdes)
    $sup_res = @DB::query($conn, "SELECT supplier_id, supplier_name FROM suppliers WHERE (is_active = 1 AND " . suppliers_active_filter($conn) . ") OR supplier_id = " . (int)($tmpl['supplier_id'] ?? 0) . " ORDER BY supplier_name ASC");
    if ($sup_res) { while ($sr = DB::fetch_assoc($sup_res)) { $supplier_options[(int)$sr['supplier_id']] = $sr['supplier_name']; } }
    htm_Field(icon:'fa-industry', labl:'@Link to Supplier', name:'supplier_id', valu:$tmpl['supplier_id'] ?: '', type:'sele', opti:$supplier_options, wdth:'30%');
}

echo '<div style="display:flex; gap:10px;">';
$acc_options = [];
require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$a_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE acc_type = 'expense' AND " . acc_visible_sql($conn, '', [(int)($tmpl['account_id'] ?? 0)]) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
while ($a_row = DB::fetch_assoc($a_res)) { $acc_options[$a_row['acc_id']] = $a_row['acc_id'] . ' - ' . acc_label($a_row['acc_name'], $a_row['acc_status'] ?? null); }
htm_Field(icon:'fa-list', labl:'@Account', name:'account_id', valu:$tmpl['account_id'] ?: '', type:'sele', opti:$acc_options, extr:'required', wdth:'50%');
htm_Field(icon:'fa-coins', labl:'@Amount', name:'amount', valu:$tmpl['amount'], type:'number', extr:'step="0.01" required', wdth:'25%');
htm_Field(icon:'fa-tag', labl:'@Type', name:'exp_type', valu:$tmpl['exp_type'], type:'sele',
    opti:['expense' => '📤 '.lang('@Expense'), 'income' => '📥 '.lang('@Income')], wdth:'25%');
echo '</div>';

htm_Field(icon:'fa-info-circle', labl:'@Description', name:'description', valu:$tmpl['description'], type:'text', wdth:'100%');
htm_ProjektCodeField($conn, (int)($tmpl['proj_id'] ?: 0) ?: null, '100%');

echo '<div style="display:flex; gap:10px; margin:15px 0;">';
htm_Field(icon:'fa-sync', labl:'@Repeat Interval', name:'interval_type', valu:$tmpl['interval_type'], type:'sele',
    opti:['monthly'=>lang('@Monthly'),'quarterly'=>lang('@Quarterly'),'yearly'=>lang('@Yearly')], extr:'id="interval_sel" onchange="updateRecurPreview()"', echo:true);
htm_Field(icon:'fa-calendar-plus', labl:'@Next Run Date', name:'next_run_date', valu:$tmpl['next_run_date'], type:'date',
    hint:'@The date the first (or next) draft expense will be generated', extr:'id="next_run_field" onchange="updateRecurPreview()"', echo:true);
echo '</div>';
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere") - se
// recurring_invoice_edit.php's samme tilføjelse for den fulde begrundelse.
echo '<div id="recur_preview" style="margin:-5px 0 15px; font-size:0.85em; color:var(--text-muted);"></div>';

echo '<label style="display:flex; align-items:center; gap:8px; margin:10px 0 20px; cursor:pointer;">';
echo '<input type="checkbox" name="is_active" value="1" '.($tmpl['is_active'] ? 'checked' : '').' style="width:18px; height:18px;">';
echo '<span>'.lang('@Active - generate expenses automatically on schedule').'</span></label>';

htm_Button(icon:'fa-save', labl:'@Save Expense', type:'success', attr:'name="save_recur" data-hint="'.lang('@Save this recurring expense template').'"', cont:'<div style="margin-top:20px; text-align:right;"></div>');
htm_Card_end();
?>
<script>
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere") - se
// recurring_invoice_edit.php's egen funktion for den fulde begrundelse.
function updateRecurPreview() {
    var box = document.getElementById('recur_preview');
    var dateField = document.getElementById('next_run_field');
    var intervalField = document.getElementById('interval_sel');
    if (!box || !dateField || !intervalField || !dateField.value) { if (box) box.innerHTML = ''; return; }
    var months = intervalField.value === 'quarterly' ? 3 : (intervalField.value === 'yearly' ? 12 : 1);
    var d = new Date(dateField.value + 'T00:00:00');
    var dates = [];
    for (var i = 0; i < 4; i++) {
        dates.push(d.toLocaleDateString('da-DK'));
        // Samme regel som recurring_next_date(): samme dag, højst månedens sidste - den sidste forbliver den sidste
        var day = d.getDate(), wasLast = day === new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
        var last = new Date(d.getFullYear(), d.getMonth() + months + 1, 0).getDate();
        d = new Date(d.getFullYear(), d.getMonth() + months, wasLast ? last : Math.min(day, last));
    }
    box.innerHTML = '<i class="fa-solid fa-calendar-days"></i> ' + <?php echo json_encode(lang('@Next occurrences:')); ?> + ' ' + dates.join(' → ');
}
document.addEventListener('DOMContentLoaded', updateRecurPreview);
</script>
<?php
htm_Footer();
