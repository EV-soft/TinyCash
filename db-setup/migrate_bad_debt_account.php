<?php # /db-setup/migrate_bad_debt_account.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION: Afskrivning af tab på debitorer (bruger-anmodet, "kan
// bogholderen ønske sig mere"). Der fandtes ingen formel måde at afskrive en
// uerholdelig faktura som et reelt tab - kun "marker betalt" (bankafstemning,
// forudsætter en reel indbetaling) eller kreditnota (invoice_credit.php, som
// reelt FORTRYDER selve salget/omsætningen). Et tab på en debitor er
// bogføringsmæssigt noget helt tredje: salget/leverancen var reel og
// omsætningen skal blive stående, men beløbet opgives som uerholdeligt.
// Samme "én ny særlig posteringskonto"-mønster som migrate_currency_
// gainloss.php (konto 7200) - ingen ny tabel nødvendig, se
// invoice_writeoff.php for selve handlingen.
echo "Motor: $db_type\n\n";

function mbd_account_exists($conn, $acc_id) {
    $res = DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $acc_id");
    return ($res && DB::num_rows($res) > 0);
}

// RETTET: oprettede den faste konto, selvom rollen allerede var dækket af en anden konto (en kontoplan fra
// setup_chart.php eller et skift til standardkontoplanen) - en ekstra, ikke-standard konto i kontoplanen
$mbd_covered = tc_role_account($conn, 'conf_acc_bad_debt', 7100);
if ($mbd_covered > 0 && $mbd_covered !== 7100) {
    echo "[SPRUNGET OVER] Tab på debitorer bogføres allerede på konto $mbd_covered (Firmaindstillinger).\n";
} elseif (mbd_account_exists($conn, 7100)) {
    echo "[SPRUNGET OVER] Konto 7100 findes allerede.\n";
} else {
    if (DB::insert($conn, 'accounts', ['acc_id' => 7100, 'acc_name' => 'Tab på debitorer', 'acc_type' => 'expense'])) {
        echo "[OK] Konto 7100 'Tab på debitorer' (type: expense) oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Sæt evt. en anden konto under Firmaindstillinger -> 'Tab på\n";
echo "debitorer-konto', hvis 7100 allerede bruges til noget andet i jeres\n";
echo "kontoplan. Kontoen bruges automatisk når en faktura afskrives som\n";
echo "uerholdeligt tab (invoice_view.php -> 'Afskriv som tab').\n";
?>
