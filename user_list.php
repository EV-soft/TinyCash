<?php # user_list.php v:1.1.0 d:2026-07-07 i:claude (Opdateret til at bruge htm_Badge og htm_ConfirmLink)
require_once 'inc/php2htm.lib.php';
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';

// Sikkerhed: Kun admins må se denne liste (Genbruger central fejlsidestyring)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}

htm_Header('@User Management');
showMenu();

htm_Card_('@System Users');

$sql = "SELECT user_id, username, user_role FROM users ORDER BY username ASC";
$res = DB::query($conn, $sql);

if (!$res) {
    echo "<p style='color:red;'>" . lang('@SQL Error') . ": " . DB::error($conn) . "</p>";
} else {
    echo "<table style='width:100%; border-collapse: collapse; font-family: sans-serif; margin-bottom: 20px;'>";
    echo "<tr style='background: var(--bg-panel); text-align: left;'>";
    echo "<th style='padding: 12px; border-bottom: 2px solid var(--border-color); color:var(--text-main);'>" . lang('@Username') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid var(--border-color); color:var(--text-main);'>" . lang('@Role') . "</th>";
    echo "<th style='padding: 12px; border-bottom: 2px solid var(--border-color); text-align: right; color:var(--text-main);'>" . lang('@Actions') . "</th>";
    echo "</tr>";

    while ($row = DB::fetch_assoc($res)) {
        $isAdmin = ($row['user_role'] === 'admin');
        $roleKey = $isAdmin ? '@Administrator' : '@Standard User';
        $roleVariant = $isAdmin ? 'danger' : 'secondary';

        echo "<tr>";
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee;'>";
        echo "<i class='fa fa-user' style='color:var(--text-muted); margin-right:8px;'></i>";
        echo "<strong>" . htmlspecialchars($row['username']) . "</strong>";
        if ($row['user_id'] == $_SESSION['user_id']) {
            echo " <small style='color:var(--color-primary);'>(" . lang('@You') . ")</small>";
        }
        echo "</td>";

        // Rolle vist via htm_Badge i stedet for manuel inline-farvelogik
        // (arver nu automatisk dark/custom-temaet via --color-danger/secondary)
        echo "<td style='padding: 12px; border-bottom: 1px solid #eee;'>";
        htm_Badge($roleKey, $roleVariant);
        echo "</td>";

        echo "<td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>";
        echo "<a href='user_edit.php?id=" . $row['user_id'] . "' style='color: var(--color-primary); text-decoration: none; margin-right:15px; font-weight:bold;'>" . lang('@Edit') . "</a>";

        if ($row['user_id'] != $_SESSION['user_id']) {
            // Erstattet manuel confirm()-onclick (uden central escaping) med htm_ConfirmLink
            htm_ConfirmLink(
                icon: '',
                labl: '@Delete',
                link: 'user_actions.php?action=delete&id='.$row['user_id'],
                mess: '@Are you sure?',
                type: 'danger',
                styl: 'background:transparent; color:var(--color-danger); padding:0; font-weight:bold; display:inline;'
            );
        } else {
            echo "<span style='color: var(--text-muted); cursor: not-allowed; font-weight:bold;'>" . lang('@Delete') . "</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // --- HER ER DEN NYE PLACERING AF KNAPPEN ---
    echo "<div style='border-top: 1px solid #eee; padding-top: 20px; display: flex; justify-content: space-between; align-items: center;'>";
        echo "<a href='user_create.php' style='background:var(--color-success); color:var(--text-light); padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;'>";
        echo "<i class='fa fa-plus-circle'></i> " . lang('@Create New User');
        echo "</a>";
    echo "</div>";
}

htm_Card_end();
htm_Footer();
?>
