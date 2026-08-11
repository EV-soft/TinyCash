<?php # /expense_actions.php v:1.2.0 d:2026-07-13 i:claude (Porteret fra rå mysqli til DB::-abstraktionen - virker nu på både SQLite og MySQL)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php'; // Sikrer adgang til lang()
require_once 'inc/audit.inc.php';    // SIKRER ADGANG TIL log_action()

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Tving scriptet til KUN at køre, hvis parametrene er 100% korrekte
if ($action !== 'delete' || $id <= 0) {
    header("Location: expense_list.php");
    exit;
}

$current_user_id = (int)($_SESSION['user_id'] ?? 1);

DB::begin_transaction($conn);

// 1. HENT VOUCHER_NO OG FILNAVN FØR HANDLING
$voucher_no = null;
$filename = "";
$is_already_cancelled = 0;

$stmt = DB::prepare_and_execute($conn, "SELECT voucher_no, attachment, is_cancelled FROM expenses WHERE exp_id = ?", [$id]);
if ($stmt) {
    $expense = DB::fetch_assoc($stmt);
    if ($expense) {
        $voucher_no = !empty($expense['voucher_no']) ? $expense['voucher_no'] : null;
        $filename = $expense['attachment'];
        $is_already_cancelled = (int)$expense['is_cancelled'];
    }
}

// Hvis posten ikke findes, eller den allerede ER annulleret, afbryd
if ($is_already_cancelled === 1) {
    DB::rollback($conn);
    header("Location: expense_list.php");
    exit;
}

// 2. SCENARIEOPDELING BASERET PÅ BOGFØRINGSSTATUS
if ($voucher_no !== null) {
    // SCENARIE A: Posten ER bogført -> Lav Soft-delete (Bevar fil og revisionsspor)
    $success = DB::prepare_and_execute($conn, "UPDATE expenses SET is_cancelled = 1, cancelled_by = ? WHERE exp_id = ?", [$current_user_id, $id]);

    if ($success) {
        // REVISIONSLOG FOR ANNULLERING
        log_action($conn, 'CANCEL_EXPENSE', 'expenses', $id, ['is_cancelled' => 0], ['is_cancelled' => 1]);

        // Synkroniser finansjournalen
        DB::prepare_and_execute($conn, "UPDATE journal SET is_cancelled = 1 WHERE voucher_no = ?", [$voucher_no]);

        DB::commit($conn);
        header("Location: expense_list.php?msg=deleted");
        exit;
    } else {
        DB::rollback($conn);
        die(lang('@Error marking cancellation in database:') . " " . DB::error($conn));
    }
} else {
    // SCENARIE B: Posten er en ubehandlet kladde -> Slet permanent (Fjern fil og DB-række)
    $success = DB::prepare_and_execute($conn, "DELETE FROM expenses WHERE exp_id = ?", [$id]);

    if ($success) {
        // REVISIONSLOG FOR SLETNING AF KLADDE (Før commit)
        log_action($conn, 'DELETE_DRAFT', 'expenses', $id, ['is_draft' => 1], null);

        DB::commit($conn);

        // Fysisk rengøring på disken, da kladden aldrig har været en del af regnskabet
        if (!empty($filename)) {
            $file_path = __DIR__ . '/uploads/' . basename($filename);
            if (file_exists($file_path) && is_file($file_path)) {
                @unlink($file_path);
            }
        }
        header("Location: expense_list.php?msg=deleted");
        exit;
    } else {
        DB::rollback($conn);
        die(lang('@Error deleting draft from database:') . " " . DB::error($conn));
    }
}

ob_end_flush();
?>
