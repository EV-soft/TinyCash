<?php # inc/db_blueprint.php v:0.9.1 d:2026-05-07 i:evs
if (file_exists(__DIR__ . '/inc/db_connect.inc.php')) { require_once __DIR__ . '/inc/db_connect.inc.php'; } 
elseif (file_exists(__DIR__ . '/../db_connect.inc.php')) { require_once __DIR__ . '/../db_connect.inc.php';} 
else { die("Error: Could not find db_connect.inc.php. Check fileplace."); }

echo "<style>
    body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
    .table-card { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; border: 1px solid #ddd; }
    .table-header { background: #3498db; color: white; padding: 10px 15px; font-weight: bold; font-size: 1.1em; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 15px; border-bottom: 1px solid #eee; }
    th { background: #f8f9fa; color: #666; font-size: 0.85em; text-transform: uppercase; }
    tr:last-child td { border-bottom: none; }
    .type { color: #e67e22; font-family: monospace; }
</style>";

echo "<h2>Database Blueprint: " . htmlspecialchars($db_name ?? 'TinyCash') . "</h2>";

// Hent alle tabeller
$tables_res = mysqli_query($conn, "SHOW TABLES");
while ($table_row = mysqli_fetch_array($tables_res)) {
    $tableName = $table_row[0];
    
    echo "<div class='table-card'>";
    echo "<div class='table-header'>Table: $tableName</div>";
    echo "<table>
            <thead>
                <tr>
                    <th>Fieldname</th>
                    <th>Type</th>
                    <th>Null</th>
                    <th>Key</th>
                    <th>Default</th>
                </tr>
            </thead>
            <tbody>";
    // Hent alle felter for denne tabel
    $cols_res = mysqli_query($conn, "DESCRIBE `$tableName`");
    while ($col = mysqli_fetch_assoc($cols_res)) {
        echo "<tr>
                <strong><td>" . $col['Field'] . "</td></strong>
                <td class='type'><big>" . $col['Type'] . "</big></td>
                <td>" .  $col['Null'] . "</td>
                <td>" . ($col['Key'] ?: '-') . "</td>
                <td>" . ($col['Default'] ?: '-') . "</td>
              </tr>";
    }
    echo "</tbody></table></div>";
}
?>