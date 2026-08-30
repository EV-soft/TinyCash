<?php # /db-setup/migrate_recurring_invoices.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: Gentagne/faste fakturaer (fra forslagslisten, næste punkt efter
// kundekontoudtog) - to nye tabeller. recurring_invoices er selve "skabelonen"
// (kunde, interval, næste kørselsdato); recurring_invoice_lines er dens
// varelinjer (samme kolonner som invoice_lines, minus valuta - se
// [[recurring-invoices]] for hvorfor). Selve genereringen sker automatisk
// (inc/recurring_invoices.inc.php, hooket ind i htm_Footer() ligesom
// auto_backup.inc.php) og opretter altid en KLADDE, aldrig en bogført
// faktura direkte - samme uforanderlighedsprincip som resten af fakturaflowet.
echo "Motor: $db_type\n\n";

function table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

if (table_exists($conn, 'recurring_invoices')) {
    echo "[SPRUNGET OVER] Tabellen 'recurring_invoices' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE recurring_invoices (
              recur_id INTEGER PRIMARY KEY AUTOINCREMENT,
              cust_id INTEGER,
              interval_type TEXT NOT NULL DEFAULT 'monthly',
              next_run_date DATE,
              last_run_date DATE,
              is_active INTEGER DEFAULT 1,
              inv_due_days INTEGER DEFAULT 8,
              cust_reference TEXT,
              inv_note TEXT,
              delivery_address TEXT,
              proj_id INTEGER,
              created_by INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE recurring_invoices (
              recur_id INT AUTO_INCREMENT PRIMARY KEY,
              cust_id INT,
              interval_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
              next_run_date DATE,
              last_run_date DATE,
              is_active TINYINT(1) DEFAULT 1,
              inv_due_days INT DEFAULT 8,
              cust_reference VARCHAR(100),
              inv_note TEXT,
              delivery_address TEXT,
              proj_id INT,
              created_by INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'recurring_invoices' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

if (table_exists($conn, 'recurring_invoice_lines')) {
    echo "[SPRUNGET OVER] Tabellen 'recurring_invoice_lines' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE recurring_invoice_lines (
              rline_id INTEGER PRIMARY KEY AUTOINCREMENT,
              recur_id INTEGER,
              line_text TEXT,
              quantity NUMERIC,
              price_each NUMERIC,
              line_vat_rate NUMERIC,
              prod_id INTEGER,
              proj_id INTEGER
          )"
        : "CREATE TABLE recurring_invoice_lines (
              rline_id INT AUTO_INCREMENT PRIMARY KEY,
              recur_id INT,
              line_text VARCHAR(255),
              quantity DECIMAL(12,2),
              price_each DECIMAL(12,2),
              line_vat_rate DECIMAL(5,2),
              prod_id INT,
              proj_id INT
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'recurring_invoice_lines' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu oprette faste fakturaer under Salg -> Gentagne fakturaer.\n";
?>
