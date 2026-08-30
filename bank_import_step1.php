<?php # /bank_import_step1.php v:1.3.0 d:2026-08-30 i:evs
# profil-gem virkede ikke på SQLite - ON DUPLICATE KEY UPDATE var MySQL-kun; htm_InputGroup->htm_Field
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; 

$upload_dir = 'uploads/';
$suggested = ['date' => '', 'text' => '', 'amount' => '', 'skip' => '1'];
$preview_data = [];
$msg_html = '';
$original_filename = '';

// --- 1. GEM PROFIL LOGIK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['profile_name'])) {
    $p_name = strtolower(DB::real_escape_string($conn, $_POST['profile_name']));
    $key = 'import_profile_' . $p_name;
    $setup = [
        'col_date'   => $_POST['col_date'],
        'col_text'   => $_POST['col_text'],
        'col_amount' => $_POST['col_amount'],
        'skip_lines' => $_POST['skip_lines']
    ];
    $val = json_encode($setup);
    // RETTET 2026-08-19: "ON DUPLICATE KEY UPDATE" er ren MySQL-syntaks og
    // fejlede stille på SQLite (bekræftet: SQLSTATE[HY000] syntax error) -
    // DB::query() fangede fejlen og returnerede false, men resultatet blev
    // aldrig tjekket, så "gemt"-beskeden vistes uanset. Følger nu samme
    // mønster som annual_report.php/company_settings.php.
    $upsert_sql = DB::is_sqlite()
        ? "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$val')
           ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
        : "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$val')
           ON DUPLICATE KEY UPDATE setting_value = '$val'";
    $save_ok = DB::query($conn, $upsert_sql);
    $msg_html = $save_ok
        ? htm_Alert(text: '@Profile saved successfully!', type: 'success', echo: false)
        : htm_Alert(text: '@Error: Could not save profile.' . ' (' . DB::error($conn) . ')', type: 'danger', echo: false);
}

// --- 2. HENT PROFIL LOGIK ---
if (isset($_GET['load_profile']) && !empty($_GET['load_profile'])) {
    $p_key = 'import_profile_' . DB::real_escape_string($conn, $_GET['load_profile']);
    $res = DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = '$p_key'");
    if ($r = DB::fetch_assoc($res)) {
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
    $original_filename = basename($_FILES['csv_file']['name']);

    // RETTET (§bugs-batch-20-review): KRITISK - det uploadede filnavn kom
    // FØR direkte fra klienten, ubeskåret og med endelsen ukontrolleret, lige
    // ind i stien i uploads/ (offentligt tilgængelig uden login). Et
    // håndlavet (ikke browser-genereret) POST kunne sætte filnavnet til fx
    // "../../../shell.php" (filsti-gennembrud UD AF uploads/) eller blot
    // "shell.php" (direkte kørbar PHP-fil i uploads/) forklædt som en
    // "CSV-fil". basename() ovenfor fjerner ethvert stikomponent, og denne
    // hvidliste tillader kun .csv - samme sårbarhedsklasse som lige rettet i
    // expense_edit.php, se den for uddybning. uploads/.htaccess blokerer nu
    // også farlige endelser direkte på Apache-niveau som endnu et lag.
    $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        die(lang('@Error: Only CSV files can be imported.'));
    }
    // Tilfældigt filnavn i stedet for det forudsigelige "time()_originalnavn"
    // - samme begrundelse som expense_edit.php's bilags-upload (se
    // [[bugs-batch-18-review]]): uploads/ er offentligt tilgængelig, så et
    // gættet/kendt filnavn ville afsløre andre brugeres importerede CSV-data.
    $file_path = $upload_dir . 'BANK_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.csv';

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
        $msg_html = htm_Alert(text: "@Error: Could not save file.", type: 'danger', echo: false);
    }
}

// --- 4. RENDER SIDE ---
htm_Header(capt: '@Bank Import', mwidth: 1000);
showMenu();
echo $msg_html;

htm_Card_(capt: '@Configure Import', wdth: 1000);

// Hent gemte profiler til dropdown-opsætning
$res = DB::query($conn, "SELECT setting_key FROM settings WHERE setting_key LIKE 'import_profile_%' ORDER BY setting_key ASC");
$profile_options = ['' => '-- ' . lang('@Select a profile...') . ' --'];
while ($row = DB::fetch_assoc($res)) {
    $name = str_replace('import_profile_', '', $row['setting_key']);
    $profile_options[$name] = ucfirst($name);
}

// Profil-vælger via InputGroup
echo '<form method="get" id="profile_form" style="margin-bottom:25px;">';
htm_Field(
    icon: 'fa-folder-open', 
    labl: '@Load Saved Profile', 
    name: 'load_profile', 
    valu: ($_GET['load_profile'] ?? ''), 
    type: 'sele', 
    opti: $profile_options, 
    extr: 'onchange="document.getElementById(\'profile_form\').submit();"',
    echo: true
);
echo '</form>';
?>

<?php if (empty($preview_data)): ?>
    <form method="post" enctype="multipart/form-data" style="text-align:center; padding:40px;">
        <?php csrf_field(); ?>
        <div style="margin-bottom: 25px;">
            <input type="file" name="csv_file" required style="font-size: 1.1em;">
        </div>
        <?php 
        htm_Button(
            icon: 'fa-upload',
            labl: '@Upload and Preview Data',
            type: 'primary',
            attr: 'type="submit" data-hint="'.lang('@Read the file and show a preview before importing').'"',
            echo: true
        );
        ?>
    </form>
<?php else: ?>
    <div style="margin-bottom: 15px; padding: 10px 15px; background: #e8f4fd; border-left: 4px solid #3498db; border-radius: 4px; font-size: 0.95em; color: #2c3e50; font-weight: 600;">
        <i class="fa-solid fa-file-csv" style="color: #3498db; margin-right: 8px; font-size: 1.1em;"></i>
        <?php echo lang('@Active File:') . ' ' . htmlspecialchars($original_filename); ?>
    </div>

    <style>
        #preview_tbl th { font-size: 11px !important; padding: 4px 6px !important; }
        #preview_tbl td { font-size: 11px !important; font-family: monospace !important; padding: 3px 6px !important; }
    </style>
    
    <div style="overflow-x:auto; margin-bottom:25px;">
        <?php
        $headers = [];
        for($i=1; $i <= count($preview_data[0]); $i++) {
            $headers[] = lang('@Col.') . ' ' . $i;
        }

        $formatted_preview = [];
        foreach($preview_data as $row) {
            $formatted_row = [];
            foreach($row as $idx => $cell) {
                $formatted_row[] = htmlspecialchars($cell);
            }
            $formatted_preview[] = $formatted_row;
        }

        htm_Table(
            head: $headers, 
            data: $formatted_preview, 
            name: 'preview_tbl', 
            limt: 0, 
            echo: true
        );
        ?>
    </div>

    <form method="post" action="bank_import_process.php" id="import_config_form" onsubmit="return tcBuildColumnMap(this);">
        <?php csrf_field(); ?>
        <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($file_path); ?>">
        <input type="hidden" name="delimiter" value="<?php echo htmlspecialchars($delimiter); ?>">
        <input type="hidden" name="col_count" value="<?php echo count($preview_data[0]); ?>">

        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px; margin-bottom:20px;">
            <?php
            htm_Field(icon: 'fa-calendar', labl: '@Date Column #', name: 'col_date', valu: $suggested['date'], type: 'number', extr: 'required', echo: true);
            htm_Field(icon: 'fa-font', labl: '@Text Column #', name: 'col_text', valu: $suggested['text'], type: 'number', extr: 'required', echo: true);
            htm_Field(icon: 'fa-money-bill', labl: '@Amount Column #', name: 'col_amount', valu: $suggested['amount'], type: 'number', extr: 'required', echo: true);
            ?>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 2fr auto; gap:15px; margin-top:15px; align-items: end;">
            <div>
                <?php htm_Field(icon: 'fa-step-forward', labl: '@Skip Lines', name: 'skip_lines', valu: $suggested['skip'], type: 'number', echo: true); ?>
            </div>
            <div>
                <?php 
                htm_Field(
                    icon: 'fa-save', 
                    labl: '@Save Configuration as Profile', 
                    name: 'profile_name', 
                    valu: '', 
                    type: 'text', 
                    extr: 'placeholder="'.lang('@Name profile...').'"', 
                    echo: true
                ); 
                ?>
            </div>
            <div style="padding-bottom: 2px;">
                <?php
                htm_Button(
                    icon: 'fa-floppy-o',
                    labl: '@Save Profile Only',
                    type: 'info',
                    attr: 'type="submit" name="save_only" formaction="bank_import_step1.php" style="height: 44px; display: flex; align-items: center;" data-hint="'.lang('@Save this column mapping as a reusable profile, without importing now').'"',
                    echo: true
                );
                ?>
            </div>
        </div>
        
        <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">
        
        <?php
        htm_Button(
            icon: 'fa-arrow-right',
            labl: '@Complete Import',
            type: 'success',
            attr: 'type="submit" style="padding:15px; font-size:1.1em; width:100%;" data-hint="'.lang('@Import the mapped transactions into the reconciliation queue').'"',
            echo: true
        );
        ?>
    </form>
    <script>
    // RETTET (§bugs-batch-24-review): ALVORLIGT FUND - denne formular
    // sendte ALDRIG det map[]-array bank_import_process.php rent faktisk
    // læser (map[kolonneindeks] = 'trans_date'/'text_val'/'amount'/'ignore').
    // Den sendte kun col_date/col_text/col_amount (kolonneNUMRE, ikke et
    // map[]), som bank_import_process.php slet ikke kender noget til.
    // Resultatet: $_POST['map'] var altid udefineret dér, foreach()-løkken
    // der rent faktisk udtrækker dato/tekst/beløb fra CSV-rækken kørte
    // derfor ALDRIG, og hver eneste importeret transaktion endte i
    // bank_statement_temp med dagens dato, tom tekst og 0 kr i beløb -
    // ALDRIG de rigtige CSV-data. Ingen fejl blev vist ("msg=imported&
    // count=N" så ud som en succesfuld import). Bekræftet direkte mod en
    // rigtig import: to rækker med reelle beløb endte som ÉN tom 0-kr-
    // "dublet" (den blanke pladsholder-række matchede sig selv i
    // dublet-tjekket). Bygger nu det rigtige map[]-array her i browseren
    // ud fra de samme tre kolonnenumre, umiddelbart før formularen sendes.
    function tcBuildColumnMap(form) {
        var colCount = parseInt(form.col_count.value, 10) || 0;
        var dateCol   = parseInt(form.col_date.value, 10);
        var textCol   = parseInt(form.col_text.value, 10);
        var amountCol = parseInt(form.col_amount.value, 10);
        for (var i = 1; i <= colCount; i++) {
            var fieldName = 'ignore';
            if (i === dateCol)        fieldName = 'trans_date';
            else if (i === textCol)   fieldName = 'text_val';
            else if (i === amountCol) fieldName = 'amount';
            var input = document.createElement('input');
            input.type = 'hidden';
            // map[]-nøglerne er 0-baserede array-indekser (fra fgetcsv()),
            // mens kolonnenumrene vist til brugeren ("Kolonne #") er
            // 1-baserede - konverteres her (i - 1).
            input.name = 'map[' + (i - 1) + ']';
            input.value = fieldName;
            form.appendChild(input);
        }
        return true;
    }
    </script>
<?php endif;

htm_Card_end();
htm_Footer();
ob_end_flush();
?>