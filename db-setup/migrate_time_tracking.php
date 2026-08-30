<?php # /db-setup/migrate_time_tracking.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION: Timeregistrering (bruger-anmodet, sidste punkt fra "hvilke
// funktioner mangler TinyCash"-gennemgangen). Ny tabel 'time_entries' -
// bevidst bygget oven på det eksisterende projekt-begreb (proj_id), samme
// grundprincip som quotes/fixed_assets denne session: en registreret time
// er IKKE et regnskabsdokument og rører ALDRIG hovedbogen direkte - kun når
// timer samles til en faktura (time_actions.php?action=invoice) opstår der
// en almindelig fakturakladde, som bogføres helt normalt derfra via den
// eksisterende, allerede grundigt testede invoice_edit.php ->
// invoice_post_action.php-pipeline.
//
// hourly_rate og line_vat_rate gemmes PR. REGISTRERING (ikke kun som en
// reference til projektets/en indstillings aktuelle sats) - samme "gem et
// øjebliksbillede, ikke en levende reference"-princip som invoice_lines'
// price_each, så en senere ændret timesats ikke ændrer historiske,
// allerede loggede timer.
echo "Motor: $db_type\n\n";

function mtt_table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

function mtt_column_exists($conn, $table, $column) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info($table)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === strtolower($column)) return true;
            }
        }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && DB::num_rows($res) > 0);
}

// --- 1. Tabellen 'time_entries' ---
if (mtt_table_exists($conn, 'time_entries')) {
    echo "[SPRUNGET OVER] Tabellen 'time_entries' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE time_entries (
              entry_id INTEGER PRIMARY KEY AUTOINCREMENT,
              proj_id INTEGER,
              user_id INTEGER,
              entry_date DATE,
              description TEXT,
              hours NUMERIC,
              hourly_rate NUMERIC,
              line_vat_rate NUMERIC DEFAULT 25,
              is_billable INTEGER DEFAULT 1,
              is_invoiced INTEGER DEFAULT 0,
              inv_id INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE time_entries (
              entry_id INT AUTO_INCREMENT PRIMARY KEY,
              proj_id INT,
              user_id INT,
              entry_date DATE,
              description VARCHAR(500),
              hours DECIMAL(8,2),
              hourly_rate DECIMAL(12,2),
              line_vat_rate DECIMAL(5,2) DEFAULT 25,
              is_billable TINYINT DEFAULT 1,
              is_invoiced TINYINT DEFAULT 0,
              inv_id INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'time_entries' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

// --- 2. projects.default_hourly_rate (kun en forvalgt værdi til nye
//     registreringer på det pågældende projekt - ren bekvemmelighed, ikke
//     en regnskabsstyrende værdi). ---
if (mtt_column_exists($conn, 'projects', 'default_hourly_rate')) {
    echo "[SPRUNGET OVER] Kolonnen 'default_hourly_rate' findes allerede på projects.\n";
} else {
    $sql = DB::is_sqlite()
        ? "ALTER TABLE projects ADD COLUMN default_hourly_rate NUMERIC"
        : "ALTER TABLE projects ADD COLUMN default_hourly_rate DECIMAL(12,2)";
    if (DB::query($conn, $sql)) {
        echo "[OK] Kolonnen 'default_hourly_rate' tilføjet til projects.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu registrere timer under Projekter -> Timeregistrering.\n";
echo "Kræver at Projekt-modulet er aktivt (Firmaindstillinger), da hver time\n";
echo "kobles til et projekt for at vide hvilken kunde den skal faktureres til.\n";
echo "En registreret time påvirker ALDRIG hovedbogen eller momsopgørelsen -\n";
echo "kun ved 'Opret faktura af timer' opstår der en almindelig fakturakladde.\n";
