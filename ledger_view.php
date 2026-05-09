<?php # /ledger_view.page.php v:1.1.0 d:2026-05-04 i:Gemini m:3
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// --- DELETE JOURNAL ENTRY LOGIC ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_jou' && isset($_GET['jou_id'])) {
    $jou_id = (int)$_GET['jou_id'];
    
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "DELETE FROM ledger WHERE jou_id = $jou_id");
        mysqli_query($conn, "DELETE FROM journal WHERE jou_id = $jou_id");
        
        mysqli_commit($conn);
        header("Location: ledger_view.page.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        die("Fejl ved sletning: " . $e->getMessage());
    }
}

htm_Header(lang('@General Ledger'));
showMenu();

echo "<div style='max-width:1200px; margin:0 auto; padding:10px;'>";

if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    htm_Alert(lang('@Journal entry and all related ledger lines have been deleted.'), 'success');
}

// 1. Hent transaktioner
$sql = "SELECT j.jou_id, j.jou_date, j.jou_text, l.acc_id, a.acc_name, l.amount 
        FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        JOIN accounts a ON l.acc_id = a.acc_id
        ORDER BY j.jou_date DESC, j.jou_id DESC, l.led_id ASC";

$res = mysqli_query($conn, $sql);

// 2. Forbered data til htm_Table
$tableData = [];
$last_jou = null;

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $is_new_jou = ($last_jou !== $row['jou_id']);
        
        // Slet-knap (kun på første linje af et bilag)
        $deleteBtn = '';
        if ($is_new_jou) {
            $deleteBtn = htm_Button(
                icon: 'fa-trash-can', 
                type: 'danger', 
                link: "ledger_view.page.php?action=delete_jou&jou_id={$row['jou_id']}", 
                styl: 'padding:2px 6px; font-size:11px;',
                attr: 'onclick="return confirm(\''.lang('@Are you sure?').'\')"',
                echo: false
            );
        }

        // Formater beløb
        $debit = ($row['amount'] > 0) ? '<span style="color:green; font-weight:500;">' . number_format($row['amount'], 2, ',', '.') . '</span>' : '';
        $credit = ($row['amount'] < 0) ? '<span style="color:red; font-weight:500;">' . number_format(abs($row['amount']), 2, ',', '.') . '</span>' : '';

        // Tilføj række til array
        $tableData[] = [
            $is_new_jou ? date('d.m.Y', strtotime($row['jou_date'])) : '<span style="color:#ccc;">»</span>',
            $is_new_jou ? "<strong>#{$row['jou_id']}</strong>" : '',
            $is_new_jou ? htmlspecialchars($row['jou_text']) : '',
            "<small style='color:#7f8c8d;'>{$row['acc_id']}</small> " . htmlspecialchars($row['acc_name']),
            "<div style='text-align:right;'>$debit</div>",
            "<div style='text-align:right;'>$credit</div>",
            "<div style='text-align:center;'>$deleteBtn</div>"
        ];
        
        $last_jou = $row['jou_id'];
    }
}

// 3. Overskrifter
$headers = ['@Date', '@Journal #', '@Description', '@Account', '@Debit (+)', '@Credit (-)', ''];

// 4. Visning i Card
htm_Card_(lang('@Transaction History'), 1200);

if (empty($tableData)) {
    htm_Alert(lang('@No transactions found'), 'info');
} else {
    // Nu med automatiske zebrastriber og søgefunktion fra biblioteket
    htm_Table($headers, $tableData, 'ledger_table', 100);
}

htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush();
?>