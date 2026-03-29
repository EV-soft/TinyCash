<?php # faktura_linjer_rediger.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

$invoice_id = (int)$_GET['id'];

// 1. Logik: Opdater linjer
if (isset($_POST['save_lines'])) {
    // Her ville din logik til at slette gamle linjer og indsætte nye (eller update) ligge
    // mysqli_query($conn, "DELETE FROM invoice_lines WHERE invoice_id = $invoice_id");
    // ... loop $_POST['line'] ...
    
    header("Location: faktura_vis.page.php?id=$invoice_id&msg=lines_updated");
    exit;
}

// 2. Data: Hent faktura-info og de eksisterende linjer
$inv_res = mysqli_query($conn, "SELECT * FROM invoices WHERE invoice_id = $invoice_id");
$inv = mysqli_fetch_assoc($inv_res);

$lines_res = mysqli_query($conn, "SELECT * FROM invoice_lines WHERE invoice_id = $invoice_id ORDER BY line_id ASC");
$products_res = mysqli_query($conn, "SELECT prod_id, prod_name, prod_price FROM products ORDER BY prod_name ASC");

htm_Header(lang('@Edit Invoice Lines'));
showMenu();

htm_Card_(lang('@Editing Lines for Invoice') . " #" . $inv['invoice_number']);
?>

<form action="" method="post" style="font-family: sans-serif;">
    <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">

    <table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead>
            <tr style="text-align: left; background: #f2f2f2;">
                <th style="padding: 10px; border-bottom: 2px solid #ddd;"><?php echo lang('@Product'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 80px;"><?php echo lang('@Qty'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 120px;"><?php echo lang('@Unit Price'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 120px;"><?php echo lang('@Total'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 50px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php while ($line = mysqli_fetch_assoc($lines_res)): ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 10px;">
                    <select name="prod_id[]" style="width:100%; padding:5px;">
                        <?php mysqli_data_seek($products_res, 0); ?>
                        <?php while ($p = mysqli_fetch_assoc($products_res)): ?>
                            <option value="<?php echo $p['prod_id']; ?>" <?php echo ($p['prod_id'] == $line['prod_id'] ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($p['prod_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </td>
                <td style="padding: 10px;">
                    <input type="number" name="qty[]" value="<?php echo $line['qty']; ?>" style="width:100%; padding:5px;">
                </td>
                <td style="padding: 10px;">
                    <input type="text" name="price[]" value="<?php echo number_format($line['price'], 2, ',', ''); ?>" style="width:100%; padding:5px;">
                </td>
                <td style="padding: 10px; font-weight: bold;">
                    <?php echo number_format($line['qty'] * $line['price'], 2, ',', '.'); ?>
                </td>
                <td style="padding: 10px; text-align: center;">
                    <button type="button" title="<?php echo lang('@Remove line'); ?>" style="color: #e74c3c; border: none; background: none; cursor: pointer; font-size: 1.2em;">&times;</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div style="margin-bottom: 20px;">
        <button type="button" style="background: #f39c12; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">
            ➕ <?php echo lang('@Add row'); ?>
        </button>
    </div>

    <div style="display: flex; gap: 10px; border-top: 1px solid #eee; padding-top: 20px;">
        <button type="submit" name="save_lines" style="background:#2ecc71; color:white; padding:10px 25px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">
            💾 <?php echo lang('@Save Changes'); ?>
        </button>
        <a href="faktura_vis.page.php?id=<?php echo $invoice_id; ?>" style="background:#95a5a6; color:white; padding:10px 25px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>