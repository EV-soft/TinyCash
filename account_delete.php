<?php # account_delete.php v:1.0.0 d:2026-07-13 i:claude (Porteret fra rå mysqli til DB::-abstraktionen - virker nu på både SQLite og MySQL)
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // 1. SIKKERHEDSTJEK: Er kontoen i brug i din ledger (bogføring)?
    $check_ledger = DB::query($conn, "SELECT led_id FROM ledger WHERE acc_id = $id LIMIT 1");

    // 2. SIKKERHEDSTJEK: Er kontoen i brug på produkter?
    $check_products = DB::query($conn, "SELECT prod_id FROM products WHERE acc_id = $id LIMIT 1");

    if (DB::num_rows($check_ledger) > 0) {
        // Stop! Der er posteringer på kontoen
        htm_Header('@Error');
        echo "<div style='max-width:600px; margin:50px auto;'>";
        htm_Alert('@Cannot delete account: It has existing transactions in the ledger.', 'error');
        htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php');
        echo "</div>";
        htm_Footer();
        exit;
    } 
    elseif (DB::num_rows($check_products) > 0) {
        // Stop! Der er produkter tilknyttet
        htm_Header('@Error');
        echo "<div style='max-width:600px; margin:50px auto;'>";
        htm_Alert('@Cannot delete account: It is assigned to one or more products.', 'error');
        htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php');
        echo "</div>";
        htm_Footer();
        exit;
    }
    else {
        // OK - Ingen afhængigheder fundet, vi kan slette den
        $sql = "DELETE FROM accounts WHERE acc_id = $id";
        if (DB::query($conn, $sql)) {
            header("Location: chart_of_accounts.php?msg=deleted");
            exit;
        } else {
            die("SQL fejl ved sletning: " . DB::error($conn));
        }
    }
} else {
    header("Location: chart_of_accounts.php");
    exit;
}
?>
