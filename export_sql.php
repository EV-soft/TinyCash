<?php # /export_sql.php v:1.3.0 d:2026-08-30 i:evs
# v1.1.0: ALVORLIGT FUND - denne fil brugte ren MySQL-syntaks direkte
# ("SHOW TABLES", "SHOW CREATE TABLE") i strid med projektets egen DB-lag-
# regel (se CLAUDE.md: "Never call mysqli_* or PDO directly") - fejlede
# derfor 100% på SQLite (dette dev-miljøs og mindst én live-installations
# motor). Brugte også den udefinerede konstant DB_NAME i filnavnet (fatal
# error i PHP 8). En korrekt, cross-engine udgave af PRÆCIS denne funktion
# fandtes allerede (DB::dump_to_sql(), brugt af auto_backup.inc.php) - denne
# fil var en overflødig, ringere, MySQL-only gendannelse af samme logik.
# Rettet ved at genbruge DB::dump_to_sql() i stedet. Fundet ved en rapport-/
# eksportgennemgang.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// Kun administratorer må trække rå databasedumps ud
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.0 403 Forbidden');
    die("Access denied.");
}

// Sæt tidsbegrænsning op, hvis databasen er stor
set_time_limit(300);

$sql_dump = DB::dump_to_sql($conn);

// Forbered browseren på en fil-download (Vigtigt: Ingen HTML/print før dette!)
$filename = 'tinycash_export_' . $db_type . '_' . date('Y-m-d_H-i-s') . '.sql';

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($sql_dump));

// Output dumpet og afslut scriptet med det samme
echo $sql_dump;
exit;
