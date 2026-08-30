<?php # /db-setup/migrate_invoice_no_counter.php v:1.3.0 d:2026-08-30 i:evs
// auth.inc.php bruger CWD-relative includes; chdir til roden så det virker fra db-setup/.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// invoice_post_action.php brugte før "SELECT MAX(invoice_no)+1" uden nogen
// låsning for at finde næste fakturanummer - sårbart for et kapløb, hvor to
// samtidige bogføringer kunne læse samme MAX() og få samme nummer. Denne
// migration opretter en atomisk tæller-tabel (samme mønster som
// voucher_counter) og seeder den til over det højeste invoice_no der
// allerede findes, så intet eksisterende fakturanummer kan blive genbrugt.
echo "Motor: $db_type\n";

$sql_sqlite = "CREATE TABLE IF NOT EXISTS invoice_no_counter (
    id INTEGER PRIMARY KEY,
    next_no INTEGER NOT NULL
)";
$sql_mysql = "CREATE TABLE IF NOT EXISTS invoice_no_counter (
    id INT PRIMARY KEY,
    next_no INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (DB::query($conn, DB::is_sqlite() ? $sql_sqlite : $sql_mysql)) {
    echo "[OK] Tabellen invoice_no_counter findes/blev oprettet.\n";
} else {
    echo "[FEJL] Kunne ikke oprette invoice_no_counter: " . DB::error($conn) . "\n";
    exit;
}

$existing = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM invoice_no_counter WHERE id = 1"));
if ($existing) {
    echo "[SPRUNGET OVER] Tælleren findes allerede (næste nummer: {$existing['next_no']}).\n";
} else {
    $max_invoice = DB::fetch_assoc(DB::query($conn, "SELECT MAX(invoice_no) AS m FROM invoices"));
    $seed = max((int)($max_invoice['m'] ?? 0) + 1, 1001);

    if (DB::insert($conn, 'invoice_no_counter', ['id' => 1, 'next_no' => $seed])) {
        echo "[OK] Tælleren seedet - næste tildelte fakturanummer bliver $seed.\n";
    } else {
        echo "[FEJL] Kunne ikke seede tælleren: " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Nye fakturaer tildeles nu fakturanummer fra en atomisk, kapløbssikker tæller. Slet db-setup/ når opsætningen er fuldført.\n";
