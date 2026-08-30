<?php # /db-setup/migrate_menu_visibility.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: konfigurerbar menu-synlighed pr. brugerniveau (bruger-anmodet). Ny
// tabel menu_visibility - mangler en række for et givent menu-punkt, er det
// synligt for alle tre niveauer som standard (uændret adfærd). Se
// menu_visibility.php for selve administrationssiden og
// inc/menu.inc.php's get_menu_visibility_overrides()/get_menu_structure().
echo "Motor: $db_type\n\n";

$table_exists = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='menu_visibility'");
    $table_exists = ($res && $res->fetch());
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'menu_visibility'");
    $table_exists = ($res && DB::num_rows($res) > 0);
}

if ($table_exists) {
    echo "[SPRUNGET OVER] Tabellen 'menu_visibility' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE menu_visibility (
              item_key TEXT PRIMARY KEY,
              level_1 INTEGER NOT NULL DEFAULT 1,
              level_2 INTEGER NOT NULL DEFAULT 1,
              level_3 INTEGER NOT NULL DEFAULT 1
          )"
        : "CREATE TABLE menu_visibility (
              item_key VARCHAR(100) PRIMARY KEY,
              level_1 TINYINT(1) NOT NULL DEFAULT 1,
              level_2 TINYINT(1) NOT NULL DEFAULT 1,
              level_3 TINYINT(1) NOT NULL DEFAULT 1
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'menu_visibility' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu styre hvilke menu-punkter der vises pr. brugerniveau under System -> Maintenance -> Menu-synlighed.\n";
?>
