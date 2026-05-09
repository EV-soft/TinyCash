<?php # /company_settings.php v:0.9.1 d:2026-05-07 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// 1. Logik: Opdater indstillinger
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    foreach ($_POST['set'] as $key => $val) {
        $key = mysqli_real_escape_string($conn, $key);
        $val = mysqli_real_escape_string($conn, $val);
        mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) 
                             VALUES ('$key', '$val') 
                             ON DUPLICATE KEY UPDATE setting_value = '$val'");
    }
    header("Location: company_settings.php?msg=updated");
    exit;
}

$s = get_settings($conn);
$msg = (isset($_GET['msg']) && $_GET['msg'] == 'updated') ? lang('@Settings updated successfully') : "";

htm_Header(capt: 'Tiny Cash');
showMenu();

if($msg) htm_Alert(text: $msg, type: 'success');

// 2. htm_Card_ udnyttelse
htm_Card_(
    capt: '@Company Information', 
    wdth: 650, 
    info: '', 
    form: 'post'
);

    // --- Input felter ---
    htm_InputGroup(icon: 'fa-building', labl: '@Company Name', name: 'set[company_name]', valu: $s['company_name'] ?? '');
    
    htm_InputGroup(icon: 'fa-id-card', labl: '@CVR Number', name: 'set[company_cvr]', valu: $s['company_cvr'] ?? '', wdth: '33%');
    htm_InputGroup(icon: 'fa-envelope', labl: '@Email', name: 'set[company_email]', valu: $s['company_email'] ?? '', type: 'email', wdth: '66%');

    htm_InputGroup(icon: 'fa-map-marker-alt', labl: '@Address', name: 'set[company_address]', valu: $s['company_address'] ?? '', type: 'textarea');

    htm_InputGroup(icon: 'fa-phone', labl: '@Phone Number', name: 'set[company_phone]', valu: $s['company_phone'] ?? '', wdth: '50%');
    htm_InputGroup(icon: 'fa-info-circle', labl: '@Extra Info', name: 'set[company_extra]', valu: $s['company_extra'] ?? '', wdth: '50%');

    echo "<hr style='margin:20px 0; border:0; border-top:1px solid #eee;'>";

    // --- Bank Details ---
    htm_InputGroup(icon: 'fa-university', labl: '@Reg. No.', name: 'set[bank_reg]', valu: $s['bank_reg'] ?? '', wdth: '30%');
    htm_InputGroup(icon: 'fa-piggy-bank', labl: '@Account No.', name: 'set[bank_acc]', valu: $s['bank_acc'] ?? '', wdth: '70%');

    // 3. htm_Button udnyttelse
    // 'cont' variablen bruger din str_replace logik til at indsætte knappen i en div
    htm_Button(
        icon: 'fa-save',
        labl: '@Save Company Settings',
        type: 'success',
        attr: 'name="save_settings"',
        styl: 'width:100%; padding:15px; font-weight:bold; margin-top:10px;',
        cont: '<div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;"></div>'
    );

htm_Card_end(); // Lukker både card og form automatisk
htm_Footer(); 
ob_end_flush();
?>