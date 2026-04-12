<?php # /reconcile_action.php v:0.8.1 d:2026-04-11 i:Gemini m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// Modtag data fra formen
$tmp_id     = isset($_POST['tmp_id']) ? (int)$_POST['tmp_id'] : 0;
$target_id  = isset($_POST['target_id']) ? (int)$_POST['target_id'] : 0; // Faktura ID
$acc_id     = isset($_POST['acc_id']) ? (int)$_POST['acc_id'] : 0;       // Direkte konto
$fee_amount = isset($_POST['fee_amount']) ? (float)$_POST['fee_amount'] : 0;
$fee_acc_id = isset($_POST['fee_acc_id']) ? (int)$_POST['fee_acc_id'] : 2320; // Standard gebyrkonto

if ($tmp_id === 0) die(lang('@No transaction selected.'));

// 1. Hent den midlertidige bankpost
$res = mysqli_query($conn, "SELECT * FROM bank_statement_temp WHERE tmp_id = $tmp_id");
$bank = mysqli_fetch_assoc($res);

if (!$bank) die(lang('@Bank entry not found.'));

$bank_date   = $bank['trans_date'];
$bank_amount = (float)$bank['amount'];
$bank_text   = mysqli_real_escape_string($conn, $bank['text_val']);

mysqli_begin_transaction($conn);

try {
    // SCENARIE A: MATCH MOD FAKTURA (INDGÅENDE)
    if ($target_id > 0) {
        // 1. Marker faktura som betalt
        mysqli_query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $target_id");

        // 2. Bogfør selve indbetalingen på Bank (Konto 1000)
        $txt = lang('@Payment inv. #') . $target_id . ": " . $bank_text;
        mysqli_query($conn, "INSERT INTO ledger (entry_date, entry_text, acc_id, amount) 
                             VALUES ('$bank_date', '$txt', 1000, $bank_amount)");

        // 3. HÅNDTER GEBYR
        if ($fee_amount > 0) {
            $fee_txt = lang('@Payment fee re: inv. #') . $target_id . " ($bank_text)";
            // Debet Gebyrkonto (Omkostning stiger)
            mysqli_query($conn, "INSERT INTO ledger (entry_date, entry_text, acc_id, amount) 
                                 VALUES ('$bank_date', '$fee_txt', $fee_acc_id, $fee_amount)");
            // Kredit Bank (Vi trækker gebyret fra bank-saldoen i regnskabet)
            mysqli_query($conn, "INSERT INTO ledger (entry_date, entry_text, acc_id, amount) 
                                 VALUES ('$bank_date', '$fee_txt', 1000, " . ($fee_amount * -1) . ")");
        }
    } 
    // SCENARIE B: DIREKTE BOGFØRING (UDGÅENDE/ANDET)
    elseif ($acc_id > 0) {
        $txt = lang('@Bank entry:') . " " . $bank_text;
        mysqli_query($conn, "INSERT INTO ledger (entry_date, entry_text, acc_id, amount) 
                             VALUES ('$bank_date', '$txt', $acc_id, $bank_amount)");
    }

    // 4. Marker som behandlet
    mysqli_query($conn, "UPDATE bank_statement_temp SET is_processed = 1 WHERE tmp_id = $tmp_id");

    mysqli_commit($conn);
    header("Location: reconcile_list.page.php?msg=success");

} catch (Exception $e) {
    mysqli_rollback($conn);
    die(lang('@Error:') . " " . $e->getMessage());
}

ob_end_flush();