<?php # /settings_fees.page.php v.0.8.1 d:2026-04-11 i:Gemini m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// --- GEM FUNKTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_rule'])) {
    $source = strtolower(mysqli_real_escape_string($conn, $_POST['source']));
    $key    = 'fee_rule_' . $source;
    
    $data = [
        'model' => $_POST['model'],
        'rate'  => (float)$_POST['rate'],
        'fixed' => (float)$_POST['fixed']
    ];
    $value = json_encode($data);
    
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) 
                        VALUES ('$key', '$value') 
                        ON DUPLICATE KEY UPDATE setting_value = '$value'");
    $msg = lang('@Rule saved successfully');
}

// --- SLET FUNKTION ---
if (isset($_GET['delete'])) {
    $key = mysqli_real_escape_string($conn, $_GET['delete']);
    mysqli_query($conn, "DELETE FROM settings WHERE setting_key = '$key'");
    header("Location: settings_fees.page.php?msg=deleted");
    exit;
}

htm_Header(lang('@Fee Rules'));
showMenu();

if (isset($msg)) echo "<div style='background:#d4edda; color:#155724; padding:10px; margin-bottom:20px; border-radius:4px;'>$msg</div>";

htm_Card_(lang('@Configure Fee Rules'), 800);
?>

<form method="post">
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; align-items: start; margin-bottom: 20px;">
        
        <?php 
        // Kolonne 1: Source
        echo htm_InputGroup('fa-tag', lang('@Source'), 'source', '', 'text', null, 'required placeholder="stripe" data-hint="'.lang('@Enter the source name, e.g., stripe, mobilepay or bank.').'"'); 

        // Kolonne 2: Model
        $models = [
            'fixed'    => lang('@Fixed amount'), 
            'relative' => lang('@Percentage'), 
            'mixed'    => lang('@Mixed')
        ];
        echo htm_InputGroup('fa-calculator', lang('@Model'), 'model', 'fixed', 'select', $models, 'data-hint="'.lang('@Choose how the fee is calculated: Fixed price, percentage, or a combination.').'"'); 
        ?>

        <fieldset class="field-group" data-hint="<?php echo lang('@Enter either a percentage (0.014 for 1.4%) or a fixed amount.'); ?>">
            <legend><?php echo lang('@Rate / Fixed'); ?></legend>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" name="rate" step="0.0001" placeholder="0.014" style="width:45% !important; border:none !important;" data-hint="<?php echo lang('@Percentage as decimal (e.g., 0.014 for 1.4%).'); ?>">
                <span style="color:#ccc;">|</span>
                <input type="number" name="fixed" step="0.01" placeholder="<?php echo lang('@amt'); ?>" style="width:45% !important; border:none !important;" data-hint="<?php echo lang('@Fixed fee per transaction.'); ?>">
            </div>
        </fieldset>

    </div>

    <button type="submit" name="save_rule" class="btn-success" style="width: auto; padding: 10px 40px;" data-hint="<?php echo lang('@Save the new rule or update an existing one with the same source name.'); ?>">
        💾 <?php echo lang('@Save Rule'); ?>
    </button>
</form>

<hr>

<fieldset class="field-group" style="padding: 15px;">
    <legend><?php echo lang('@Active Rules'); ?></legend>
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="text-align:left; border-bottom:2px solid #eee; font-size: 12px; color: #7f8c8d;">
                <th style="padding:10px;"><?php echo lang('@Source'); ?></th>
                <th style="padding:10px;"><?php echo lang('@Model'); ?></th>
                <th style="padding:10px;"><?php echo lang('@Details'); ?></th>
                <th style="padding:10px; width:120px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $res = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
            // Hent valuta fra indstillinger (samme logik som report_income)
            $curr_res = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1");
            $row_curr = mysqli_fetch_assoc($curr_res);
            $currency = $row_curr['setting_value'] ?? 'DKK';

            while ($r = mysqli_fetch_assoc($res)) {
                $source = str_replace('fee_rule_', '', $r['setting_key']);
                $val = json_decode($r['setting_value'], true);
                
                $rate_pct = ($val['rate'] * 100) . "%";
                $fixed_amt = number_format($val['fixed'], 2, ',', '.') . " " . $currency;
                
                echo "<tr style='border-bottom:1px solid #eee;'>
                        <td style='padding:10px; font-weight:bold;'>" . ucfirst($source) . "</td>
                        <td style='padding:10px; font-size:13px;'>".lang('@'.$val['model'])."</td>
                        <td style='padding:10px; font-size:13px; color:#555;'>".lang('@Rate').": $rate_pct | ".lang('@Fixed').": $fixed_amt</td>
                        <td style='padding:10px; text-align:right;'>
                            <a href='?delete={$r['setting_key']}' 
                               onclick='return confirm(\"".lang('@Are you sure?')."\")' 
                               data-hint='".lang('@Permanently delete this fee rule.')."'
                               style='color:#e74c3c; text-decoration:none; border:1px solid #ffcdd2; padding:5px 8px; border-radius:4px; display: inline-flex; align-items: center; gap: 5px; font-size:11px; font-weight:bold; text-transform:uppercase;'>
                               <span>🗑️</span> ".lang('@Remove')."
                            </a>
                        </td>
                      </tr>";
            }
            ?>
        </tbody>
    </table>
</fieldset>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush();
?>