<?php # kunde_rediger.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// 1. Logik: Opdater kunde
if (isset($_POST['update_customer'])) {
    $id      = (int)$_POST['customer_id'];
    $name    = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $contact = mysqli_real_escape_string($conn, $_POST['contact_person']);
    $email   = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $zip     = mysqli_real_escape_string($conn, $_POST['zip']);
    $city    = mysqli_real_escape_string($conn, $_POST['city']);
    $cvr     = mysqli_real_escape_string($conn, $_POST['cvr_number']);

    $sql = "UPDATE customers SET 
            customer_name = '$name', 
            contact_person = '$contact', 
            email = '$email', 
            address = '$address', 
            zip = '$zip', 
            city = '$city', 
            cvr_number = '$cvr' 
            WHERE customer_id = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: kunder.page.php?msg=updated");
        exit;
    } else {
        $error = lang('@Error saving') . ": " . mysqli_error($conn);
    }
}

// 2. Data: Hent eksisterende kunde
$id = (int)$_GET['id'];
$res = mysqli_query($conn, "SELECT * FROM customers WHERE customer_id = $id");
$c = mysqli_fetch_assoc($res);

if (!$c) die(lang('@Customer not found'));

htm_Header(lang('@Edit Customer'));
showMenu();

htm_Card_(lang('@Customer Details') . ": " . htmlspecialchars($c['customer_name']));
if (isset($error)) echo "<p style='color:red;'>$error</p>";
?>

<form action="" method="post" style="font-family: sans-serif; max-width: 600px;">
    <input type="hidden" name="customer_id" value="<?php echo $c['customer_id']; ?>">

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Customer Name'); ?>:</label>
        <input type="text" name="customer_name" value="<?php echo htmlspecialchars($c['customer_name']); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Contact Person'); ?>:</label>
        <input type="text" name="contact_person" value="<?php echo htmlspecialchars($c['contact_person'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Email Address'); ?>:</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($c['email'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Address'); ?>:</label>
        <input type="text" name="address" value="<?php echo htmlspecialchars($c['address'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="display:flex; gap:10px; margin-bottom: 15px;">
        <div style="flex:1;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Zip Code'); ?>:</label>
            <input type="text" name="zip" value="<?php echo htmlspecialchars($c['zip'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div style="flex:2;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@City'); ?>:</label>
            <input type="text" name="city" value="<?php echo htmlspecialchars($c['city'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@CVR Number'); ?>:</label>
        <input type="text" name="cvr_number" value="<?php echo htmlspecialchars($c['cvr_number'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="display: flex; gap: 10px;">
        <button type="submit" name="update_customer" style="background:#3498db; color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">
            💾 <?php echo lang('@Save Changes'); ?>
        </button>
        <a href="kunder.page.php" style="background:#95a5a6; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>