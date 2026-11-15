<?php # /chart_import.php v:1.3.1 d:2026-09-15 i:evs
// Importér en kontoplan fra en fil (XLSX/CSV), som brugeren selv har hentet - fx BAS-kontoplanen fra bas.se (Sverige)
// eller NS 4102 (Norge). Trin 1: upload og forhåndsvisning (filens konti, udledt kontotype, hvad der findes i forvejen);
// trin 2: opret de nye konti. Eksisterende konti røres aldrig. Se inc/chart_import.inc.php.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/chart_import.inc.php';

$type_labels = ['income' => '@Income (Revenue)', 'expense' => '@Expense (Costs)', 'bank' => '@Bank / Cash', 'vat' => '@VAT (Settlement)',
                'asset' => '@Asset (Balance)', 'liability' => '@Liability (Debt)', 'equity' => '@Equity'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    // (forhåndsvisningen hører til det regnskab den blev lavet i - ikke et regnskab der er skiftet til siden)
    $sess = $_SESSION['chart_import'] ?? null;
    $list = (is_array($sess) && ($sess['account'] ?? null) === (string)($_SESSION['account_id'] ?? '')) ? ($sess['list'] ?? null) : null;
    if (!is_array($list) || !$list) redirect('chart_import.php', '@Upload the file again - the preview has expired.', 'warning');
    // (typen kan rettes pr. konto i forhåndsvisningen)
    foreach ((array)($_POST['type'] ?? []) as $no => $t) {
        foreach ($list as &$a) if ((int)$a['no'] === (int)$no && isset(ACC_TYPE_KEYS[$t])) $a['type'] = $t;
        unset($a);
    }
    try { $r = chart_import_apply($conn, $list, (string)($_POST['status'] ?? 'off')); }
    catch (Throwable $e) { redirect('chart_import.php', lang('@The import failed:') . ' ' . htmlspecialchars($e->getMessage()), 'error'); }
    unset($_SESSION['chart_import']);
    redirect('chart_of_accounts.php', sprintf(lang('@%d accounts were added. %d accounts already existed and were not changed.'), $r['added'], count($r['existing'])), 'success');
}

$list = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['chart_file'])) {
    $f = $_FILES['chart_file'];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) redirect('chart_import.php', '@The file could not be uploaded.', 'error');
    if ($f['size'] > 10 * 1024 * 1024) redirect('chart_import.php', '@The file is too large (max. 10 MB).', 'error');
    try { $list = chart_import_accounts(chart_import_read($f['tmp_name'], (string)$f['name'])); }
    catch (Throwable $e) { redirect('chart_import.php', htmlspecialchars($e->getMessage()), 'error'); }
    if (!$list) redirect('chart_import.php', '@No accounts were found in the file - an account is a 4-6 digit number followed by a name.', 'warning');
    $_SESSION['chart_import'] = ['account' => (string)($_SESSION['account_id'] ?? ''), 'list' => $list];
}

htm_Header(capt: '@Import Chart of Accounts', mwidth: 1000);
showMenu();
htm_Card_(capt: '@Import Chart of Accounts', wdth: 1000);
htm_Note(lang('@Import accounts from an Excel (.xlsx) or CSV file - for example the BAS chart of accounts, which you can download free of charge from bas.se, or the NS 4102 chart from your accountant. Every row with an account number (4-6 digits) followed by a name becomes an account. Accounts that already exist are never changed.'), 'margin-top:0; font-size:0.9em;');

if ($list === null) {
    echo '<form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:flex-end;">';
    csrf_field();
    echo '<div><label style="display:block; font-size:0.85em; color:var(--text-muted); margin-bottom:4px;">' . lang('@File (.xlsx or .csv)') . '</label>';
    echo '<input type="file" name="chart_file" accept=".xlsx,.csv,.txt" required></div>';
    htm_Button(icon: 'fa-upload', labl: '@Show preview', type: 'primary', attr: 'type="submit"');
    echo '</form>';
    if (!tc_country_pack('chart_classes')) htm_Note(lang('@The country pack does not know the account classes, so every account is imported as an expense account unless the file has a type column - check the types in the preview.'), 'font-size:0.85em; margin-top:10px;');
} else {
    $mine = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts");
    while ($res && ($r = DB::fetch_assoc($res))) $mine[(int)$r['acc_id']] = (string)$r['acc_name'];
    $new = array_filter($list, fn($a) => !isset($mine[(int)$a['no']]));
    echo '<p>' . sprintf(lang('@%d accounts in the file: %d new, %d already exist (kept as they are).'), count($list), count($new), count($list) - count($new)) . '</p>';
    echo '<form method="post">';
    csrf_field();
    $opts = array_map(fn($l) => lang($l), $type_labels);
    $data = [];
    foreach ($list as $a) {
        $no = (int)$a['no'];
        if (isset($mine[$no])) {
            $state = mb_strtolower($mine[$no]) === mb_strtolower($a['name']) ? lang('@Exists') : sprintf(lang('@Exists as "%s"'), htmlspecialchars($mine[$no]));
            $data[] = [$no, htmlspecialchars($a['name']), lang($type_labels[$a['type']] ?? $a['type']), '<span style="color:var(--text-muted);">' . $state . '</span>'];
        } else {
            $sel = htm_Select("type[$no]", $opts, $a['type'], 'padding:3px;' . (!empty($a['unknown']) ? ' border:1px dashed var(--color-warning) !important;' : ''), '', false);
            $data[] = [$no, htmlspecialchars($a['name']), $sel, lang('@New')];
        }
    }
    htm_Table(['@No.', '@Account Name', '@Type', '@Status'], $data, 'chartImpTbl', 2000, '', true, [], '60vh');
    echo '<div style="display:flex; gap:10px; align-items:flex-end; margin-top:15px;">';
    htm_Field(icon: 'fa-toggle-on', labl: '@New accounts are', name: 'status', valu: 'off', type: 'sele',
        opti: ['off' => lang('@switched off - switch on the ones you use'), 'active' => lang('@active')], wdth: '320px', legd: 'align-left');
    if ($new) htm_Button(icon: 'fa-file-import', labl: sprintf(lang('@Import %d new accounts'), count($new)), type: 'success', attr: 'type="submit" name="do_import" value="1"');
    htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'chart_import.php');
    echo '</div></form>';
}
htm_Card_end();
htm_Footer();
