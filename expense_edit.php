<?php # /expense_edit.php v:0.9.0 d:2026-05-08 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$exp_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$err = "";
$upload_dir = 'uploads/expenses/';

// 1. HÅNDTER GEM (Både NY og REDIGER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date     = mysqli_real_escape_string($conn, $_POST['exp_date']);
    $supp     = mysqli_real_escape_string($conn, $_POST['supplier']);
    $acc_id   = (int)$_POST['account_id'];
    $desc     = mysqli_real_escape_string($conn, $_POST['description']);
    $amount   = (float)str_replace(',', '.', $_POST['amount']);
    $vat_rate = (float)str_replace(',', '.', $_POST['vat_rate']);
    
    $file_sql = "";

    // Håndter Bilag (Upload)
    if (!empty($_FILES['attachment']['name'])) {
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $file_name = time() . '_' . uniqid() . '.' . $file_ext;
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
            $file_sql = ", attachment = '$file_name'";
        } else {
            $err = lang("@Error: Could not save attachment.");
        }
    }

    if (!$err) {
        if ($exp_id > 0) {
            $sql = "UPDATE expenses SET 
                    exp_date = '$date', supplier = '$supp', account_id = $acc_id, 
                    amount = $amount, vat_rate = $vat_rate, description = '$desc' $file_sql
                    WHERE exp_id = $exp_id";
        } else {
            // Husk at tilføje kolonnen 'attachment' i din DB hvis den mangler
            $sql = "INSERT INTO expenses (exp_date, supplier, account_id, amount, vat_rate, description, attachment) 
                    VALUES ('$date', '$supp', $acc_id, $amount, $vat_rate, '$desc', '".str_replace(", attachment = '", "", rtrim($file_sql, "'"))."')";
        }

        if (mysqli_query($conn, $sql)) {
            header("Location: expense_list.php?msg=success");
            exit;
        } else {
            $err = lang('@SQL Error:') . " " . mysqli_error($conn);
        }
    }
}

// 2. FORBERED DATA
if ($exp_id > 0) {
    $res = mysqli_query($conn, "SELECT * FROM expenses WHERE exp_id = $exp_id");
    $exp = mysqli_fetch_assoc($res);
    if (!$exp) die(lang('@Expense not found'));
} else {
    $exp = [
        'exp_id' => 0,
        'exp_date' => date('Y-m-d'),
        'supplier' => '',
        'account_id' => '',
        'amount' => 0,
        'vat_rate' => 25.00,
        'description' => '',
        'attachment' => ''
    ];
}

$acc_res = mysqli_query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id ASC");
$accounts = [];
while($a = mysqli_fetch_assoc($acc_res)) {
    $accounts[$a['acc_id']] = $a['acc_id'] . " - " . lang($a['acc_name']);
}

// 3. RENDER
$pageTitle = ($exp_id > 0) ? '@Edit Expense' : '@New Expense';
htm_Header(capt: $pageTitle); 
showMenu();

if ($err) htm_Alert(text: $err, type: 'error');

// Tilføjet 'enctype' via form-attributten i htm_Card_
htm_Card_(capt: $pageTitle, wdth: 600, form: 'expense_form" enctype="multipart/form-data');
    
    echo "<div style='display:flex; gap:15px;'>";
        htm_InputGroup(icon: 'fa-fingerprint', labl: 'ID', name: 'exp_id', valu: $exp['exp_id'], extr: 'readonly align-left', wdth: '50%');
        htm_InputGroup(icon: 'fa-calendar', labl: '@Date', name: 'exp_date', valu: $exp['exp_date'], type: 'date', extr: 'align-left', wdth: '50%');
    echo "</div>";

    htm_InputGroup(icon: 'fa-truck', labl: '@Supplier', name: 'supplier', valu: $exp['supplier'], extr: 'required align-left', hint: '@Vendor name', wdth: '100%');

    echo "<div style='display:flex; gap:15px; margin-top:10px;'>";
        htm_InputGroup(icon: 'fa-coins', labl: '@Total Amount', name: 'amount', valu: number_format((float)$exp['amount'], 2, ',', ''), type: 'text', extr: 'required align-left', wdth: '30%');
        htm_InputGroup(icon: 'fa-percentage', labl: '@VAT', name: 'vat_rate', valu: number_format((float)$exp['vat_rate'], 2, ',', ''), type: 'text', extr: 'align-left', wdth: '20%');
        htm_InputGroup(icon: 'fa-list-ol', labl: '@Account', name: 'account_id', valu: $exp['account_id'], type: 'sele', opti: $accounts, extr: 'align-left', wdth: '60%');
    echo "</div>";

    htm_InputGroup(icon: 'fa-align-left', labl: '@Description', name: 'description', valu: $exp['description'], type: 'textarea', extr: 'align-left', wdth: '100%');

    // SEKTION FOR BILAG
    echo "<div style='margin-top:15px; padding:15px; background:#f9f9f9; border:1px dashed #ccc; border-radius:8px;'>";
        echo "<label style='display:block; margin-bottom:5px; font-weight:bold; color:#666;'><i class='fa fa-paperclip'></i> ".lang('@Attachment')."</label>";
        if (!empty($exp['attachment'])) {
            echo "<div style='margin-bottom:10px; font-size:0.85em;'>";
            echo "<i class='fa fa-file-pdf'></i> <a href='".$upload_dir.$exp['attachment']."' target='_blank'>".$exp['attachment']."</a>";
            echo "</div>";
        }
        echo lang('@Add').": <input type='file' name='attachment' style='font-size:0.9em;'>";
    echo "</div>";

    echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
        htm_Button(icon: 'fa-save', labl: ($exp_id > 0 ? '@Update' : '@Create'), type: 'success', styl: 'flex:2;');
        htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'expense_list.php', styl: 'flex:1;');
    echo "</div>";

htm_Card_end();
htm_Footer();
ob_end_flush();
?>