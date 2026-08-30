<?php # /bank_integration_callback.php v:1.3.0 d:2026-08-30 i:evs
# v2.2.0: samme mangel som v2.1.0 rettede for "afventer bekræftelse" gjaldt
# også for "msg=error" (kode/state manglede, ELLER state ikke fundet i
# bank_connections) - begge endte på samme tomme, generiske besked. Viser nu
# de rå GET-parametre banken/Enable Banking rent faktisk sendte tilbage (fx
# error/error_description, hvis brugeren afbrød hos banken, eller state var
# forkert/udløbet), i stedet for kun "prøv igen".
# v2.1.0: samme rettelse for eb_create_session()-fejl - se
# [[bank-integration-psd2]] for baggrunden (bruger-bekræftet: en rigtig
# gennemført bank-godkendelse endte alligevel på "Afventer bekræftelse").
# v2.0.0: omskrevet mod Enable Banking (se bank_integration.php v2.0.0).
# Banken sender brugeren tilbage hertil efter samtykke, med ?code=...&state=...
# i URL'en (Enable Bankings egen konvention - IKKE en URL vi selv byggede pr.
# forbindelse, se bank_integration_connect.php). $state bruges til at finde
# hvilken bank_connections-række der fuldføres.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/enablebanking.lib.php';

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

// Fælles hjælpefunktion: send brugeren tilbage med en synlig, reel
// fejlbeskrivelse i stedet for en tavs generisk besked - se filens
// header-kommentar for hvorfor dette blev tilføjet.
function eb_callback_fail(?int $conn_id, string $reason, array $context = []): void {
    global $conn;
    error_log("bank_integration_callback.php: $reason - " . json_encode($context));
    if ($conn_id) {
        log_action($conn, 'BANK_CONNECTION_FAILED', 'bank_connections', $conn_id, null, array_merge(['reason' => $reason], $context));
    }
    header("Location: bank_integration.php?msg=error&detail=" . urlencode(substr($reason . ' - ' . json_encode($context), 0, 400)));
    exit;
}

if ($code === '' || $state === '') {
    // Brugeren afbrød hos banken, ELLER banken/Enable Banking sendte fejl-
    // parametre i stedet for code/state (standard OAuth-mønster: error +
    // error_description). Viser dem, hvis de er der, i stedet for at gætte.
    eb_callback_fail(null, 'Manglende code/state i redirect fra banken', [
        'error'             => $_GET['error'] ?? null,
        'error_description' => $_GET['error_description'] ?? null,
        'all_params'        => array_keys($_GET),
    ]);
}

$state_esc = DB::escape($conn, $state);
$bc = DB::fetch_assoc(DB::query($conn, "SELECT * FROM bank_connections WHERE state_token = '$state_esc'"));

if (!$bc) {
    // State-tokenet matcher ingen ventende forbindelse - kan skyldes at
    // koden allerede er brugt (fx et dobbelt-klik/genindlæst side), er
    // udløbet, eller at forbindelsen blev afbrudt manuelt undervejs.
    eb_callback_fail(null, 'State-token matcher ingen ventende forbindelse (allerede brugt, afbrudt, eller udløbet)', [
        'state_prefix' => substr($state, 0, 12) . '...',
    ]);
}

$session = eb_create_session($code);

if (!empty($session['session_id']) && !empty($session['accounts'][0]['uid'])) {
    $account_uid = DB::escape($conn, $session['accounts'][0]['uid']);
    DB::query($conn, "UPDATE bank_connections SET status = 'LN', gc_account_id = '$account_uid', requisition_id = '" . DB::escape($conn, $session['session_id']) . "' WHERE conn_id = " . (int)$bc['conn_id']);
    log_action($conn, 'BANK_CONNECTION_LINKED', 'bank_connections', (int)$bc['conn_id'], ['status' => $bc['status']], ['status' => 'LN']);
    header("Location: bank_integration.php?msg=connected"); exit;
}

// Session-oprettelsen fejlede, eller kontolisten var tom.
$http_status = $session['_status'] ?? 0;
error_log("bank_integration_callback.php: eb_create_session fejlede for conn_id=" . $bc['conn_id'] . " - HTTP $http_status - " . json_encode($session));
log_action($conn, 'BANK_CONNECTION_FAILED', 'bank_connections', (int)$bc['conn_id'], null, ['http_status' => $http_status, 'response' => $session]);

$error_summary = $session['error'] ?? $session['message'] ?? $session['error_description'] ?? ('HTTP ' . $http_status);
header("Location: bank_integration.php?msg=pending&detail=" . urlencode(substr((string)$error_summary, 0, 300)));
exit;
?>
