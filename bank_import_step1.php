<?php # /bank_import_step1.php v:0.9.0 d:2026-05-08 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$upload_dir = 'uploads/';
$suggested = ['date' => '', 'text' => '', 'amount' => '', 'skip' => '1'];
$preview_data = [];
$msg_html = '';

// --- 1. GEM PROFIL LOGIK ---
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
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$val') ON DUPLICATE KEY UPDATE setting_value = '$val'");
    $msg_html = htm_Alert('@Profile saved successfully!', 'success','',false);
}

// --- 2. HENT PROFIL LOGIK ---
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

// --- 3. HÅNDTER UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $file_path = $upload_dir . time() . '_' . $_FILES['csv_file']['name'];
    
    if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $file_path)) {
        $handle = fopen($file_path, "r");
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
        rewind($handle);

        while (($data = fgetcsv($handle, 1000, $delimiter, '"')) !== FALSE) {
            $preview_data[] = $data;
            if (count($preview_data) >= 6) break;
        }
        fclose($handle);

        // Auto-detect kolonner hvis ingen profil er indlæst
        if (!empty($preview_data) && empty($suggested['date'])) {
            foreach ($preview_data[0] as $idx => $val) {
                $col = $idx + 1;
                $val = mb_strtolower($val);
                if (preg_match('/payout date|date|dato/i', $val)) $suggested['date'] = $col;
                if (preg_match('/merchant name|tekst|text/i', $val)) $suggested['text'] = $col;
                if (preg_match('/gross amount|amount|beløb/i', $val)) $suggested['amount'] = $col;
            }
        }
    } else {
        $msg_html = htm_Alert("@Error: Could not save file.", 'danger','',false);
    }
}


htm_Header('@Bank Import');
showMenu();
echo $msg_html;

htm_Card_('@Configure Import', 1000);
?>

<fieldset class="field-group" style="margin-bottom:25px; border-color:#3498db !important;">
    <legend style="color:#3498db;"><?php lang('@Load Saved Profile'); ?></legend>
    <form method="get" style="display:flex; gap:10px; padding:5px 0;">
        <select name="load_profile" style="flex:1; border:none; background:transparent;" onchange="this.form.submit();">
            <option value=""><?php lang('@Select a profile...'); ?></option>
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
        <button type="submit" class="btn-primary" style="width:250px;"><?php lang('@Upload and Preview Data'); ?></button>
    </form>
<?php else: ?>
    <div style="overflow-x:auto; margin-bottom:25px;">
        <?php
        // Dynamiske headers til htm_Table
        $headers = [];
        for($i=1; $i <= count($preview_data[0]); $i++) {
            $headers["col$i"] = lang('@Col.') . ' ' . $i;
        }
        // Formater data til htm_Table (associative rækker)
        $formatted_preview = [];
        foreach($preview_data as $row) {
            $formatted_row = [];
            foreach($row as $idx => $cell) {
                $formatted_row["col".($idx+1)] = htmlspecialchars($cell);
            }
            $formatted_preview[] = $formatted_row;
        }

        htm_Table($formatted_preview, $headers, extr:'style="font-size:11px; font-family:monospace;"');
        ?>
    </div>

    <form method="post" action="bank_import_process.php">
        <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($file_path); ?>">
        <input type="hidden" name="delimiter" value="<?php echo htmlspecialchars($delimiter); ?>">
        
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px; margin-bottom:20px;">
            <?php
            htm_InputGroup(icon:'fa-calendar', labl:'@Date Column #', name:'col_date', valu:$suggested['date'], type:'number', extr:'required');
            htm_InputGroup(icon:'fa-font', labl:'@Text Column #', name:'col_text', valu:$suggested['text'], type:'number', extr:'required');
            htm_InputGroup(icon:'fa-money-bill', labl:'@Amount Column #', name:'col_amount', valu:$suggested['amount'], type:'number', extr:'required');
            ?>
        </div>

        <div style="display:flex; gap:15px; margin-top:15px; align-items: flex-end;">
            <div style="flex:1;">
                <?php htm_InputGroup(icon:'fa-step-forward', labl:'@Skip Lines', name:'skip_lines', valu:$suggested['skip'], type:'number'); ?>
            </div>
            <div style="flex:2;">
                <fieldset class="field-group" style="border-color:#3498db !important;">
                    <legend style="color:#3498db;"><?php lang('@Save as Profile'); ?></legend>
                    <div style="display:flex; gap:5px;">
                        <input type="text" name="profile_name" placeholder="<?php lang('@Name profile...'); ?>" style="flex:1; border:none; outline:none; background:transparent; padding:5px;">
                        <button type="submit" name="save_only" formaction="bank_import_step1.php" class="btn-info" style="margin:0; width:auto; border-radius: 4px;">💾</button>
                    </div>
                </fieldset>
            </div>
        </div>
        
        <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">
        <button type="submit" class="btn-success" style="padding:15px; font-size:1.1em; width:100%;">
            <?php lang('@Complete Import'); ?> ➔
        </button>
    </form>
<?php endif; 

htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>