<?php # /db-setup/migrate_bank_match_rules.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33):
// bankafstemning (reconcile_list.php) foreslog hidtil KUN en konto automatisk
// for beløb der matcher præcis én åben faktura - enhver anden banklinje
// (udgifter/direkte konteringer) krævede manuelt valg af konto, hver gang,
// selv for tilbagevendende poster (fx "NETS - Betalingsservice" -> altid
// samme konto). Denne tabel gemmer "hvis beskrivelse indeholder X -> konto Y"-
// regler, som reconcile_list.php nu slår op og foreslår automatisk. Se
// bank_rules.php (administration) og reconcile_action.php (gem-ved-afstemning).
echo "Motor: $db_type\n\n";

$sql_sqlite = "CREATE TABLE IF NOT EXISTS bank_match_rules (
    rule_id INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern TEXT NOT NULL,
    acc_id INTEGER NOT NULL,
    created_by INTEGER,
    created_at TEXT
)";
$sql_mysql = "CREATE TABLE IF NOT EXISTS bank_match_rules (
    rule_id INT AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(255) NOT NULL,
    acc_id INT NOT NULL,
    created_by INT,
    created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (DB::query($conn, DB::is_sqlite() ? $sql_sqlite : $sql_mysql)) {
    echo "[OK] Tabellen bank_match_rules findes/blev oprettet.\n";
} else {
    echo "[FEJL] Kunne ikke oprette bank_match_rules: " . DB::error($conn) . "\n";
}

echo "\nFærdig. Ingen regler er oprettet endnu - de tilføjes enten via\n";
echo "bank_rules.php eller \"Husk denne regel\" ved en almindelig afstemning.\n";
