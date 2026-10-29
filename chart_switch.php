<?php # /chart_switch.php v:1.3.1 d:2026-09-15 i:evs
# Skift til Erhvervsstyrelsens standardkontoplan - guidet: 1) vælg afløsere og dato, 2) se planen (saldo-
# overførsel, hvad der flyttes), 3) udfør. Al logik i inc/chart_switch.inc.php.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/chart_switch.inc.php';

$cur  = $global_settings['currency'] ?? 'DKK';
$err  = '';  $done = null;  $plan = null;  $resolved = null;
$date = (string)($_POST['switch_date'] ?? '');
$map  = array_map('intval', (array)($_POST['map'] ?? []));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['import_std'])) {
        try { acc_import_standard($conn); } catch (Throwable $e) { $err = $e->getMessage(); }
    } elseif (isset($_POST['confirm_switch'])) {
        try { $done = cs_execute($conn, $map, $date); } catch (Throwable $e) { $err = $e->getMessage(); }
    } elseif (isset($_POST['resolve_conflicts'])) {
        $res = cs_resolve_conflicts($conn, (array)($_POST['clash'] ?? []), array_map('intval', (array)($_POST['target'] ?? [])));
        if ($res['errors']) $err = implode(' ', $res['errors']);
        else $resolved = $res['done'];
    } elseif (isset($_POST['preview'])) {
        $plan = cs_plan($conn, $map, $date);
    }
}

// Standardkontoplanen er kendt fra filen - afløserne behøver ikke være importeret (de oprettes ved skiftet)
$std = cs_std_available($conn);
$std_imported = (bool)cs_std_accounts($conn);
$own = cs_own_accounts($conn);

// Standarddato: første dag i det første åbne regnskabsår - ellers i dag
if ($date === '') {
    $fy = @DB::query($conn, "SELECT start_date FROM fiscal_years WHERE is_closed = 0 ORDER BY start_date LIMIT 1");
    $fy = $fy ? DB::fetch_assoc($fy) : null;
    $last = DB::fetch_row(DB::query($conn, "SELECT MAX(jou_date) FROM journal"));
    $date = ($fy && !empty($fy['start_date']) && (string)($last[0] ?? '') <= (string)$fy['start_date']) ? (string)$fy['start_date'] : date('Y-m-d');
}

htm_Header('@Switch to the Standard Chart of Accounts', 1200);
showMenu();
echo "<div style='max-width:1200px; margin:0 auto; padding:10px;'>";

if ($err !== '') htm_Alert(htmlspecialchars($err), 'error', 1200);
if ($resolved) htm_Alert(lang('@The conflicts are solved:') . '<ul style="margin:6px 0 0 18px; padding:0;"><li>' . implode('</li><li>', array_map('htmlspecialchars', $resolved)) . '</li></ul>', 'success', 1200);
elseif ($resolved !== null) htm_Alert(lang('@No solution was chosen - nothing was changed.'), 'info', 1200);

// --- Udført ---
if ($done) {
    htm_Card_('@Switch to the Standard Chart of Accounts', 1200);
    htm_Alert(sprintf(lang('@The switch is done: %d accounts replaced.'), count($done['map']))
        . ($done['voucher_no'] ? ' ' . sprintf(lang('@Balances transferred with voucher no. %d.'), $done['voucher_no']) : ' ' . lang('@No balances needed to be transferred.')), 'success', 1150);
    $rows = [];
    foreach ($done['counts'] as $t => $n) if ($n > 0) $rows[] = [htmlspecialchars($t), (int)$n];
    if ($rows) htm_Table(['@Updated', '@Rows'], $rows, 'cs_done', 50);
    echo '<p><a href="chart_of_accounts.php">' . lang('@Chart of Accounts') . '</a> · <a href="ledger_view.php">' . lang('@General Ledger') . '</a> · <a href="company_settings.php">' . lang('@Company Settings') . '</a></p>';
    htm_Card_end();
    echo '</div>';  htm_Footer();  ob_end_flush();  exit;
}

htm_Card_('@Switch to the Standard Chart of Accounts', 1200);
echo '<p style="margin:0 0 8px;"><a href="setup_chart.php?tpl=official" data-hint="' . lang('@Look through the whole standard chart of accounts - nothing is changed') . '"><i class="fa fa-eye"></i> ' . lang('@View the Standard Chart of Accounts') . '</a></p>';
echo '<p style="color:var(--text-muted); line-height:1.5;">' . lang('@Posted entries cannot be moved, so the switch applies from a date: each of your accounts gets a standard account as its replacement, the balances of balance sheet accounts are transferred with one voucher, and everything that points to an old account (settings, VAT codes, products, web shops, bank rules, fixed assets, budgets, drafts) points to the replacement from then on. The old accounts are switched off - their history stays in the reports.') . '</p>';

// Uden import: kun de standardkonti der vælges som afløsere, oprettes ved skiftet - hele planen kan stadig importeres
if (!$std_imported) {
    echo '<form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 10px; font-size:0.9em; color:var(--text-muted);">' . csrf_field(false)
        . '<span><i class="fa fa-info-circle"></i> ' . lang('@The standard chart of accounts is not imported - only the standard accounts you choose as replacements are created when you switch. The others can be imported at any time.') . '</span>'
        . '<button type="submit" name="import_std" value="1" class="btn" style="padding:4px 10px;"><i class="fa-solid fa-file-import"></i> ' . lang('@Import the whole standard chart now') . '</button></form>';
}

// --- Planen (forhåndsvisning) ---
if ($plan) {
    foreach ($plan['errors'] as $e)   htm_Alert(htmlspecialchars($e), 'error', 1150);
    foreach ($plan['warnings'] as $w) htm_Alert(htmlspecialchars($w), 'warning', 1150);
    if (!$plan['errors']) {
        echo '<h3 style="margin:10px 0 6px;">' . lang('@Balances transferred on') . ' ' . htmlspecialchars(date(CONF_DATE_FORMAT, strtotime($date))) . '</h3>';
        $rows = [];
        foreach ($plan['transfers'] as [$old, $new, $b]) {
            $rows[] = [$old . ' ' . htmlspecialchars($own[$old]['acc_name']), $new . ' ' . htmlspecialchars($std[$new]['acc_name']),
                       '<div style="text-align:right;">' . number_format($b, 2, ',', '.') . ' ' . $cur . '</div>'];
        }
        if ($rows) htm_Table(['@From', '@To', '@Balance'], $rows, 'cs_tr', 200);
        else echo '<p style="color:var(--text-muted);">' . lang('@No balances need to be transferred.') . '</p>';
        echo '<form method="post" style="margin-top:12px;">' . csrf_field(false)
           . '<input type="hidden" name="switch_date" value="' . htmlspecialchars($date) . '">';
        foreach ($plan['map'] as $o => $n) echo '<input type="hidden" name="map[' . (int)$o . ']" value="' . (int)$n . '">';
        echo '<button type="submit" name="confirm_switch" value="1" style="background:var(--color-danger); color:#fff; border:0; padding:10px 16px; border-radius:4px; cursor:pointer; font-weight:bold;"'
           . ' onclick="return confirm(' . htmlspecialchars(json_encode(lang('@Carry out the switch? The transfer is posted and cannot be undone except by a new posting.')), ENT_QUOTES) . ')">'
           . '<i class="fa-solid fa-right-left"></i> ' . sprintf(lang('@Carry out the switch (%d accounts)'), count($plan['map'])) . '</button></form>';
        echo '<hr style="margin:20px 0; border:0; border-top:1px solid var(--border-color);">';
    }
}

// --- Konflikter: dine konti der optager et standardnummer med et ANDET indhold ---
// Standardkontoen kan ikke importeres på et optaget nummer. Hver konflikt får et forslag: flyt din konto (kun uden
// posteringer), lad den overtage standardkontoen (samme kontofamilie), importér standardkontoen under et ledigt
// nummer, eller gør intet. Se inc/chart_switch.inc.php (cs_conflicts / cs_resolve_conflicts).
$cs_conf = cs_conflicts($conn);
if ($cs_conf) {
    $type_txt = fn($t) => lang('@' . ucfirst((string)$t));
    $opt_txt = ['move' => lang('@Move your account to number'), 'takeover' => lang('@Your account becomes the standard account'),
                'alt' => lang('@Import the standard account as number'), 'keep' => lang('@Do nothing (choose another replacement below)')];
    echo '<h3 style="margin:14px 0 4px;"><i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> '
       . sprintf(lang('@Numbers used by your own accounts (%d)'), count($cs_conf)) . '</h3>';
    echo '<p style="color:var(--text-muted); margin:0 0 8px; line-height:1.5;">' . lang('@The standard account cannot be imported while your account has its number. An account without postings can be moved; an account with the same content can become the standard account (it keeps its history, but earlier reports show the new name); otherwise the standard account can be imported under a free number - SAF-T still sees it as the standard account.') . '</p>';
    echo '<form method="post">' . csrf_field(false) . '<input type="hidden" name="switch_date" value="' . htmlspecialchars($date) . '">';
    $rows = [];
    foreach ($cs_conf as $no => $c) {
        $act = (string)($_POST['clash'][$no] ?? $c['proposal']);
        $sel = '<select name="clash[' . $no . ']" class="cs-clash" data-no="' . $no . '" onchange="csClash(this)" style="width:100%; padding:4px;">';
        foreach ($c['options'] as $o) {
            $sel .= '<option value="' . $o . '"' . ($o === $act ? ' selected' : '') . '>' . htmlspecialchars($opt_txt[$o]) . ($o === $c['proposal'] ? ' ★' : '') . '</option>';
        }
        $sel .= '</select>';
        $tgt = (int)($_POST['target'][$no] ?? $c['target']);
        $num = '<input type="number" name="target[' . $no . ']" id="cs-t-' . $no . '" value="' . $tgt . '" min="1" style="width:90px; padding:4px;"' . (in_array($act, ['move', 'alt'], true) ? '' : ' hidden') . '>';
        $posts = $c['postings'] ? sprintf(lang($c['postings'] === 1 ? '@%d posting' : '@%d postings'), $c['postings']) : lang('@no postings');
        $rows[] = [
            '<b>' . $no . '</b>',
            htmlspecialchars($c['own']['acc_name']) . '<div style="font-size:0.8em; color:var(--text-muted);">' . htmlspecialchars($type_txt($c['own']['acc_type'])) . ' · ' . $posts . '</div>',
            htmlspecialchars($c['std']['name']) . '<div style="font-size:0.8em; color:var(--text-muted);">' . htmlspecialchars($type_txt($c['std']['type'])) . '</div>',
            '<div style="display:flex; gap:6px; align-items:center;">' . $sel . $num . '</div>',
        ];
    }
    htm_Table(['@No.', '@Your account', '@Standard account', '@Solution'], $rows, 'cs_clash', 400, '', true, ['width:70px;', '', '', 'width:470px;']);
    echo '<p style="font-size:0.85em; color:var(--text-muted); margin:4px 0 0;">★ ' . lang('@suggested') . '</p>';
    echo '<button type="submit" name="resolve_conflicts" value="1" style="margin-top:8px; padding:9px 16px; border-radius:4px; border:0; background:var(--color-primary); color:#fff; cursor:pointer;"'
       . ' onclick="return confirm(' . htmlspecialchars(json_encode(lang('@Carry out the chosen solutions? Accounts are moved, renamed or imported now - nothing is posted.')), ENT_QUOTES) . ')">'
       . '<i class="fa-solid fa-wand-magic-sparkles"></i> ' . lang('@Solve the conflicts') . '</button></form>';
    echo '<script>function csClash(s){var t=document.getElementById("cs-t-"+s.dataset.no); if(t) t.hidden=!(s.value==="move"||s.value==="alt");}</script>';
    echo '<hr style="margin:20px 0; border:0; border-top:1px solid var(--border-color);">';
}

// --- Trin 1: afløsere og dato ---
$by_family = [];
foreach ($std as $no => $s) $by_family[cs_family((string)$s['acc_type'])][$no] = $no . ' - ' . $s['acc_name']
    . ((int)$s['std_no'] !== $no ? ' (' . sprintf(lang('@standard %d'), $s['std_no']) . ')' : '');
$role_txt = ['conf_acc_bank' => lang('@Bank'), 'conf_acc_debitor' => lang('@Debitors'), 'conf_acc_creditor' => lang('@Creditors'), 'conf_acc_sales' => lang('@Sales'),
             'conf_acc_vat' => lang('@Output VAT'), 'conf_acc_purchase_vat' => lang('@Input VAT'), 'conf_acc_fx' => lang('@Currency Gain/Loss'), 'conf_acc_bad_debt' => lang('@Bad debt')];
echo '<form method="post">' . csrf_field(false);
echo '<div style="display:flex; gap:10px; align-items:center; margin:8px 0 12px;"><label style="font-weight:bold;">' . lang('@Switch date') . ':</label>'
   . '<input type="date" name="switch_date" value="' . htmlspecialchars($date) . '" required style="padding:6px; border:1px solid var(--border-color); border-radius:4px;">'
   . '<span style="color:var(--text-muted); font-size:0.9em;">' . lang('@Preferably the first day of a fiscal year, after the year-end closing.') . '</span></div>';
$rows = [];
foreach ($own as $id => $a) {
    if ($a['postings'] === 0 && !$a['roles'] && ($a['acc_status'] ?? 'active') === 'off') continue;      // ubrugt og slukket: ikke relevant
    $pick = isset($_POST['map'][$id]) ? (int)$_POST['map'][$id] : cs_propose($a, $std);
    $opts = '<option value="0">' . htmlspecialchars(lang('@- keep this account -')) . '</option>';
    foreach ($by_family[cs_family((string)$a['acc_type'])] ?? [] as $no => $t) $opts .= '<option value="' . $no . '"' . ($no === $pick ? ' selected' : '') . '>' . htmlspecialchars($t) . '</option>';
    $badges = '';
    foreach ($a['roles'] as $r) $badges .= ' <span style="background:var(--bg-panel); border:1px solid var(--border-color); border-radius:8px; padding:1px 6px; font-size:0.8em;">' . htmlspecialchars($role_txt[$r] ?? $r) . '</span>';
    $rows[] = [
        $id, htmlspecialchars($a['acc_name']) . $badges, htmlspecialchars(lang('@' . ucfirst((string)$a['acc_type']))),
        '<div style="text-align:right;">' . (in_array($a['acc_type'], CS_BALANCE_TYPES, true) ? number_format($a['balance'], 2, ',', '.') : '<span style="color:var(--text-muted);">-</span>') . '</div>',
        '<select name="map[' . $id . ']" style="width:100%; padding:4px;">' . $opts . '</select>',
    ];
}
if ($rows) htm_Table(['@No.', '@Your account', '@Type', '@Balance', '@Replaced by'], $rows, 'cs_map', 500, '', true,
                     ['width:70px;', '', 'width:90px;', 'width:120px; text-align:right;', 'width:420px;']);
else echo '<p>' . lang('@All accounts in use are already standard accounts - there is nothing to switch.') . '</p>';
if ($rows) echo '<button type="submit" name="preview" value="1" style="margin-top:12px; padding:9px 16px; border-radius:4px; border:0; background:var(--color-primary); color:#fff; cursor:pointer;">'
              . '<i class="fa-solid fa-magnifying-glass"></i> ' . lang('@Show the plan') . '</button>';
echo '</form>';
htm_Card_end();
echo '</div>';
htm_Footer();
ob_end_flush();
