<?php # bank_import_step1.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// --- 1. SAVE PROFILE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['profile_name'])) {
    $p_name = strtolower(mysqli_real_escape_string($conn, $_POST['profile_name']));
    $key = 'import_profile_' . $p_name;
    $setup = [
        'col_date'   => $_POST['col_date'],
        'col_text'   => $_POST['col_text'],
        'col_amount' => $_POST['col_amount'],
        'skip_lines' => $_POST['skip_lines']
    ];
    $val = json_encode($setup);
    
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) 
                        VALUES ('$key', '$val') 
                        ON DUPLICATE KEY UPDATE setting_value = '$val'");
    $msg = lang('@Profile saved successfully!');
}

htm_Header(lang('@Bank Import - Step 1'));
showMenu();

$preview_data = [];
$delimiter = ';';
$file_path = '';
$suggested = ['date' => '', 'text' => '', 'amount' => '', 'skip' => '1'];

// --- 2. LOAD PROFILE IF SELECTED ---
if (isset($_GET['load_profile']) && !empty($_GET['load_profile'])) {
    $p_key = 'import_profile_' . mysqli_real_escape_string($conn, $_GET['load_profile']);
    $res = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = '$p_key'");
    if ($r = mysqli_fetch_assoc($res)) {
        $p_data = json_decode($r['setting_value'], true);
        $suggested['date']   = $p_data['col_date'];
        $suggested['text']   = $p_data['col_text'];
        $suggested['amount'] = $p_data['col_amount'];
        $suggested['skip']   = $p_data['skip_lines'] ?? '1';
    }
}

// --- 3. HANDLE UPLOAD AND AUTO-DETECT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $tmp_name = $_FILES['csv_file']['tmp_name'];
    $destination = 'uploads/' . time() . '_' . $_FILES['csv_file']['name'];
    if (move_uploaded_file($tmp_name, $destination)) {
        $file_path = $destination;
        $handle = fopen($file_path, "r");
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
        rewind($handle)
        for ($i = 0; $i < 5; $i++) {
            if ($data = fgetcsv($handle, 1000, $delimiter)) $preview_data[] = $data;
        }
        fclose($handle);
        if (!empty($preview_data)) {
            foreach ($preview_data[0] as $idx => $val) {
                $col_num = $idx + 1;
                $val = mb_strtolower($val);
                if (empty($suggested['date']) && preg_match('/dato|date|tid/i', $val)) $suggested['date'] = $col_num;
                if (empty($suggested['text']) && preg_match('/tekst|beskrivelse|text|desc|merchant/i', $val)) $suggested['text'] = $col_num;
                if (empty($suggested['amount']) && preg_match('/brutto|gross|samlet|total/i', $val)) $suggested['amount'] = $col_num;
                elseif (empty($suggested['amount']) && preg_match('/beløb|amount|pris/i', $val)) $suggested['amount'] = $col_num;
            }
        }
    }
}

if (isset($msg)) echo "<div style='background:#d4edda; color:#155724; padding:10px; margin-bottom:20px; border-radius:4px;'>$msg</div>";

htm_Card_(lang('@Configure Import'), 1000);
?>
<fieldset class="field-group" style="margin-bottom: 25px; border-color: #3498db !important;" data-hint="<?php echo lang('@Select a previously saved setup to save time.'); ?>">
    <legend style="color: #3498db;"><?php echo lang('@Load Saved Profile'); ?></legend>
    <form method="get" id="profile_loader" style="display: flex; gap: 10px; padding: 5px 0;">
        <select name="load_profile" style="flex: 1; border:none;" onchange="this.form.submit();">
            <option value=""><?php echo lang('@Select a profile...'); ?></option>
            <?php
            $res = mysqli_query($conn, "SELECT setting_key FROM settings WHERE setting_key LIKE 'import_profile_%' ORDER BY setting_key ASC");
            while ($row = mysqli_fetch_assoc($res)) {
                $name = str_replace('import_profile_', '', $row['setting_key']);
                $sel = (isset($_GET['load_profile']) && $_GET['load_profile'] == $name) ? 'selected' : '';
                echo "<option value='$name' $sel>".ucfirst($name)."</option>";
            }
            ?>
        </select>
    </form>
</fieldset>
<?php if (empty($preview_data)): ?>
    <form method="post" enctype="multipart/form-data" style="text-align:center; padding:40px;">
        <input type="file" name="csv_file" required style="margin:20px 0;"><br>
        <button type="submit" class="btn-primary" style="width:200px;" data-hint="<?php echo lang('@Select your bank file (CSV) and click here to preview.'); ?>"><?php echo lang('@Upload and Preview Data'); ?></button>
    </form>
<?php else: ?>
    <div style="overflow-x:auto; margin-bottom:25px; border:1px solid #eee; border-radius:4px;">
        <table style="width:100%; border-collapse:collapse; font-size:11px; font-family:monospace;">
            <tr style="background:#f8f9fa;">
                <?php for($i=1; $i <= count($preview_data[0]); $i++): ?>
                    <th style="padding:8px; border:1px solid #ddd; color:#e67e22;"><?php echo lang('@Col.') . ' ' . $i; ?></th>
                <?php endfor; ?>
            </tr>
            <?php foreach($preview_data as $row): ?>
                <tr><?php foreach($row as $cell): ?><td style="padding:8px; border:1px solid #eee;"><?php echo htmlspecialchars($cell); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
        </table>
    </div>
    <form method="post" action="bank_import_process.php">
        <input type="hidden" name="file_path" value="<?php echo $file_path; ?>">
        <input type="hidden" name="delimiter" value="<?php echo $delimiter; ?>">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
            <?php
            echo htm_InputGroup('fa-calendar', lang('@Date Column #'), 'col_date', $suggested['date'], 'number', null, 'required data-hint="'.lang('@Which column number contains the date?').'"');
            echo htm_InputGroup('fa-font', lang('@Text Column #'), 'col_text', $suggested['text'], 'number', null, 'required data-hint="'.lang('@Which column number contains the transaction text?').'"');
            echo htm_InputGroup('fa-money-bill', lang('@Amount Column #'), 'col_amount', $suggested['amount'], 'number', null, 'required data-hint="'.lang('@Which column number contains the amount?').'"');
            ?>
        </div>
        <div style="display: flex; gap: 15px; margin-top:15px;">
            <div style="flex:1;">
                <?php echo htm_InputGroup('fa-step-forward', lang('@Skip Lines'), 'skip_lines', $suggested['skip'], 'number', null, 'data-hint="'.lang('@Number of header lines to skip (typically 1).').'"'); ?>
            </div>
            <div style="flex:2;">
                <fieldset class="field-group" style="border-color:#3498db !important;" data-hint="<?php echo lang('@Save these settings as a profile for next time.'); ?>">
                    <legend style="color:#3498db;"><?php echo lang('@Save as Profile'); ?></legend>
                    <div style="display:flex; gap:5px;">
                        <input type="text" name="profile_name" placeholder="<?php echo lang('@Name profile...'); ?>" style="flex:1;">
                        <button type="submit" name="save_only" formaction="bank_import_step1.page.php" class="btn-info" style="margin:0; width:auto;" data-hint="<?php echo lang('@Click here to save the profile now without importing.'); ?>">💾</button>
                    </div>
                </fieldset>
            </div>
        </div>
        <hr>
        <button type="submit" class="btn-success" style="padding:15px; font-size:1.1em;" data-hint="<?php echo lang('@Complete the import for all rows in the file.'); ?>">
            <?php echo lang('@Complete Import'); ?> ➔
        </button>
    </form>
<?php endif;

htm_Card_end();
htm_Footer();
ob_end_flush();