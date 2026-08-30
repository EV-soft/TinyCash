<?php # /bank_integration_sync.php v:1.3.0 d:2026-08-30 i:evs
# v2.0.0: omskrevet mod Enable Banking (se bank_integration.php v2.0.0) -
# felt-navne og pagineringen er forskellige fra GoCardless, se
# inc/enablebanking.lib.php's header-kommentar.
#
# Henter transaktioner for en tilkoblet konto og lægger dem i den
# EKSISTERENDE bank_statement_temp-tabel - reelt et alternativt "import"-
# trin til bank_import_process.php, så resten af bankafstemningsflowet
# (reconcile_list.php/reconcile_action.php) er helt uændret bagefter.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/enablebanking.lib.php';

$conn_id = (int)($_GET['conn_id'] ?? 0);
$bc = $conn_id > 0 ? DB::fetch_assoc(DB::query($conn, "SELECT * FROM bank_connections WHERE conn_id = $conn_id")) : null;

if (!$bc || $bc['status'] !== 'LN' || empty($bc['gc_account_id'])) {
    header("Location: bank_integration.php?msg=error"); exit;
}

// Banker udleverer typisk kun et begrænset antal dage tilbage - 90 dage er
// et sikkert, almindeligt understøttet loft. Bruges også som naturlig "kun
// nye transaktioner"-afgrænsning, da dedupliceringen (raw_hash) alligevel
// forhindrer dubletter, hvis den samme periode synkroniseres flere gange.
$date_from = date('Y-m-d', strtotime('-90 days'));
$date_to   = date('Y-m-d');

$transactions = eb_get_transactions($bc['gc_account_id'], $date_from, $date_to);

$imported = 0;
foreach ($transactions as $t) {
    $tx_id = $t['transaction_id'] ?? $t['entry_reference'] ?? null;
    if (!$tx_id) continue; // ingen stabil id at deduplikere på - spring over frem for at risikere en dublet

    $hash = sha1($bc['gc_account_id'] . '|' . $tx_id);
    $exists = DB::fetch_assoc(DB::query($conn, "SELECT tmp_id FROM bank_statement_temp WHERE raw_hash = '" . DB::escape($conn, $hash) . "'"));
    if ($exists) continue;

    $trans_date = $t['booking_date'] ?? $t['value_date'] ?? $t['transaction_date'] ?? date('Y-m-d');
    $amount     = eb_signed_amount($t); // fortegn ALTID fra credit_debit_indicator, se enablebanking.lib.php

    // remittance_information er et array af tekstlinjer hos Enable Banking
    // (i modsætning til GoCardless' enkeltfelt) - sammensæt til én tekst.
    $remittance = $t['remittance_information'] ?? [];
    $text = is_array($remittance) && !empty($remittance) ? implode(' ', $remittance) : null;
    if (!$text) {
        $party = $t['creditor']['name'] ?? $t['debtor']['name'] ?? null;
        $text  = $party ?: lang('@Bank transaction');
    }

    $text_esc = DB::escape($conn, mb_substr((string)$text, 0, 255));
    $date_esc = DB::escape($conn, $trans_date);
    $hash_esc = DB::escape($conn, $hash);

    DB::query($conn, "INSERT INTO bank_statement_temp (import_source, acc_id, trans_date, text_val, amount, raw_hash)
                      VALUES ('enablebanking', " . (int)$bc['acc_id'] . ", '$date_esc', '$text_esc', $amount, '$hash_esc')");
    $imported++;
}

DB::query($conn, "UPDATE bank_connections SET last_sync_at = " . (DB::is_sqlite() ? "datetime('now')" : "NOW()") . " WHERE conn_id = $conn_id");
log_action($conn, 'SYNC_BANK_TRANSACTIONS', 'bank_connections', $conn_id, null, ['imported' => $imported]);

// Samme redirect-mål som CSV-importen (bank_import_process.php) - de
// hentede transaktioner er nu klar til afstemning, ikke noget der skal
// gennemgås her på siden.
header("Location: reconcile_list.php?msg=imported&count=$imported&date_warnings=0");
exit;
?>
