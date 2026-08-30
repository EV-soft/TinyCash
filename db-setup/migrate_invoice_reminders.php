<?php # /db-setup/migrate_invoice_reminders.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: rykkerfunktion for forfaldne fakturaer (reminders.php) - tilføjer to
// felter til invoices, så vi kan vise/sortere efter seneste rykker og antal
// rykkere sendt, uden en separat historik-tabel (holder det simpelt til v1).
echo "Motor: $db_type\n";

$columns = [
    'reminder_sent_at' => DB::is_sqlite() ? 'TIMESTAMP' : 'DATETIME',
    'reminder_count'   => DB::is_sqlite() ? 'INTEGER DEFAULT 0' : 'INT DEFAULT 0',
];

foreach ($columns as $col => $type) {
    $has_column = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(invoices)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === $col) { $has_column = true; break; }
            }
        }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM invoices LIKE '$col'");
        $has_column = ($res && DB::num_rows($res) > 0);
    }

    if ($has_column) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på invoices.\n";
    } else {
        $alter_sql = "ALTER TABLE invoices ADD COLUMN $col $type";
        if (DB::query($conn, $alter_sql)) {
            echo "[OK] Kolonnen '$col' tilføjet til invoices.\n";
        } else {
            echo "[FEJL] " . DB::error($conn) . "\n";
        }
    }
}

echo "\nFærdig. Du kan nu bruge reminders.php (Rykkere for forfaldne fakturaer).\n";
?>
