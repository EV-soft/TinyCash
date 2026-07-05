<?php # /vat_save.php v:0.9.1 d:2026-05-07 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Saniter og hent input
    $vat_id      = DB::real_escape_string($conn, $_POST['vat_id']);
    $vat_name    = DB::real_escape_string($conn, $_POST['vat_name']);
    $vat_rate    = (float)str_replace(',', '.', $_POST['vat_rate']);
    
    // Håndtering af vat_account (skal være int eller NULL)
    $vat_account_raw = trim($_POST['vat_account'] ?? '');
    $vat_account = (!empty($vat_account_raw)) ? intval($vat_account_raw) : "NULL";

    if (empty($vat_id)) {
        die("❌ " . lang('@Error: VAT ID cannot be empty.'));
    }

    // 2. Tjek om koden eksisterer (UPSERT logik)
    $check_res = DB::query($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = '$vat_id'");
    
    if (DB::num_rows($check_res) > 0) {
        // UPDATE eksisterende
        $sql = "UPDATE vat_codes SET 
                vat_name = '$vat_name', 
                vat_rate = $vat_rate, 
                vat_account = $vat_account 
                WHERE vat_id = '$vat_id'";
    } else {
        // INSERT ny
        $sql = "INSERT INTO vat_codes (vat_id, vat_name, vat_rate, vat_account) 
                VALUES ('$vat_id', '$vat_name', $vat_rate, $vat_account)";
    }

    // 3. Eksekver
    if (DB::query($conn, $sql)) {
        header("Location: vat_list.php?msg=success");
        exit;
    } else {
        // Vi dør med en fejlbesked, så vi kan se præcis hvad SQL brokker sig over
        die("❌ SQL Error: " . DB::error($conn) . "<br>Query: " . $sql);
    }
} else {
    header("Location: vat_list.php");
    exit;
}