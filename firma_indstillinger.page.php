<?php # firma_indstillinger.page.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// Definition af stien til din JSON-fil
$json_folder = __DIR__ . '/json-data';
$json_file   = $json_folder . '/stamdata.json';

// 1. Logik: Gem indstillinger
if (isset($_POST['save_settings'])) {
    $newData = $_POST['setting'];
    
    // Tjek om mappen findes, ellers opret den
    if (!is_dir($json_folder)) {
        mkdir($json_folder, 0777, true);
    }

    // Gem data
    if (file_put_contents($json_file, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        header("Location: firma_indstillinger.page.php?msg=updated");
        exit;
    } else {
        $error = "Kunne ikke gemme. Tjek om mappen 'json-data' har skrive-rettigheder (CHMOD 777).";
    }
}

// 2. Data: Hent eksisterende indstillinger
$settings = [];
if (file_exists($json_file)) {
    $json_content = file_get_contents($json_file);
    $settings = json_decode($json_content, true) ?? [];
}

htm_Header(lang('@Company Settings'));
showMenu();

if (isset($error)) {
    echo "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:4px; margin-bottom:20px;'>$error</div>";
}

if (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
    echo "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:4px; margin-bottom:20px;'>" . lang('@Settings updated successfully') . "</div>";
}

htm_Card_(lang('@Company Information'));
?>

<form action="" method="post" style="font-family: sans-serif;">
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        
        <div>
            <h3 style="border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php echo lang('@Basic Info'); ?></h3>
            
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold;">Firma Navn:</label>
                <input type="text" name="setting[company_name]" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold;">Adresse:</label>
                <input type="text" name="setting[address]" value="<?php echo htmlspecialchars($settings['address'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>

            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div style="flex:1;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">Postnr:</label>
                    <input type="text" name="setting[zip]" value="<?php echo htmlspecialchars($settings['zip'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div style="flex:2;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">By:</label>
                    <input type="text" name="setting[city]" value="<?php echo htmlspecialchars($settings['city'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold;">CVR Nummer:</label>
                <input type="text" name="setting[cvr]" value="<?php echo htmlspecialchars($settings['cvr'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>
        </div>

        <div>
            <h3 style="border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php echo lang('@Payment Details'); ?></h3>
            
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold;">Bank:</label>
                <input type="text" name="setting[bank_name]" value="<?php echo htmlspecialchars($settings['bank_name'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>

            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div style="flex:1;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">Reg.nr:</label>
                    <input type="text" name="setting[bank_reg]" value="<?php echo htmlspecialchars($settings['bank_reg'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div style="flex:2;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">Kontonr:</label>
                    <input type="text" name="setting[bank_acc]" value="<?php echo htmlspecialchars($settings['bank_acc'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold;">Email til fakturaer:</label>
                <input type="email" name="setting[email]" value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>
        </div>

    </div>

    <div style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;">
        <button type="submit" name="save_settings" style="background:#3498db; color:white; padding:12px 30px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; width: auto;">
            💾 Gem firmadata
        </button>
    </div>

</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>