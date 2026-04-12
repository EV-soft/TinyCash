<?php # /customer_edit.page.php v:0.8.1 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ""; $err = "";

// 1. HÅNDTER OPDATERING (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cust_id > 0) {
    $name    = mysqli_real_escape_string($conn, $_POST['cust_name']);
    $email   = mysqli_real_escape_string($conn, $_POST['cust_email']);
    $phone   = mysqli_real_escape_string($conn, $_POST['cust_phone']);
    $address = mysqli_real_escape_string($conn, $_POST['cust_address']);
    $cvr     = mysqli_real_escape_string($conn, $_POST['cust_cvr']);

    $sql = "UPDATE customers SET 
            cust_name = '$name', 
            cust_email = '$email', 
            cust_phone = '$phone', 
            cust_address = '$address',
            cust_cvr = '$cvr'
            WHERE cust_id = $cust_id";

    if (mysqli_query($conn, $sql)) {
        $msg = lang('@Customer updated successfully');
    } else {
        $err = lang('@SQL Error:') . " " . mysqli_error($conn);
    }
}

// 2. HENT AKTUELLE DATA
$res = mysqli_query($conn, "SELECT * FROM customers WHERE cust_id = $cust_id");
$cust = mysqli_fetch_assoc($res);

if (!$cust) die(lang('@Customer not found'));

htm_Header(lang('@Edit Customer') . ": " . $cust['cust_name']);
showMenu();

// NY STANDARD: Vis beskeder via den nye funktion
htm_Alert($msg, 'success');
htm_Alert($err, 'error');

htm_Card_(lang('@Customer Information'), 500);
?>

<form method="POST">
    <?php
    echo htm_InputGroup('fa-user', lang('@Name'), 'cust_name', $cust['cust_name'], 'text', null, 'required');
    echo htm_InputGroup('fa-id-card', lang('@CVR'), 'cust_cvr', $cust['cust_cvr'], 'text');
    
    echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>";
        echo htm_InputGroup('fa-envelope', lang('@Email'), 'cust_email', $cust['cust_email'], 'email');
        echo htm_InputGroup('fa-phone', lang('@Phone'), 'cust_phone', $cust['cust_phone'], 'text');
    echo "</div>";

    echo htm_InputGroup('fa-map-marker-alt', lang('@Address'), 'cust_address', $cust['cust_address'], 'text');
    ?>

    <div style="display:flex; gap:10px; margin-top:25px;">
        <button type="submit" class="btn-success" style="flex:2; padding:12px; font-weight:bold;">
            💾 <?php echo lang('@Save Changes'); ?>
        </button>
        <a href="customer_list.page.php" class="btn-secondary" style="flex:1; padding:12px; text-align:center; text-decoration:none; font-weight:bold;">
            <?php echo lang('@Back'); ?>
        </a>
    </div>
</form>

<?php 
htm_Card_end(); 
htm_Footer(); 
ob_end_flush();
?>