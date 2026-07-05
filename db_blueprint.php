<?php # inc/db_blueprint.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/db_connect.inc.php'; 

// Hent den rigtige SQL til at liste tabeller
$sql_tables = DB::is_sqlite() 
    ? "SELECT name FROM sqlite_master WHERE type='table'" 
    : "SHOW TABLES";

$tables_res = DB::query($conn, $sql_tables);

echo "<style>
    body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
    .table-card { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; border: 1px solid #ddd; }
    .table-header { background: #3498db; color: white; padding: 10px 15px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 15px; border-bottom: 1px solid #eee; }
</style>";

echo "<h2>Database Blueprint</h2>";

while ($table_row = DB::fetch_array($tables_res)) {
    $tableName = $table_row[0];
    echo "<div class='table-card'><div class='table-header'>Table: $tableName</div><table>";
    
    // Hent kolonner: SQLite bruger PRAGMA, MySQL bruger DESCRIBE
    $sql_cols = DB::is_sqlite() ? "PRAGMA table_info(`$tableName`)" : "DESCRIBE `$tableName`";
    $cols_res = DB::query($conn, $sql_cols);
    
    while ($col = DB::fetch_assoc($cols_res)) {
        // Håndter forskelle i kolonnenavne mellem SQLite og MySQL
        $name = $col['Field'] ?? $col['name'];
        $type = $col['Type'] ?? $col['type'];
        $null = $col['Null'] ?? ($col['notnull'] ? 'NO' : 'YES');
        
        echo "<tr><td>$name</td><td>$type</td><td>$null</td></tr>";
    }
    echo "</table></div>";
}
?>