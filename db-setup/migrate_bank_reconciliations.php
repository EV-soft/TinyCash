<?php # /db-setup/migrate_bank_reconciliations.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT (BEK 811/2026-gennemgang, Bilag 3 "bankafstemning", se
// [[digital-standard-bookkeeping-registration-goal]]): bank_balance_check.php
// sammenlignede allerede hovedbogens saldo mod kontoudtogets pr. en valgt
// dato (pr. konto, synlig difference) - men gjorde det bevidst UDEN at gemme
// noget ("ren on-demand sammenligning, ingen ny tabel nødvendig", se dens
// egen oprindelige kommentar). En bekendtgørelse om et "standard bogførings-
// system" lægger vægt på at kunne DOKUMENTERE at afstemning reelt er udført
// løbende, ikke kun at værktøjet FINDES - en ren on-demand-visning efterlader
// intet revisionsspor af, hvornår og af hvem en konto sidst blev afstemt.
// Denne tabel gør en udført afstemning til en permanent, dateret post.
// Bevidst APPEND-ONLY (ingen redigerings-/sletteendpoint bygget nogen steder)
// - samme filosofi som append_only-triggerne på journal/ledger/expenses:
// beviset for at en afstemning blev udført på et givent tidspunkt må ikke
// kunne ændres/slettes bagefter.
echo "Motor: $db_type\n\n";

$sql_sqlite = "CREATE TABLE IF NOT EXISTS bank_reconciliations (
    recon_id INTEGER PRIMARY KEY AUTOINCREMENT,
    acc_id INTEGER NOT NULL,
    stmt_date TEXT NOT NULL,
    gl_balance NUMERIC NOT NULL,
    stmt_balance NUMERIC NOT NULL,
    difference NUMERIC NOT NULL,
    note TEXT,
    created_by INTEGER,
    created_at TEXT
)";
$sql_mysql = "CREATE TABLE IF NOT EXISTS bank_reconciliations (
    recon_id INT AUTO_INCREMENT PRIMARY KEY,
    acc_id INT NOT NULL,
    stmt_date DATE NOT NULL,
    gl_balance DECIMAL(15,2) NOT NULL,
    stmt_balance DECIMAL(15,2) NOT NULL,
    difference DECIMAL(15,2) NOT NULL,
    note TEXT,
    created_by INT,
    created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (DB::query($conn, DB::is_sqlite() ? $sql_sqlite : $sql_mysql)) {
    echo "[OK] Tabellen bank_reconciliations findes/blev oprettet.\n";
} else {
    echo "[FEJL] Kunne ikke oprette bank_reconciliations: " . DB::error($conn) . "\n";
}

echo "\nFaerdig. Afstemninger gemmes nu fra bank_balance_check.php (knappen\n";
echo "\"Gem afstemning\" efter en sammenligning) - ingen historik er migreret,\n";
echo "da ingen tidligere on-demand-sammenligninger blev gemt nogen steder.\n";
