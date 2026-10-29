<?php # /user_list.php v:1.3.1 d:2026-09-15 i:evs
# tilføjet besked-visning for user_actions.php's redirects - "Slet"-knappen linkede før til en ikke-eksisterende fil
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

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

htm_Card_(capt: '@System Users', wdth: 900);

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): ingen side viste
// hvilke brugere - særligt andre admin-konti - reelt har to-faktor-login
// slået til. En admin kunne derfor ikke se hvilke konti der er særligt
// sårbare uden 2FA. Samme "stille degradering hvis ikke migreret endnu"-
// mønster som andre nyere kolonner i denne kodebase - totp_enabled findes
// kun efter db-setup/migrate_2fa.php er kørt.
$has_2fa_col = false;
if (DB::is_sqlite()) {
    $c2fa = DB::query($conn, "PRAGMA table_info(users)");
    if ($c2fa) { while ($cr = DB::fetch_assoc($c2fa)) { if ($cr['name'] === 'totp_enabled') { $has_2fa_col = true; break; } } }
} else {
    $c2fa = DB::query($conn, "SHOW COLUMNS FROM users LIKE 'totp_enabled'");
    $has_2fa_col = ($c2fa && DB::num_rows($c2fa) > 0);
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): samme mønster som
// 2FA-kolonnen ovenfor - is_active findes kun efter db-setup/migrate_user_
// active_status.php er kørt (se user_edit.php/inc/auth.inc.php for selve
// deaktiverings-funktionen).
$has_active_col = false;
if (DB::is_sqlite()) {
    $cact = DB::query($conn, "PRAGMA table_info(users)");
    if ($cact) { while ($cr2 = DB::fetch_assoc($cact)) { if ($cr2['name'] === 'is_active') { $has_active_col = true; break; } } }
} else {
    $cact = DB::query($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
    $has_active_col = ($cact && DB::num_rows($cact) > 0);
}

$select_cols = "user_id, username, user_role" . ($has_2fa_col ? ", totp_enabled" : "") . ($has_active_col ? ", is_active" : "");
$sql = "SELECT $select_cols FROM users ORDER BY username ASC";
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

        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): login_log
        // (login_history.php, tidligere runde) gemmer allerede hvert
        // loginforsøg, men intet sted viste hvornår en given bruger sidst
        // reelt loggede ind - en admin kunne derfor ikke se hvilke konti der
        // rent faktisk er i brug versus glemte/forladte, relevant for at
        // rydde op i adgange. Kun 'Success'-rækker tæller (et fejlet forsøg
        // er ikke et reelt login).
        $last_login_row = DB::fetch_assoc(DB::query($conn,
            "SELECT MAX(login_time) AS t FROM login_log WHERE user_id = " . (int)$row['user_id'] . " AND status = 'Success'"));
        $last_login = $last_login_row['t'] ?? null;
        if ($last_login) {
            $days_ago = days_between(date('Y-m-d', strtotime($last_login)), date('Y-m-d'));
            $login_cell = date(CONF_DATE_FORMAT . ' H:i', strtotime($last_login));
            if ($days_ago > 90) {
                $login_cell = '<span style="color:var(--color-danger);">' . $login_cell . '</span>';
            }
        } else {
            $login_cell = '<span style="color:var(--text-muted);">' . lang('@Never') . '</span>';
        }

        if ($has_2fa_col) {
            $twofa_cell = !empty($row['totp_enabled'])
                ? '<span style="color:var(--color-success); font-weight:bold;"><i class="fa-solid fa-shield-halved"></i> ' . lang('@On') . '</span>'
                : '<span style="color:var(--color-danger); font-weight:bold;" data-hint="'.htmlspecialchars(lang('@This account can be accessed with just a password')).'"><i class="fa-solid fa-shield"></i> ' . lang('@Off') . '</span>';
        }

        if ($has_active_col) {
            $status_cell = !empty($row['is_active']) || !isset($row['is_active'])
                ? '<span style="color:var(--color-success); font-weight:bold;"><i class="fa-solid fa-circle-check"></i> ' . lang('@Active') . '</span>'
                : '<span style="color:var(--color-danger); font-weight:bold;"><i class="fa-solid fa-ban"></i> ' . lang('@Disabled') . '</span>';
        }

        $row_cells = [$name_cell, $role_cell];
        if ($has_2fa_col) $row_cells[] = $twofa_cell;
        if ($has_active_col) $row_cells[] = $status_cell;
        $row_cells[] = $login_cell;
        $row_cells[] = '<div style="text-align:right;">'.$actions_cell.'</div>';
        $rows[] = $row_cells;
    }
    $headers = ['@Username', '@Role'];
    if ($has_2fa_col) $headers[] = '@2FA';
    if ($has_active_col) $headers[] = '@Status';
    $headers[] = '@Last Login';
    $headers[] = '@Actions';
    htm_Table($headers, $rows, 'user_tbl', 100);

    // --- HER ER DEN NYE PLACERING AF KNAPPEN ---
    echo "<div style='border-top: 1px solid #eee; padding-top: 20px; display: flex; justify-content: space-between; align-items: center;'>";
        echo "<a href='user_create.php' data-hint='" . lang('@Create a new user with a role and access level') . "' style='background:var(--color-success); color:var(--text-light); padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;'>";
        echo "<i class='fa fa-plus-circle'></i> " . lang('@Create New User');
        echo "</a>";
    echo "</div>";
}

htm_Card_end();
htm_Footer();
?>
