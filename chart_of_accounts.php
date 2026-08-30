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
require_once 'inc/php2htm.lib.php'; 

htm_Header('@Chart of Accounts');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";
if (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
    htm_Alert('@Account updated successfully', 'success', 700);
}

$top_btn = htm_Button('fa-plus', '@Add New Account', 'success', 'account_edit.php?id=0', '', 'data-hint="'.lang('@Create a new account in the chart of accounts').'"', '', false);
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

        $acc_no_cell = $id;
        $acc_name_cell = "<strong>" . htmlspecialchars($row['acc_name']) . "</strong>";
        if (isset($special_accounts[$id])) {
            $role = $special_accounts[$id];
            $badge_style = "background:{$role['bg']}; color:{$role['fg']}; padding:2px 8px; border-radius:10px; font-weight:bold; font-size:0.85em; display:inline-block;";
            $acc_no_cell = "<span style=\"$badge_style\">$id</span>";
            $acc_name_cell .= " <span data-hint=\"" . htmlspecialchars(lang('@Used by Company Settings for automatic posting. Change it there, not here.')) . "\" style=\"$badge_style cursor:help;\">{$role['label']}</span>";
        }

        $data[] = [
            $acc_no_cell,
            $acc_name_cell,
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
