<?php # /db-setup/migrate_equity_account.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

$acc_id = 3000;
$check = DB::query($conn, "SELECT COUNT(*) FROM accounts WHERE acc_id = $acc_id");

if ($check) {
    $row = DB::fetch_row($check);
    if ((int)$row[0] > 0) {
        echo "[SPRUNGET OVER] Konto $acc_id findes allerede - ingen ændringer foretaget.\n";
        echo "Tjek at dens acc_type er sat til 'equity' manuelt i Chart of Accounts, hvis den er oprettet med en anden type.\n";
    } else {
        $ok = DB::insert($conn, 'accounts', [
            'acc_id'   => $acc_id,
            'acc_name' => 'Egenkapital / Overført resultat',
            'acc_type' => 'equity',
            'vat_code' => null,
            'vat_rate' => 0
        ]);
        if ($ok) {
            echo "[OK] Konto $acc_id 'Egenkapital / Overført resultat' (type: equity) oprettet.\n";
        } else {
            echo "[FEJL] Kunne ikke oprette kontoen: " . DB::error($conn) . "\n";
        }
    }
} else {
    echo "[FEJL] Kunne ikke tjekke accounts-tabellen: " . DB::error($conn) . "\n";
}

echo "\nDu kan nu bruge denne konto i year_end_close.php. Du kan slette setup/-mappen, når du er færdig.\n";
