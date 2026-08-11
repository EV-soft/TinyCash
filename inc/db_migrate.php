<?php # inc/db_migrate.php v:1.1.0 d:2026-07-02 i:evs
require_once __DIR__ . '/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once __DIR__ . '/php2htm.lib.php';

// Adgangskontrol
session_name('TCC_V100_SESSION');
session_start();
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] < 3) {
    die("Access Denied");
}

// Definer alle dine migrationer her
$migrations = [
    '20260706_add_currency_expenses' => "ALTER TABLE expenses ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_invoices' => "ALTER TABLE invoices ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_journal'  => "ALTER TABLE journal ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_bank'     => "ALTER TABLE bank_transactions ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_transactions' => "ALTER TABLE transactions ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_invoice_lines' => "ALTER TABLE invoice_lines ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'"
];
// 1. Header kaldes (indeholder menuen)
htm_Header("Migration Engine");
showMenu();

echo '<div class="cardW000"><h1>Database Migration</h1>';

// 1. Sørg for at log-tabellen findes
if (DB::is_sqlite()) {
    DB::query($conn, "CREATE TABLE IF NOT EXISTS system_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        migration_key TEXT UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} else {
    DB::query($conn, "CREATE TABLE IF NOT EXISTS system_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration_key VARCHAR(100) UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}


foreach ($migrations as $key => $sql) {
    // 1. Find tabelnavnet
    preg_match('/ALTER TABLE `?(\w+)`?/', $sql, $matches);
    $table = $matches[1] ?? '';

    // 2. Tjek tabellens eksistens (Sikker metode)
    $table_exists = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        $table_exists = ($res && $res->fetch());
    } else {
        $res = DB::query($conn, "SHOW TABLES LIKE '$table'");
        $table_exists = ($res && DB::num_rows($res) > 0);
    }

    if (!$table_exists) {
        echo "<p style='color:orange;'>⏭️ Ignoreret: $key (Tabellen '$table' findes ikke)</p>";
        continue; // GÅ VIDERE TIL NÆSTE MIGRATION - dette er afgørende!
    }

    // 3. Tjek for kolonne (kun hvis tabellen findes)
    // 3. Tjek om kolonnen findes
    if (DB::is_sqlite()) {
        // SQLite tjek for kolonne
        $col_check = DB::query($conn, "PRAGMA table_info($table)");
        $col_exists = false;
        while ($row = DB::fetch_assoc($col_check)) {
            if ($row['name'] == 'currency') { $col_exists = true; break; }
        }
    } else {
        // MySQL tjek
        $col_check = DB::query($conn, "SHOW COLUMNS FROM `$table` LIKE 'currency'");
        $col_exists = (DB::num_rows($col_check) > 0);
    }
    
    // Før du kører ALTER TABLE, tjek om kolonnen eksisterer
$col_exists = false;
if (DB::is_sqlite()) {
    // SQLite tjekker PRAGMA table_info
    $res = DB::query($conn, "PRAGMA table_info($table)");
    if ($res) {
        foreach ($res as $row) {
            if (isset($row['name']) && $row['name'] == 'currency') {
                $col_exists = true;
            }
        }
    }
} else {
    // MySQL tjek
    $res = DB::query($conn, "SHOW COLUMNS FROM `$table` LIKE 'currency'");
    if ($res && DB::num_rows($res) > 0) {
        $col_exists = true;
    }
}

// Kør KUN hvis den IKKE findes
if (!$col_exists) {
    if (DB::query($conn, $sql)) {
        DB::query($conn, "INSERT INTO system_migrations (migration_key) VALUES ('$key')");
        echo "<p style='color:green;'>✅ Oprettet: $key</p>";
    }
} else {
    echo "<p style='color:gray;'>⏭️ Eksisterer allerede: $key</p>";
    // Log den som kørt, hvis den mangler i loggen
    DB::query($conn, "INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('$key')");
}
   
    // 4. KØR KUN HVIS TABELLEN ER BEKRÆFTET
    if (!$col_exists) {
        if (DB::query($conn, $sql)) {
            DB::query($conn, "INSERT INTO system_migrations (migration_key) VALUES ('$key')");
            echo "<p style='color:green;'>✅ Oprettet: $key</p>";
        }
    }
}