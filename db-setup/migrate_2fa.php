<?php # /db-setup/migrate_2fa.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: To-faktor-login (2FA), fra forslagslisten (§Sikkerhed). Tre nye felter
// på users - hver brugers TOTP-hemmelighed, om 2FA er slået til, og de
// engangs-gendannelseskoder der bruges hvis authenticator-appen mistes.
echo "Motor: $db_type\n";

$columns = [
    'totp_secret'          => DB::is_sqlite() ? 'TEXT' : 'VARCHAR(64)',
    'totp_enabled'         => DB::is_sqlite() ? 'INTEGER DEFAULT 0' : 'TINYINT(1) DEFAULT 0',
    'totp_recovery_codes'  => 'TEXT',
];

foreach ($columns as $col => $type) {
    $has_column = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(users)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === $col) { $has_column = true; break; }
            }
        }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM users LIKE '$col'");
        $has_column = ($res && DB::num_rows($res) > 0);
    }

    if ($has_column) {
        echo "[SPRUNGET OVER] Kolonnen '$col' findes allerede på users.\n";
    } else {
        $alter_sql = "ALTER TABLE users ADD COLUMN $col $type";
        if (DB::query($conn, $alter_sql)) {
            echo "[OK] Kolonnen '$col' tilføjet til users.\n";
        } else {
            echo "[FEJL] " . DB::error($conn) . "\n";
        }
    }
}

echo "\nFærdig. Hver bruger kan nu selv slå 2FA til under 'Min konto -> Sikkerhed' (my_2fa.php).\n";
?>
