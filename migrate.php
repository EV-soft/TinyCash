<?php
# migrate.php - Opdaterer databasestruktur sikkert
require_once __DIR__ . '/inc/db_connect.inc.php';

// Liste over ændringer vi vil køre
$queries = [
    "ALTER TABLE invoices ADD COLUMN jou_id INTEGER DEFAULT NULL",
    // Tilføj flere her hvis nødvendigt senere
];

echo "<h2>Starter database-migration...</h2>";

foreach ($queries as $sql) {
    try {
        // Vi bruger DB::query fra din klasse
        $res = DB::query($conn, $sql);
        if ($res) {
            echo "<p style='color:green;'>✅ Succes: $sql</p>";
        } else {
            echo "<p style='color:orange;'>⚠️ Måske allerede kørt: $sql</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Fejl ved: $sql <br> Besked: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>Migration fuldført! Slet venligst denne fil af sikkerhedshensyn.</h3>";
?>