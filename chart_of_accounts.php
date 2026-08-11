<?php # /chart_of_accounts.php v:1.2.0 d:2026-07-07 i:claude (Opdateret til at bruge htm_ActionButtons)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; 

htm_Header('@Chart of Accounts');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";
if (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
    htm_Alert('@Account updated successfully', 'success', 700);
}

$top_btn = htm_Button('fa-plus', '@Add New Account', 'success', 'account_edit.php?id=0', '', '', '', false);
htm_Card_('@Chart of Accounts', 1000, '', 'acc_card', true, $top_btn);

$headers = ['@No.', '@Account Name', '@VAT Code', '@Rate', '@Actions'];
$data = [];

$sql = "SELECT a.*, v.vat_name, v.vat_rate 
        FROM accounts a 
        LEFT JOIN vat_codes v ON a.vat_code = v.vat_id 
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

        $data[] = [
            $id,
            "<strong>" . htmlspecialchars($row['acc_name']) . "</strong>",
            $vat_txt,
            $rate_txt,
            $btns
        ];
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
