<?php # /invoice_post_action.php v:1.0.0 d:2026-06-15 i:evs
// Deaktiver visuelle fejlmeddelelser der kan ødelægge JSON-outputtet
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';

header('Content-Type: application/json; charset=utf-8');

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($inv_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ugyldigt ID-parameter']);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Databaseforbindelse mistet']);
    exit;
}

// Hent dynamiske kontonumre fra dine regnskabsindstillinger (med nødbremse/fallback)
$acc_bank    = isset($CONF['conf_acc_bank']) ? (int)$CONF['conf_acc_bank'] : 5000;
$acc_debitor = isset($CONF['conf_acc_debitor']) ? (int)$CONF['conf_acc_debitor'] : 8100;

mysqli_begin_transaction($conn);

try {
    // 1. SIKRET: Tjek nuværende status samt hent beløb og dato til finanspostering
    $stmt_check = $conn->prepare("SELECT inv_status, inv_date, total_amount FROM invoices WHERE inv_id = ?");
    $stmt_check->bind_param("i", $inv_id);
    $stmt_check->execute();
    $res = $stmt_check->get_result();
    $row = $res->fetch_assoc();
    $stmt_check->close();

    if (!$row) {
        throw new Exception('Fakturaen blev ikke fundet i systemet');
    }

    if ($row['inv_status'] !== 'draft') {
        throw new Exception('Fakturaen er allerede bogført (Status: ' . $row['inv_status'] . ')');
    }

    $inv_date   = $row['inv_date'];
    $inv_amount = (float)$row['total_amount'];

    // 2. Find det næste ledige fakturanummer
    $num_res = mysqli_query($conn, "SELECT MAX(CAST(invoice_no AS UNSIGNED)) as max_no FROM invoices");
    if (!$num_res) {
        throw new Exception('Kunne ikke generere nyt fakturanummer');
    }
    $num_row = mysqli_fetch_assoc($num_res);
    $next_invoice_no = ($num_row['max_no'] > 0) ? $num_row['max_no'] + 1 : 1001; 

    // 3. SIKRET: Opdater fakturaen med nummer og ændr status til 'sent'
    $stmt_update = $conn->prepare("UPDATE invoices SET invoice_no = ?, inv_status = 'sent' WHERE inv_id = ?");
    $stmt_update->bind_param("si", $next_invoice_no, $inv_id);
    $stmt_update->execute();
    $stmt_update->close();

    // 4. FINANSBOGFØRING (Dobbelt bogholderi jf. Bogføringsloven)
    $journal_text = "Bogført salgsfaktura #" . $next_invoice_no;
    
    // Opret Hovedbilag i Journalen
    $stmt_jou = $conn->prepare("INSERT INTO journal (jou_date, jou_text, voucher_no) VALUES (?, ?, ?)");
    $stmt_jou->bind_param("sss", $inv_date, $journal_text, $next_invoice_no);
    $stmt_jou->execute();
    $jou_id = $conn->insert_id;
    $stmt_jou->close();

    // Præparer linjer til Ledger
    $stmt_ledger = $conn->prepare("INSERT INTO ledger (jou_id, acc_id, amount) VALUES (?, ?, ?)");

    // Linje 1: DEBET Debitor/Tilgodehavender (Øger aktivet, f.eks. konto 8100)
    $stmt_ledger->bind_param("iid", $jou_id, $acc_debitor, $inv_amount);
    $stmt_ledger->execute();

    // Linje 2: KREDIT Varesalg/Omsætning (Konto 1000 - Modpost der balancerer regnskabet til 0)
    $acc_sales = 1000;
    $sales_amount = $inv_amount * -1;
    $stmt_ledger->bind_param("iid", $jou_id, $acc_sales, $sales_amount);
    $stmt_ledger->execute();

    $stmt_ledger->close();

    // Log handlingen i revisionssporet
    log_action($conn, 'POST_INVOICE', 'invoices', $inv_id, ['status' => 'draft'], ['status' => 'sent', 'invoice_no' => $next_invoice_no]);

    // Alt gik godt -> Gem ændringer i databasen permanent
    mysqli_commit($conn);
    echo json_encode(['success' => true, 'invoice_no' => $next_invoice_no]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
?>