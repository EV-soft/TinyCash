<?php # /reconcile_list.page.php v.0.8 d:2026-04-11 i:evs m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// --- DELETE FUNCTION ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    mysqli_query($conn, "DELETE FROM bank_statement_temp WHERE tmp_id = $id");
    header("Location: reconcile_list.page.php?msg=deleted");
    exit;
}

htm_Header(lang('@Bank Reconciliation'));
showMenu();

// 1. Get rules from settings
$rules_res = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
$fee_rules = [];
while($r = mysqli_fetch_assoc($rules_res)) {
    $fee_rules[$r['setting_key']] = json_decode($r['setting_value'], true);
}
?>

<script>
const feeRules = <?php echo json_encode($fee_rules); ?>;
function applyFeeRule(tmpId, amount) {
    const sourceKey = document.getElementById('source_' + tmpId).value;
    const ruleKey = 'fee_rule_' + sourceKey.toLowerCase();
    const rule = feeRules[ruleKey];
    const feeField = document.getElementById('fee_input_' + tmpId);
    
    if (!rule) { 
        feeField.value = "0.00"; 
        return; 
    }

    let fee = 0;
    if (rule.model === 'fixed') {
        fee = parseFloat(rule.rate);
    } else if (rule.model === 'relative') {
        fee = (amount / (1 - parseFloat(rule.rate))) - amount;
    } else if (rule.model === 'mixed') {
        fee = ((amount + parseFloat(rule.fixed)) / (1 - parseFloat(rule.rate))) - amount;
    }
    feeField.value = fee.toFixed(2);
}
</script>

<?php
// Get unpaid invoices
$inv_options = ['' => '-- ' . lang('@Select Invoice') . ' --'];
$res_inv = mysqli_query($conn, "SELECT i.inv_id, i.invoice_no, c.cust_name, 
    (SELECT SUM(quantity * price_each * (1 + vat_rate/100)) FROM invoice_lines WHERE inv_id = i.inv_id) as total 
    FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_status != 'paid'");
$invoices = [];
while($row = mysqli_fetch_assoc($res_inv)) {
    $invoices[] = $row;
    $inv_options[$row['inv_id']] = "#{$row['invoice_no']} - {$row['cust_name']} (" . number_format($row['total'],2,',','.') . " kr)";
}

// Get chart of accounts
$acc_options = ['' => '-- ' . lang('@Select Account') . ' --'];
$res_acc = mysqli_query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id");
while($row = mysqli_fetch_assoc($res_acc)) { $acc_options[$row['acc_id']] = "{$row['acc_id']} - {$row['acc_name']}"; }

// Get bank entries
$res_bank = mysqli_query($conn, "SELECT * FROM bank_statement_temp WHERE is_processed = 0 ORDER BY trans_date DESC");

htm_Card_(lang('@Bank Reconciliation'), 1100);
?>

<table style="width:100%; border-collapse:collapse;">
    <tr style="background:#f8f9fa; text-align:left; border-bottom:2px solid #eee;">
        <th style="padding:10px; width:80px;"><?php echo lang('@Date'); ?></th>
        <th style="padding:10px;"><?php echo lang('@Description'); ?></th>
        <th style="padding:10px; text-align:right;"><?php echo lang('@Amount'); ?></th>
        <th style="padding:10px; vertical-align: middle; white-space: nowrap;">
            <div style="display: inline-flex; align-items: center; gap: 15px; width: auto;">
                <span><?php echo lang('@Fee and Invoice Mapping'); ?></span>
                <button type="button" onclick="document.getElementById('helpModal').style.display='block'" 
                        style="background: #3498db; color: white; border: none; padding: 5px 12px; border-radius: 20px; cursor: pointer; font-size: 11px; font-weight: bold; display: flex; align-items: center; gap: 8px;">
                    <span style="background: white; color: #3498db; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px;">?</span>
                    <?php echo lang('@Help'); ?>
                </button>
            </div>
        </th>        
        <th style="padding:10px; width:30px;"></th>
    </tr>

    <?php while($b = mysqli_fetch_assoc($res_bank)): 
        $is_inc = ($b['amount'] > 0);
        $match_id = 0;
        foreach($invoices as $inv) { if(abs($inv['total'] - $b['amount']) < 0.01) $match_id = $inv['inv_id']; }
        $source = $b['import_source'] ?? 'bank';
    ?>
    <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px;"><?php echo date('d.m.y', strtotime($b['trans_date'])); ?></td>
        <td style="padding:10px;" data-hint="<?php echo lang('@Bank transaction text'); ?>">
            <?php echo htmlspecialchars($b['text_val']); ?>
        </td>
        <td style="padding:10px; text-align:right; font-weight:bold; color:<?php echo $is_inc?'green':'red';?>" 
            data-hint="<?php echo $is_inc ? lang('@Deposit to account') : lang('@Withdrawal from account'); ?>">
            <?php echo number_format($b['amount'], 2, ',', '.'); ?>
        </td>
        <td style="padding:10px;">
            <form action="reconcile_action.php" method="post" style="display:flex; gap:10px; align-items:flex-end; margin:0;">
                <input type="hidden" name="tmp_id" value="<?php echo $b['tmp_id']; ?>">
                <input type="hidden" id="source_<?php echo $b['tmp_id']; ?>" value="<?php echo $source; ?>">
                
                <?php if($is_inc): ?>
                    <div style="display: flex; gap: 8px; background: #f9f9f9; padding: 5px; border-radius: 4px; border: 1px solid #eee; align-items: center;">
                        <div style="display: flex; flex-direction: column; width: 90px;">
                            <label style="font-size: 10px; cursor: pointer; color: blue; text-decoration: underline; margin-bottom: 2px;" 
                                   data-hint="<?php echo lang('@Click to calculate fee automatically'); ?>"
                                   onclick="applyFeeRule(<?php echo $b['tmp_id']; ?>, <?php echo $b['amount']; ?>)">
                                ⚡ <?php echo lang('@Fee'); ?>
                            </label>
                            <input type="number" name="fee_amount" id="fee_input_<?php echo $b['tmp_id']; ?>" 
                                   value="0.00" step="0.01" 
                                   style="width: 100%; height: 30px; text-align: right; border: 1px solid #ccc; border-radius: 3px;">
                        </div>
                        <div style="display: flex; flex-direction: column; width: 140px;">
                            <label style="font-size: 10px; color: #666; margin-bottom: 2px;">&nbsp;</label>
                            <select name="fee_acc_id" style="width: 100%; height: 30px; font-size: 11px;">
                                <?php foreach($acc_options as $k => $v) echo "<option value='$k' ".($k=='2320'?'selected':'').">$v</option>"; ?>
                            </select>
                        </div>
                        <div style="display: flex; flex-direction: column; flex: 1;">
                            <label style="font-size: 10px; color: #666; margin-bottom: 2px;">&nbsp;</label>
                            <select name="target_id" onchange="this.form.submit()" 
                               style="width: 100%; height: 30px; <?php echo $match_id ? 'border:2px solid #2ecc71; background:#fafffa;' : ''; ?>">
                                <?php foreach($inv_options as $val => $lbl) {
                                    $sel = ($val == $match_id) ? 'selected' : '';
                                    echo "<option value='$val' $sel>$lbl</option>";
                                } ?>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="flex:1;">
                        <select name="acc_id" onchange="this.form.submit()" style="width:100%; padding:8px;">
                            <?php foreach($acc_options as $k => $v) echo "<option value='$k'>$v</option>"; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </form>
        </td>
        <td style="padding:10px; text-align:right;">
            <a href="reconcile_list.page.php?action=delete&id=<?php echo $b['tmp_id']; ?>" 
               data-hint="<?php echo lang('@Remove entry permanently'); ?>"
               onclick="return confirm('<?php echo lang('@Are you sure you want to remove this entry?'); ?>')" 
               style="color:#e74c3c; text-decoration:none; border:1px solid #ffcdd2; padding:5px 10px; border-radius:4px; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">
                <span style="font-size: 16px;">🗑️</span>
                <span style="font-size: 11px; font-weight: bold; text-transform: uppercase;"><?php echo lang('@Remove'); ?></span>
            </a>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

<?php 
htm_Card_end(); 

echo '
<div id="helpModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); overflow:auto;">
    <div style="background:white; margin:5% auto; padding:30px; border-radius:8px; width:75%; max-width:850px; position:relative;">
        <span onclick="document.getElementById(\'helpModal\').style.display=\'none\'" 
              style="float:right; font-size:28px; font-weight:bold; cursor:pointer; color:#aaa; position:absolute; right:20px; top:10px;">&times;</span>
        <h2>📘 ' . lang('@Guide: Bank Reconciliation & Fees') . '</h2>
        <p>' . lang('@Here you can manage your entries and calculate fees using the lightning button.') . ' ⚡.</p>
        <button onclick="document.getElementById(\'helpModal\').style.display=\'none\'" 
                style="margin-top:25px; background:#2c3e50; color:white; border:none; padding:12px 25px; border-radius:4px; cursor:pointer; width:100%;">
            ' . lang('@Close Guide') . '
        </button>
    </div>
</div>
<script>
window.onclick = function(event) {
    let modal = document.getElementById("helpModal");
    if (event.target == modal) modal.style.display = "none";
}
</script>';

htm_Footer(); 
ob_end_flush(); 
?>