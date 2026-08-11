<?php # /migration_credit.php v:1.2.0 d:2026-08-11 i:evs 
# Tilføjer credit_ref kolonne på invoices og no_attachment_reason på expenses
require_once 'db_connect.inc.php';

$is_sqlite = DB::is_sqlite();
$ok = 0; $skip = 0;

$alters = [
    // Kreditnota-reference på fakturaer
    ['invoices',  'credit_ref',            'INTEGER DEFAULT NULL'],
    // Bilagsmangelsbegrundelse på udgifter
    ['expenses',  'no_attachment_reason',   'TEXT DEFAULT NULL'],
    // Krediteret og annulleret af på udgifter
    ['expenses',  'cancelled_by',           'INTEGER DEFAULT NULL'],
];

foreach ($alters as [$tbl, $col, $def]) {
    // Tjek om kolonnen allerede eksisterer
    if ($is_sqlite) {
        $res = DB::query($conn, "PRAGMA table_info($tbl)");
        $exists = false;
        while ($r = DB::fetch_assoc($res)) {
            if ($r['name'] === $col) { $exists = true; break; }
        }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM $tbl LIKE '$col'");
        $exists = (DB::fetch_assoc($res) !== false);
    }

    if ($exists) {
        echo "<p style='color:var(--text-muted); font-family:sans-serif;'>⏭ $tbl.$col — allerede til stede, springer over.</p>";
        $skip++;
    } else {
        DB::query($conn, "ALTER TABLE $tbl ADD COLUMN $col $def");
        echo "<p style='color:green; font-family:sans-serif;'>✓ $tbl.$col tilføjet.</p>";
        $ok++;
    }
}

// Registrer migration
$mig_key = 'credit_note_columns_2026_08_05';
$mig_sql = $is_sqlite
    ? "INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('$mig_key')"
    : "INSERT IGNORE INTO system_migrations (migration_key) VALUES ('$mig_key')";
DB::query($conn, $mig_sql);

echo "<p style='font-family:sans-serif; margin-top:10px; font-weight:bold;'>Migration fuldført — $ok kolonner tilføjet, $skip sprunget over.</p>";
?>
