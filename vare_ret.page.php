<?php # vare_ret.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; // Heri ligger din lang() funktion
require 'menu.inc.php';

// 1. Logik: Håndter opdatering af varen
if (isset($_POST['update_product'])) {
    $id     = (int)$_POST['prod_id'];
    $sku    = mysqli_real_escape_string($conn, $_POST['prod_sku']);
    $name   = mysqli_real_escape_string($conn, $_POST['prod_name']);
    
    // Håndterer både dansk komma og punktum
    $price  = (float)str_replace(',', '.', $_POST['prod_price']); 
    
    $stock  = (int)$_POST['prod_stock'];
    $acc_id = (int)$_POST['acc_id'];

    $sql = "UPDATE products SET 
            prod_sku = '$sku', 
            prod_name = '$name', 
            prod_price = '$price', 
            prod_stock = '$stock', 
            acc_id = '$acc_id' 
            WHERE prod_id = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: lager_liste.page.php?msg=updated");
        exit;
    } else {
        $error = lang('@Error saving') . ": " . mysqli_error($conn);
    }
}

// 2. Data: Hent den eksisterende vare
$id = (int)($_GET['id'] ?? $_POST['prod_id'] ?? 0);
$res = mysqli_query($conn, "SELECT * FROM products WHERE prod_id = $id");
$v = mysqli_fetch_assoc($res);

if (!$v) { 
    die("Varen blev ikke fundet i databasen."); 
}

// Hent kontoplan til dropdown
$accounts_res = mysqli_query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id ASC");

// 3. Visning: HTML-output
htm_Header(lang('@Edit product'));
showMenu();

htm_Card_(lang('@Edit product') . ": " . htmlspecialchars($v['prod_name']));
if (isset($error)) echo "<p style='color:red;'>$error</p>";
?>

<form action="" method="post" style="font-family: sans-serif; max-width: 500px;">
    <input type="hidden" name="prod_id" value="<?php echo $v['prod_id']; ?>">

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@SKU'); ?>:</label>
        <input type="text" name="prod_sku" value="<?php echo htmlspecialchars($v['prod_sku']); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Product name'); ?>:</label>
        <input type="text" name="prod_name" value="<?php echo htmlspecialchars($v['prod_name']); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px; display: flex; gap: 10px;">
        <div style="flex: 1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Sales price'); ?>:</label>
            <input type="text" name="prod_price" value="<?php echo number_format($v['prod_price'], 2, ',', ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div style="flex: 1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@In stock'); ?>:</label>
            <input type="number" name="prod_stock" value="<?php echo $v['prod_stock']; ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Accounting account'); ?>:</label>
        <select name="acc_id" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            <?php while($acc = mysqli_fetch_assoc($accounts_res)): ?>
                <option value="<?php echo $acc['acc_id']; ?>" <?php if($acc['acc_id'] == $v['acc_id']) echo 'selected'; ?>>
                    <?php echo $acc['acc_id'] . " - " . htmlspecialchars($acc['acc_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div style="display: flex; gap: 10px;">
        <button type="submit" name="update_product" style="background:#3498db; color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">
            💾 <?php echo lang('@Save changes'); ?>
        </button>
        <a href="lager_liste.page.php" style="background:#95a5a6; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>