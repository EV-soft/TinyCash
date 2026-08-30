<?php # /db-setup/migration_projects.php v:1.3.0 d:2026-08-30 i:evs
/**
 * Migration: Projekt-modul
 *
 * v1.0.0  Oprettede tabel 'projects', proj_id på invoice_lines/expenses/transactions
 * v1.1.0  proj_id tilføjet til journal
 * v1.2.0  proj_id tilføjet til invoices
 * v1.3.0  exp_type på expenses (expense/income)
 *         note_expenses, note_income, note_general på projects
 * v1.4.0  RETTET - spærren krævede at nøglen 'add_projects_module_v1' allerede
 *         var logget i system_migrations, FØR v1.3.0-tilføjelserne kunne
 *         køres. Intet script i db-setup/ har nogensinde skrevet den nøgle
 *         (v1.0-v1.2 blev konsolideret ind i denne samme fil på et tidspunkt,
 *         uden at det blev logget) - spærren var derfor UMULIG at komme
 *         forbi på enhver installation, uanset om projects-tabellen (det
 *         reelle grundlag) faktisk fandtes. Ramte direkte enhver installation
 *         hvor projects-tabellen kom fra create_all_tables.php (som allerede
 *         inkluderer exp_type/note_expenses/note_income/note_general i sit
 *         grund-skema) - der findes intet at logge en "grundmigration" for,
 *         og v1.3.0 kunne aldrig køres/bekræftes. Erstattet med et tjek af
 *         det RIGTIGE grundlag (findes projects-tabellen overhovedet?) i
 *         stedet for en nøgle intet script udskriver.
 *
 * Understøtter både SQLite og MySQL. Idempotent.
 */

chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';

// --- Tjek om grundlaget (selve projects-tabellen) findes ---
$projects_table_exists = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='projects'");
    $projects_table_exists = ($res && DB::fetch_assoc($res));
} else {
    $res = DB::query($conn, "SHOW TABLES LIKE 'projects'");
    $projects_table_exists = ($res && DB::num_rows($res) > 0);
}
if (!$projects_table_exists) {
    echo "❌ Tabellen 'projects' findes ikke endnu. Kør db-setup/create_all_tables.php først (opretter hele grundskemaet, inkl. projects).\n";
    exit;
}

$upgrade_key = 'add_projects_module_v1_3';
$check2 = DB::query($conn, "SELECT id FROM system_migrations WHERE migration_key = '$upgrade_key'");
if (DB::num_rows($check2) > 0) {
    echo "Migration '$upgrade_key' er allerede anvendt. Springer over.\n";
    exit;
}

$errors = [];
DB::begin_transaction($conn);

try {

    // =========================================================
    // 1. expenses: tilføj exp_type (expense / income)
    // =========================================================
    $col_exists = false;
    if (DB::is_sqlite()) {
        $pragma = DB::query($conn, "PRAGMA table_info(expenses)");
        while ($col = DB::fetch_assoc($pragma)) {
            if ($col['name'] === 'exp_type') { $col_exists = true; break; }
        }
        if (!$col_exists) {
            $res = DB::query($conn, "ALTER TABLE expenses ADD COLUMN exp_type TEXT NOT NULL DEFAULT 'expense'");
            if ($res === false) $errors[] = "Fejl ved ALTER TABLE expenses (SQLite): " . DB::error($conn);
        }
    } else {
        $db_name = DB::escape($conn, $db_settings['DB_NAME'] ?? '');
        $check_col = DB::query($conn,
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'exp_type'");
        if (DB::num_rows($check_col) === 0) {
            $res = DB::query($conn, "ALTER TABLE `expenses` ADD COLUMN `exp_type` VARCHAR(10) NOT NULL DEFAULT 'expense'");
            if ($res === false) $errors[] = "Fejl ved ALTER TABLE expenses (MySQL): " . DB::error($conn);
        }
    }

    // =========================================================
    // 2. projects: tilføj note_expenses, note_income, note_general
    // =========================================================
    $note_cols = ['note_expenses', 'note_income', 'note_general'];

    foreach ($note_cols as $col_name) {
        $col_exists = false;
        if (DB::is_sqlite()) {
            $pragma = DB::query($conn, "PRAGMA table_info(projects)");
            while ($col = DB::fetch_assoc($pragma)) {
                if ($col['name'] === $col_name) { $col_exists = true; break; }
            }
            if (!$col_exists) {
                $res = DB::query($conn, "ALTER TABLE projects ADD COLUMN $col_name TEXT DEFAULT NULL");
                if ($res === false) $errors[] = "Fejl ved ALTER TABLE projects ADD $col_name: " . DB::error($conn);
            }
        } else {
            $db_name = DB::escape($conn, $db_settings['DB_NAME'] ?? '');
            $check_col = DB::query($conn,
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'projects' AND COLUMN_NAME = '$col_name'");
            if (DB::num_rows($check_col) === 0) {
                $res = DB::query($conn, "ALTER TABLE `projects` ADD COLUMN `$col_name` TEXT DEFAULT NULL");
                if ($res === false) $errors[] = "Fejl ved ALTER TABLE projects ADD $col_name (MySQL): " . DB::error($conn);
            }
        }
    }

    // =========================================================
    // 3. Registrér opgraderingen
    // =========================================================
    if (empty($errors)) {
        $res = DB::query($conn, "INSERT INTO system_migrations (migration_key) VALUES ('$upgrade_key')");
        if ($res === false) $errors[] = "Fejl ved registrering i system_migrations: " . DB::error($conn);
    }

    if (!empty($errors)) throw new Exception(implode("\n", $errors));

    DB::commit($conn);
    echo "✅ Migration '$upgrade_key' gennemført uden fejl.\n";
    echo "   - expenses.exp_type tilføjet (DEFAULT 'expense')\n";
    echo "   - projects.note_expenses, note_income, note_general tilføjet\n";

} catch (Exception $e) {
    DB::rollback($conn);
    echo "❌ Migration fejlede — alle ændringer er rullet tilbage.\n";
    echo $e->getMessage() . "\n";
}
?>
