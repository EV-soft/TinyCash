<?php # /company_settings.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$msg = ""; $err = "";

// 1. HENT AKTUELLE INDSTILLINGER
$s = get_settings($conn);

// 2. HÅNDTER OPDATERING (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    foreach ($_POST['set'] as $key => $val) {
        $key = mysqli_real_escape_string($conn, $key);
        $val = mysqli_real_escape_string($conn, $val);
        
        // Update eller Insert (UPSERT logik)
        $sql = "INSERT INTO settings (setting_key, setting_value) 
                VALUES ('$key', '$val') 
                ON DUPLICATE KEY UPDATE setting_value = '$val'";
        mysqli_query($conn, $sql);
    }
    header("Location: company_settings.page.php?msg=updated");
    exit;
}

if (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
    $msg = lang('@Settings updated successfully');
}

htm_Header(lang('@Company Settings'));
showMenu();

if($msg) {
    echo "<div style='background:#d4edda; color:#155724; padding:15px; margin:10px auto; max-width:700px; border-radius:4px; border:1px solid #c3e6cb;'>✅ $msg</div>";
}

htm_Card_(lang('@Company Information'), 450);
?>

<form method="post">
    <?php
    // Vi bruger 'set[key]' som navn for nemt at loop'e dem i POST
    echo htm_InputGroup('fa-building', lang('@Company Name'), 'set[company_name]', $s['company_name'] ?? '', 'text');
    
    echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>";
        echo htm_InputGroup('fa-id-card', lang('@CVR Number'), 'set[company_cvr]', $s['company_cvr'] ?? '', 'text');
        echo htm_InputGroup('fa-envelope', lang('@Email'), 'set[company_email]', $s['company_email'] ?? '', 'email');
    echo "</div>";

    echo htm_InputGroup('fa-map-marker-alt', lang('@Address'), 'set[company_address]', $s['company_address'] ?? '', 'text');

    echo "<hr style='margin:30px 0; border:0; border-top:1px solid #eee;'>";
    echo "<h3 style='margin-bottom:15px; color:#2c3e50;'>" . lang('@Bank Details') . "</h3>";

    echo "<div style='display: grid; grid-template-columns: 1fr 2fr; gap: 15px;'>";
        echo htm_InputGroup('fa-university', lang('@Reg. No.'), 'set[bank_reg]', $s['bank_reg'] ?? '', 'text');
        echo htm_InputGroup('fa-piggy-bank', lang('@Account No.'), 'set[bank_acc]', $s['bank_acc'] ?? '', 'text');
    echo "</div>";
    
    echo htm_InputGroup('fa-globe', lang('@IBAN'), 'set[bank_iban]', $s['bank_iban'] ?? '', 'text');
    ?>

    <div style="margin-top:30px;">
        <button type="submit" name="save_settings" class="btn-success" style="padding:15px; font-size:1.1em; width:100%; cursor:pointer;">
            💾 <?php echo lang('@Save Company Settings'); ?>
        </button>
    </div>
</form>

<?php 
htm_Card_end();
htm_Footer(); 
ob_end_flush();
?>