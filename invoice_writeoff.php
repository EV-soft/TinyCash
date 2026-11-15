<?php # /invoice_writeoff.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): der var
# ingen formel måde at afskrive en uerholdelig faktura som et reelt tab - kun
# "marker betalt" (invoice_mark_paid.php, forudsætter en reel indbetaling)
# eller kreditnota (invoice_credit.php, som reelt FORTRYDER selve salget/
# omsætningen). Et tab på en debitor er bogføringsmæssigt noget helt tredje:
# leverancen var reel og omsætningen skal blive stående - kun det udestående
# beløb opgives som uerholdeligt.
#
# Genbruger BEVIDST invoice_payments/status-flip-mønsteret fra
# reconcile_one_bank_line() (inc/reconcile.inc.php) - en "betaling" logges så
# aging_report.php/reminders.php/subledger_reconciliation.php automatisk ser
# fakturaen som afsluttet, uden nogen særbehandling andre steder i appen.
# Kan IKKE genbruge selve reconcile_one_bank_line()-funktionen direkte: den
# debiterer altid conf_acc_bank internt (uafhængigt af sin $acc_id-parameter,
# som kun bruges i et andet scenarie) - her skal der debiteres kontoen for
# "Tab på debitorer" (7100), IKKE banken, da der reelt ikke kommer nogen
# indbetaling. Egen, dedikeret posteringslogik derfor nødvendig.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($inv_id <= 0) { redirect("sales_hub.php"); }

$inv = DB::fetch_assoc(DB::query($conn,
    "SELECT i.*, c.cust_name FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id"));
if (!$inv) { redirect("sales_hub.php"); }

// 'sent' - eller en krediteret faktura der stadig skylder et bogført rykkergebyr (se invoice_open_dkk())
$wo_credited = strtolower($inv['inv_status']) === 'credited' && empty($inv['credit_ref']) && invoice_open_dkk($conn, $inv_id) > 0.01;
if (strtolower($inv['inv_status']) !== 'sent' && !$wo_credited) {
    redirect("invoice_view.php?id=$inv_id", '@This invoice cannot be written off in its current status.', 'error');
}

$totals = invoice_dkk_totals($conn, $inv_id);
$paid_row = DB::fetch_assoc(DB::query($conn, "SELECT COALESCE(SUM(amount),0) AS paid FROM invoice_payments WHERE inv_id = $inv_id"));
$remaining = invoice_open_dkk($conn, $inv_id);      // (total + delvis kreditnota - betalinger)

$err = '';
if ($remaining <= 0.01) {
    $err = lang('@Nothing to write off - this invoice is already fully paid.');
}

// Findes "Tab på debitorer"-kontoen (db-setup/migrate_bad_debt_account.php)?
// Samme "stille degradering hvis ikke migreret endnu"-mønster som andre
// nyere posteringskonti (fx conf_acc_fx, §currency-gainloss-feature).
$acc_bad_debt = (int)($global_settings['conf_acc_bad_debt'] ?? 7100);
$acc_exists = tc_account_ok($conn, $acc_bad_debt);
if (!$acc_exists) {
    $err = lang('@The "Bad Debt Loss" account has not been set up yet. Run the migration under System -> Maintenance -> Database migration.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_writeoff']) && $err === '') {
    $wo_date = $_POST['writeoff_date'] ?? date('Y-m-d');
    $wo_note = trim($_POST['writeoff_note'] ?? '');
    $wo_vat_reg = !empty($_POST['writeoff_vat']);      // (konstateret tab: salgsmomsen af det tabte reguleres - momsloven § 27, stk. 6)

    if (!tc_is_date($wo_date)) {
        $err = lang('@Please enter a valid date.');
    } elseif ($wo_date < substr((string)$inv['inv_date'], 0, 10) || $wo_date > date('Y-m-d')) {
        // RETTET: tabet kunne dateres før fakturaen eller ud i fremtiden (tab i en periode hvor fordringen ikke fandtes)
        $err = lang('@The write-off date must be between the invoice date and today.');
    } elseif (is_date_locked($conn, $wo_date)) {
        $err = lang('@Write-off date is in a locked accounting period.');
    } else {
        DB::begin_transaction($conn);
        try {
            // Genindlæs restbeløbet inde i transaktionen - forhindrer et kapløb
            // mod en samtidig delvis betaling (fx via bankafstemning) der kunne
            // nedbringe restbeløbet mellem sidevisningen og selve afskrivningen.
            // Samme atomiske "claim"-tankegang som reconcile_one_bank_line()/
            // reminder_action.php's egne kapløbslukninger denne session.
            $cur_paid_row = DB::fetch_assoc(DB::query($conn, "SELECT COALESCE(SUM(amount),0) AS paid FROM invoice_payments WHERE inv_id = $inv_id"));
            $cur_remaining = invoice_open_dkk($conn, $inv_id);
            if ($cur_remaining <= 0.01) {
                throw new Exception(lang('@Nothing to write off - this invoice is already fully paid.'));
            }

            // (en krediteret faktura forbliver 'credited' - kun 'sent' bliver 'paid'; restbeløbet ovenfor er genlæst i transaktionen)
            if (!$wo_credited) {
                $upd_result = DB::query($conn, "UPDATE invoices SET inv_status = 'paid' WHERE inv_id = $inv_id AND inv_status = 'sent'");
                if (!$upd_result || DB::affected_rows($conn, $upd_result) < 1) {
                    throw new Exception(lang('@This invoice was already updated by another, concurrent request.'));
                }
            }
            // Momsdelen af det tabte: fakturaens momsandel af varebeløbet (rykkergebyrer er momsfri og regnes fra først)
            $wo_vat = 0.0;
            if ($wo_vat_reg) {
                $wo_tot   = invoice_dkk_totals($conn, $inv_id);
                $wo_ratio = $wo_tot['incl'] > 0 ? $wo_tot['vat'] / $wo_tot['incl'] : 0.0;
                $wo_goods = max(0.0, $cur_remaining - invoice_fees_dkk($conn, $inv_id));
                $wo_vat   = round(min($wo_goods, $cur_remaining) * $wo_ratio, 2);
            }

            DB::query($conn, "INSERT INTO invoice_payments (inv_id, payment_date, amount, note, created_by)
                VALUES ($inv_id, '" . DB::escape($conn, $wo_date) . "', $cur_remaining, '" . DB::escape($conn, lang('@Written off as bad debt') . ($wo_note !== '' ? ' - ' . $wo_note : '')) . "', " . (int)($_SESSION['user_id'] ?? 0) . ")");

            $acc_debitor = (int)($global_settings['conf_acc_debitor'] ?? 8100);
            $wo_voucher_no = next_voucher_no($conn);
            $jou_text = DB::escape($conn, sprintf(lang('@Bad debt write-off for invoice #%s: %s'), $inv['invoice_no'] ?: $inv_id, $inv['cust_name']));
            $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
            // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
            $cb = journal_created_by_sql($conn);
            DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
                VALUES ('" . DB::escape($conn, $wo_date) . "', '$jou_text', 'bad_debt_writeoff', $wo_voucher_no, '$jou_currency'{$cb['val']})");
            $jou_id = DB::insert_id($conn);

            // DEBET Tab på debitorer (udgift) + KREDIT debitor (nedbringer
            // tilgodehavendet) - samme retning som en normal betaling, men mod
            // tabskontoen i stedet for banken, da der reelt ikke modtages penge.
            ledger_post($conn, $jou_id, $acc_bad_debt, round($cur_remaining - $wo_vat, 2));
            // (DEBET salgsmoms: momsen af tabet - på den momskonto fakturaen blev bogført på; en OSS-/IOSS-fakturas moms
            // står på ordningens egen konto og blev aldrig en del af den hjemlige salgsmoms)
            $wo_vat_acc = (int)($global_settings['conf_acc_vat'] ?? 6900);
            if (!empty($inv['inv_oss']))      $wo_vat_acc = oss_accounts($conn)[1];
            elseif (!empty($inv['inv_ioss'])) $wo_vat_acc = ioss_accounts($conn)[1];
            if ($wo_vat != 0) ledger_post($conn, $jou_id, $wo_vat_acc, $wo_vat);
            ledger_post($conn, $jou_id, $acc_debitor, $cur_remaining * -1);

            log_action($conn, 'WRITE_OFF_INVOICE', 'invoices', $inv_id,
                ['status' => strtolower((string)$inv['inv_status']), 'remaining' => $cur_remaining],      // (stod altid 'sent' - også for en krediteret faktura med et skyldigt gebyr)
                ['status' => $wo_credited ? 'credited' : 'paid', 'writeoff_amount' => $cur_remaining, 'vat_regulated' => $wo_vat, 'writeoff_date' => $wo_date, 'note' => $wo_note]);

            DB::commit($conn);
            redirect("invoice_view.php?id=$inv_id", '@Invoice written off as bad debt successfully.');

        } catch (Exception $e) {
            DB::rollback($conn);
            $err = lang('@Error writing off invoice:') . ' ' . htmlspecialchars($e->getMessage());
        }
    }
}

htm_Header(lang('@Write Off as Bad Debt'));
showMenu();

$tools = htm_Button('fa-times', '@Cancel', 'secondary', 'invoice_view.php?id='.$inv_id, '', 'data-hint="'.lang('@Discard and return to the invoice').'"', '', false);
htm_Card_(lang('@Write Off as Bad Debt') . ' — ' . lang('@Invoice') . ' #' . ($inv['invoice_no'] ?? $inv_id), 600, '', '', true, $tools);

if ($err !== '') htm_Alert($err, 'error');

$writeoff_notice = '<strong><i class="fa fa-info-circle"></i> ' . lang('@What happens when you write off this invoice:') . '</strong><ul style="margin:8px 0 0 0; padding-left:20px; line-height:1.8;">'
    . '<li>' . lang('@The invoice is marked as settled - the sale/revenue itself is NOT reversed') . '</li>'
    . '<li>' . lang('@The outstanding amount is posted as a bad debt loss, reducing the receivable') . '</li>'
    . '<li>' . lang('@This cannot be undone from the UI - use a credit note instead if the sale itself was wrong') . '</li>'
    . '<li>' . lang(tc_country() === 'dk' ? '@With the box below ticked, the sales VAT on the lost amount is posted back (the Danish VAT Act section 27(6)) - untick it if the loss is not yet established' : '@With the box below ticked, the sales VAT on the lost amount is posted back - untick it if the loss is not yet established') . '</li>'      // (sagde at momsreglerne IKKE anvendes - feltet herunder gør netop det)
    . '</ul>';
htm_Banner($writeoff_notice, 'warning');

if ($remaining > 0.01 && $acc_exists) {
    htm_Note(sprintf(lang('@Outstanding amount to be written off: %s.'), fmt_dk($remaining) . ' ' . ($global_settings['currency'] ?? 'DKK')), 'font-size:0.9em;');

    echo '<form method="POST">';
    csrf_field();
    // (efter en fejl beholdes det indtastede - før stod dato, begrundelse og momsvalget nulstillet)
    $wo_posted = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_writeoff']));
    htm_Field(icon:'fa-calendar', labl:'@Write-off date', name:'writeoff_date', valu:(string)($_POST['writeoff_date'] ?? date('Y-m-d')), type:'date', echo:true);
    htm_Field(icon:'fa-comment', labl:'@Reason (optional)', name:'writeoff_note', valu:(string)($_POST['writeoff_note'] ?? ''), type:'text', hint:'@E.g. "customer bankrupt" or "debt collection unsuccessful"', echo:true);
    echo '<label style="display:block; margin:8px 0;" data-hint="' . htmlspecialchars(lang(tc_country() === 'dk' ? '@When a loss on a customer is established, the sales VAT on the lost amount may be reclaimed (the Danish VAT Act section 27(6)). The VAT part is posted back on the sales VAT account and only the rest is a loss.' : '@When a loss on a customer is established, the sales VAT on the lost amount may be reclaimed. The VAT part is posted back on the sales VAT account and only the rest is a loss.')) . '"><input type="checkbox" name="writeoff_vat" value="1"' . ($wo_posted && empty($_POST['writeoff_vat']) ? '' : ' checked') . '> '
       . lang('@Reclaim the sales VAT on the lost amount') . '</label>';

    echo '<div style="margin-top:20px; display:flex; gap:10px;">';
    echo '<button type="submit" name="confirm_writeoff" value="1" style="flex:1; background:var(--color-danger); color:white; border:none; padding:12px; border-radius:4px; font-weight:bold; cursor:pointer;"><i class="fa fa-file-invoice-dollar"></i> ' . lang('@Confirm Write-Off') . '</button>';
    echo '</div>';
    echo '</form>';
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
