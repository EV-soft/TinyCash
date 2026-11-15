<?php # /invoice_mark_paid.php v:1.0.0 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): der var
# INGEN måde at markere en faktura som betalt, andet end via
# bankafstemning (reconcile_list.php) - bekræftet ved en fuld gennemsøgning:
# reconcile_action.php er det ENESTE sted i hele kodebasen der nogensinde
# indsætter i invoice_payments. En kunde der betaler kontant, via MobilePay
# uden for banken, eller på en måde der først dukker op på et senere
# bankudtog, kunne derfor ALDRIG markeres betalt før det rigtige
# bankudtog blev importeret - fakturaen stod som "sendt" på ubestemt tid.
#
# Genbruger BEVIDST den allerede grundigt afprøvede reconcile_one_bank_line()
# (reconcile_action.php) i stedet for at genopfinde posterings-/delvis-
# betalings-/kapløbslogikken et andet sted - opretter en "syntetisk"
# bank_statement_temp-række (import_source='manual', så den altid kan
# skelnes fra en rigtig bankimport i historikken) og afstemmer den
# øjeblikkeligt mod fakturaen via nøjagtig samme funktion som
# reconcile_list.php selv bruger. Samme konto (conf_acc_bank) som en
# almindelig bankafstemning - dette er bevidst IKKE en "vælg hvilken konto"-
# funktion, kun en genvej til at registrere en betaling der reelt skete,
# uden at vente på at den dukker op i en bankimport. FX-lukning
# ($close_fx_invoice) understøttes bevidst ikke her - kun almindeligt
# beløbsmatch, samme som reconcile_list.php's egen standardvej.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/reconcile.inc.php'; // definerer reconcile_one_bank_line() - se dens egen header for hvorfor den bor i inc/ og ikke i selve reconcile_action.php

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($inv_id <= 0) { redirect("sales_hub.php"); }

$inv = DB::fetch_assoc(DB::query($conn,
    "SELECT i.*, c.cust_name FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id"));
if (!$inv) { redirect("sales_hub.php"); }

// 'sent' - eller en krediteret faktura der stadig skylder noget (et bogført rykkergebyr, se invoice_open_dkk())
if (strtolower($inv['inv_status']) !== 'sent' && !(strtolower($inv['inv_status']) === 'credited' && empty($inv['credit_ref']) && invoice_open_dkk($conn, $inv_id) > 0.01)) {
    redirect("invoice_view.php?id=$inv_id", '@This invoice cannot be manually marked as paid in its current status.', 'error');
}

$totals = invoice_dkk_totals($conn, $inv_id);
$paid_row = DB::fetch_assoc(DB::query($conn, "SELECT COALESCE(SUM(amount),0) AS paid FROM invoice_payments WHERE inv_id = $inv_id"));
$remaining = invoice_open_dkk($conn, $inv_id);      // (total + delvis kreditnota - betalinger)

$err = '';

// Bank- og kassekonti, som betalingen kan gå ind på (som bankafstemningen: inc/reconcile.inc.php, tc_bank_line_account())
require_once 'inc/accounts.inc.php';
$mp_s = get_settings($conn);
$mp_bank_accounts = [];
$mp_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE acc_type = 'bank' AND " . acc_visible_sql($conn, '', [(int)($mp_s['conf_acc_bank'] ?? 0)]) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
while ($mp_res && ($mr = DB::fetch_assoc($mp_res))) $mp_bank_accounts[(int)$mr['acc_id']] = $mr['acc_id'] . ' - ' . acc_label($mr['acc_name'], $mr['acc_status'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $pay_date = $_POST['pay_date'] ?? date('Y-m-d');
    $pay_amount = parse_dk_number($_POST['pay_amount'] ?? '0');
    $pay_note = trim($_POST['pay_note'] ?? '');
    // Kontant- og MobilePay-betalinger går ikke ind på bankkontoen - kontoen vælges (bank/kasse); tom = standard-bankkontoen
    $pay_acc = (int)($_POST['pay_acc'] ?? 0);
    if ($pay_acc > 0 && !isset($mp_bank_accounts[$pay_acc])) $pay_acc = 0;

    if (!tc_is_date($pay_date)) {
        $err = lang('@Please enter a valid date.');
    } elseif ($pay_amount <= 0 || tc_num($_POST['pay_amount'] ?? '', 0.01) === null) {      // ("1.250 kr" blev registreret som 1,25)
        $err = lang('@Please enter a valid amount.');
    // RETTET: ethvert beløb og enhver dato blev taget imod - en tastefejl (12.500 i stedet for 1.250) markerede
    // fakturaen betalt og efterlod kunden med et tilgodehavende på 11.250, og en betaling kunne dateres før
    // fakturaen eller ud i fremtiden. En reel overbetaling kommer ind via bankafstemningen.
    } elseif ($pay_amount > $remaining + 0.01) {
        $err = sprintf(lang('@The amount is more than what is due (%s). Register an overpayment through the bank reconciliation.'), fmt_dk($remaining));
    } elseif ($pay_date < substr((string)$inv['inv_date'], 0, 10) || $pay_date > date('Y-m-d')) {
        $err = lang('@The payment date cannot be before the invoice date or in the future.');
    } else {
        $text_val = lang('@Manual payment') . ($pay_note !== '' ? ' - ' . $pay_note : '') . ' (' . $inv['cust_name'] . ')';
        DB::query($conn, "INSERT INTO bank_statement_temp (trans_date, text_val, amount, fee_amount, import_source, is_processed, acc_id)
            VALUES ('" . DB::escape($conn, $pay_date) . "', '" . DB::escape($conn, $text_val) . "', $pay_amount, 0, 'manual', 0, " . ($pay_acc > 0 ? $pay_acc : 'NULL') . ")");
        $tmp_id = DB::insert_id($conn);

        $s = get_settings($conn);
        $result = reconcile_one_bank_line($conn, $s, $tmp_id, $inv_id, 0, 0.0, 0, 0, false);

        if (!empty($result['ok'])) {
            redirect("invoice_view.php?id=$inv_id", '@Payment registered successfully.');
        } else {
            $err = $result['message'] ?? lang('@Error registering payment.');
            // Fejlet afstemning - fjern den syntetiske banklinje igen, ellers
            // ville den stå tilbage uafstemt i reconcile_list.php's liste og
            // forvirre brugeren senere.
            DB::query($conn, "DELETE FROM bank_statement_temp WHERE tmp_id = $tmp_id");
        }
    }
}

htm_Header(lang('@Register Payment'));
showMenu();

$tools = htm_Button('fa-times', '@Cancel', 'secondary', 'invoice_view.php?id='.$inv_id, '', 'data-hint="'.lang('@Discard and return to the invoice').'"', '', false);
htm_Card_(lang('@Register Payment') . ' — ' . lang('@Invoice') . ' #' . ($inv['invoice_no'] ?? $inv_id), 600, '', '', true, $tools);

if ($err !== '') htm_Alert($err, 'error');

htm_Note(sprintf(lang('@For payments that did not come through a bank import (cash, MobilePay, etc.) - amount due: %s.'), fmt_dk($remaining) . ' ' . ($global_settings['currency'] ?? 'DKK')), 'font-size:0.9em;');

echo '<form method="POST">';
csrf_field();
// (efter en fejl beholdes det indtastede - før stod dato, beløb og note nulstillet)
htm_Field(icon:'fa-calendar', labl:'@Payment date', name:'pay_date', valu:(string)($_POST['pay_date'] ?? date('Y-m-d')), type:'date', echo:true);
htm_Field(icon:'fa-coins', labl:'@Amount', name:'pay_amount', valu:(string)($_POST['pay_amount'] ?? fmt_dk_input($remaining)), type:'text', hint:'@Defaults to the full amount due - lower it for a partial payment.', echo:true);
if (count($mp_bank_accounts) > 1) {
    htm_Field(icon:'fa-building-columns', labl:'@Paid into account', name:'pay_acc', valu:(string)(int)($_POST['pay_acc'] ?? ($mp_s['conf_acc_bank'] ?? 0)), type:'sele', opti:$mp_bank_accounts, hint:'@The bank or cash account the money was paid into - e.g. the cash account for a cash payment.', echo:true);
}
htm_Field(icon:'fa-comment', labl:'@Note (optional)', name:'pay_note', valu:(string)($_POST['pay_note'] ?? ''), type:'text', echo:true);

echo '<div style="margin-top:20px; display:flex; gap:10px;">';
echo '<button type="submit" name="confirm_payment" value="1" style="flex:1; background:var(--color-success); color:white; border:none; padding:12px; border-radius:4px; font-weight:bold; cursor:pointer;"><i class="fa fa-check"></i> ' . lang('@Register Payment') . '</button>';
echo '</div>';
echo '</form>';

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
