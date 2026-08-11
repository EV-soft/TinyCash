<?php # /reconcile_action.php v:1.2.0 d:2026-08-11 i:evs 
# (Tilføjet proj_id på journal + auto-expense ved scenarie B)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

$tmp_id     = isset($_POST['tmp_id'])     ? (int)$_POST['tmp_id']     : 0;
$target_id  = isset($_POST['target_id'])  ? (int)$_POST['target_id']  : 0;
$acc_id     = isset($_POST['acc_id'])     ? (int)$_POST['acc_id']     : 0;
$fee_amount = isset($_POST['fee_amount']) ? (float)$_POST['fee_amount'] : 0;
$fee_acc_id = isset($_POST['fee_acc_id']) ? (int)$_POST['fee_acc_id'] : 2320;
$proj_id    = isset($_POST['proj_id'])    ? (int)$_POST['proj_id']    : 0;  // NYT

if ($tmp_id === 0) die(lang('@No transaction selected.'));

$res  = DB::query($conn, "SELECT * FROM bank_statement_temp WHERE tmp_id = $tmp_id");
$bank = DB::fetch_assoc($res);
if (!$bank) die(lang('@Bank entry not found.'));

$bank_date   = $bank['trans_date'];
$bank_amount = (float)$bank['amount'];
$bank_text   = DB::real_escape_string($conn, $bank['text_val']);

// Projekt-felt til SQL — NULL hvis intet valgt
$proj_sql = ($proj_id > 0) ? $proj_id : 'NULL';

// Tjek om projekt-modulet er aktivt (for at afgøre om expense-auto-oprettelse er relevant)
$s = get_settings($conn);
$projects_active = !empty($s['module_projects']) && $s['module_projects'] == '1';

DB::begin_transaction($conn);
try {

    // --- 1. OPRET JOURNAL POST ---
    $journal_text = ($target_id > 0)
        ? lang('@Payment inv. #') . $target_id
        : lang('@Bank entry:') . " " . $bank_text;

    // proj_id gemmes på journal-rækken (altid, uanset scenarie)
    DB::query($conn, "INSERT INTO journal (jou_date, jou_text, proj_id)
                      VALUES ('$bank_date', '$journal_text', $proj_sql)");
    $jou_id = DB::insert_id($conn);

    // --- 2. BOGFØR SELVE TRANSAKTIONEN ---
    if ($target_id > 0) {
        // Scenarie A: Faktura betalt — proj_id ikke relevant her
        // (fakturaen bærer allerede projektinfo via invoice_lines)
        DB::query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $target_id");

        DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                          VALUES ($jou_id, 1000, $bank_amount)");

        if ($fee_amount > 0) {
            DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                              VALUES ($jou_id, $fee_acc_id, $fee_amount)");
            DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                              VALUES ($jou_id, 1000, " . ($fee_amount * -1) . ")");
        }

    } elseif ($acc_id > 0) {
        // Scenarie B: Direkte bogføring (udgift/intern postering)
        DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                          VALUES ($jou_id, $acc_id, $bank_amount)");
        DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                          VALUES ($jou_id, 1000, " . ($bank_amount * -1) . ")");

        // Auto-opret expense-række så udgiften er synlig i udgiftslisten
        // og korrekt knyttet til projektet hvis modulet er aktivt
        $supplier_esc = $bank_text;
        $created_by   = $_SESSION['user_id'] ?? 0;
        $exp_proj_sql = ($projects_active && $proj_id > 0) ? $proj_id : 'NULL';

        DB::query($conn, "INSERT INTO expenses
                            (exp_date, supplier, account_id, amount, description,
                             proj_id,     created_by, created_at)
                          VALUES
                            ('$bank_date', '$supplier_esc', $acc_id, $bank_amount, '$bank_text',
                             $exp_proj_sql, $created_by, CURRENT_TIMESTAMP)");
    }

    // --- 3. MARKER SOM BEHANDLET ---
    DB::query($conn, "UPDATE bank_statement_temp SET is_processed = 1 WHERE tmp_id = $tmp_id");

    DB::commit($conn);
    header("Location: reconcile_list.php?msg=success");

} catch (Exception $e) {
    DB::rollback($conn);
    die(lang('@Error:') . " " . $e->getMessage());
}
ob_end_flush();
?>
