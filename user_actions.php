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
    redirect("user_list.php");
}

// Server-side værn mod selvsletning (UI'et skjuler allerede knappen for egen
// konto, men et direkte URL-kald skal spærres uafhængigt af det).
if ($id === (int)($_SESSION['user_id'] ?? 0)) {
    redirect('user_list.php', '@You cannot delete your own account', 'error');
}

$res = DB::query($conn, "SELECT username, user_role FROM users WHERE user_id = $id");
$target = $res ? DB::fetch_assoc($res) : null;

if (!$target) {
    redirect('user_list.php', '@User not found', 'error');
}

// RETTET: denne bruger blev slettet med et HÅRDT DELETE, uden noget tjek for om bruger-ID'et er historisk
// refereret nogen steder - modsat account_delete.php/inventory_actions.php, som allerede korrekt blokerer sletning
// af en konto/produkt der er i brug. login_log er bevidst IKKE med her - ren logind-historik skal ikke kunne
// forhindre oprydning i gamle brugerkonti.
// NYT: samme fejlklasse igen - time_entries.user_id (hvem der reelt loggede hver time) blev tilføjet EFTER denne
// liste blev skrevet og var aldrig med. @-hæmmet, da tabellen kun findes efter db-setup/migrate_time_tracking.php
// er kørt.
$in_use = false;
$user_ref_checks = [['ledger','user_id'], ['audit_log','user_id'], ['expenses','created_by'], ['expenses','cancelled_by'], ['invoice_payments','created_by'], ['time_entries','user_id']];
foreach ($user_ref_checks as [$tbl, $col]) {
    $chk = @DB::query($conn, "SELECT 1 FROM $tbl WHERE $col = $id LIMIT 1");
    if ($chk && DB::num_rows($chk) > 0) { $in_use = true; break; }
}
if ($in_use) {
    redirect('user_list.php', '@Cannot delete user: they have historical ledger entries, audit log records, or expenses/payments attributed to them', 'error');
}

if (DB::query($conn, "DELETE FROM users WHERE user_id = $id")) {
    log_action($conn, 'DELETE_USER', 'users', $id,
        ['username' => $target['username'], 'user_role' => $target['user_role']], null);
    redirect('user_list.php', '@User deleted successfully');
} else {
    redirect('user_list.php', '@Error deleting user', 'error');
}

ob_end_flush();
?>
