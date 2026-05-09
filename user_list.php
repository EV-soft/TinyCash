<?php # user_list.php v:0.9.1 d:2026-05-07 i:evs
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';

// Sikkerhed: Kun admins må se denne liste
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

htm_Header('@User Management');
showMenu();

htm_Card_('@System Users');

$sql = "SELECT user_id, username, user_role FROM users ORDER BY username ASC";
$res = mysqli_query($conn, $sql);

if (!$res) {
    echo "<p style='color:red;'>" . lang('@SQL Error') . ": " . mysqli_error($conn) . "</p>";
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif; margin-bottom: 20px;'>";
    echo "<tr style='background: #f8f9fa; text-align: left;'>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6;'>" . lang('@Username') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6;'>" . lang('@Role') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid #dee2e6; text-align: right;'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    while ($row = mysqli_fetch_assoc($res)) {
        $isAdmin = ($row['user_role'] === 'admin');
        $roleLabel = $isAdmin ? lang('@Administrator') : lang('@Standard User');
        
        $roleStyle = "padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold;";
        $roleStyle .= $isAdmin ? " background: #fcebea; color: #e74c3c;" : " background: #f1f2f6; color: #7f8c8d;";

        echo "<tr>";
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee;'>";
        echo "<i class='fa fa-user' style='color:#bdc3c7; margin-right:8px;'></i>";
        echo "<strong>" . htmlspecialchars($row['username']) . "</strong>";
        if ($row['user_id'] == $_SESSION['user_id']) {
            echo " <small style='color:#3498db;'>(" . lang('@You') . ")</small>";
        }
        echo "</td>";
        
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee;'><span style='$roleStyle'>" . $roleLabel . "</span></td>";
        
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>";
        echo "<a href='user_edit.page.php?id=" . $row['user_id'] . "' style='color: #3498db; text-decoration: none; margin-right:15px; font-weight:bold;'>" . lang('@Edit') . "</a>";
        
        if ($row['user_id'] != $_SESSION['user_id']) {
            echo "<a href='user_actions.php?action=delete&id=" . $row['user_id'] . "' style='color: #e74c3c; text-decoration: none; font-weight:bold;' onclick='return confirm(\"" . lang('@Are you sure?') . "\")'>" . lang('@Delete') . "</a>";
        } else {
            echo "<span style='color: #bdc3c7; cursor: not-allowed; font-weight:bold;'>" . lang('@Delete') . "</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // --- HER ER DEN NYE PLACERING AF KNAPPEN ---
    echo "<div style='border-top: 1px solid #eee; padding-top: 20px; display: flex; justify-content: space-between; align-items: center;'>";
        echo "<a href='user_create.page.php' style='background:#2ecc71; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;'>";
        echo "<i class='fa fa-plus-circle'></i> " . lang('@Create New User');
        echo "</a>";

        // Tilføjer også Backup linket diskret her i bunden
        echo "<a href='backup_restore.page.php' style='color: #95a5a6; text-decoration: none; font-size: 0.9em;'>";
        echo "⚙ " . lang('@System Tools');
        echo "</a>";
    echo "</div>";
}

htm_Card_end();
htm_Footer();
?>