<?php # /expense_create.page.php v:0.8.1 d:2026-04-10 i:Gemini m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// 1. Hent standard moms fra indstillinger
$res_set = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'vat_rate'");
$set_row = mysqli_fetch_assoc($res_set);
$default_vat = $set_row['setting_value'] ?? 25;
$msg = ""; $err = "";

// 2. Håndter gemning af udgift
if (isset($_POST['save_expense'])) {
    $date      = $_POST['exp_date'];
    $supplier  = mysqli_real_escape_string($conn, $_POST['supplier']);
    $account   = (int)$_POST['account_id'];
    $amount    = (float)str_replace(',', '.', $_POST['amount']);
    $vat_rate  = (float)$_POST['vat_rate'];
    $descr     = mysqli_real_escape_string($conn, $_POST['description']);
    $attachment_to_save = ""; 
    
    // --- ATTACHMENT UPLOAD LOGIK ---
    if (!empty($_FILES['receipt']['name'])) {
        $target_dir = "uploads/"; 
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        $file_name = "exp_" . date('Ymd_His') . "_" . uniqid() . "." . $ext;
        $target_file = $target_dir . $file_name;
        
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target_file)) {
            $attachment_to_save = $file_name; 
        }
    }
    $sql = "INSERT INTO expenses (exp_date, supplier, account_id, amount, vat_rate, description, attachment_file) 
            VALUES ('$date', '$supplier', '$account', '$amount', '$vat_rate', '$descr', '$attachment_to_save')";
        
    if (mysqli_query($conn, $sql)) {
        $msg = lang('@Expense registered successfully!');
    } else {
        $err = lang('@SQL Error:') . ' ' . mysqli_error($conn);
    }
}

htm_Header(lang('@Register Purchase'));
showMenu();

// 3. Forbered Data til Dropdowns
$acc_opt = ['' => '-- ' . lang('@Select Account') . ' --'];
$res = mysqli_query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $acc_opt[$row['acc_id']] = $row['acc_id'] . " - " . $row['acc_name'];
}

$vat_opt = [
    $default_vat => $default_vat . '%',
    '0' => '0%'
];

echo "<div style='max-width:600px; margin:0 auto;'>";
    htm_Card_(lang('@Register Purchase'), '500');
    htm_Alert($msg, 'success');
    htm_Alert($err, 'error');
?>
<form method="post" action="" enctype="multipart/form-data">
    <div style="display: grid; gap: 5px;">
        <?php 
            htm_InputGroup('fa-calendar', lang('@Date'), 'exp_date', date('Y-m-d'), 'date', null, 'required'); 
            htm_InputGroup('fa-industry', lang('@Supplier'), 'supplier', '', 'text', null, 'required placeholder="'.lang('@e.g. Stark').'"'); 
            htm_InputGroup('fa-list-ol', lang('@Account'), 'account_id', '', 'select', $acc_opt, 'required'); 
            htm_InputGroup('fa-info-circle', lang('@Description'), 'description', '', 'text', null, 'placeholder="'.lang('@What did you buy?').'"'); 
        ?>
        <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 15px;">
            <?php 
                htm_InputGroup('fa-money-bill-wave', lang('@Amount incl. VAT'), 'amount', '', 'text', null, 'required placeholder="0,00"'); 
                htm_InputGroup('fa-percent', lang('@VAT %'), 'vat_rate', $default_vat, 'select', $vat_opt); 
            ?>
        </div>
        <?php 
            htm_InputGroup('fa-paperclip', lang('@Attachment') . ' (PDF/JPG)', 'receipt', '', 'file', null, 'accept=".pdf,.jpg,.jpeg,.png"'); 
        ?>
        <button type="submit" name="save_expense" class="btn-success" style="padding:15px; width:100%; font-size:1.1em; margin-top:20px;">
            💾 <?php echo lang('@Save Expense'); ?>
        </button>
    </div>
</form>
<?php
    htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush();