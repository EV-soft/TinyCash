<?php # /export_sql.php v:1.0.0 d:2026-06-15 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// Kun administratorer må trække rå databasedumps ud
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.0 403 Forbidden');
    die("Access denied.");
}

// Sæt tidsbegrænsning op, hvis databasen er stor
set_time_limit(300);

$tables = [];
$result = mysqli_query($conn, "SHOW TABLES");
while ($row = mysqli_fetch_row($result)) {
    $tables[] = $row[0];
}

$sql_dump = "-- ERP System Database Export\n";
$sql_dump .= "-- Genereret: " . date('Y-m-d H:i:s') . "\n";
$sql_dump .= "-- --------------------------------------------------------\n\n";
$sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // 1. Drop & Create Table struktur
    $row2 = mysqli_fetch_row(mysqli_query($conn, "SHOW CREATE TABLE `" . $table . "`"));
    $sql_dump .= "\n\n" . $row2[1] . ";\n\n";
    
    // 2. Hent data ud af tabellen
    $result_data = mysqli_query($conn, "SELECT * FROM `" . $table . "`");
    $num_fields = mysqli_num_fields($result_data);
    
    while ($row = mysqli_fetch_row($result_data)) {
        $sql_dump .= "INSERT INTO `" . $table . "` VALUES(";
        for ($j = 0; $j < $num_fields; $j++) {
            if (isset($row[$j])) {
                // Undgå SQL injection i selve dumpet og bevar linjeskift
                $escaped = mysqli_real_escape_string($conn, $row[$j]);
                $sql_dump .= '"' . $escaped . '"';
            } else {
                $sql_dump .= 'NULL';
            }
            if ($j < ($num_fields - 1)) {
                $sql_dump .= ',';
            }
        }
        $sql_dump .= ");\n";
    }
}

$sql_dump .= "\n\nSET FOREIGN_KEY_CHECKS=1;\n";

// Forbered browseren på en fil-download (Vigtigt: Ingen HTML/print før dette!)
$filename = 'db_backup_' . DB_NAME . '_' . date('Y-m-d_H-i-s') . '.sql';

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