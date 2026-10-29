<?php # /chart_of_accounts.php v:1.3.0 d:2026-08-30 i:evs
# Kontoplan-oversigt. De fem conf_acc_*-styrede "særlige konti" markeres med
# en kulørt baggrund + rolle-mærkat (Bank/Debitor/Salg/Udg. moms/Indg. moms).
# menu_visibility.php's egen standardopsætning kategoriserer denne side som
# niveau-3-kun (samme liste som storage_browser.php/settings_fees.php/
# user_list.php), men det var kun håndhævet i MENU-visningen, ikke server-
# side her eller i account_edit.php/account_delete.php - en niveau-1-bruger
# kunne tilgå alle tre direkte via URL'en og redigere/slette konti i
# kontoplanen. $rLev tilføjet til alle tre, samme mønster som
# storage_browser.php.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';require_once 'inc/audit.inc.php';
require_once 'inc/accounts.inc.php';      // aktiv / dæmpet / slukket + Erhvervsstyrelsens standardkontoplan

// Kontoplanens synlighed: status pr. konto (én fælles formular - knapperne i tabellen peger på den med form=)
$st_ready = acc_status_ready($conn);
$st_msg = '';  $st_msg_type = 'success';  $st_confirm = null;
if ($st_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['set_status']) && preg_match('/^(\d+):(active|dimmed|off)(:force)?$/', (string)$_POST['set_status'], $m)) {
        $r = acc_set_status($conn, (int)$m[1], $m[2], !empty($m[3]));
        if (!$r['ok']) {
            $st_msg = $r['msg'];  $st_msg_type = !empty($r['confirm']) ? 'warning' : 'error';
            if (!empty($r['confirm'])) $st_confirm = $m[1] . ':off:force';
        }
    } elseif (isset($_POST['import_std'])) {
        try {
            $r = acc_import_standard($conn);
            $st_msg = sprintf(lang('@The standard chart of accounts is imported: %d accounts added (switched off), %d skipped because you already use the number.'), $r['added'], count($r['skipped']))
                    . ($r['reset'] ? ' ' . sprintf(lang('@%d old standard mappings were removed - map them again under Map to Standard Accounts.'), $r['reset']) : '');
        } catch (Throwable $e) { $st_msg = $e->getMessage();  $st_msg_type = 'error'; }
    }
}
$st_view = in_array($_GET['view'] ?? '', ['all', 'off'], true) ? $_GET['view'] : 'used';
 

htm_Header('@Chart of Accounts');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";
if (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
    htm_Alert('@Account updated successfully', 'success', 700);
}
if ($st_msg !== '') {
    htm_Alert($st_msg . ($st_confirm ? ' <button type="submit" form="accStatusForm" name="set_status" value="' . htmlspecialchars($st_confirm) . '" style="margin-left:8px;">' . lang('@Switch it off anyway') . '</button>' : ''), $st_msg_type, 1000);
}
if (!$st_ready) {
    htm_Banner('<i class="fa fa-info-circle"></i> ' . lang('@Only the accounts you use can be shown in the lists - this needs a database update:') . ' <a href="run_migrate.php">' . lang('@Database migration') . '</a>', 'info');
}

$top_btn = htm_Button('fa-plus', '@Add New Account', 'success', 'account_edit.php?id=0', '', 'data-hint="'.lang('@Create a new account in the chart of accounts').'"', '', false)
    . ' ' . htm_Button('fa-random', '@Bulk Map to Standard Accounts', 'secondary', 'account_std_mapping.php', '', 'data-hint="'.lang('@Map all accounts to the Danish standard chart of accounts (used for SAF-T reporting) from one page').'"', '', false)
    . ' ' . htm_Button('fa-right-left', '@Switch to the Standard Chart of Accounts', 'secondary', 'chart_switch.php', '', 'data-hint="'.lang('@Replace your accounts by standard accounts from a date - balances are transferred, settings and references follow').'"', '', false)
    . ' ' . htm_Button('fa-eye', '@View the Standard Chart of Accounts', 'secondary', 'setup_chart.php?tpl=official', '', 'data-hint="'.lang('@Look through the official standard chart of accounts before you import it or switch to it').'"', '', false);
htm_Card_('@Chart of Accounts', 1000, '', 'acc_card', true, $top_btn);

// "Særlige konti" = dem der er navngivet direkte i Firmaindstillinger og
// styrer den automatiske postering (conf_acc_*) - markeres med en kulørt
// baggrund + rolle-mærkat, så det er tydeligt hvorfor de ikke bare må
// omdøbes/slettes uden at rette indstillingen først (bruger-anmodet).
$s = get_settings($conn);
// RETTET (§bugs-batch-24-review): manglede conf_acc_creditor - samme liste
// duplikeret fra account_delete.php, som fik nøjagtig samme rettelse (se
// dens kommentar). Kun visningsmæssig konsekvens her (badge/farve), men bør
// stemme overens med den liste der reelt beskytter kontoen mod sletning.
$special_accounts = [
    (int)($s['conf_acc_bank'] ?? 5000)         => ['label' => lang('@Bank'),                 'bg' => '#dbeafe', 'fg' => '#1e40af'],
    (int)($s['conf_acc_debitor'] ?? 8100)      => ['label' => lang('@Debitors'),              'bg' => '#d1fae5', 'fg' => '#065f46'],
    (int)($s['conf_acc_creditor'] ?? 4000)     => ['label' => lang('@Creditors'),             'bg' => '#fee2e2', 'fg' => '#991b1b'],
    (int)($s['conf_acc_sales'] ?? 1000)        => ['label' => lang('@Sales'),                 'bg' => '#ede9fe', 'fg' => '#5b21b6'],
    (int)($s['conf_acc_vat'] ?? 6900)          => ['label' => lang('@Output VAT'),            'bg' => '#ffedd5', 'fg' => '#9a3412'],
    (int)($s['conf_acc_purchase_vat'] ?? 6910) => ['label' => lang('@Input VAT'),             'bg' => '#fef3c7', 'fg' => '#92400e'],
    (int)($s['conf_acc_fx']           ?? 7200) => ['label' => lang('@Currency Gain/Loss'),    'bg' => '#e0e7ff', 'fg' => '#3730a3'],
];

$headers = $st_ready ? ['@No.', '@Account Name', '@VAT Code', '@Rate', '@Shown in lists', '@Actions'] : ['@No.', '@Account Name', '@VAT Code', '@Rate', '@Actions'];
$st_where = $st_view === 'off' ? "WHERE a.acc_status = 'off'" : ($st_view === 'used' && $st_ready ? "WHERE COALESCE(a.acc_status, 'active') <> 'off'" : '');
$st_counts = $st_ready ? DB::fetch_assoc(DB::query($conn, "SELECT SUM(CASE WHEN acc_status = 'off' THEN 1 ELSE 0 END) AS n_off, COUNT(*) AS n FROM accounts")) : null;
$st_protected = $st_ready ? acc_protected_ids($conn) : [];
if ($st_ready) {
    // Filter: i brug (aktive + dæmpede) / alle / slukkede - og importen af standardkontoplanen
    echo '<form id="accStatusForm" method="post" style="display:none;">' . csrf_field(false) . '</form>';
    echo '<div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin:0 0 10px;">';
    foreach (['used' => lang('@In use (active and dimmed)'), 'all' => lang('@All accounts'), 'off' => lang('@Switched off')] as $v => $t) {
        $on = $st_view === $v;
        echo '<a href="chart_of_accounts.php?view=' . $v . '" data-hint="' . lang('@Filter the list: accounts in use, all accounts or the switched-off accounts') . '" style="padding:5px 12px; border-radius:14px; text-decoration:none; font-size:13px; border:1px solid var(--border-color);'
           . ($on ? ' background:var(--color-primary); color:#fff;' : ' color:var(--text-main);') . '">' . $t . ($v === 'off' && $st_counts ? ' (' . (int)$st_counts['n_off'] . ')' : '') . '</a>';
    }
    $has_std = (bool)DB::fetch_assoc(DB::query($conn, "SELECT std_id FROM std_accounts WHERE std_id = 1010"));
    echo '<span style="margin-left:auto;"></span>';
    echo '<button type="submit" form="accStatusForm" name="import_std" value="1" style="padding:5px 12px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-panel); color:var(--text-main); cursor:pointer;"'
       . ' onclick="return confirm(' . htmlspecialchars(json_encode(lang('@Import the standard chart of accounts of the Danish Business Authority? About 485 accounts are added switched off - accounts whose number you already use are skipped. Switch on the accounts you need.')), ENT_QUOTES) . ')"'
       . ' data-hint="' . htmlspecialchars(lang('@The official standard chart of accounts (2026) - added switched off, numbered and mapped to the standard (SAF-T)')) . '">'
       . '<i class="fa-solid fa-file-import"></i> ' . ($has_std ? lang('@Import the standard chart again') : lang('@Import the standard chart of accounts')) . '</button>';
    echo '</div>';
}
$data = [];

$sql = "SELECT a.*, v.vat_name, v.vat_rate
        FROM accounts a
        LEFT JOIN vat_codes v ON a.vat_code = v.vat_id
        $st_where
        ORDER BY a.acc_id ASC";
$res = DB::query($conn, $sql);

if (!$res) {
    echo htm_Alert("@SQL Error: " . DB::error($conn), "danger");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $vat_txt = $row['vat_name'] ?: lang('@None');
        $rate_txt = (isset($row['vat_rate']) && $row['vat_rate'] > 0) ? number_format($row['vat_rate'], 0) . "%" : "-";
        $id = (int)$row['acc_id'];

        $accRowActions = [
            ['icon' => 'fa-edit',  'link' => 'account_edit.php?id='.$id, 'hint' => '@Edit', 'type' => 'primary'],
            ['icon' => 'fa-trash', 'link' => 'account_delete.php?id='.$id, 'hint' => '@Delete', 'confirm' => '@Are you sure?', 'type' => 'danger'],
        ];
        $btns = htm_ActionButtons($accRowActions, false);

        $acc_no_cell = $id;
        $acc_name_cell = "<strong>" . htmlspecialchars($row['acc_name']) . "</strong>";
        if (isset($special_accounts[$id])) {
            $role = $special_accounts[$id];
            $badge_style = "background:{$role['bg']}; color:{$role['fg']}; padding:2px 8px; border-radius:10px; font-weight:bold; font-size:0.85em; display:inline-block;";
            $acc_no_cell = "<span style=\"$badge_style\">$id</span>";
            $acc_name_cell .= " <span data-hint=\"" . htmlspecialchars(lang('@Used by Company Settings for automatic posting. Change it there, not here.')) . "\" style=\"$badge_style cursor:help;\">{$role['label']}</span>";
        }
        // NYT (BEK 811/2026-gennemgang, Bilag 3 "bogføringsguide/konterings-
        // vejledning", se [[digital-standard-bookkeeping-registration-goal]]).
        if (!empty($row['guidance'] ?? '')) {
            $acc_name_cell .= ' <i class="fa-solid fa-circle-info" data-hint="' . htmlspecialchars($row['guidance']) . '" style="color:var(--color-info); cursor:help;"></i>';
        }

        $row_data = [$acc_no_cell, $acc_name_cell, $vat_txt, $rate_txt];
        if ($st_ready) {
            // Aktiv / Dæmpet / Slukket - den valgte er fremhævet; "Slukket" findes ikke for konti TinyCash bogfører på automatisk
            $st = $row['acc_status'] ?: 'active';
            $seg = '<span style="display:inline-flex; border:1px solid var(--border-color); border-radius:12px; overflow:hidden; font-size:11px;">';
            foreach (['active' => lang('@Active'), 'dimmed' => lang('@Dimmed'), 'off' => lang('@Off')] as $k => $t) {
                if ($k === 'off' && isset($st_protected[$id])) {
                    $seg .= '<span style="padding:2px 7px; color:var(--text-muted); opacity:.5;" data-hint="' . htmlspecialchars(sprintf(lang('@Cannot be switched off - %s'), $st_protected[$id])) . '">' . $t . '</span>';
                    continue;
                }
                $sel = $st === $k;
                $bg = $sel ? ($k === 'active' ? 'var(--color-success)' : ($k === 'dimmed' ? 'var(--color-secondary)' : 'var(--color-danger)')) : 'transparent';
                $seg .= '<button type="submit" form="accStatusForm" name="set_status" value="' . $id . ':' . $k . '" style="border:0; padding:2px 7px; cursor:pointer; font-size:11px; background:' . $bg . '; color:' . ($sel ? '#fff' : 'var(--text-main)') . ';">' . $t . '</button>';
            }
            $row_data[] = $seg . '</span>';
            if ($st === 'dimmed') $row_data[1] = '<span style="opacity:.6;">' . $row_data[1] . '</span>';
            if ($st === 'off')    $row_data[1] = '<span style="opacity:.45;">' . $row_data[1] . '</span>';
        }
        $row_data[] = $btns;
        $data[] = $row_data;
    }
    
    if (empty($data)) {
        echo "<p style='padding:20px; text-align:center; color:var(--text-muted);'>" . lang('@No accounts found') . "</p>";
    } else {
        htm_Table($headers, $data, 'accTbl');
    }
}

htm_Card_end();
echo "</div>";
htm_Footer();
ob_end_flush();
?>
