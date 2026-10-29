<?php # /budget_edit.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): der findes
# slet ingen budgetfunktion i systemet - report_income.php viste kun faktiske
# tal, uden sammenligning til noget planlagt. Bevidst afgrænset til ÉT
# årligt beløb pr. konto (ikke månedlig granularitet) - se
# db-setup/migrate_account_budgets.php for tabellen og report_income.php for
# selve sammenligningen mod det faktiske.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$has_table = false;
if (DB::is_sqlite()) {
    $tchk = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='account_budgets'");
    $has_table = ($tchk && DB::fetch_assoc($tchk));
} else {
    $tchk = DB::query($conn, "SHOW TABLES LIKE 'account_budgets'");
    $has_table = ($tchk && DB::num_rows($tchk) > 0);
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$msg = ''; $err = '';

if ($has_table && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_budgets'])) {
    $post_year = (int)($_POST['year'] ?? $year);
    $budgets = $_POST['budget'] ?? [];
    foreach ($budgets as $acc_id => $raw) {
        $acc_id = (int)$acc_id;
        // RETTET: et TOMT felt blev gemt som budget 0 - på alle konti ved første gem. "Intet budget" og "budget 0"
        // kunne så ikke skelnes, og resultatopgørelsen viste hver udgiftskonto som overskredet. Tomt = intet budget.
        if (trim((string)$raw) === '') {
            DB::query($conn, "DELETE FROM account_budgets WHERE acc_id = $acc_id AND year = $post_year");
            continue;
        }
        $amount = parse_dk_number($raw);
        // Nulstiller til 0 i stedet for at slette rækken helt ved et tomt felt -
        // enklere at slå op/nulstille senere end at genskabe en slettet række.
        $exists = DB::num_rows(DB::query($conn, "SELECT 1 FROM account_budgets WHERE acc_id = $acc_id AND year = $post_year")) > 0;
        if ($exists) {
            DB::query($conn, "UPDATE account_budgets SET budget_amount = $amount WHERE acc_id = $acc_id AND year = $post_year");
        } else {
            DB::query($conn, "INSERT INTO account_budgets (acc_id, year, budget_amount) VALUES ($acc_id, $post_year, $amount)");
        }
    }
    log_action($conn, 'UPDATE_ACCOUNT_BUDGETS', 'account_budgets', $post_year, null, ['year' => $post_year, 'accounts_updated' => count($budgets)]);
    $year = $post_year;
    $msg = lang('@Budgets saved successfully');
}

htm_Header('@Account Budgets');
showMenu();

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

if (!$has_table) {
    htm_Card_('@Account Budgets', 900);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The budget database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

echo '<form method="get" style="max-width:900px; margin:10px auto 0 auto; display:flex; gap:10px; align-items:flex-end;">';
echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Year') . '</label>';
echo '<input type="number" name="year" value="' . $year . '" style="width:100px; padding:6px;"></div>';
htm_Button(labl: '@Show', type: 'primary', attr: 'data-hint="'.lang('@Show budgets for this year').'"');
echo '</form>';

require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$acc_res = DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts WHERE acc_type IN ('income','expense')
    AND (" . acc_visible_sql($conn) . " OR acc_id IN (SELECT acc_id FROM account_budgets WHERE year = $year)) ORDER BY acc_type DESC, " . acc_order_sql($conn) . "acc_id ASC");
$existing = [];
$bud_res = DB::query($conn, "SELECT acc_id, budget_amount FROM account_budgets WHERE year = $year");
while ($b = DB::fetch_assoc($bud_res)) { $existing[(int)$b['acc_id']] = (float)$b['budget_amount']; }

htm_Card_(lang('@Account Budgets') . ' - ' . $year, 900);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' . lang('@Set one annual budget amount per account - compared against the actual posted amount on report_income.php for the same year.') . '</p>';
echo '<form method="post">';
csrf_field();
echo '<input type="hidden" name="year" value="' . $year . '">';
echo '<table style="width:100%; border-collapse:collapse;">';
echo '<thead><tr style="background:#2c3e50; color:white;"><th style="padding:10px; text-align:left;">' . lang('@Account') . '</th><th style="padding:10px; text-align:right; width:200px;">' . lang('@Budget') . '</th></tr></thead><tbody>';
$cur_type = null;
while ($a = DB::fetch_assoc($acc_res)) {
    if ($a['acc_type'] !== $cur_type) {
        $cur_type = $a['acc_type'];
        $type_label = $cur_type === 'income' ? lang('@Income Accounts') : lang('@Expense Accounts');
        echo '<tr><td colspan="2" style="padding:8px 10px; background:var(--bg-panel); font-weight:bold; font-size:0.85em; text-transform:uppercase; color:var(--text-muted);">' . $type_label . '</td></tr>';
    }
    $val = isset($existing[(int)$a['acc_id']]) ? number_format($existing[(int)$a['acc_id']], 2, ',', '') : '';
    echo '<tr style="border-bottom:1px solid #eee;">';
    echo '<td style="padding:8px 10px;"><span style="color:#95a5a6; font-size:0.85em; margin-right:8px;">' . $a['acc_id'] . '</span>' . htmlspecialchars($a['acc_name']) . '</td>';
    echo '<td style="padding:8px 10px; text-align:right;"><input type="text" name="budget[' . (int)$a['acc_id'] . ']" value="' . $val . '" placeholder="0,00" style="width:150px; padding:6px; text-align:right;"></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '<div style="margin-top:20px;">';
echo '<button type="submit" name="save_budgets" value="1" style="background:var(--color-success); color:#fff; border:none; padding:10px 20px; border-radius:4px; font-weight:bold; cursor:pointer;"><i class="fa fa-save"></i> ' . lang('@Save Budgets') . '</button>';
echo ' <a href="report_income.php?year=' . $year . '" style="margin-left:10px;">' . lang('@View Income Statement for this year') . '</a>';
echo '</div>';
echo '</form>';
htm_Card_end();

htm_Footer();
ob_end_flush();
?>
