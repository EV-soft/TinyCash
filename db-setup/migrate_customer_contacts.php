<?php # /db-setup/migrate_customer_contacts.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33):
// customers-tabellen har kun ét fladt sæt kontaktfelter (cust_contact_person/
// cust_email/cust_phone) - en kunde med fx en separat bogholder til fakturaer
// og en anden kontaktperson til almindelig korrespondance kunne ikke
// registreres. Denne migration tilføjer en separat customer_contacts-tabel,
// se customer_contact_action.php (gem/slet) og customer_edit.php (visning).
echo "Motor: $db_type\n\n";

$sql_sqlite = "CREATE TABLE IF NOT EXISTS customer_contacts (
    contact_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cust_id INTEGER NOT NULL,
    contact_name TEXT,
    contact_email TEXT,
    contact_phone TEXT,
    contact_note TEXT,
    created_at TEXT
)";
$sql_mysql = "CREATE TABLE IF NOT EXISTS customer_contacts (
    contact_id INT AUTO_INCREMENT PRIMARY KEY,
    cust_id INT NOT NULL,
    contact_name VARCHAR(200),
    contact_email VARCHAR(200),
    contact_phone VARCHAR(50),
    contact_note VARCHAR(200),
    created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (DB::query($conn, DB::is_sqlite() ? $sql_sqlite : $sql_mysql)) {
    echo "[OK] Tabellen customer_contacts findes/blev oprettet.\n";
} else {
    echo "[FEJL] Kunne ikke oprette customer_contacts: " . DB::error($conn) . "\n";
}

echo "\nFærdig. Ingen kontaktpersoner er oprettet endnu - tilføjes på den\n";
echo "enkelte kundes side (customer_edit.php).\n";
