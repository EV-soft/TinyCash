<?php # /db-setup/migrate_expense_lines.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 30):
// en udgift/bilag kunne hidtil kun konteres på ÉN konto - en kvittering med
// fx både kontorartikler og bilekstra kunne ikke splittes. Ny tabel
// expense_lines, samme rolle på udgiftssiden som invoice_lines har på
// salgssiden. NÅR en udgift har linjer, er expenses.account_id/vat_rate
// bevidst IKKE længere autoritativ for selve beløbsfordelingen - se
// expense_get_lines() (inc/db_connect.inc.php) for den ENESTE autoritative
// kilde, brugt konsekvent af både selve bogføringen (expense_edit.php) og
// momsafstemningen (vat_reconciliation.php). Helt bagudkompatibelt: en
// udgift UDEN linjer opfører sig 100% som før (expense_get_lines()
// returnerer i så fald én "syntetisk" linje bygget af expensens egne felter).
echo "Motor: $db_type\n\n";

$has_table = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='expense_lines'");
    $has_table = ($res && DB::fetch_assoc($res));
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'expense_lines'");
    $has_table = ($res && DB::num_rows($res) > 0);
}

if ($has_table) {
    echo "[SPRUNGET OVER] Tabellen 'expense_lines' findes allerede.\n";
} else {
    if (DB::is_sqlite()) {
        $ok = DB::query($conn, "CREATE TABLE expense_lines (
            line_id INTEGER PRIMARY KEY AUTOINCREMENT,
            exp_id INTEGER NOT NULL,
            account_id INTEGER NOT NULL,
            amount NUMERIC NOT NULL,
            line_vat_rate NUMERIC,
            description TEXT
        )");
    } else {
        $ok = DB::query($conn, "CREATE TABLE expense_lines (
            line_id INT AUTO_INCREMENT PRIMARY KEY,
            exp_id INT NOT NULL,
            account_id INT NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            line_vat_rate DECIMAL(5,2) NULL,
            description VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($ok) {
        echo "[OK] Tabellen 'expense_lines' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Eksisterende udgifter er uændrede (ingen linjer oprettet\n";
echo "automatisk) - opdeling på flere konti er en ny, valgfri mulighed ved\n";
echo "redigering af en udgift fra nu af.\n";
?>
