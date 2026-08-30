<?php # /bank_integration_connect.php v:1.3.0 d:2026-08-30 i:evs
# v2.2.0: sender nu ASPSP'ens egen "maximum_consent_validity" (fra
# bank_integration.php's institutionsliste) videre til
# eb_start_authorization() - se inc/enablebanking.lib.php v1.1.0. psu_type-
# skiftet i v2.1.0 løste ikke den rapporterede "server_error" alene.
# v2.1.0: psu_type var hårdkodet til "business" - bruger-rapporteret
# "server_error" fra Enable Banking under den faktiske bank-godkendelse
# (efter en vellykket /auth-start). Sandbox-/mock-banker understøtter ofte
# kun "personal" - gjort valgbart pr. forbindelse i stedet for at gætte.
# v2.0.0: omskrevet mod Enable Banking (se bank_integration.php v2.0.0).
# POST-modtager fra bank_integration.php's "Forbind"-knap. Starter en Enable
# Banking-godkendelse og sender brugeren videre til bankens egen login-side.
# Selve kontoen kobles først når banken sender brugeren tilbage til
# bank_integration_callback.php.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/enablebanking.lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: bank_integration.php"); exit; }

$institution_name    = DB::escape($conn, $_POST['institution_name'] ?? '');
$institution_country = DB::escape($conn, $_POST['institution_country'] ?? '');
$acc_id               = (int)($_POST['acc_id'] ?? 0);
$psu_type             = in_array($_POST['psu_type'] ?? '', ['personal', 'business'], true) ? $_POST['psu_type'] : 'personal';
// ASPSP'ens egen "maximum_consent_validity" (sekunder), videresendt uændret
// fra bank_integration.php's institutionsliste - tomt/0 betyder "ukendt",
// og eb_start_authorization() falder da tilbage til sin egen standard.
$max_validity_seconds = isset($_POST['max_validity_seconds']) && $_POST['max_validity_seconds'] !== '' ? (int)$_POST['max_validity_seconds'] : null;

if ($institution_name === '' || $acc_id <= 0) {
    header("Location: bank_integration.php?msg=error"); exit;
}

// Et tilfældigt, uforudsigeligt state-token - Enable Banking bruger ÉN fast,
// forudregistreret redirect-URL (ikke én pr. forbindelse), så konteksten
// (hvilken bank_connections-række der fuldføres) bæres i stedet via dette
// token, som banken sender uændret tilbage i redirect'et.
$state_token = bin2hex(random_bytes(24));

DB::query($conn, "INSERT INTO bank_connections (institution_name, institution_country, state_token, acc_id, status, created_by)
                  VALUES ('$institution_name', '$institution_country', '$state_token', $acc_id, 'CR', " . (int)($_SESSION['user_id'] ?? 0) . ")");
$conn_id = DB::insert_id($conn);

$redirect_url = eb_redirect_base();
$auth = eb_start_authorization($_POST['institution_name'], $_POST['institution_country'] ?? '', $state_token, $redirect_url, $psu_type, $max_validity_seconds);

if (empty($auth['url'])) {
    DB::query($conn, "DELETE FROM bank_connections WHERE conn_id = $conn_id");
    header("Location: bank_integration.php?msg=error"); exit;
}

log_action($conn, 'CONNECT_BANK', 'bank_connections', $conn_id, null,
    ['institution_name' => $_POST['institution_name'], 'institution_country' => $_POST['institution_country'] ?? '', 'acc_id' => $acc_id, 'psu_type' => $psu_type]);

header("Location: " . $auth['url']);
exit;
?>
