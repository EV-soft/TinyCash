<?php # account_edit.php v:1.0.0 d:2026-07-13 i:claude (Porteret fra rå mysqli til DB::-abstraktionen - virker nu på både SQLite og MySQL)
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = "";

// 1. Håndter Gem (Både ny og opdatering)
if (isset($_POST['save_account'])) {
    $new_id     = (int)$_POST['acc_id'];
    $name       = DB::escape($conn, $_POST['acc_name']);
    $type       = DB::escape($conn, $_POST['acc_type']);
    $vat_code   = trim(DB::escape($conn, $_POST['vat_code']));
    $vat_rate   = (float)$_POST['vat_rate'];

    // Hent og valider standard-reference (std_ref_id er en INT i databasen)
    $std_ref_id = trim($_POST['std_ref_id']);
    $std_ref_sql = ($std_ref_id === '') ? "NULL" : (int)$std_ref_id;

    if ($vat_code !== '' && $vat_code !== '0') {
        $check_vat = DB::query($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = '$vat_code'");
        if (DB::num_rows($check_vat) == 0) {
            $insert_vat = "INSERT INTO vat_codes (vat_id, vat_name, vat_rate) 
                           VALUES ('$vat_code', '" . DB::escape($conn, lang('@Oprettet via konto')) . "', $vat_rate)";
            DB::query($conn, $insert_vat);
        }
    }

    if ($id == 0) {
        // OPRET NY KONTO
        $sql = "INSERT INTO accounts (acc_id, acc_name, acc_type, vat_rate, vat_code, std_ref_id) 
                VALUES ($new_id, '$name', '$type', $vat_rate, '$vat_code', $std_ref_sql)";
    } else {
        // OPDATER EKSISTERENDE
        $sql = "UPDATE accounts SET 
                    acc_name = '$name', 
                    acc_type = '$type', 
                    vat_rate = $vat_rate,
                    vat_code = '$vat_code',
                    std_ref_id = $std_ref_sql 
                WHERE acc_id = $id";
    }

    if (DB::query($conn, $sql)) {
        header("Location: chart_of_accounts.php?msg=updated");
        exit;
    } else {
        $message = htm_Alert(lang('@Error saving account') . ": " . DB::error($conn), 'error', 0, true);
    }
}

// 2. Hent data eller sæt standardværdier
if ($id > 0) {
    $res = DB::query($conn, "SELECT * FROM accounts WHERE acc_id = $id");
    $row = DB::fetch_assoc($res);
    if (!$row) {
        die(htm_Header('@Error') . htm_Alert('@Account not found', 'error') . htm_Footer());
    }
} else {
    $row = [
        'acc_id'     => '', 
        'acc_name'   => '', 
        'acc_type'   => 'revenue', 
        'vat_code'   => '', 
        'vat_rate'   => 0,
        'std_ref_id' => ''
    ];
}

// Hent eksisterende momskoder til datalist
$vat_options = [];
$vat_res = DB::query($conn, "SELECT vat_id, vat_name, vat_rate FROM vat_codes ORDER BY vat_rate DESC");
if ($vat_res) {
    while ($v_row = DB::fetch_assoc($vat_res)) {
        $vat_options[] = $v_row;
    }
}

// Hent standard-referencer fra std_accounts
$std_options = [];
$std_res = DB::query($conn, "SELECT std_id, std_name FROM std_accounts ORDER BY std_id ASC");
if ($std_res) {
    while ($s_row = DB::fetch_assoc($std_res)) {
        $std_options[] = $s_row;
    }
}

htm_Header($id == 0 ? "@New Account" : "@Edit Account");
showMenu();

htm_Card_($id == 0 ? "@Create New Account" : "@Edit Account: " . $id, "500");
echo $message;
?>

<form method="post">
    <div style="margin-bottom: 15px;">
        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo lang('@Account Number'); ?>:</label>
        <input type="number" name="acc_id" value="<?php echo htmlspecialchars($row['acc_id']); ?>" 
               <?php echo ($id > 0 ? 'readonly' : 'required'); ?> 
               style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main); <?php echo ($id > 0 ? 'opacity:0.6;' : ''); ?>">
        <?php if($id > 0) echo '<small style="color:var(--text-muted);">'.lang('@Account number cannot be changed').'</small>'; ?>
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo lang('@Account Name'); ?>:</label>
        <input type="text" name="acc_name" value="<?php echo htmlspecialchars($row['acc_name']); ?>" required style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo lang('@Account Type'); ?>:</label>
        <select name="acc_type" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);">
            <?php 
            $types = [
                'revenue'   => '@Revenue (Income)',
                'expense'   => '@Expense (Costs)',
                'asset'     => '@Asset (Balance)',
                'liability' => '@Liability (Debt)'
            ];
            foreach ($types as $key => $label) {
                $sel = ($row['acc_type'] == $key) ? 'selected' : '';
                echo "<option value='$key' $sel>" . lang($label) . "</option>";
            }
            ?>
        </select>
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo lang('@Standard Account Mapping'); ?>:</label>
        <select name="std_ref_id" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);">
            <option value=""><?php echo lang('@No mapping / Not reportable'); ?></option>
            <?php 
            foreach ($std_options as $s_opt) {
                $sel = ($row['std_ref_id'] == $s_opt['std_id']) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($s_opt['std_id']) . "' $sel>";
                echo htmlspecialchars($s_opt['std_id'] . " - " . $s_opt['std_name']);
                echo "</option>";
            }
            ?>
        </select>
    </div>

    <div style="display: flex; gap: 10px; margin-bottom: 5px;">
        <div style="flex: 1;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">
                <?php echo lang('@VAT Code'); ?> 
                <i class="fa fa-question-circle" 
                   title="<?php echo htmlspecialchars(lang('@Clear the existing text to type a new code or see all suggestions.')); ?>" 
                   style="color: var(--color-primary); cursor: help; margin-left: 3px;"></i>
            </label>
            <input type="text" name="vat_code" id="vat_code_input" list="vat_codes_list" value="<?php echo htmlspecialchars($row['vat_code']); ?>" placeholder="f.eks. I15" autocomplete="off" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);" onchange="updateVatRate(this.value)">
            
            <datalist id="vat_codes_list">
                <?php 
                foreach ($vat_options as $v_opt) {
                    echo "<option value='" . htmlspecialchars($v_opt['vat_id']) . "' data-rate='" . (float)$v_opt['vat_rate'] . "'>" . htmlspecialchars($v_opt['vat_name']) . " (" . (float)$v_opt['vat_rate'] . "%)</option>";
                }
                ?>
            </datalist>
        </div>
        <div style="flex: 1;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo lang('@VAT Rate'); ?> (%):</label>
            <input type="number" name="vat_rate" id="vat_rate_input" step="0.01" value="<?php echo (float)$row['vat_rate']; ?>" style="width:100%; padding:8px; border:1px solid var(--color-primary); border-radius:4px; background:var(--bg-card); color:var(--text-main); font-weight:bold;">
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <small style="color: var(--text-muted);"><?php echo lang('@Note: VAT codes and rates are managed under System -> VAT Codes.'); ?></small>
    </div>

    <div style="display: flex; justify-content: space-between; margin-top: 20px;">
        <?php 
            htm_Button('fa-save', ($id == 0 ? '@Create Account' : '@Update Account'), 'primary', '', '', 'name="save_account"'); 
            htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php'); 
        ?>
    </div>
</form>

<script>
function updateVatRate(val) {
    var dl = document.getElementById('vat_codes_list');
    var options = dl.getElementsByTagName('option');
    for (var i = 0; i < options.length; i++) {
        if (options[i].value === val) {
            document.getElementById('vat_rate_input').value = options[i].getAttribute('data-rate');
            return;
        }
    }
}
</script>

<?php 
htm_Card_end();
htm_Footer(); 
?>
