<?php # /db-setup/db_migrate.php v:1.3.0 d:2026-08-30 i:evs
# flyttet fra inc/; auth-gate + chdir; stier via __DIR__
# v1.3.0: ryddet op i migrations-løkken, som tidligere dublerede kolonne-
# tjek og ALTER-kørsel to gange i træk (harmløst, men rodet - resultat af
# iterativ redigering). Nu ét samlet, rent gennemløb. Tilføjet manglende
# fejlvisning ved en fejlet ALTER, og INSERT IGNORE/OR IGNORE konsekvent
# ved logning, så den ikke kan fejle på en allerede-logget nøgle.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';   // session + php2htm + deny_access_gracefully
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/menu.inc.php';    // til showMenu() i selve siden

// Definer alle dine migrationer her
// RETTET (bruger-anmodet sweep for "lignende problemer" i db-setup/):
// '20260706_add_currency_bank' målrettede en tabel ved navn
// "bank_transactions", som aldrig har eksisteret noget sted i skemaet (den
// faktiske bankimport-tabel hedder bank_statement_temp) - en fuld
// gennemsøgning af hele kodebasen viser INGEN andre referencer til
// "bank_transactions" overhovedet. Løkken nedenfor tjekker tabellens
// eksistens og springer pænt over hvis den mangler (§table_exists), så
// dette var ikke funktionelt ødelagt - blot en støjende, forvirrende
// "Ignoreret: Tabellen findes ikke"-besked ved hvert eneste kald, uden
// nogensinde at kunne blive rettet ved bare at vente. Fjernet i stedet for
// at gætte på en tabel der aldrig var tiltænkt at eksistere.
$migrations = [
    '20260706_add_currency_expenses' => "ALTER TABLE expenses ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_invoices' => "ALTER TABLE invoices ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
    '20260706_add_currency_journal'  => "ALTER TABLE journal ADD COLUMN currency VARCHAR(3) DEFAULT 'DKK'",
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

    // 3. Tjek om kolonnen findes (RYDDET OP 2026-08-19 - dette tjek og selve
    // ALTER-kørslen stod tidligere dublet to gange i træk pga. iterativ
    // redigering; det andet forsøg fejlede altid stille, fordi kolonnen
    // allerede var tilføjet af det første - harmløst, men rodet. Nu ét
    // samlet, rent gennemløb.)
    $col_exists = false;
    if (DB::is_sqlite()) {
        $col_check = DB::query($conn, "PRAGMA table_info($table)");
        while ($row = DB::fetch_assoc($col_check)) {
            if ($row['name'] === 'currency') { $col_exists = true; break; }
        }
    } else {
        $col_check = DB::query($conn, "SHOW COLUMNS FROM `$table` LIKE 'currency'");
        $col_exists = ($col_check && DB::num_rows($col_check) > 0);
    }

    // 4. Kør KUN hvis kolonnen ikke allerede findes
    if (!$col_exists) {
        if (DB::query($conn, $sql)) {
            $log_sql = DB::is_sqlite()
                ? "INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('$key')"
                : "INSERT IGNORE INTO system_migrations (migration_key) VALUES ('$key')";
            DB::query($conn, $log_sql);
            echo "<p style='color:green;'>✅ Oprettet: $key</p>";
        } else {
            echo "<p style='color:red;'>❌ Fejl ved $key: " . DB::error($conn) . "</p>";
        }
    } else {
        echo "<p style='color:gray;'>⏭️ Eksisterer allerede: $key</p>";
        // Log den som kørt, hvis den mangler i loggen
        $log_sql = DB::is_sqlite()
            ? "INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('$key')"
            : "INSERT IGNORE INTO system_migrations (migration_key) VALUES ('$key')";
        DB::query($conn, $log_sql);
    }
}