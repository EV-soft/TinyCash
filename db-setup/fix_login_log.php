<?php # /db-setup/fix_login_log.php v:1.3.0 d:2026-08-30 i:evs
# - opretter KUN login_log-tabellen
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers fatal error - se
// subdir-scripts-need-chdir i hukommelsen; samme fejl som create_all_tables.php).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Motor i brug: $db_type\n";

if (DB::is_sqlite()) {
    $sql = "CREATE TABLE IF NOT EXISTS login_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        logged_username TEXT,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address TEXT,
        status TEXT,
        user_agent TEXT
    )";
} else {
    $sql = "CREATE TABLE IF NOT EXISTS login_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        logged_username VARCHAR(100),
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45),
        status VARCHAR(20),
        user_agent VARCHAR(255)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

if (DB::query($conn, $sql)) {
    echo "[OK] login_log-tabellen findes nu (oprettet, eller fandtes allerede).\n";
    $check = DB::query($conn, "SELECT COUNT(*) FROM login_log");
    if ($check) {
        $row = DB::fetch_row($check);
        echo "[OK] Bekræftet: login_log er læsbar, indeholder " . $row[0] . " række(r).\n";
    }
} else {
    echo "[FEJL] Kunne ikke oprette login_log: " . DB::error($conn) . "\n";
}

echo "\nDu kan nu slette setup/-mappen og prøve at logge ind igen.\n";
