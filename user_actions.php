<?php # /user_actions.php v:1.3.0 d:2026-08-30 i:evs
# Manglede helt i projektet - user_list.php's "Slet"-knap linkede hertil,
# men filen fandtes ikke noget sted, så brugersletning gav en 404 og virkede
# reelt aldrig. Fundet under en revisionsspor-gennemgang. Bruger-anmodet.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

// Kun admins må slette brugere (samme tjek som user_list.php/user_create.php/user_edit.php).
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if ($action !== 'delete' || $id <= 0) {
    header("Location: user_list.php");
    exit;
}

// Server-side værn mod selvsletning (UI'et skjuler allerede knappen for egen
// konto, men et direkte URL-kald skal spærres uafhængigt af det).
if ($id === (int)($_SESSION['user_id'] ?? 0)) {
    header("Location: user_list.php?msg=cannot_delete_self");
    exit;
}

$res = DB::query($conn, "SELECT username, user_role FROM users WHERE user_id = $id");
$target = $res ? DB::fetch_assoc($res) : null;

if (!$target) {
    header("Location: user_list.php?msg=not_found");
    exit;
}

// RETTET (§bugs-batch-21-review): denne bruger blev slettet med et HÅRDT
// DELETE, uden noget tjek for om bruger-ID'et er historisk refereret nogen
// steder - modsat account_delete.php/inventory_actions.php, som allerede
// korrekt blokerer sletning af en konto/produkt der er i brug. ledger.user_id
// (hvem der reelt bogførte hver postering i selve hovedbogen),
// audit_log.user_id (revisionssporet), expenses.created_by/cancelled_by og
// invoice_payments.created_by ville alle stå tilbage med en henvisning til
// et bruger-ID der ikke længere findes - "bogført af" ville stille forsvinde
// fra historikken, hvilket underminerer selve revisionssporets formål (se
// bogforingslov-compliance). login_log er bevidst IKKE med her - ren
// logind-historik skal ikke kunne forhindre oprydning i gamle brugerkonti.
//
// NYT (§bugs-batch-30-review): samme fejlklasse igen - time_entries.user_id
// (hvem der reelt loggede hver time) blev tilføjet EFTER denne liste blev
// skrevet og var aldrig med. "Hvem lavede arbejdet" er lige så meget en del
// af revisionssporet som "hvem bogførte posteringen" ovenfor - en slettet
// bruger ville ellers stille forsvinde fra timeregistreringernes historik,
// inkl. for allerede fakturerede timer. @-hæmmet, da tabellen kun findes
// efter db-setup/migrate_time_tracking.php er kørt.
$in_use = false;
$user_ref_checks = [['ledger','user_id'], ['audit_log','user_id'], ['expenses','created_by'], ['expenses','cancelled_by'], ['invoice_payments','created_by'], ['time_entries','user_id']];
foreach ($user_ref_checks as [$tbl, $col]) {
    $chk = @DB::query($conn, "SELECT 1 FROM $tbl WHERE $col = $id LIMIT 1");
    if ($chk && DB::num_rows($chk) > 0) { $in_use = true; break; }
}
if ($in_use) {
    header("Location: user_list.php?msg=cannot_delete_in_use");
    exit;
}

if (DB::query($conn, "DELETE FROM users WHERE user_id = $id")) {
    log_action($conn, 'DELETE_USER', 'users', $id,
        ['username' => $target['username'], 'user_role' => $target['user_role']], null);
    header("Location: user_list.php?msg=deleted");
    exit;
} else {
    header("Location: user_list.php?msg=error");
    exit;
}

ob_end_flush();
?>
