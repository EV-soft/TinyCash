<?php # /db-setup/migrate_cancelled_by.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: expense_actions.php (den ægte, fundne version) skriver til
// expenses.cancelled_by ved annullering - denne kolonne fandtes ikke i
// det oprindelige skema, vi havde adgang til. Tilføjes her, hvis den mangler.
echo "Motor: $db_type\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(expenses)");
    if ($res) {
        while ($row = DB::fetch_assoc($res)) {
            if (strtolower($row['name']) === 'cancelled_by') { $has_column = true; break; }
        }
    }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE 'cancelled_by'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'cancelled_by' findes allerede på expenses.\n";
} else {
    $alter_sql = DB::is_sqlite()
        ? "ALTER TABLE expenses ADD COLUMN cancelled_by INTEGER"
        : "ALTER TABLE expenses ADD COLUMN cancelled_by INT";
    if (DB::query($conn, $alter_sql)) {
        echo "[OK] Kolonnen 'cancelled_by' tilføjet til expenses.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nDu kan nu slette setup/-mappen, når du er færdig med opsætningen.\n";
