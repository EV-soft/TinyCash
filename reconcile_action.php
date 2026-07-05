<?php # /reconcile_action.php v:1.1.0 d:2026-07-05 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

$tmp_id     = isset($_POST['tmp_id']) ? (int)$_POST['tmp_id'] : 0;
$target_id  = isset($_POST['target_id']) ? (int)$_POST['target_id'] : 0; 
$acc_id     = isset($_POST['acc_id']) ? (int)$_POST['acc_id'] : 0;       
$fee_amount = isset($_POST['fee_amount']) ? (float)$_POST['fee_amount'] : 0;
$fee_acc_id = isset($_POST['fee_acc_id']) ? (int)$_POST['fee_acc_id'] : 2320; 

if ($tmp_id === 0) die(lang('@No transaction selected.'));

$res = DB::query($conn, "SELECT * FROM bank_statement_temp WHERE tmp_id = $tmp_id");
$bank = DB::fetch_assoc($res);
if (!$bank) die(lang('@Bank entry not found.'));

$bank_date   = $bank['trans_date'];
$bank_amount = (float)$bank['amount'];
$bank_text   = DB::real_escape_string($conn, $bank['text_val']);

DB::begin_transaction($conn);

try {
    // --- 1. OPRET JOURNAL POST (Hovedbilaget) ---
    $journal_text = ($target_id > 0) ? lang('@Payment inv. #') . $target_id : lang('@Bank entry:') . " " . $bank_text;
    DB::query($conn, "INSERT INTO journal (jou_date, jou_text) VALUES ('$bank_date', '$journal_text')");
    $jou_id = DB::insert_id($conn);

    // --- 2. BOGFØR SELVE TRANSAKTIONEN ---
    if ($target_id > 0) {
        // Scenarie A: Faktura betalt
        DB::query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $target_id");
        
        // Bank (Debet/Kredit alt efter fortegn) på konto 1000
        DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount) VALUES ($jou_id, 1000, $bank_amount)");
        
        // Modpost: Her burde du teknisk set bogføre mod debitor (f.eks. konto 5000), 
        // men i dette simple setup bogfører vi bankposten direkte.
        
        // HÅNDTER GEBYR (Kræver sin egen journalpost eller ekstra linjer)
        if ($fee_amount > 0) {
            // Vi tilføjer gebyret til samme journal-ID
            // Gebyrkonto (Omkostning - Debet)
            DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount) VALUES ($jou_id, $fee_acc_id, $fee_amount)");
            // Bank modregning (Kredit)
            DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount) VALUES ($jou_id, 1000, " . ($fee_amount * -1) . ")");
        }
    } elseif ($acc_id > 0) {
        // Scenarie B: Direkte bogføring på valgt konto
        DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount) VALUES ($jou_id, $acc_id, $bank_amount)");
        
        // Modpost til bank (1000) for at balancere
        DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount) VALUES ($jou_id, 1000, " . ($bank_amount * -1) . ")");
    }

    // --- 3. MARKER SOM BEHANDLET ---
    DB::query($conn, "UPDATE bank_statement_temp SET is_processed = 1 WHERE tmp_id = $tmp_id");

    DB::commit($conn);
    header("Location: reconcile_list.page.php?msg=success");

} catch (Exception $e) {
    DB::rollback($conn);
    die(lang('@Error:') . " " . $e->getMessage());
}

ob_end_flush(); 
?>