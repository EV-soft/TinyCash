<?php # /vat_save.php v:1.3.1 d:2026-09-15 i:evs
# samme parse_dk_number()-fund som invoice_edit.php/expense_edit.php
# v0.9.3: vat_rate brugte den utilstrækkelige str_replace(',', '.') - erstattet
# af parse_dk_number(). Lav praktisk risiko her (moms-satser er altid små
# tal), men rettet for konsistens. Se [[invoice-line-comma-amount-fix]].
# KRITISK (§bugs-batch-15-review): samme manglende niveau-tjek som
# vat_codes.php, som denne fil rent faktisk gemmer for - tilføjet her også.
#
# NYT (§find-fem-fejl runde 40): to fund.
#  (1) INSERT/UPDATE blev ALDRIG logget til revisionssporet - kun vat_codes.
#      php's egen sletning gjorde. En momssats/-konto kunne ændres og ændres
#      tilbage igen uden nogensinde at efterlade et spor, selvom den styrer
#      momsberegningen på hver eneste faktura/udgift i systemet. Tilføjet
#      log_action() for begge grene, samme mønster som account_edit.php.
#  (2) en fejlet SQL endte i et råt die() med forbindelsesmotorens fejltekst
#      OG selve SQL-forespørgslen, uden sidens skal (menu/header/footer) -
#      samme fejlklasse som account_delete.php/inc/inventory_actions.php (se
#      deres egne rettelser samme runde). Erstattet med en flash-besked +
#      redirect tilbage til vat_codes.php, som allerede har fuld sidevisning.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Saniter og hent input
    $vat_id      = DB::real_escape_string($conn, $_POST['vat_id']);
    $vat_name    = DB::real_escape_string($conn, $_POST['vat_name']);
    $vat_rate    = parse_dk_number($_POST['vat_rate']);
    
    // Håndtering af vat_account (skal være int eller NULL)
    $vat_account_raw = trim($_POST['vat_account'] ?? '');
    $vat_account = (!empty($vat_account_raw)) ? intval($vat_account_raw) : "NULL";

    if (empty($vat_id)) {
        redirect('vat_codes.php', '@Error: VAT ID cannot be empty.', 'error');      // (var et rå die() uden sideskal)
    }
    // RETTET: satsen blev ikke kontrolleret - en momskode på fx 250 % eller -25 % blev gemt, og alle konti med koden
    // beregnede derefter forkert moms på fakturaer og udgifter. Samme grænse som fakturalinjernes momssats.
    if (tc_pct($_POST['vat_rate'] ?? '') === null) {
        redirect('vat_codes.php', '@The VAT rate must be between 0 and 100 %.', 'error');
    }

    // 2. Tjek om koden eksisterer (UPSERT logik)
    $check_res = DB::query($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = '$vat_id'");
    $is_update = DB::num_rows($check_res) > 0;
    // RETTET: formularen "Tilføj ny momskode" OVERSKREV stille en eksisterende kode med samme id (fx S25 med en ny
    // sats) - koden som kontiene allerede bruger. En ny kode med et brugt id afvises nu (en rettelse sender update=1).
    if ($is_update && empty($_POST['update'])) {
        flash(sprintf(lang('@The VAT code %s already exists - tick "Change the existing code" to change it.'), htmlspecialchars($_POST['vat_id'])), 'error');
        redirect("vat_codes.php");
    }
    // RETTET: momskontoen blev ikke kontrolleret - en kode kunne pege på en konto der ikke findes
    if ($vat_account !== "NULL" && !tc_account_ok($conn, $vat_account)) {
        flash(sprintf(lang('@The account %d does not exist in the chart of accounts - check Company Settings (posting accounts) or the chart of accounts. Nothing was posted.'), (int)$vat_account), 'error');
        redirect("vat_codes.php");
    }

    // Hent momskodens data FØR en evt. opdatering, til revisionssporet -
    // samme mønster som vat_codes.php's egen sletning og account_edit.php.
    $before_row = null;
    if ($is_update) {
        $before_res = DB::query($conn, "SELECT vat_name, vat_rate, vat_account FROM vat_codes WHERE vat_id = '$vat_id'");
        $before_row = $before_res ? DB::fetch_assoc($before_res) : null;
    }

    if ($is_update) {
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
        $new_values = ['vat_name' => $_POST['vat_name'], 'vat_rate' => $vat_rate, 'vat_account' => $vat_account];
        if ($is_update) {
            log_action($conn, 'UPDATE_VAT_CODE', 'vat_codes', 0, $before_row, $new_values);
        } else {
            log_action($conn, 'CREATE_VAT_CODE', 'vat_codes', 0, null, array_merge(['vat_id' => $vat_id], $new_values));
        }
        // RETTET: vat_list.php findes ikke i projektet (vat_codes.php er den
        // reelle side) - hvert vellykket gem sendte derfor brugeren til en 404.
        redirect('vat_codes.php', '@VAT code saved.');
    } else {
        // RETTET: ikke længere et råt die med motor-fejltekst + hele SQL-forespørgslen uden sidens skal - se
        // filens header. Fejlbeskeden flashes til sessionen og vises af vat_codes.php, som allerede har fuld
        // header/menu/footer.
        flash(lang('@Error saving VAT code:') . ' ' . htmlspecialchars((string)DB::error($conn)), 'error');
        redirect("vat_codes.php");
    }
} else {
    redirect("vat_codes.php");
}