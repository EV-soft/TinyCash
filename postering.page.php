<?php # postering.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// 1. Logik: Håndter gemning af køb/udgift
if (isset($_POST['save_expense'])) {
    $date    = mysqli_real_escape_string($conn, $_POST['trans_date']);
    $desc    = mysqli_real_escape_string($conn, $_POST['trans_desc']);
    $amount  = (float)str_replace(',', '.', $_POST['trans_amount']);
    $acc_id  = (int)$_POST['acc_id'];
    
    // Her ville din SQL INSERT typisk ligge
    // $sql = "INSERT INTO transactions ...";
    
    header("Location: dashboard.page.php?msg=success");
    exit;
}

// Hent udgiftskonti (f.eks. 2000-serien) til dropdown
$accounts_res = mysqli_query($conn, "SELECT acc_id, acc_name FROM accounts WHERE acc_id >= 2000 ORDER BY acc_id ASC");

htm_Header(lang('@Register purchase'));
showMenu();

htm_Card_(lang('@Expense details'));
?>

<form action="" method="post" style="font-family: sans-serif; max-width: 600px;">
    
    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Date'); ?>:</label>
        <input type="date" name="trans_date" value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Description'); ?>:</label>
        <input type="text" name="trans_desc" placeholder="<?php echo lang('@e.g. Office supplies'); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px; display: flex; gap: 10px;">
        <div style="flex: 1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Amount including VAT'); ?>:</label>
            <input type="text" name="trans_amount" placeholder="0,00" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div style="flex: 1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Expense account'); ?>:</label>
            <select name="acc_id" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                <option value=""><?php echo lang('@Select account'); ?></option>
                <?php while($acc = mysqli_fetch_assoc($accounts_res)): ?>
                    <option value="<?php echo $acc['acc_id']; ?>">
                        <?php echo $acc['acc_id'] . " - " . htmlspecialchars($acc['acc_name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
    </div>

    <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px dashed #ccc; border-radius: 4px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Attach receipt'); ?> (PDF/JPG):</label>
        <input type="file" name="receipt_file">
    </div>

    <div style="display: flex; gap: 10px;">
        <button type="submit" name="save_expense" style="background:#e67e22; color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">
            📥 <?php echo lang('@Save expense'); ?>
        </button>
        <a href="dashboard.page.php" style="background:#95a5a6; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>