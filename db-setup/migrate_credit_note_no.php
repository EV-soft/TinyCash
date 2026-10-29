<?php # /db-setup/migrate_credit_note_no.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33):
// en kreditnota (invoice_credit.php) fik ALDRIG sit eget kundevendte
// fakturanummer - kun et internt bilagsnummer (voucher_no) i journalen.
// invoice_view.php viste derfor "@DRAFT" for en kreditnota (den har jo intet
// invoice_no), og customer_statement.inc.php/sales_hub.php viste enten et
// tomt felt eller "Kreditnota #000000" - selvom kreditnotaen er en fuldt
// bogført, uforanderlig transaktion i samme øjeblik den oprettes. Denne
// migration tilføjer en SEPARAT nummerserie (credit_note_no), parallel til
// invoice_no_counter, så en kreditnota fremover kan vises som fx "KN-000001".
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(invoices)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'credit_note_no') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM invoices LIKE 'credit_note_no'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'credit_note_no' findes allerede på invoices.\n";
} else {
    $type = DB::is_sqlite() ? 'INTEGER' : 'INT';
    if (DB::query($conn, "ALTER TABLE invoices ADD COLUMN credit_note_no $type")) {
        echo "[OK] Kolonnen 'credit_note_no' tilføjet til invoices.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

$sql_sqlite = "CREATE TABLE IF NOT EXISTS credit_note_no_counter (
    id INTEGER PRIMARY KEY,
    next_no INTEGER NOT NULL
)";
$sql_mysql = "CREATE TABLE IF NOT EXISTS credit_note_no_counter (
    id INT PRIMARY KEY,
    next_no INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (DB::query($conn, DB::is_sqlite() ? $sql_sqlite : $sql_mysql)) {
    echo "[OK] Tabellen credit_note_no_counter findes/blev oprettet.\n";
} else {
    echo "[FEJL] Kunne ikke oprette credit_note_no_counter: " . DB::error($conn) . "\n";
    exit;
}

$existing = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM credit_note_no_counter WHERE id = 1"));
if (!$existing) {
    if (DB::insert($conn, 'credit_note_no_counter', ['id' => 1, 'next_no' => 1])) {
        echo "[OK] Tælleren seedet - næste tildelte kreditnota-nummer bliver 1.\n";
    } else {
        echo "[FEJL] Kunne ikke seede tælleren: " . DB::error($conn) . "\n";
        exit;
    }
} else {
    echo "[SPRUNGET OVER] Tælleren findes allerede (næste nummer: {$existing['next_no']}).\n";
}

// Bagudkompatibilitet: eksisterende kreditnotaer (oprettet før denne
// migration) har credit_ref sat, men credit_note_no = NULL. Tildel dem
// numre nu, i kronologisk (inv_id) rækkefølge, så de ikke for evigt
// forbliver nummerløse.
$backfilled = 0;
$res = DB::query($conn, "SELECT inv_id FROM invoices WHERE credit_ref IS NOT NULL AND credit_note_no IS NULL ORDER BY inv_id ASC");
if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $counter_row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM credit_note_no_counter WHERE id = 1"));
        $no = (int)$counter_row['next_no'];
        DB::query($conn, "UPDATE invoices SET credit_note_no = $no WHERE inv_id = " . (int)$row['inv_id']);
        DB::query($conn, "UPDATE credit_note_no_counter SET next_no = next_no + 1 WHERE id = 1");
        $backfilled++;
    }
}
if ($backfilled > 0) {
    echo "[OK] $backfilled eksisterende kreditnota(er) fik tildelt et nummer med tilbagevirkende kraft.\n";
}

echo "\nFærdig. Nye kreditnotaer får fremover automatisk deres eget nummer\n";
echo "(vist som 'KN-NNNNNN' på invoice_view.php/kundeudtog/faktura-oversigt),\n";
echo "adskilt fra den almindelige fakturanummerserie.\n";
