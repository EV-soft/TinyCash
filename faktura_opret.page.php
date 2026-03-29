<?php # faktura_opret.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// 1. Logik: Hent nødvendige data med KORREKTE feltnavne fra blueprint
$kunder_res = mysqli_query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name ASC");
// $varer_res  = mysqli_query($conn, "SELECT prod_id, prod_name, prod_price FROM products ORDER BY prod_name ASC");
$varer_res  = mysqli_query($conn, "SELECT prod_id, prod_name, prod_price, prod_stock FROM products ORDER BY prod_name ASC");


// Tjek for SQL fejl med det samme for at undgå hvid skærm
if (!$kunder_res || !$varer_res) {
    die("Databasefejl: " . mysqli_error($conn));
}

htm_Header(lang('@Create New Invoice'));
showMenu();

htm_Card_(lang('@Invoice Details'));
?>

<form action="faktura_gem.php" method="post" style="font-family: sans-serif;">
    
    <div style="margin-bottom: 20px; display: flex; gap: 20px;">
        <div style="flex: 2;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Select Customer'); ?>:</label>
            <select name="cust_id" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                <option value=""><?php echo lang('@Choose a customer'); ?>...</option>
                <?php while($k = mysqli_fetch_assoc($kunder_res)): ?>
                    <option value="<?php echo $k['cust_id']; ?>"><?php echo htmlspecialchars($k['cust_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div style="flex: 1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Invoice Date'); ?>:</label>
            <input type="date" name="inv_date" value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div style="flex: 1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Due Date'); ?>:</label>
            <input type="date" name="inv_due_date" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
    </div>

    <hr style="border:0; border-top:1px solid #eee; margin: 20px 0;">

    <table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead>
            <tr style="text-align: left; background: #f9f9f9;">
                <th style="padding: 10px; border-bottom: 2px solid #ddd;"><?php echo lang('@Product'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 80px;"><?php echo lang('@Qty'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 120px;"><?php echo lang('@Unit Price'); ?></th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd; width: 120px;"><?php echo lang('@Total'); ?></th>
            </tr>
        </thead>
        
        <tbody>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <select name="line_prod[]" style="width:100%; padding:5px;">
                        <option value=""><?php echo lang('@Select product'); ?>...</option>
                        <?php mysqli_data_seek($varer_res, 0); ?>
                        
                        <?php while($v = mysqli_fetch_assoc($varer_res)): ?>
                            <?php 
                                // Logik til at vise lagerstatus
                                $lager_tekst = " (Lager: " . $v['prod_stock'] . ")";
                                $er_tomt = ($v['prod_stock'] <= 0);
                            ?>
                            <option value="<?php echo $v['prod_id']; ?>" <?php echo $er_tomt ? "disabled style='color:red;'" : ""; ?>>
                                <?php echo htmlspecialchars($v['prod_name'] . " - " . number_format($v['prod_price'], 2, ',', '.') . " kr." . $lager_tekst); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <input type="number" name="line_qty[]" value="1" step="0.01" style="width:100%; padding:5px;">
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <input type="text" name="line_price[]" placeholder="0,00" style="width:100%; padding:5px;">
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;">0,00</td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 30px; display: flex; gap: 10px;">
        <button type="submit" style="background:#2ecc71; color:white; padding:12px 25px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; font-size: 1.1em;">
            📄 <?php echo lang('@Create Invoice'); ?>
        </button>
        <a href="fakturaer.page.php" style="background:#95a5a6; color:white; padding:12px 25px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>