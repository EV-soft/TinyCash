<?php # /db-setup/migrate_quote_followup.php v:1.3.1 d:2026-09-15 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: tilbud-opfølgning (quote_followup.php) - samme to felter, samme
// begrundelse, som reminders.php fik af migrate_invoice_reminders.php,
// blot på quotes i stedet for invoices (kræver at db-setup/migrate_quotes.php
// allerede er kørt - quotes-tabellen skal findes).
echo "Motor: $db_type\n";

$quotes_exists = @DB::query($conn, "SELECT 1 FROM quotes LIMIT 1") !== false;
if (!$quotes_exists) {
    echo "[FEJL] Tabellen 'quotes' findes ikke endnu - kør db-setup/migrate_quotes.php først.\n";
    exit;
}

$columns = [
    'followup_sent_at' => DB::is_sqlite() ? 'TIMESTAMP' : 'DATETIME',
    'followup_count'   => DB::is_sqlite() ? 'INTEGER DEFAULT 0' : 'INT DEFAULT 0',
];

foreach ($columns as $col => $type) {
    $has_column = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(quotes)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === $col) { $has_column = true; break; }
            }
        }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM quotes LIKE '$col'");
        $has_column = ($res && DB::num_rows($res) > 0);
    }

    if ($has_column) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på quotes.\n";
    } else {
        $alter_sql = "ALTER TABLE quotes ADD COLUMN $col $type";
        if (DB::query($conn, $alter_sql)) {
            echo "[OK] Kolonnen '$col' tilføjet til quotes.\n";
        } else {
            echo "[FEJL] " . DB::error($conn) . "\n";
        }
    }
}

echo "\nFærdig. Du kan nu bruge quote_followup.php (Tilbud-opfølgning).\n";
?>
