<?php # /expense_list.php v:0.9.0 d:2026-05-08 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$cur = 'DKK'; 
$upload_dir = 'uploads/expenses/';

htm_Header('@Expenses');
showMenu();

// Vi bruger htm_Shell til den ydre container
htm_Shell_('max-width:1100px; margin:0 auto; padding:10px;');

$top_btn = htm_Button(icon:'fa-plus', labl:'@Add Expense', type:'success', link:'expense_edit.php', echo:false);

htm_Card_('@Expense Overview', 1100, '', 'exp_card', true, $top_btn);

$headers = ['@Date', '@Supplier', '@Description', '@Account', '@Amount', '@File', '@Actions'];
$data = [];

if (!$conn) {
    htm_Alert("Database connection failed", "error");
} else {
    $sql = "SELECT e.*, a.acc_name 
            FROM expenses e 
            LEFT JOIN accounts a ON e.account_id = a.acc_id 
            ORDER BY e.exp_date DESC";
    $res = mysqli_query($conn, $sql);

    if (!$res) {
        htm_Alert("SQL Error: " . mysqli_error($conn), "error");
    } else {
        while ($r = mysqli_fetch_assoc($res)) {
            $id = (int)$r['exp_id'];
            $date = date('d-m-Y', strtotime($r['exp_date']));
            $amt = number_format($r['amount'], 2, ',', '.') . " " . $cur;
            
            // Bilags-indikator med htm_Shell
            $attachment_cell = '---';
            if (!empty($r['attachment'])) {
                $file_path = $upload_dir . $r['attachment'];
                $attachment_cell = htm_Shell_('display:inline-block; color:#e67e22;', 'span', false);
                $attachment_cell .= '<a href="'.$file_path.'" target="_blank" style="color:inherit;"><i class="fa-solid fa-paperclip" title="'.htmlspecialchars($r['attachment']).'"></i></a>';
                $attachment_cell .= htm_Shell_end(false);
            }

            $btnEdit = htm_Button(icon:'fa-edit', type:'primary', link:'expense_edit.php?id='.$id, echo:false);
            $btnDel  = htm_Button(icon:'fa-trash', type:'danger', link:'expense_actions.php?action=delete&id='.$id, attr:'onclick="return confirm(\''.lang('@Are you sure?').'\')"', echo:false);

            $data[] = [
                $date,
                "<strong>" . htmlspecialchars($r['supplier']) . "</strong>",
                "<span style='font-size:0.85em; color:#7f8c8d;'>" . htmlspecialchars($r['description'] ?? '') . "</span>",
                ($r['acc_name'] ?? '---'),
                "<strong>$amt</strong>",
                $attachment_cell,
                $btnEdit . " " . $btnDel
            ];
        }
    }
}

if (empty($data) && isset($res) && $res) {
    echo "<p style='padding:40px; text-align:center; color:#999;'>" . lang('@No expenses found') . "</p>";
} elseif (!empty($data)) {
    htm_Table($headers, $data, 'expTbl');
}

htm_Card_end();
htm_Shell_end(); // Lukker den ydre container

htm_Footer();
ob_end_flush();
?>