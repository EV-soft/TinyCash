<?php # /db-setup/migrate_field_visibility.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet): højreklik-skjul for enkelte FORMULARFELTER -
// samme princip og tabel-facon som den allerede eksisterende menu_visibility
// (inc/menu.inc.php/menu_hide_action.php), blot for felter i stedet for
// menupunkter. Se inc/field_visibility.inc.php/field_hide_action.php for
// selve mekanismen.
echo "Motor: $db_type\n\n";

$has_table = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='field_visibility'");
    $has_table = ($res && DB::fetch_assoc($res));
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'field_visibility'");
    $has_table = ($res && DB::num_rows($res) > 0);
}

if ($has_table) {
    echo "[SPRUNGET OVER] Tabellen 'field_visibility' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE field_visibility (
              item_key TEXT PRIMARY KEY,
              level_1 INTEGER NOT NULL DEFAULT 1,
              level_2 INTEGER NOT NULL DEFAULT 1,
              level_3 INTEGER NOT NULL DEFAULT 1
           )"
        : "CREATE TABLE field_visibility (
              item_key VARCHAR(191) PRIMARY KEY,
              level_1 TINYINT(1) NOT NULL DEFAULT 1,
              level_2 TINYINT(1) NOT NULL DEFAULT 1,
              level_3 TINYINT(1) NOT NULL DEFAULT 1
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'field_visibility' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Højreklik på et markeret (stiplet blå) felt for at skjule det\n";
echo "for L1/L2 - en administrator ser altid feltet.\n";
?>
