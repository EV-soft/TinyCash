<?php # /db-setup/migrate_account_budgets.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION: Simpelt budget vs. faktisk (bruger-anmodet, "kan bogholderen
// ønske sig mere"). Der findes slet ingen budgetfunktion i systemet -
// report_income.php viser kun faktiske tal, uden sammenligning til noget
// planlagt. Bevidst afgrænset til ÉT årligt beløb pr. konto (ikke månedlig
// granularitet) - se budget_edit.php for selve redigeringen og
// report_income.php for sammenligningen.
echo "Motor: $db_type\n\n";

$has_table = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='account_budgets'");
    $has_table = ($res && DB::fetch_assoc($res));
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'account_budgets'");
    $has_table = ($res && DB::num_rows($res) > 0);
}

if ($has_table) {
    echo "[SPRUNGET OVER] Tabellen 'account_budgets' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE account_budgets (
              acc_id INTEGER NOT NULL,
              year INTEGER NOT NULL,
              budget_amount NUMERIC NOT NULL DEFAULT 0,
              PRIMARY KEY (acc_id, year)
           )"
        : "CREATE TABLE account_budgets (
              acc_id INT NOT NULL,
              year INT NOT NULL,
              budget_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
              PRIMARY KEY (acc_id, year)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'account_budgets' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Sæt et årligt budget pr. konto på budget_edit.php - vises\n";
echo "derefter automatisk som en afvigelseskolonne på report_income.php, når\n";
echo "et bestemt årstal er valgt.\n";
?>
