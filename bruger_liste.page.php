<?php # bruger_liste.page.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// Sikkerhed: Kun admins må se denne liste
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

htm_Header(lang('@User Management'));
showMenu();

// Knap til oprettelse
echo "<div style='margin-bottom: 20px;'>";
echo "<a href='bruger_opret.page.php' style='background:#2ecc71; color:white; padding:10px 15px; text-decoration:none; border-radius:4px; font-weight:bold;'>+ " . lang('@Create New User') . "</a>";
echo "</div>";

htm_Card_(lang('@System Users'));

// Vi henter KUN de 3 felter der skal vises (vi udelader password_hash af sikkerhedshensyn)
$sql = "SELECT user_id, username, user_role FROM users ORDER BY username ASC";
$res = mysqli_query($conn, $sql);

if (!$res) {
    echo "<p style='color:red;'>Fejl: " . mysqli_error($conn) . "</p>";
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif;'>";
    echo "<tr style='background: #f8f9fa; text-align: left;'>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6;'>" . lang('@Username') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6;'>" . lang('@Role') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6; text-align: left;'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    while ($row = mysqli_fetch_assoc($res)) {
        $roleLabel = ($row['user_role'] === 'admin') ? lang('@Administrator') : lang('@Standard User');
        $roleColor = ($row['user_role'] === 'admin') ? '#e74c3c' : '#7f8c8d';

        echo "<tr>";
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee; font-weight:bold;'>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee; color:$roleColor; font-weight:bold;'>" . $roleLabel . "</td>";
        
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: left;'>";
        echo "<a href='bruger_ret.page.php?id=" . $row['user_id'] . "' style='color: #3498db; text-decoration: none; margin-right:15px; font-weight:bold;'>" . lang('@Edit') . "</a>";
        
        // Undgå at slette sig selv
        if ($row['user_id'] != $_SESSION['user_id']) {
            echo "<a href='bruger_slet.php?id=" . $row['user_id'] . "' style='color: #e74c3c; text-decoration: none; font-weight:bold;' onclick='return confirm(\"" . lang('@Are you sure?') . "\")'>" . lang('@Delete') . "</a>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

htm_Card_end();
htm_Footer();
?>