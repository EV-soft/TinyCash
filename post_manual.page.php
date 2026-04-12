<?php # /post_manual.page.php v:0.8 d:2026-04-11 i:evs m:1
ob_start(); 
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';

// 1. Logik: Håndter gemning
if (isset($_POST['save_expense'])) {
    $date    = mysqli_real_escape_string($conn, $_POST['trans_date']);
    $desc    = mysqli_real_escape_string($conn, $_POST['trans_desc']);
    $amount  = (float)str_replace(',', '.', $_POST['trans_amount']);
    $acc_id  = (int)$_POST['acc_id'];
    
    // Her ville din SQL INSERT typisk ligge
    // ...
    
    header("Location: dashboard.page.php?msg=success");
    exit;
}

// 2. Forbered Kontoliste til Dropdown
$acc_opt = ['' => '-- ' . lang('@Select account') . ' --'];
$accounts_res = mysqli_query($conn, "SELECT acc_id, acc_name FROM accounts WHERE acc_id >= 2000 ORDER BY acc_id ASC");
while($acc = mysqli_fetch_assoc($accounts_res)) {
    $acc_opt[$acc['acc_id']] = $acc['acc_id'] . " - " . $acc['acc_name'];
}

htm_Header(lang('@New Manual Posting'));
showMenu();

echo "<div style='max-width:700px; margin:0 auto;'>";
    htm_Card_(lang('@Expense details'), '500');
?>
<form action="" method="post" enctype="multipart/form-data">
    <div style="display: grid; gap: 10px;">
        <div style="display: flex; gap: 15px;">
            <div style="flex: 1;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-calendar', 
                    label: '@Date:', 
                    name:  'trans_date', 
                    val:   date('Y-m-d'), 
                    type:  'date', 
                    extra: 'required'
                ); 
                ?>
            </div>
            <div style="flex: 2;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-pencil-alt', 
                    label: '@Description:', 
                    name:  'trans_desc', 
                    type:  'text', 
                    extra: 'required placeholder="'.lang('@e.g. Office supplies').'"'
                ); 
                ?>
            </div>
        </div>
        <div style="display: flex; gap: 15px;">
            <div style="flex: 1;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-money-bill-wave', 
                    label: '@Amount including VAT:', 
                    name:  'trans_amount', 
                    type:  'text', 
                    extra: 'placeholder="0,00" required style="font-weight:bold; color:#2c3e50;"'
                ); 
                ?>
            </div>
            <div style="flex: 1;">
                <?php 
                htm_InputGroup(
                    icon:  'fa-book', 
                    label: '@Expense account:', 
                    name:  'acc_id', 
                    type:  'select', 
                    opt:   $acc_opt, 
                    extra: 'required'
                ); 
                ?>
            </div>
        </div>
        <?php 
            htm_InputGroup(
                icon:  'fa-paperclip', 
                label: '@Attach receipt (PDF/JPG):', 
                name:  'receipt_file', 
                type:  'file', 
                extra: 'accept=".pdf,.jpg,.jpeg,.png"'
            ); 
        ?>
        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <button type="submit" name="save_expense" class="btn-primary" style="padding:15px; flex: 2; font-size: 1.1em; cursor:pointer;">
                <i class="fa fa-download"></i> <?php echo lang('@Save expense'); ?>
            </button>
            <a href="dashboard.page.php" class="btn-secondary" style="padding:15px; text-decoration:none; text-align:center; flex: 1; font-weight: bold; line-height: 20px;">
                <?php echo lang('@Cancel'); ?>
            </a>
        </div>
    </div>
</form>
<?php
    htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush(); 
?>