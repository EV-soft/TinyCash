<?php # /migration_auto_backup.php v:1.2.0 d:2026-08-11 i:evs 
# Kør denne fil én gang via browser eller run_migrate.php
# Virker med både SQLite (INSERT OR IGNORE) og MySQL (INSERT IGNORE)
require_once 'inc/db_connect.inc.php';

$rows = [
    ['auto_backup_mail',     ''],
    ['auto_backup_password', ''],
    ['auto_backup_last',     '0'],
    ['auto_backup_error',    ''],
];

$is_sqlite = DB::is_sqlite();
$ok = 0; $skip = 0;

foreach ($rows as [$key, $val]) {
    $sql = $is_sqlite
        ? "INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('$key', '$val')"
        : "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('$key', '$val')";
    DB::query($conn, $sql) ? $ok++ : $skip++;
}

// Registrer migration
$mig_sql = $is_sqlite
    ? "INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('auto_backup_settings_2026_08_04')"
    : "INSERT IGNORE INTO system_migrations (migration_key) VALUES ('auto_backup_settings_2026_08_04')";
DB::query($conn, $mig_sql);

echo "<p style='font-family:sans-serif; color:green;'>✓ Migration fuldført — $ok nøgler tilføjet, $skip sprunget over (eksisterede allerede).</p>";
