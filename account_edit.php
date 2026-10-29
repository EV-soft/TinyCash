<?php # /account_edit.php v:1.3.1 d:2026-09-15 i:evs
# Opret/redigér en konto i kontoplanen. Kontotype-dropdown dækker de typer
# resten af systemet reelt bruger (income/expense/asset/liability/bank/vat/
# equity). Ændring af kontotype/momskode logges til revisionssporet.
# Samme niveau-3-krav som chart_of_accounts.php (se dens header for hvorfor -
# manglede FØR helt på denne side, selvom den kun nås derfra).
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = "";

// 1. Håndter Gem (Både ny og opdatering)
if (isset($_POST['save_account'])) {
    $new_id     = (int)$_POST['acc_id'];
    $name       = DB::escape($conn, $_POST['acc_name']);
    $type       = DB::escape($conn, $_POST['acc_type']);
    $vat_code   = trim(DB::escape($conn, $_POST['vat_code']));
    // RETTET (§find-fem-fejl runde 40): brugte (float) direkte i stedet for
    // parse_dk_number(), i modsætning til vat_save.php's egen håndtering af
    // nøjagtig samme felt (samme fejlklasse som [[invoice-line-comma-amount-fix]]).
    // (float)"7,5" afkortes stille til 7.0 i PHP - lav praktisk risiko via et
    // <input type=number> (accepterer typisk ikke komma), men samme lave-
    // risiko-men-ret-for-konsistens begrundelse som vat_save.php allerede gav.
    $vat_rate   = parse_dk_number($_POST['vat_rate']);
    // NYT (BEK 811/2026-gennemgang, Bilag 3 "bogføringsguide/konterings-
    // vejledning", se [[digital-standard-bookkeeping-registration-goal]]):
    // fri konteringsvejledning pr. konto - vises på chart_of_accounts.php
    // (tooltip) og journal_entry.php (direkte ved selve konteringen).
    $guidance   = trim($_POST['guidance'] ?? '');
    $guidance_sql = DB::escape($conn, $guidance);

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

    // Hent kontoens data FØR en evt. opdatering, til revisionssporet - en
    // ændret kontotype (fx udgift -> indtægt) eller momskode kan stille
    // omklassificere både historiske og fremtidige posteringer i rapporter.
    // Bruger-anmodet.
    $before_row = null;
    if ($id > 0) {
        $before_res = DB::query($conn, "SELECT acc_type, vat_code FROM accounts WHERE acc_id = $id");
        $before_row = $before_res ? DB::fetch_assoc($before_res) : null;
    }

    // RETTET: typen på en konto MED posteringer kunne ændres - fx indtægt -> aktiv. Det flyttede stille hele
    // kontoens historik mellem resultatopgørelse og balance i alle rapporter, også i lukkede år og
    // årsrapporter der allerede var afleveret. En konto med posteringer beholder sin type; brug en ny konto
    // (og en postering der flytter saldoen), hvis typen var forkert.
    $acc_type_locked = false;
    if ($before_row && (string)$before_row['acc_type'] !== (string)$_POST['acc_type']) {
        $has_post = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM ledger WHERE acc_id = $id"));
        if ($has_post && (int)$has_post[0] > 0) {
            $acc_type_locked = true;
            $type = DB::escape($conn, (string)$before_row['acc_type']);
            $_POST['acc_type'] = $before_row['acc_type'];
        }
    }

    if ($id == 0) {
        // OPRET NY KONTO
        $sql = "INSERT INTO accounts (acc_id, acc_name, acc_type, vat_rate, vat_code, std_ref_id, guidance)
                VALUES ($new_id, '$name', '$type', $vat_rate, '$vat_code', $std_ref_sql, '$guidance_sql')";
    } else {
        // OPDATER EKSISTERENDE
        $sql = "UPDATE accounts SET
                    acc_name = '$name',
                    acc_type = '$type',
                    vat_rate = $vat_rate,
                    vat_code = '$vat_code',
                    std_ref_id = $std_ref_sql,
                    guidance = '$guidance_sql'
                WHERE acc_id = $id";
    }

    if (DB::query($conn, $sql)) {
        // Log kun ved en reel ændring af type/momskode, ikke ved en
        // gemning af rene navne-/rate-redigeringer.
        if ($before_row && ((string)$before_row['acc_type'] !== (string)$_POST['acc_type'] || (string)($before_row['vat_code'] ?? '') !== (string)$vat_code)) {
            log_action($conn, 'UPDATE_ACCOUNT', 'accounts', $id,
                ['acc_type' => $before_row['acc_type'], 'vat_code' => $before_row['vat_code']],
                ['acc_type' => $_POST['acc_type'], 'vat_code' => $vat_code]);
        }
        header("Location: " . ($acc_type_locked ? "account_edit.php?id=$id&err=type_locked" : "chart_of_accounts.php?msg=updated"));
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
        'acc_type'   => 'income',
        'vat_code'   => '', 
        'vat_rate'   => 0,
        'std_ref_id' => '',
        'guidance'   => ''
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
if (($_GET['err'] ?? '') === 'type_locked') $message .= htm_Alert(lang('@The account has postings, so its type cannot be changed - the other changes are saved.'), 'warning', 0, true);
echo $message;
?>

<form method="post">
    <?php csrf_field(); ?>
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
            // RETTET 2026-08-15: manglede 'income'/'bank'/'vat'/'equity' - kun
            // 'revenue'/'expense'/'asset'/'liability' var mulige at vælge, selv
            // om resten af systemet (posteringer, rapporter, årsafslutning)
            // forudsætter 'income' (ikke 'revenue'), samt 'bank'/'vat'/'equity'.
            // Se regnskabslov-status i hukommelsen for den fulde analyse.
            $types = [
                'income'    => '@Income (Revenue)',
                'expense'   => '@Expense (Costs)',
                'bank'      => '@Bank / Cash',
                'vat'       => '@VAT (Settlement)',
                'asset'     => '@Asset (Balance)',
                'liability' => '@Liability (Debt)',
                'equity'    => '@Equity'
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

    <div style="margin-bottom: 20px;">
        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo lang('@Posting Guidance'); ?>:</label>
        <textarea name="guidance" rows="3" placeholder="<?php echo htmlspecialchars(lang('@Optional note shown to bookkeepers when posting to this account, e.g. what belongs here and what does not.')); ?>" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main); resize:vertical;"><?php echo htmlspecialchars($row['guidance'] ?? ''); ?></textarea>
    </div>

    <div style="display: flex; justify-content: space-between; margin-top: 20px;">
        <?php 
            htm_Button('fa-save', ($id == 0 ? '@Create Account' : '@Update Account'), 'primary', '', '', 'name="save_account" data-hint="'.lang($id == 0 ? '@Add this account to the chart of accounts' : '@Save changes to this account').'"');
            htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php', '', 'data-hint="'.lang('@Discard changes and return to the chart of accounts').'"');
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
