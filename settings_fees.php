<?php # /settings_fees.php v:1.2.0 d:2026-08-11 i:evs 
# (Opdateret til at vise satser i tabellen)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; // Sikr at denne er med

$msg = "";

// --- GEM/SLET FUNKTIONER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_rule'])) {
    $source = strtolower(trim($_POST['source']));
    $key    = 'fee_rule_' . $source;
    $data   = ['model' => $_POST['model'], 'rate' => (float)$_POST['rate'], 'fixed' => (float)$_POST['fixed']];
    $value  = json_encode($data);

    // SQLite: Slet eksisterende nøgle og indsæt ny
    DB::query($conn, "DELETE FROM settings WHERE setting_key = '" . str_replace("'", "''", $key) . "'");
    DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('" . str_replace("'", "''", $key) . "', '" . str_replace("'", "''", $value) . "')");
    
    $msg = lang('@Rule saved successfully');
}

if (isset($_GET['delete'])) {
    // Fjern real_escape_string da den ikke findes i SQLite
    $key = str_replace("'", "''", $_GET['delete']);
    DB::query($conn, "DELETE FROM settings WHERE setting_key = '$key'");
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
        htm_InputGroup(icon: 'fa-tag', labl: '@Source', name: 'source', extr: 'required placeholder="'.lang('@Name').'" data-hint="'.lang('@Kildens navn').'"'); 

        $models = ['fixed' => lang('@Fixed amount'), 'relative' => lang('@Percentage'), 'mixed' => lang('@Mixed')];
        htm_InputGroup(icon: 'fa-calculator', labl: '@Model', name: 'model', valu: 'fixed', type: 'sele', opti: $models); 

        $dual_input = '
            <div style="display: flex; gap: 5px; align-items: center;">
                <input type="number" name="rate" step="0.0001" placeholder="0.014" style="width: 100%; border: none; outline: none; background: transparent;">
                <span style="color:var(--text-muted);">|</span>
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

<fieldset style="padding: 15px; border: 2px solid #ddd; border-radius: 8px;">
    <legend style="color: var(--text-muted); font-size: 0.9em; padding: 0 10px; text-align: center;';">
                <?php echo lang('@Active Rules'); ?>
    </legend>
    <table style="width:100%; border-collapse:collapse;">
        <tbody>
            <?php
            $res = DB::query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
            if (DB::num_rows($res) == 0) {
                echo '<tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">'.
                    lang('@No fee rules found').'</td></tr>';
            } else {
                while ($r = DB::fetch_assoc($res)) {
                    $source = str_replace('fee_rule_', '', $r['setting_key']);
                    $val = json_decode($r['setting_value'], true);
                    
                    // Sikkerhedstjek: Hvis json_decode fejlede, giv den en standardværdi
                    if (!is_array($val)) $val = ['model' => 'fixed', 'rate' => 0, 'fixed' => 0];
                    
                    // Formater satser pænt til visning
                    $rate_display = ($val['model'] === 'relative' || $val['model'] === 'mixed') ? ($val['rate'] * 100) . '%' : '-';
                    $fixed_display = ($val['model'] === 'fixed' || $val['model'] === 'mixed') ? number_format($val['fixed'], 2) . ' kr.' : '-';
                    if ($val['model'] === 'mixed') {
                        $rate_display = ($val['rate'] * 100) . '% + ' . number_format($val['fixed'], 2) . ' kr.';
                        $fixed_display = ''; // Samlet i rate_display for overskuelighed
                    } elseif ($val['model'] === 'relative') {
                        $rate_display = ($val['rate'] * 100) . '%';
                    } elseif ($val['model'] === 'fixed') {
                        $rate_display = number_format($val['fixed'], 2) . ' kr.';
                    }

                    echo '<tr style="border-bottom:1px solid #f9f9f9;">
                            <td style="padding:12px; font-weight:bold;">' . ucfirst($source) . '</td>
                            <td style="padding:12px;">'. lang('@'. $val['model']) . '</td>
                            <td style="padding:12px; color: var(--text-muted);">' . $rate_display . '</td>
                            <td style="padding:12px; text-align:right;">';
                    
                    htm_ConfirmLink(
                        icon: 'fa-trash-can',
                        link: "?delete=".$r['setting_key'],
                        mess: '@Are you sure?',
                        type: 'danger',
                        styl: 'padding: 4px 8px; font-size: 12px;'
                    );
                    echo '</td></tr>';
                }
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