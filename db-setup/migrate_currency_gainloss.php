<?php # /db-setup/migrate_currency_gainloss.php v:1.3.0 d:2026-08-30 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION: Kursgevinst/-tab ved betaling (bruger-anmodet, "reel multi-
// valuta-bogføring" - afgrænset til den vigtigste reelle mangel efter en
// afklaring af omfang). En udenlandsk faktura (EUR/USD/...) bogføres til den
// kurs der gjaldt VED FAKTURERINGEN (se invoice_post_action.php/
// invoice_dkk_totals()), men kunden betaler typisk uger senere til en anden
// kurs - det modtagne DKK-beløb rammer derfor næsten aldrig præcis det
// bogførte DKK-beløb. Ingen ny tabel nødvendig - kun ÉN ny "særlig
// posteringskonto" (samme mønster som conf_acc_bank/debitor/creditor/sales/
// vat), sat op via company_settings.php, brugt af reconcile_action.php til
// at bogføre selve forskellen som en rigtig kursgevinst/-tab, i stedet for
// enten (a) aldrig at kunne lukke fakturaen (nuværende adfærd, se
// invoice_dkk_totals()'s egen kommentar) eller (b) lade en lille uforklaret
// rest stå tilbage på debitorkontoen for evigt.
echo "Motor: $db_type\n\n";

function mcg_account_exists($conn, $acc_id) {
    $res = DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $acc_id");
    return ($res && DB::num_rows($res) > 0);
}

if (mcg_account_exists($conn, 7200)) {
    echo "[SPRUNGET OVER] Konto 7200 findes allerede.\n";
} else {
    if (DB::insert($conn, 'accounts', ['acc_id' => 7200, 'acc_name' => 'Kursgevinst/-tab, valuta', 'acc_type' => 'income'])) {
        echo "[OK] Konto 7200 'Kursgevinst/-tab, valuta' (type: income) oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Sæt evt. en anden konto under Firmaindstillinger -> 'Kursgevinst/\n";
echo "-tabskonto', hvis 7200 allerede bruges til noget andet i jeres kontoplan.\n";
echo "Kontoen bruges automatisk ved bankafstemning, når du markerer en\n";
echo "udenlandsk faktura som afsluttet på trods af en kursforskel.\n";
