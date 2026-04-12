<?php # account_edit.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$acc_id = intval($_GET['id'] ?? 0);
$msg = "";

// 1. Håndter Opdatering
if (isset($_POST['update_account']) && $acc_id > 0) {
    $new_id   = intval($_POST['new_acc_id']);
    $name     = mysqli_real_escape_string($conn, $_POST['acc_name']);
    $vat_code = mysqli_real_escape_string($conn, $_POST['vat_code']);

    $sql = "UPDATE accounts SET 
            acc_id = $new_id, 
            acc_name = '$name', 
            vat_code = " . ($vat_code == '' ? "NULL" : "'$vat_code'") . " 
            WHERE acc_id = $acc_id";
    if (mysqli_query($conn, $sql)) {
        header("Location: chart_of_accounts.page.php?msg=updated");
        exit;
    } else {
        $msg = '<div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:4px; margin-bottom:20px;">❌ ' . lang('@Error updating account') . ': ' . mysqli_error($conn) . '</div>';
    }
}

// 2. Hent eksisterende data
$res = mysqli_query($conn, "SELECT * FROM accounts WHERE acc_id = $acc_id");
$acc = mysqli_fetch_assoc($res);
if (!$acc) {
    die(lang('@Account not found'));
}

// 3. Hent momskoder
$vat_opts = ['' => lang('@No VAT')];
$v_res = mysqli_query($conn, "SELECT vat_id, vat_name, vat_rate FROM vat_codes ORDER BY vat_id ASC");
while ($v_row = mysqli_fetch_assoc($v_res)) {
    $vat_opts[$v_row['vat_id']] = $v_row['vat_id'] . " (" . $v_row['vat_name'] . " " . number_format($v_row['vat_rate'], 0) . "%)";
}

htm_Header(lang('@Edit Account'));
showMenu();

echo "<div style='max-width:500px; margin:0 auto;'>";
    htm_Card_(lang('@Edit Account') . ": " . htmlspecialchars($acc['acc_name']), '100%');
    echo $msg;
?>

<form method="post" action="">
    <div style="display: grid; gap: 15px;">
        <div>
            <label style="font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@Account Number'); ?>:</label>
            <input type="number" name="new_acc_id" value="<?php echo (int)$acc['acc_id']; ?>" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div>
            <label style="font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@Account Name'); ?>:</label>
            <input type="text" name="acc_name" value="<?php echo htmlspecialchars($acc['acc_name']); ?>" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div>
            <label style="font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@VAT Code'); ?>:</label>
            <select name="vat_code" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <?php foreach ($vat_opts as $code => $label): ?>
                    <option value="<?php echo htmlspecialchars($code); ?>" <?php if($acc['vat_code'] == $code) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 10px;">
            <button type="submit" name="update_account" style="background:#3498db; color:white; padding:12px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; flex: 2;">
                💾 <?php echo lang('@Update Account'); ?>
            </button>
            <a href="chart_of_accounts.page.php" style="background:#95a5a6; color:white; padding:12px; text-decoration:none; border-radius:4px; text-align:center; flex: 1; font-weight: bold;">
                <?php echo lang('@Back'); ?>
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