<?php # /settings_fees.php v:0.9.0 d:2026-04-25 i:evs m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; // Sikr at denne er med

$msg = "";

// --- GEM/SLET FUNKTIONER BEHOLDES SOM DE ER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_rule'])) {
    $source = strtolower(mysqli_real_escape_string($conn, $_POST['source']));
    $key    = 'fee_rule_' . $source;
    $data = ['model' => $_POST['model'], 'rate' => (float)$_POST['rate'], 'fixed' => (float)$_POST['fixed']];
    $value = json_encode($data);
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$value') ON DUPLICATE KEY UPDATE setting_value = '$value'");
    $msg = lang('@Rule saved successfully');
}

if (isset($_GET['delete'])) {
    $key = mysqli_real_escape_string($conn, $_GET['delete']);
    mysqli_query($conn, "DELETE FROM settings WHERE setting_key = '$key'");
    header('Location: settings_fees.php?msg=deleted');
    exit;
}

htm_Header('@Fee Rules');
showMenu();

if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') $msg = lang('@Rule deleted successfully');
if ($msg) htm_Alert($msg, 'success');

htm_Card_('@Configure Fee Rules', '800');
?>

<form method="post">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: start; margin-bottom: 20px;">
        <?php 
        htm_InputGroup(icon: 'fa-tag', labl: '@Source', name: 'source', extr: 'required placeholder="stripe" data-hint="'.lang('@Kildens navn').'"'); 

        $models = ['fixed' => lang('@Fixed amount'), 'relative' => lang('@Percentage'), 'mixed' => lang('@Mixed')];
        htm_InputGroup(icon: 'fa-calculator', labl: '@Model', name: 'model', valu: 'fixed', type: 'select', opti: $models); 

        $dual_input = '
            <div style="display: flex; gap: 5px; align-items: center;">
                <input type="number" name="rate" step="0.0001" placeholder="0.014" style="width: 100%; border: none; outline: none; background: transparent;">
                <span style="color:#ccc;">|</span>
                <input type="number" name="fixed" step="0.01" value="1.00" style="width: 100%; border: none; outline: none; background: transparent;">
            </div>';
        htm_InputGroup(icon: 'fa-percent', labl: '@Rate / Fixed', name: 'combined', valu: $dual_input, type: 'raw'); 
        ?>
    </div>

    <?php
    // BRUG HTM_BUTTON TIL GEM
    htm_Button(
        icon: 'fa-save', 
        labl: '@Save Rule', 
        type: 'success', 
        styl: 'padding: 12px 40px;', 
        attr: 'name="save_rule"',
        cont: '<div style="text-align: right; margin-bottom: 30px;"></div>'
    );
    ?>
</form>

<fieldset style="padding: 15px; border: 1px solid #eee; border-radius: 8px;">
    <legend style="color: #7f8c8d; font-size: 0.9em; padding: 0 10px;"><?php echo lang('@Active Rules'); ?></legend>
    <table style="width:100%; border-collapse:collapse;">
        <tbody>
            <?php
            $res = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
            while ($r = mysqli_fetch_assoc($res)) {
                $source = str_replace('fee_rule_', '', $r['setting_key']);
                $val = json_decode($r['setting_value'], true);
                
                echo '<tr style="border-bottom:1px solid #f9f9f9;">
                        <td style="padding:12px; font-weight:bold;">' . ucfirst($source) . '</td>
                        <td style="padding:12px;">'.lang('@'.$val['model']).'</td>
                        <td style="padding:12px; text-align:right;">';
                
                // BRUG HTM_BUTTON TIL SLET (Lille ikon)
                htm_Button(
                    icon: 'fa-trash-can', 
                    type: 'danger', 
                    link: "?delete=".$r['setting_key'], 
                    styl: 'padding: 4px 8px; font-size: 12px;',
                    attr: 'onclick="return confirm(\''.lang('@Are you sure?').'\')"'
                );
                echo '</td></tr>';
            }
            ?>
        </tbody>
    </table>
</fieldset>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush();
?>