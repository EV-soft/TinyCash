<?php # /db-setup/migrate_customer_no_reminders.php v:1.0.0 d:2026-09-15 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): der var ingen måde
// at udelukke en bestemt kunde fra automatiske betalingsrykkere - relevant
// for følsomme kunderelationer, hvor bogholderen hellere vil rykke manuelt/
// telefonisk end sende en automatisk mail. Respekteres af reminders.php's
// liste (kunden vises stadig, men markeret og ikke valgbar til masse-
// udsendelse - "fravalgt" er en bevidst beslutning, ikke en fejl der skal
// skjules).
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(customers)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'cust_no_reminders') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'cust_no_reminders'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'cust_no_reminders' findes allerede på customers.\n";
} else {
    $type = DB::is_sqlite() ? 'INTEGER DEFAULT 0' : 'TINYINT(1) DEFAULT 0';
    if (DB::query($conn, "ALTER TABLE customers ADD COLUMN cust_no_reminders $type")) {
        echo "[OK] Kolonnen 'cust_no_reminders' tilføjet til customers.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Fravalg af rykkere kan nu sættes pr. kunde på customer_edit.php.\n";
?>
