<?php # bank_import_process.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file_path = $_POST['file_path'];
    $delimiter = $_POST['delimiter'] ?? ',';
    $mapping   = $_POST['map'];
    $source    = mysqli_real_escape_string($conn, $_POST['import_source'] ?? 'bank');
    $d_sep     = $_POST['decimal_sep']  ?? ',';
    $t_sep     = ($d_sep == ',') ? '.' : ','; // Automatic thousands separator

    if (!file_exists($file_path)) {
        die(lang('@Error: File not found'));
    }

    $handle = fopen($file_path, "r");
    $importCount = 0;
    
    // Skip header row
    fgetcsv($handle, 1000, $delimiter);

    while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
        $raw = ['date' => date('Y-m-d'), 'text' => '', 'net' => 0.0, 'fee' => 0.0, 'gross' => 0.0];
        $has_net = false;
        $has_gross = false;

        foreach ($mapping as $index => $db_field) {
            if ($db_field == 'ignore' || !isset($data[$index])) continue;
            $val = trim($data[$index]);

            if (in_array($db_field, ['amount', 'net_amount', 'fee_amount'])) {
                
                // --- Robust numeric cleaning ---
                $val = str_replace(' ', '', $val); // Remove spaces
                $val = str_replace($t_sep, '', $val); // Remove thousands sep
                $val = str_replace($d_sep, '.', $val); // Convert decimal sep to dot
                $val = preg_replace('/[^-0-9.]/', '', $val); // Keep only minus, numbers and dot
                $num = (float)$val;

                if ($db_field == 'amount')     { $raw['gross'] = $num; $has_gross = true; }
                if ($db_field == 'net_amount') { $raw['net'] = $num; $has_net = true; }
                if ($db_field == 'fee_amount') { $raw['fee'] = $num; }
            } 
            elseif ($db_field == 'trans_date') {
                $val = str_replace(['/', '.'], '-', $val);
                $date_ts = strtotime($val);
                $raw['date'] = $date_ts ? date('Y-m-d', $date_ts) : date('Y-m-d');
            } 
            elseif ($db_field == 'text_val') {
                $raw['text'] = mysqli_real_escape_string($conn, $val);
            }
        }

        // LOGIC: Calculate missing values (Net, Fee, Gross)
        // If we have Net and Fee, but no Gross: Gross = Net + Fee
        if ($has_net && !$has_gross) {
            $raw['gross'] = $raw['net'] + $raw['fee'];
        } 
        // If we have both Gross and Net: Fee = Gross - Net
        elseif ($has_gross && $has_net && $raw['fee'] == 0) {
            $raw['fee'] = $raw['gross'] - $raw['net'];
        }

        $d = $raw['date'];
        $t = $raw['text'];
        $a = $raw['gross']; // Gross amount is used for bank matching
        $f = $raw['fee'];

        // DUPLICATE CHECK & INSERT
        $check = mysqli_query($conn, "SELECT tmp_id FROM bank_statement_temp WHERE trans_date='$d' AND text_val='$t' AND amount=$a LIMIT 1");
        
        if (mysqli_num_rows($check) == 0) {
            $sql = "INSERT INTO bank_statement_temp (trans_date, text_val, amount, fee_amount, import_source) 
                    VALUES ('$d', '$t', $a, $f, '$source')";
            if (mysqli_query($conn, $sql)) $importCount++;
        }
    }
    fclose($handle);
    @unlink($file_path);

    header("Location: reconcile_list.page.php?msg=imported&count=$importCount");
    exit;
}