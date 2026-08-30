<?php # /db-setup/migrate_invoice_payments.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: delvis betaling af fakturaer (fra forslagslisten) - ny tabel
// invoice_payments giver en fuld historik af hver enkelt indbetaling mod en
// faktura, i stedet for kun ét binært "betalt/ikke betalt"-flag. En faktura
// markeres først 'paid' i invoices.inv_status, når summen af dens
// indbetalinger dækker det fulde beløb.
echo "Motor: $db_type\n\n";

$table_exists = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='invoice_payments'");
    $table_exists = ($res && $res->fetch());
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'invoice_payments'");
    $table_exists = ($res && DB::num_rows($res) > 0);
}

if ($table_exists) {
    echo "[SPRUNGET OVER] Tabellen 'invoice_payments' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE invoice_payments (
              payment_id INTEGER PRIMARY KEY AUTOINCREMENT,
              inv_id INTEGER,
              payment_date DATE,
              amount NUMERIC,
              note TEXT,
              created_by INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE invoice_payments (
              payment_id INT AUTO_INCREMENT PRIMARY KEY,
              inv_id INT,
              payment_date DATE,
              amount DECIMAL(12,2),
              note VARCHAR(255),
              created_by INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'invoice_payments' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu bruge delvis betaling via bankafstemningen (reconcile_action.php).\n";
?>
