<?php # /bank_rules.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33):
# administration af de gemte "hvis beskrivelse indeholder X -> konto Y"-
# regler (bank_match_rules), som reconcile_list.php slår op og foreslår
# automatisk ved bankafstemning. Regler kan også oprettes direkte derfra
# ("Husk denne regel"-afkrydsningsfelt) - denne side er til overblik/oprydning.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$has_rules_table = false;
if (DB::is_sqlite()) {
    $brt = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='bank_match_rules'");
    $has_rules_table = ($brt && DB::fetch_assoc($brt));
} else {
    $brt = DB::query($conn, "SHOW TABLES LIKE 'bank_match_rules'");
    $has_rules_table = ($brt && DB::num_rows($brt) > 0);
}

// --- HANDLINGER (skal køre FØR htm_Header() - header("Location: ...") kan
// ikke sendes efter der allerede er skrevet HTML-output). ---
if ($has_rules_table && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        header("Location: bank_rules.php?msg=csrf_error"); exit;
    }
    if ($_POST['action'] === 'add') {
        $pattern = trim($_POST['pattern'] ?? '');
        $acc_id  = (int)($_POST['acc_id'] ?? 0);
        if ($pattern !== '' && $acc_id > 0) {
            $dup = DB::fetch_assoc(DB::query($conn, "SELECT rule_id FROM bank_match_rules WHERE pattern = '" . DB::escape($conn, $pattern) . "' AND acc_id = $acc_id"));
            if (!$dup) {
                DB::insert($conn, 'bank_match_rules', [
                    'pattern'    => $pattern,
                    'acc_id'     => $acc_id,
                    'created_by' => (int)($_SESSION['user_id'] ?? 0),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            header("Location: bank_rules.php?msg=added"); exit;
        }
        header("Location: bank_rules.php?msg=invalid"); exit;
    }
    if ($_POST['action'] === 'delete') {
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        if ($rule_id > 0) DB::query($conn, "DELETE FROM bank_match_rules WHERE rule_id = $rule_id");
        header("Location: bank_rules.php?msg=deleted"); exit;
    }
}

htm_Header('@Bank Matching Rules');
showMenu();

if (!$has_rules_table) {
    htm_Card_(capt: '@Bank Matching Rules', wdth: 700);
    htm_Alert(lang('@The bank matching rules database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    htm_Footer();
    exit;
}

if (isset($_GET['msg'])) {
    $msg_map = [
        'added'   => ['@Rule added.', 'success'],
        'deleted' => ['@Rule deleted.', 'success'],
        'invalid' => ['@Please fill in both a pattern and an account.', 'error'],
    ];
    if (isset($msg_map[$_GET['msg']])) {
        htm_Alert(lang($msg_map[$_GET['msg']][0]), $msg_map[$_GET['msg']][1]);
    }
}

htm_Card_(capt: '@Bank Matching Rules', wdth: 900);

echo '<p style="color:var(--text-muted); font-size:13px; margin-top:0;">' . lang('@When a bank line\'s description contains the pattern below, the account is suggested automatically during reconciliation. The bookkeeper can still change it before confirming.') . '</p>';

$acc_options = ['' => '-- ' . lang('@Select Account') . ' --'];
require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$res_acc = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE " . acc_visible_sql($conn) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
while ($row = DB::fetch_assoc($res_acc)) { $acc_options[$row['acc_id']] = $row['acc_id'] . ' - ' . acc_label($row['acc_name'], $row['acc_status'] ?? null); }

echo '<form method="post" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px; flex-wrap:wrap;">';
csrf_field();
echo '<input type="hidden" name="action" value="add">';
echo '<div><label style="font-size:12px; color:var(--text-muted); display:block;">' . lang('@Pattern (part of the description)') . '</label>';
echo '<input type="text" name="pattern" required style="padding:6px; width:260px;"></div>';
echo '<div><label style="font-size:12px; color:var(--text-muted); display:block;">' . lang('@Account') . '</label>';
htm_Select('acc_id', $acc_options, '', 'width:260px; padding:6px;');
echo '</div>';
echo '<button type="submit" class="btn-success" style="padding:8px 16px;">' . lang('@Add Rule') . '</button>';
echo '</form>';

$res_rules = DB::query($conn, "SELECT r.*, a.acc_name FROM bank_match_rules r LEFT JOIN accounts a ON r.acc_id = a.acc_id ORDER BY r.rule_id DESC");
$headers = ['@Pattern', '@Account', '@Created', '@Actions'];
$rows = [];
if ($res_rules) {
    while ($r = DB::fetch_assoc($res_rules)) {
        $del_form = '<form method="post" style="display:inline;" onsubmit="return confirm(' . json_encode(lang('@Are you sure?')) . ');">'
            . csrf_field(false) . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="rule_id" value="' . (int)$r['rule_id'] . '">'
            . '<button type="submit" style="background:transparent; border:none; color:var(--color-danger); cursor:pointer; font-size:16px;" data-hint="' . htmlspecialchars(lang('@Delete this rule')) . '"><i class="fa fa-trash"></i></button></form>';
        $rows[] = [
            htmlspecialchars($r['pattern']),
            htmlspecialchars($r['acc_id'] . ' - ' . ($r['acc_name'] ?: '?')),
            !empty($r['created_at']) ? date(CONF_DATE_FORMAT, strtotime($r['created_at'])) : '-',
            $del_form,
        ];
    }
}
if (empty($rows)) {
    echo '<p style="color:var(--text-muted);">' . lang('@No rules saved yet.') . '</p>';
} else {
    htm_Table($headers, $rows, 'bank_rules_tbl');
}

htm_Card_end();
htm_Footer();
