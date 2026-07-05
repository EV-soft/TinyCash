<?php # /reconcile_list.php v:1.1.0 d:2026-07-05 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// --- DELETE FUNCTION ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    DB::query($conn, "DELETE FROM bank_statement_temp WHERE tmp_id = $id");
    header("Location: reconcile_list.php?msg=deleted");
    exit;
}

htm_Header('@Bank Reconciliation');
showMenu();

// --- VISNING AF SENESTE BOGFØRING ---
if (isset($_GET['msg']) && $_GET['msg'] == 'success') {
    echo "
    <div style='background: #e3f2fd; border-left: 5px solid #2196f3; padding: 15px; margin-bottom: 20px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
        <h4 style='margin: 0 0 10px 0; color: #0d47a1;'><i class='fa fa-check-circle'></i> " . lang('@Transaction Processed Successfully') . "</h4>
        <p style='font-size: 13px; margin: 5px 0;'><strong>" . lang('@What happened in the database:') . "</strong></p>
        <ul style='font-size: 12px; color: #444; line-height: 1.6;'>
            <li>✅ " . lang('@A new entry was created in the') . " <strong>journal</strong> table.</li>
            <li>✅ " . lang('@Two or more balance lines were added to') . " <strong>ledger</strong> (Double-entry).</li>
            <li>✅ " . lang('@The temporary bank entry was marked as') . " <strong>processed</strong>.</li>
        </ul>
    </div>";
}

// 1. Get rules from settings
$rules_res = DB::query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
$fee_rules = [];
while($r = DB::fetch_assoc($rules_res)) {
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
$res_inv = DB::query($conn, "SELECT i.inv_id, i.invoice_no, c.cust_name, 
    (SELECT SUM(quantity * price_each * (1 + line_vat_rate/100)) FROM invoice_lines WHERE inv_id = i.inv_id) as total 
    FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_status != 'paid'");

$invoices = [];
while($row = DB::fetch_assoc($res_inv)) {                
    $invoices[] = $row;
    $inv_options[$row['inv_id']] = "#{$row['invoice_no']} - {$row['cust_name']} (" . number_format($row['total'] ?? 0, 2, ',', '.') . " kr)";
}

// Get chart of accounts
$acc_options = ['' => '-- ' . lang('@Select Account') . ' --'];
$res_acc = DB::query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id");
while($row = DB::fetch_assoc($res_acc)) { $acc_options[$row['acc_id']] = "{$row['acc_id']} - {$row['acc_name']}"; }

// Get bank entries
$res_bank = DB::query($conn, "SELECT * FROM bank_statement_temp WHERE is_processed = 0 ORDER BY trans_date DESC");

htm_Card_('@Bank Reconciliation', 1100);
?>

<table style="width:100%; border-collapse:collapse;">
    <tr style="background:#f8f9fa; text-align:left; border-bottom:2px solid #eee;">
        <th style="padding:10px; width:80px;"><?php echo lang('@Date'); ?></th>
        <th style="padding:10px;"><?php echo lang('@Description'); ?></th>
        <th style="padding:10px; text-align:right;"><?php echo lang('@Amount'); ?></th>
        <th style="padding:10px;"><?php echo lang('@Reconciliation Action'); ?></th>        
        <th style="padding:10px; width:30px;"></th>
    </tr>

    <?php while($b = DB::fetch_assoc($res_bank)): 
        $is_inc = ($b['amount'] > 0);
        $match_id = 0;
        foreach($invoices as $inv) { if(abs($inv['total'] - $b['amount']) < 0.01) $match_id = $inv['inv_id']; }
        $source = $b['import_source'] ?? 'bank';
    ?>
    <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px;"><?php echo date('d.m.y', strtotime($b['trans_date'])); ?></td>
        <td style="padding:10px;"><?php echo htmlspecialchars($b['text_val']); ?></td>
        <td style="padding:10px; text-align:right; font-weight:bold; color:<?php echo $is_inc?'green':'red';?>">
            <?php echo number_format($b['amount'], 2, ',', '.'); ?>
        </td>
        <td style="padding:10px;">
            <form action="reconcile_action.php" method="post" style="display:flex; gap:10px; align-items:stretch; margin:0;">
                <input type="hidden" name="tmp_id" value="<?php echo $b['tmp_id']; ?>">
                <input type="hidden" id="source_<?php echo $b['tmp_id']; ?>" value="<?php echo $source; ?>">
                
                <?php if($is_inc): ?>
                    <div style="display: flex; gap: 8px; background: #f9f9f9; padding: 5px; border-radius: 4px; border: 1px solid #eee; align-items: center; flex: 1;">
                        <div style="display: flex; flex-direction: column; width: 80px;">
                            <label style="font-size: 10px; cursor: pointer; color: blue; text-decoration: underline;" onclick="applyFeeRule(<?php echo $b['tmp_id']; ?>, <?php echo $b['amount']; ?>)">
                                ⚡ <?php echo lang('@Fee'); ?>
                            </label>
                            <input type="number" name="fee_amount" id="fee_input_<?php echo $b['tmp_id']; ?>" value="0.00" step="0.01" style="width: 100%; height: 28px; text-align: right;">
                        </div>
                        <div style="display: flex; flex-direction: column; width: 130px;">
                            <label style="font-size: 10px; color: #666;">&nbsp;</label>
                            <select name="fee_acc_id" style="width: 100%; height: 28px; font-size: 11px;">
                                <?php foreach($acc_options as $k => $v) echo "<option value='$k' ".($k=='2320'?'selected':'').">$v</option>"; ?>
                            </select>
                        </div>
                        <div style="display: flex; flex-direction: column; flex: 2;">
                            <label style="font-size: 10px; color: #666;">&nbsp;</label>
                            <select name="target_id" style="width: 100%; height: 28px; <?php echo $match_id ? 'border:2px solid #2ecc71;' : ''; ?>">
                                <?php foreach($inv_options as $val => $lbl) {
                                    $sel = ($val == $match_id) ? 'selected' : '';
                                    echo "<option value='$val' $sel>$lbl</option>";
                                } ?>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="flex:1;">
                        <select name="acc_id" style="width:100%; padding:6px; height: 38px;">
                            <?php foreach($acc_options as $k => $v) echo "<option value='$k'>$v</option>"; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-success" style="padding: 0 15px; height: 40px; margin: 0; font-weight: bold;">
                    <?php echo lang('@OK'); ?>
                </button>
            </form>
        </td>
        <td style="padding:10px; text-align:right;">
            <a href="reconcile_list.php?action=delete&id=<?php echo $b['tmp_id']; ?>" 
               onclick="return confirm('<?php echo lang('@Are you sure?'); ?>')" 
               style="color:#e74c3c; text-decoration:none; font-size: 18px;">🗑️</a>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

<?php 
htm_Card_end(); 
htm_Footer(); 
ob_end_flush(); 
?>