<?php # /db-setup/migrate_fixed_assets.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: Anlægsaktiver/afskrivninger (bruger-anmodet, fra "hvilke funktioner
// mangler"-gennemgangen: "den mest 'rigtige bogholder'-mangel hvis I har
// udstyr/inventar på balancen"). Et anlægskartotek (fixed_assets) med rigtig,
// bogført lineær (lige store rater pr. måned) afskrivning - IKKE bare et
// regneark ved siden af regnskabet. Samme princip som leverandørmodulets
// leverandørgæld (se db-setup/migrate_suppliers.php): én kontrolkonto i
// kontoplanen pr. aktivkategori, med den reelle detalje i denne nye tabel -
// nøjagtig samme mønster som debitorer/kreditorer allerede bruger.
//
// Anskaffelse bogføres DIREKTE her ved oprettelse (DEBET aktivkonto, KREDIT
// bank) - ikke via expense_edit.php, som kun tillader konti af typen
// 'expense' (et anlægsaktiv er per definition IKKE en udgift, det er en
// investering der afskrives over tid). Hver afskrivningskørsel poster en
// egen journalpost (DEBET afskrivningskonto, KREDIT aktivkonto) for hvert
// aktivs andel siden sidste kørsel - lineær, pr. hele måned, samme
// datodrift-undgåelses-princip som recurring_invoices.inc.php's
// recurring_next_date(). Afhændelse (solgt/kasseret) fjerner aktivets
// resterende bogførte værdi og bogfører en eventuel gevinst/tab.
echo "Motor: $db_type\n\n";

function fa_table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

// --- 1. Tabellen 'fixed_assets' ---
if (fa_table_exists($conn, 'fixed_assets')) {
    echo "[SPRUNGET OVER] Tabellen 'fixed_assets' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE fixed_assets (
              asset_id INTEGER PRIMARY KEY AUTOINCREMENT,
              asset_name TEXT NOT NULL,
              description TEXT,
              acquisition_date DATE NOT NULL,
              acquisition_cost NUMERIC NOT NULL,
              residual_value NUMERIC DEFAULT 0,
              useful_life_years INTEGER NOT NULL DEFAULT 5,
              asset_account_id INTEGER NOT NULL,
              depreciation_account_id INTEGER NOT NULL,
              accumulated_depreciation NUMERIC DEFAULT 0,
              last_depreciated_date DATE,
              status TEXT NOT NULL DEFAULT 'active',
              disposed_date DATE,
              disposal_proceeds NUMERIC,
              voucher_no INTEGER,
              proj_id INTEGER,
              created_by INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE fixed_assets (
              asset_id INT AUTO_INCREMENT PRIMARY KEY,
              asset_name VARCHAR(255) NOT NULL,
              description TEXT,
              acquisition_date DATE NOT NULL,
              acquisition_cost DECIMAL(12,2) NOT NULL,
              residual_value DECIMAL(12,2) DEFAULT 0,
              useful_life_years INT NOT NULL DEFAULT 5,
              asset_account_id INT NOT NULL,
              depreciation_account_id INT NOT NULL,
              accumulated_depreciation DECIMAL(12,2) DEFAULT 0,
              last_depreciated_date DATE,
              status VARCHAR(20) NOT NULL DEFAULT 'active',
              disposed_date DATE,
              disposal_proceeds DECIMAL(12,2),
              voucher_no INT,
              proj_id INT,
              created_by INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'fixed_assets' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

// --- 2. Standard-konti til anlægsaktiver, samme mønster som
//     migrate_liability_account.php/migrate_equity_account.php - oprettes
//     kun hvis kontonummeret ikke allerede er i brug til noget andet. ---
function fa_ensure_account($conn, $acc_id, $acc_name, $acc_type) {
    $check = DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts WHERE acc_id = " . (int)$acc_id);
    $row = $check ? DB::fetch_assoc($check) : null;
    if ($row) {
        if ($row['acc_type'] !== $acc_type) {
            echo "[OBS] Konto $acc_id findes allerede som '{$row['acc_name']}' (type: {$row['acc_type']}) - IKKE '$acc_type' som forventet. Opret evt. en anden konto manuelt i Kontoplanen og vælg den i stedet, når du opretter et anlægsaktiv.\n";
        } else {
            echo "[SPRUNGET OVER] Konto $acc_id findes allerede ('{$row['acc_name']}').\n";
        }
        return;
    }
    $ok = DB::insert($conn, 'accounts', [
        'acc_id' => $acc_id, 'acc_name' => $acc_name, 'acc_type' => $acc_type, 'vat_code' => null, 'vat_rate' => 0,
    ]);
    echo $ok ? "[OK] Konto $acc_id '$acc_name' (type: $acc_type) oprettet.\n" : "[FEJL] " . DB::error($conn) . "\n";
}

fa_ensure_account($conn, 8200, 'Anlægsaktiver (driftsmidler og inventar)', 'asset');
fa_ensure_account($conn, 2600, 'Af- og nedskrivninger af anlægsaktiver', 'expense');

echo "\nFærdig. Du kan nu registrere anlægsaktiver under Regnskab -> Anlægsaktiver.\n";
echo "De to foreslåede konti (8200/2600) er kun forudvalgte standarder - du kan\n";
echo "vælge andre, allerede eksisterende konti pr. aktiv i formularen, hvis du\n";
echo "ønsker at opdele efter aktivkategori (fx grunde/bygninger for sig).\n";
