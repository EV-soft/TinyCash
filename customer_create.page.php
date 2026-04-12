<?php # /customer_create.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = mysqli_real_escape_string($conn, $_POST['cust_name']);
    $address = mysqli_real_escape_string($conn, $_POST['cust_address']);
    $email   = mysqli_real_escape_string($conn, $_POST['cust_email']);
    $phone   = mysqli_real_escape_string($conn, $_POST['cust_phone']);
    $cvr     = mysqli_real_escape_string($conn, $_POST['cust_cvr']);
    $payDays = intval($_POST['cust_payment_days'] ?? 8);

    $sql = "INSERT INTO customers (cust_name, cust_address, cust_email, cust_phone, cust_cvr, cust_payment_days) 
            VALUES ('$name', '$address', '$email', '$phone', '$cvr', $payDays)";

    if (mysqli_query($conn, $sql)) {
        header("Location: customer_list.page.php?msg=created");
        exit;
    } else {
        $msg = "<div style='background:#f8d7da; color:#721c24; padding:15px; margin-bottom:20px; border-radius:4px; border:1px solid #f5c6cb;'>❌ " . lang('@SQL Error:') . " " . mysqli_error($conn) . "</div>";
    }
}

htm_Header(lang('@Add New Customer'));
showMenu();

echo "<div style='max-width:500px; margin:20px auto;'>";

    htm_Card_(lang('@Add New Customer'), '100%');
    echo $msg;
    ?>
    <form action="customer_create.page.php" method="POST">

        <?php 
        // Vi benytter htm_InputGroup for at holde koden ren og ensartet
        echo htm_InputGroup('fa-building', lang('@Company / Name'), 'cust_name', '', 'text', null, 'required');
        echo htm_InputGroup('fa-map-marker-alt', lang('@Address'), 'cust_address', '', 'text'); // Kan evt. ændres til textarea i lib
        echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>";
            echo htm_InputGroup('fa-envelope', lang('@Email'), 'cust_email', '', 'email');
            echo htm_InputGroup('fa-phone', lang('@Phone'), 'cust_phone', '', 'text');
        echo "</div>";
        echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>";
            echo htm_InputGroup('fa-id-card', lang('@CVR Number'), 'cust_cvr', '', 'text');
            echo htm_InputGroup('fa-calendar-check', lang('@Payment Days'), 'cust_payment_days', '8', 'number');
        echo "</div>";
        ?>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="btn-success" style="flex:2; padding:12px; font-weight:bold;">
                💾 <?php echo lang('@Save Customer'); ?>
            </button>
            <a href="customer_list.page.php" class="btn-secondary" style="flex:1; text-align:center; padding:12px; text-decoration:none; font-weight:bold;">
                <?php echo lang('@Cancel'); ?>
            </a>
        </div>
    </form>

    <?php 
    htm_Card_end();

echo "</div>";

htm_Footer();
ob_end_flush();
?>