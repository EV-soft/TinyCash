<?php # /expense_actions.php v:1.3.0 d:2026-08-30 i:evs
# POST-handler for sletning/annullering af en udgift (kaldt fra expense_list.php).
# En ikke-bogført udgift slettes normalt. En allerede bogført udgift
# (voucher_no sat) kan ikke slettes direkte - i stedet posteres en fuld
# modpostering (samme jou_id's ledger-linjer, negeret beløb) med sit eget
# nye bilagsnummer, og selve udgiften markeres is_cancelled=1. Uden denne
# modpostering ville en annullering ikke have nogen reel effekt i regnskabet,
# da rapporter/årsafslutning bevidst ikke filtrerer på is_cancelled. Bruger
# DB::-abstraktionen, så virker på både SQLite og MySQL.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php'; // Sikrer adgang til lang()
require_once 'inc/audit.inc.php';    // SIKRER ADGANG TIL log_action()

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Tving scriptet til KUN at køre, hvis parametrene er 100% korrekte
if (!in_array($action, ['delete', 'mark_paid'], true) || $id <= 0) {
    header("Location: expense_list.php");
    exit;
}

// NYT (leverandørmodul, se db-setup/migrate_suppliers.php): bogfører den
// faktiske betaling af en tidligere "Ikke betalt endnu"-udgift - krediterer
// banken og debiterer den samme kreditor-/gældskonto den oprindelige
// postering krediterede, så saldoen på gældskontoen igen går i nul for
// denne postering. Egen, selvstændig journalpostering med sit eget
// bilagsnummer (samme princip som annulleringens modpostering ovenfor) -
// ALDRIG en ændring af den oprindelige, allerede bogførte postering.
if ($action === 'mark_paid') {
    $exp = DB::fetch_assoc(DB::query($conn, "SELECT * FROM expenses WHERE exp_id = $id AND is_cancelled = 0"));
    if (!$exp || empty($exp['voucher_no']) || $exp['exp_type'] !== 'expense') {
        header("Location: expense_list.php");
        exit;
    }

    $today = date('Y-m-d');
    if (is_date_locked($conn, $today)) {
        die(lang('@This transaction date is in a locked accounting period and cannot be posted.'));
    }

    DB::begin_transaction($conn);

    // Atomisk claim - se [[bugs-batch-19-review]] for baggrunden om hvorfor
    // en efterfølgende SELECT ikke er nok: kun den forespørgsel der reelt
    // ændrer paid_date fra NULL, må fortsætte til selve betalingsposteringen.
    $claim = DB::prepare_and_execute($conn, "UPDATE expenses SET paid_date = ? WHERE exp_id = ? AND paid_date IS NULL", [$today, $id]);
    if (!$claim || DB::affected_rows($conn, $claim) < 1) {
        DB::rollback($conn);
        header("Location: expense_list.php?msg=already_paid");
        exit;
    }

    $s          = get_settings($conn);
    $acc_bank   = (int)($s['conf_acc_bank']     ?? 5000);
    $acc_credit = (int)($s['conf_acc_creditor'] ?? 4000);
    $gross      = (float)$exp['amount'];

    $pay_voucher = next_voucher_no($conn);
    $pay_text    = DB::escape($conn, "Betaling af udgift #$id (bilag #{$exp['voucher_no']}): " . $exp['supplier']);
    // RETTET (§currency-setting-is-cosmetic-label): journal.currency blev
    // aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
    $pay_currency = DB::escape($conn, $s['currency'] ?? 'DKK');
    DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency) VALUES ('$today', '$pay_text', 'expense_payment', $pay_voucher, '$pay_currency')");
    $pay_jou_id = DB::insert_id($conn);

    ledger_post($conn, $pay_jou_id, $acc_credit, $gross);   // DEBET kreditorkonto - gælden udlignes
    ledger_post($conn, $pay_jou_id, $acc_bank,  -$gross);   // KREDIT bank - pengene forlader banken nu

    log_action($conn, 'PAY_EXPENSE', 'expenses', $id, ['paid_date' => null], ['paid_date' => $today, 'voucher_no' => $pay_voucher]);

    DB::commit($conn);
    header("Location: expense_list.php?msg=paid");
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
    // RETTET (§bugs-batch-21-review): denne annullering poster en rigtig,
    // dags-daterede modpostering (linje ~107 nedenfor) til hovedbogen, men
    // var det ENESTE af appens posteringsflow (invoice_post_action.php,
    // invoice_credit.php, reconcile_action.php, expense_edit.php's egen
    // oprettelse har alle tjekket) der aldrig kaldte is_date_locked(). Er
    // periodespærringen sat til og med i dag (fx en streng afslutning midt i
    // måneden), kunne en annullering alligevel poste ind i den låste
    // periode. Tjekkes nu FØR noget som helst skrives.
    if (is_date_locked($conn, date('Y-m-d'))) {
        DB::rollback($conn);
        die(lang('@This transaction date is in a locked accounting period and cannot be posted.'));
    }

    // SCENARIE A: Posten ER bogført -> Lav Soft-delete (Bevar fil og revisionsspor)
    // RETTET (§bugs-batch-19-review): tjekket for "allerede annulleret" ovenfor
    // (linje 45) kører kun ÉN gang tidligt i denne sidevisning - to næsten-
    // samtidige annulleringsforsøg for samme udgift kunne begge bestå det,
    // FØR nogen af dem nåede at skrive, og dermed begge poste en fuld
    // modpostering (dobbelt reversering af samme originale beløb). WHERE-
    // klausulen tjekker nu atomisk is_cancelled=0 ved selve skrivningen, og
    // et 0-rækker-resultat (nogen nåede allerede at annullere) afbryder
    // resten af flowet, i stedet for at lade en dublet-modpostering ske.
    $upd_stmt = DB::prepare_and_execute($conn, "UPDATE expenses SET is_cancelled = 1, cancelled_by = ? WHERE exp_id = ? AND is_cancelled = 0", [$current_user_id, $id]);
    $success  = $upd_stmt && DB::affected_rows($conn, $upd_stmt) > 0;
    if (!$success) {
        // RETTET (§bugs-batch-19-review, opfølgning): den oprindelige udgave
        // af denne rettelse tjekkede kun, OM is_cancelled=1 ved en SELECT
        // bagefter - men det viser blot om SLUTTILSTANDEN er korrekt, ikke om
        // DENNE forespørgsel selv var den, der satte den. To næsten-samtidige
        // forsøg kunne begge se "is_cancelled=1" efter en anden, hurtigere
        // forespørgsels commit, og tabervalgets forsøg ville derfor fejlagtigt
        // tro sin egen annullering lykkedes - og fortsætte til modposteringen
        // nedenfor alligevel (kapløbet var reelt IKKE lukket). Rettet til at
        // måle antal reelt berørte rækker fra selve UPDATE'en (se
        // DB::affected_rows()) - kun den forespørgsel, der faktisk ændrede
        // rækken fra 0 til 1, må fortsætte til modposteringen.
        DB::rollback($conn);
        header("Location: expense_list.php?msg=already_cancelled");
        exit;
    }

    if ($success) {
        // REVISIONSLOG FOR ANNULLERING
        log_action($conn, 'CANCEL_EXPENSE', 'expenses', $id, ['is_cancelled' => 0], ['is_cancelled' => 1]);

        // Synkroniser finansjournalen
        DB::prepare_and_execute($conn, "UPDATE journal SET is_cancelled = 1 WHERE voucher_no = ?", [$voucher_no]);

        // RETTET (ALVORLIGT FUND, se [[bugs-batch-10-review]]): dette var HELE
        // annulleringen - is_cancelled=1 blev sat, men der blev ALDRIG postet
        // en modpostering. Rapporter/årsafslutning (report_income.php,
        // inc/annual_report.lib.php, year_end_close.php) filtrerer BEVIDST
        // ikke på is_cancelled (se deres egne kommentarer) - de regner med at
        // en annullering ALTID ledsages af en rigtig modpostering, der
        // balancerer originalen ud til 0 (præcis mønsteret ledger_view.php's
        // Annullér allerede bruger). Uden den modpostering her forblev den
        // originale udgifts fulde beløb med i alle rapporter for evigt -
        // is_cancelled-flaget havde reelt INGEN regnskabsmæssig effekt.
        $orig_jou = DB::fetch_assoc(DB::query($conn, "SELECT jou_id, jou_date, currency FROM journal WHERE voucher_no = " . (int)$voucher_no . " LIMIT 1"));
        if ($orig_jou) {
            $orig_lines = [];
            $lres = DB::query($conn, "SELECT acc_id, amount FROM ledger WHERE jou_id = " . (int)$orig_jou['jou_id']);
            while ($lr = DB::fetch_assoc($lres)) { $orig_lines[] = $lr; }

            if (!empty($orig_lines)) {
                $cancel_voucher_no = next_voucher_no($conn);
                $cancel_text = DB::escape($conn, "Annullering af udgift #$id (bilag #$voucher_no)");
                // RETTET (§currency-setting-is-cosmetic-label): modposteringen
                // arver nu den oprindelige posterings valuta (i stedet for
                // altid at falde tilbage til skemaets DEFAULT 'DKK'), så en
                // annullering aldrig fejlagtigt skifter valuta undervejs.
                $cancel_currency = DB::escape($conn, $orig_jou['currency'] ?? ($global_settings['currency'] ?? 'DKK'));
                DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency) VALUES ('" . date('Y-m-d') . "', '$cancel_text', 'cancellation', $cancel_voucher_no, '$cancel_currency')");
                $new_jou_id = DB::insert_id($conn);
                foreach ($orig_lines as $ol) {
                    ledger_post($conn, $new_jou_id, (int)$ol['acc_id'], -1 * (float)$ol['amount']);
                }
            }
        }

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
