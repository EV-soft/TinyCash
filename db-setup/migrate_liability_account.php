<?php # /db-setup/migrate_liability_account.php v:1.3.0 d:2026-08-30 i:evs
# RETTET 2026-08-15: filen var en copy/paste-fejl af migrate_equity_account.php
# - oprettede SAMME konto-ID (3000) med SAMME navn ("Egenkapital / Overført
# resultat"), kun acc_type differerede. Da de to migrationer tjekker på
# acc_id, ville kun den der blev kørt FØRST reelt oprette noget - man kunne
# aldrig ende med både en rigtig egenkapital- OG gældskonto. Bruger nu et
# separat konto-ID og et navn der faktisk er gæld, ikke egenkapital.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

$acc_id = 4000;
$check = DB::query($conn, "SELECT COUNT(*) FROM accounts WHERE acc_id = $acc_id");

if ($check) {
    $row = DB::fetch_row($check);
    if ((int)$row[0] > 0) {
        echo "[SPRUNGET OVER] Konto $acc_id findes allerede - ingen ændringer foretaget.\n";
        echo "Tjek at dens acc_type er sat til 'liability' manuelt i Chart of Accounts, hvis den er oprettet med en anden type.\n";
    } else {
        $ok = DB::insert($conn, 'accounts', [
            'acc_id'   => $acc_id,
            'acc_name' => 'Leverandørgæld og anden gæld',
            'acc_type' => 'liability',
            'vat_code' => null,
            'vat_rate' => 0
        ]);
        if ($ok) {
            echo "[OK] Konto $acc_id 'Leverandørgæld og anden gæld' (type: liability) oprettet.\n";
        } else {
            echo "[FEJL] Kunne ikke oprette kontoen: " . DB::error($conn) . "\n";
        }
    }
} else {
    echo "[FEJL] Kunne ikke tjekke accounts-tabellen: " . DB::error($conn) . "\n";
}

echo "\nDette er en simpel, samlet gældskonto (svarer til ÅRL's forenklede opstilling for regnskabsklasse B). Du kan oprette flere/mere specifikke gældskonti manuelt i Kontoplanen om nødvendigt. Du kan slette db-setup/-mappen, når du er færdig med opsætningen.\n";
