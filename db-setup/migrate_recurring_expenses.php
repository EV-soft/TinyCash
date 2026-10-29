<?php # /db-setup/migrate_recurring_expenses.php v:1.3.1 d:2026-09-15 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: Gentagne/faste udgifter (§bogholder-behov-gennemgang 2026-09-01) -
// udgiftssidens modstykke til recurring_invoices (se
// db-setup/migrate_recurring_invoices.php). Én tabel - i modsætning til
// fakturaer er en udgift i dette system altid ÉN flad række (ingen separat
// linje-tabel, expenses har ingen "expense_lines"), så skabelonen kan være
// det samme. Selve genereringen sker automatisk (inc/recurring_expenses.inc.php,
// hooket ind i htm_Footer() ligesom recurring_invoices/auto_backup) og
// opretter altid en UBOGFØRT udgift (voucher_no IS NULL), aldrig en bogført
// direkte - samme uforanderlighedsprincip som fakturaflowet, og strengt taget
// endnu mere begrundet her: INGEN mennesker har set beløbet før det rammer
// bogen, i modsætning til en manuelt indtastet udgift.
echo "Motor: $db_type\n\n";

function table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

if (table_exists($conn, 'recurring_expenses')) {
    echo "[SPRUNGET OVER] Tabellen 'recurring_expenses' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE recurring_expenses (
              recur_id INTEGER PRIMARY KEY AUTOINCREMENT,
              supplier TEXT,
              supplier_id INTEGER,
              account_id INTEGER,
              amount NUMERIC,
              description TEXT,
              exp_type TEXT NOT NULL DEFAULT 'expense',
              interval_type TEXT NOT NULL DEFAULT 'monthly',
              next_run_date DATE,
              last_run_date DATE,
              is_active INTEGER DEFAULT 1,
              proj_id INTEGER,
              created_by INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE recurring_expenses (
              recur_id INT AUTO_INCREMENT PRIMARY KEY,
              supplier VARCHAR(150),
              supplier_id INT,
              account_id INT,
              amount DECIMAL(12,2),
              description VARCHAR(255),
              exp_type VARCHAR(20) NOT NULL DEFAULT 'expense',
              interval_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
              next_run_date DATE,
              last_run_date DATE,
              is_active TINYINT(1) DEFAULT 1,
              proj_id INT,
              created_by INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'recurring_expenses' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu oprette faste udgifter under Indkøb -> Faste udgifter.\n";
?>
