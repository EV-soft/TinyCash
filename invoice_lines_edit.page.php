<?php # /invoice_lines_edit.page.php v:0.8.7 d:2026-04-11
ob_start(); 
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- GEM LOGIK ---
if (isset($_POST['save_lines'])) {
    if (isset($_POST['line_id']) && is_array($_POST['line_id'])) {
        foreach ($_POST['line_id'] as $key => $id) {
            $l_id   = (int)$id;
            $p_id   = (int)($_POST['prod_id'][$key] ?? 0);
            $acc_id = (int)($_POST['acc_id'][$key] ?? 1000); 
            $qty    = (float)($_POST['qty'][$key] ?? 0);
            $price  = (float)($_POST['price'][$key] ?? 0);

            // NY SIKKERHEDSLOGIK: Hent aktuel momssats fra kontoplanen før gem
            $vat_lookup = mysqli_query($conn, "
                SELECT v.vat_rate 
                FROM accounts a
                INNER JOIN vat_codes v ON a.vat_code = v.vat_id
                WHERE a.acc_id = $acc_id
            ");
            $vat_data = mysqli_fetch_assoc($vat_lookup);
            $current_vat_rate = (float)($vat_data['vat_rate'] ?? 25.00); // Fallback til 25 hvis konto fejler

            // Opdater nu linjen inklusiv den fastlåste momssats
            $update_sql = "UPDATE invoice_lines SET 
                            prod_id = $p_id, 
                            acc_id = $acc_id,
                            quantity = $qty, 
                            price_each = $price,
                            line_vat_rate = $current_vat_rate
                            WHERE line_id = $l_id AND inv_id = $inv_id";
            
            mysqli_query($conn, $update_sql);
        }
    }
    header("Location: invoice_view.page.php?id=$inv_id&msg=lines_updated");
    exit;
}

// --- DATA HENTNING ---
$inv = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM invoices WHERE inv_id = $inv_id"));
$lines_res = mysqli_query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC");
$products_res = mysqli_query($conn, "SELECT prod_id, prod_name FROM products ORDER BY prod_name ASC");

// Hent alle konti inkl. deres momssatser til dropdown
$accounts = [];
// Vi joiner vat_codes her for at vise den rigtige sats i dropdown-menuen
$acc_res = mysqli_query($conn, "
    SELECT a.acc_id, a.acc_name, v.vat_rate 
    FROM accounts a
    LEFT JOIN vat_codes v ON a.vat_code = v.vat_id
    ORDER BY a.acc_id ASC
");
while($a = mysqli_fetch_assoc($acc_res)) { 
    $accounts[$a['acc_id']] = $a; 
}

htm_Header(lang('@Edit Invoice Lines'));
showMenu();
?>

<div style="max-width: 1100px; margin: 0 auto;">
    <div style="margin-bottom: 15px;">
        <h2 style="margin: 0; color: #2c3e50;">🧾 <?php echo lang('@Invoice') . " #" . ($inv['invoice_no'] ?? 'N/A'); ?></h2>
    </div>

    <?php htm_Card_(lang('@Invoice Lines')); ?>

    <form action="" method="post">
        <input type="hidden" name="inv_id" value="<?php echo $inv_id; ?>">
        
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; background: #f8f9fa; color: #7f8c8d; font-size: 0.8em; text-transform: uppercase;">
                    <th style="padding: 10px; border-bottom: 2px solid #eee; width: 35%;"><?php echo lang('@Product'); ?></th>
                    <th style="padding: 10px; border-bottom: 2px solid #eee; width: 20%; text-align: center;"><?php echo lang('@VAT / Account'); ?></th>
                    <th style="padding: 10px; border-bottom: 2px solid #eee; width: 10%; text-align: center;"><?php echo lang('@Qty'); ?></th>
                    <th style="padding: 10px; border-bottom: 2px solid #eee; width: 15%; text-align: right;"><?php echo lang('@Price'); ?></th>
                    <th style="padding: 10px; border-bottom: 2px solid #eee; width: 15%; text-align: right;"><?php echo lang('@Total'); ?></th>
                    <th style="padding: 10px; border-bottom: 2px solid #eee; width: 5%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                while ($line = mysqli_fetch_assoc($lines_res)): 
                    $line_total = $line['quantity'] * $line['price_each'];
                    $cur_acc = $accounts[$line['acc_id']] ?? ['vat_rate' => 0, 'acc_name' => 'Unknown'];
                ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <input type="hidden" name="line_id[]" value="<?php echo $line['line_id']; ?>">

                    <td style="padding: 8px;">
                        <select name="prod_id[]" style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                            <?php 
                            mysqli_data_seek($products_res, 0);
                            while ($p = mysqli_fetch_assoc($products_res)): 
                                $sel = ($p['prod_id'] == $line['prod_id']) ? 'selected' : '';
                                echo "<option value='{$p['prod_id']}' $sel>{$p['prod_name']}</option>";
                            endwhile; 
                            ?>
                        </select>
                    </td>

                    <td style="padding: 8px; text-align: center;">
                        <select name="acc_id[]" style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px; text-align: center; font-weight: bold; background: #fffcf0;">
                            <?php foreach ($accounts as $acc): 
                                $sel = ($acc['acc_id'] == $line['acc_id']) ? 'selected' : '';
                                $vat_val = (int)$acc['vat_rate'];
                                echo "<option value='{$acc['acc_id']}' $sel>{$vat_val}% (Konto {$acc['acc_id']})</option>";
                            endforeach; ?>
                        </select>
                        <div style="font-size: 0.7em; color: #95a5a6; margin-top: 2px;">
                            <?php echo htmlspecialchars($cur_acc['acc_name']); ?>
                        </div>
                    </td>

                    <td style="padding: 8px;">
                        <input type="number" name="qty[]" step="0.01" value="<?php echo $line['quantity']; ?>" style="width: 100%; text-align: center; border: 1px solid #ddd; padding: 5px; border-radius: 4px;">
                    </td>

                    <td style="padding: 8px;">
                        <input type="text" name="price[]" value="<?php echo number_format($line['price_each'], 2, '.', ''); ?>" style="width: 100%; text-align: right; border: 1px solid #ddd; padding: 5px; border-radius: 4px;">
                    </td>

                    <td style="padding: 8px; text-align: right; font-weight: bold; color: #2c3e50;">
                        <?php echo number_format($line_total, 2, ',', '.'); ?>
                    </td>

                    <td style="padding: 8px; text-align: center;">
                        <button type="button" style="color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 1.2em;">&times;</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; background: #f9f9f9; padding: 20px; border-radius: 8px;">
            <a href="invoice_view.page.php?id=<?php echo $inv_id; ?>" style="color: #7f8c8d; text-decoration: none; font-weight: bold;">
                <i class="fa fa-arrow-left"></i> <?php echo lang('@Back'); ?>
            </a>
            <button type="submit" name="save_lines" style="background: #2ecc71; color: white; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                <i class="fa fa-save"></i> <?php echo lang('@Save All Changes'); ?>
            </button>
        </div>
    </form>
    <?php htm_Card_end(); ?>
</div>

<?php
htm_Footer();
ob_end_flush(); 
?>