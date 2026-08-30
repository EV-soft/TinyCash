<?php # /db-setup/migrate_voucher_counter.php v:1.3.0 d:2026-08-30 i:evs
// auth.inc.php bruger CWD-relative includes; chdir til roden så det virker fra db-setup/.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// Bogføringslov: bilagsnumre (voucher_no) skal være ét fælles, fortløbende,
// hulfrit nummerserie på tværs af ALLE posteringstyper - ikke separate
// tællere pr. tabel (fakturaer brugte invoice_no, udgifter deres egen
// MAX+1, og kreditnotaer/bankafstemninger satte slet intet voucher_no).
// Denne migration opretter en fælles tæller-tabel og seeder den til over
// det højeste voucher_no der allerede findes nogen steder i systemet, så
// ingen eksisterende bilagsnumre kan blive genbrugt.
echo "Motor: $db_type\n";

$sql_sqlite = "CREATE TABLE IF NOT EXISTS voucher_counter (
    id INTEGER PRIMARY KEY,
    next_no INTEGER NOT NULL
)";
$sql_mysql = "CREATE TABLE IF NOT EXISTS voucher_counter (
    id INT PRIMARY KEY,
    next_no INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (DB::query($conn, DB::is_sqlite() ? $sql_sqlite : $sql_mysql)) {
    echo "[OK] Tabellen voucher_counter findes/blev oprettet.\n";
} else {
    echo "[FEJL] Kunne ikke oprette voucher_counter: " . DB::error($conn) . "\n";
    exit;
}

$existing = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM voucher_counter WHERE id = 1"));
if ($existing) {
    echo "[SPRUNGET OVER] Tælleren findes allerede (næste nummer: {$existing['next_no']}).\n";
} else {
    // Højeste kendte voucher_no/bilagsnummer, uanset hvilken tabel det stammer fra.
    $max_journal  = DB::fetch_assoc(DB::query($conn, "SELECT MAX(voucher_no) AS m FROM journal"));
    $max_expense  = DB::fetch_assoc(DB::query($conn, "SELECT MAX(voucher_no) AS m FROM expenses"));
    $max_invoice  = DB::fetch_assoc(DB::query($conn, "SELECT MAX(invoice_no) AS m FROM invoices"));

    $highest = max(
        (int)($max_journal['m'] ?? 0),
        (int)($max_expense['m'] ?? 0),
        (int)($max_invoice['m'] ?? 0)
    );
    $seed = $highest + 1;

    if (DB::insert($conn, 'voucher_counter', ['id' => 1, 'next_no' => $seed])) {
        echo "[OK] Tælleren seedet - næste tildelte bilagsnummer bliver $seed.\n";
    } else {
        echo "[FEJL] Kunne ikke seede tælleren: " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Alle nye posteringer (fakturaer, kreditnotaer, udgifter, bankafstemning) tildeles nu bilagsnummer fra samme fælles, hulfri talrække. Slet db-setup/ når opsætningen er fuldført.\n";
