<?php # /user_list.php v:1.3.0 d:2026-08-30 i:evs
# tilføjet besked-visning for user_actions.php's redirects - "Slet"-knappen linkede før til en ikke-eksisterende fil
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

if (isset($_GET['msg'])) {
    $msg_map = [
        'deleted'             => ['@User deleted successfully', 'success'],
        'cannot_delete_self'  => ['@You cannot delete your own account', 'error'],
        'cannot_delete_in_use' => ['@Cannot delete user: they have historical ledger entries, audit log records, or expenses/payments attributed to them', 'error'],
        'not_found'           => ['@User not found', 'error'],
        'error'               => ['@Error deleting user', 'error'],
    ];
    if (isset($msg_map[$_GET['msg']])) {
        htm_Alert(lang($msg_map[$_GET['msg']][0]), $msg_map[$_GET['msg']][1]);
    }
}

htm_Card_('@System Users');

$sql = "SELECT user_id, username, user_role FROM users ORDER BY username ASC";
$res = DB::query($conn, $sql);

if (!$res) {
    echo "<p style='color:red;'>" . lang('@SQL Error') . ": " . DB::error($conn) . "</p>";
} else {
    // RETTET (§bugs-batch-22-review, del b): erstattet den håndrullede
    // <table> med htm_Table() (se csrf-protection-added.md/
    // htm-alert-banner-refactor.md for baggrunden på hele denne oprydnings-
    // runde) - cellernes indhold (badge/links) er uændret, kun selve
    // tabelrammen er nu den fælles funktion.
    $rows = [];
    while ($row = DB::fetch_assoc($res)) {
        $isAdmin = ($row['user_role'] === 'admin');
        $roleKey = $isAdmin ? '@Administrator' : '@Standard User';
        $roleVariant = $isAdmin ? 'danger' : 'secondary';

        $name_cell = "<i class='fa fa-user' style='color:var(--text-muted); margin-right:8px;'></i><strong>" . htmlspecialchars($row['username']) . "</strong>";
        if ($row['user_id'] == $_SESSION['user_id']) {
            $name_cell .= " <small style='color:var(--color-primary);'>(" . lang('@You') . ")</small>";
        }

        // Rolle vist via htm_Badge i stedet for manuel inline-farvelogik
        // (arver nu automatisk dark/custom-temaet via --color-danger/secondary)
        $role_cell = htm_Badge($roleKey, $roleVariant, false);

        $actions_cell = "<a href='user_edit.php?id=" . $row['user_id'] . "' style='color: var(--color-primary); text-decoration: none; margin-right:15px; font-weight:bold;'>" . lang('@Edit') . "</a>";
        if ($row['user_id'] != $_SESSION['user_id']) {
            $actions_cell .= htm_ConfirmLink(
                icon: '',
                labl: '@Delete',
                link: 'user_actions.php?action=delete&id='.$row['user_id'],
                mess: '@Are you sure?',
                type: 'danger',
                styl: 'background:transparent; color:var(--color-danger); padding:0; font-weight:bold; display:inline;',
                attr: 'data-hint="'.lang('@Delete this user account').'"',
                echo: false
            );
        } else {
            $actions_cell .= "<span style='color: var(--text-muted); cursor: not-allowed; font-weight:bold;'>" . lang('@Delete') . "</span>";
        }

        $rows[] = [$name_cell, $role_cell, '<div style="text-align:right;">'.$actions_cell.'</div>'];
    }
    htm_Table(['@Username', '@Role', '@Actions'], $rows, 'user_tbl', 100);

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
