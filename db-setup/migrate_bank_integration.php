<?php # /db-setup/migrate_bank_integration.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// v2.0.0: GoCardless Bank Account Data lukkede for nye tilmeldinger juli
// 2025 (bekræftet: bankaccountdata.gocardless.com/new-signups-disabled,
// ingen venteliste, ingen genåbningsdato) - integrationen er derfor
// omskrevet mod Enable Banking (inc/enablebanking.lib.php) i stedet.
// Tabellen bank_connections er den samme, men to nye kolonner er tilføjet:
// - state_token: Enable Banking bruger ÉN fast, forudregistreret redirect-
//   URL (ikke én pr. forbindelse som hos GoCardless), så vi bærer i stedet
//   konteksten (hvilken forbindelse der fuldføres) via et tilfældigt
//   "state"-token, som banken sender uændret tilbage ved redirect.
// - institution_country: Enable Banking identificerer en bank via navn+land
//   sammen (ikke ét samlet institution_id som hos GoCardless).
// Findes tabellen slet ikke endnu, oprettes den direkte med alle felter.
// Findes den allerede (fra den gamle GoCardless-udgave af denne migration),
// tilføjes kun de to manglende kolonner - resten af tabellen/dataene røres
// ikke.
echo "Motor: $db_type\n\n";

$table_exists = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='bank_connections'");
    $table_exists = ($res && $res->fetch());
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'bank_connections'");
    $table_exists = ($res && DB::num_rows($res) > 0);
}

if (!$table_exists) {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE bank_connections (
              conn_id INTEGER PRIMARY KEY AUTOINCREMENT,
              institution_id TEXT,
              institution_name TEXT,
              institution_country TEXT,
              requisition_id TEXT,
              gc_account_id TEXT,
              state_token TEXT,
              acc_id INTEGER,
              status TEXT DEFAULT 'CR',
              last_sync_at TIMESTAMP,
              created_by INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE bank_connections (
              conn_id INT AUTO_INCREMENT PRIMARY KEY,
              institution_id VARCHAR(100),
              institution_name VARCHAR(150),
              institution_country VARCHAR(2),
              requisition_id VARCHAR(100),
              gc_account_id VARCHAR(100),
              state_token VARCHAR(64),
              acc_id INT,
              status VARCHAR(10) DEFAULT 'CR',
              last_sync_at TIMESTAMP NULL,
              created_by INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'bank_connections' oprettet (inkl. state_token/institution_country).\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
} else {
    echo "[SPRUNGET OVER] Tabellen 'bank_connections' findes allerede - tjekker for manglende kolonner.\n";

    $columns = [
        'state_token'          => DB::is_sqlite() ? 'TEXT' : 'VARCHAR(64)',
        'institution_country'  => DB::is_sqlite() ? 'TEXT' : 'VARCHAR(2)',
    ];

    foreach ($columns as $col => $type) {
        $has_column = false;
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "PRAGMA table_info(bank_connections)");
            if ($res) {
                while ($row = DB::fetch_assoc($res)) {
                    if (strtolower($row['name']) === $col) { $has_column = true; break; }
                }
            }
        } else {
            $res = DB::query($conn, "SHOW COLUMNS FROM bank_connections LIKE '$col'");
            $has_column = ($res && DB::num_rows($res) > 0);
        }

        if ($has_column) {
            echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på bank_connections.\n";
        } else {
            if (DB::query($conn, "ALTER TABLE bank_connections ADD COLUMN $col $type")) {
                echo "[OK] Kolonnen '$col' tilføjet til bank_connections.\n";
            } else {
                echo "[FEJL] " . DB::error($conn) . "\n";
            }
        }
    }
}

echo "\nFærdig. Husk at udfylde [enablebanking_config] i inc/data/env.ini\n";
echo "(EB_APPLICATION_ID, EB_PRIVATE_KEY_PATH, EB_REDIRECT_URL) - opret en\n";
echo "konto på https://enablebanking.com/sign-in/ for at hente disse\n";
echo "(bemærk: prisen for reel produktionsbrug kræver kontakt til deres\n";
echo "salgsafdeling, men et Application + sandbox kan oprettes selvbetjent).\n";
echo "Derefter kan du forbinde en bank under Regnskab -> Bankintegration (PSD2).\n";
?>
