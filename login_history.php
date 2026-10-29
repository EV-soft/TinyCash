<?php # /login_history.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): login_log-
# tabellen findes og bliver allerede skrevet til ved hvert login-forsøg
# (login.php, både succes og fejl), men der fandtes ingen side hvor man
# kunne SE loggen - præcis samme mangel som audit_log.php lukkede for
# revisionssporet ("data blev indsamlet, men aldrig vist"). Særligt
# relevant her: mislykkede loginforsøg registreres allerede (status=
# 'Failed'), men var fuldstændig usynlige for en admin - et forsøg på at
# gætte en adgangskode ville derfor kunne foregå ubemærket. Samme
# admin-only-niveau og layout-mønster som audit_log.php.
$rLev = 3; // kun admin - loginhistorik/IP-adresser er følsomme
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header('@Login History');
showMenu();

// --- FILTRE (alle valgfrie, kombinerbare - samme mønster som audit_log.php) ---
$f_user   = trim($_GET['username'] ?? '');
$f_status = trim($_GET['status'] ?? '');
$f_from   = trim($_GET['date_from'] ?? '');
$f_to     = trim($_GET['date_to'] ?? '');

$where = [];
if ($f_user   !== '') $where[] = "logged_username = '" . DB::escape($conn, $f_user) . "'";
// RETTET: et mislykket 2FA-forsøg logges som 'Failed - 2FA' (login.php) - "Mislykket" fandt kun 'Failed'
if ($f_status === 'Failed')  $where[] = "status LIKE 'Failed%'";
elseif ($f_status !== '')    $where[] = "status = '" . DB::escape($conn, $f_status) . "'";
if ($f_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $where[] = "login_time >= '" . db_ts_param("$f_from 00:00:00") . "'";
if ($f_to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $where[] = "login_time <= '" . db_ts_param("$f_to 23:59:59") . "'";
$where_sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

$user_options = ['' => '-- ' . lang('@All') . ' --'];
$res = DB::query($conn, "SELECT DISTINCT logged_username FROM login_log ORDER BY logged_username ASC");
if ($res) while ($r = DB::fetch_assoc($res)) { $user_options[$r['logged_username']] = $r['logged_username']; }

$status_options = [
    ''        => '-- ' . lang('@All') . ' --',
    'Success' => lang('@Success'),
    'Failed'  => lang('@Failed'),
];

htm_Card_(capt: '@Login History', wdth: 1100);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@A read-only record of every login attempt - who, when, from which IP address, and whether it succeeded. Repeated failed attempts for the same username may indicate someone trying to guess a password.') .
    '</p>';

// --- Opsummering: samlet antal mislykkede forsøg de sidste 7 dage - hurtig
// "er der noget at bekymre sig om"-indikator øverst, uden at skulle læse
// hele tabellen igennem selv. ---
$week_ago = db_ts_param(date('Y-m-d H:i:s', strtotime('-7 days')));     // (login_time er databasens egen tid)
$fail_row = DB::fetch_assoc(DB::query($conn, "SELECT COUNT(*) AS c FROM login_log WHERE status LIKE 'Failed%' AND login_time >= '$week_ago'"));     // (også mislykkede 2FA-koder)
$recent_fails = (int)($fail_row['c'] ?? 0);
if ($recent_fails > 0) {
    htm_Banner('<i class="fa-solid fa-triangle-exclamation"></i> ' . sprintf(lang('@%d failed login attempt(s) in the last 7 days.'), $recent_fails), 'warning');
}

// --- FILTERFORM ---
echo '<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:20px; padding:12px; background:var(--bg-panel); border-radius:6px;">';
htm_Field(icon: 'fa-user', labl: '@Username', name: 'username', valu: $f_user, type: 'sele', opti: $user_options, wdth: '180px');
htm_Field(icon: 'fa-shield-halved', labl: '@Status', name: 'status', valu: $f_status, type: 'sele', opti: $status_options, wdth: '160px');
htm_Field(icon: 'fa-calendar', labl: '@From', name: 'date_from', valu: $f_from, type: 'date', wdth: '150px');
htm_Field(icon: 'fa-calendar', labl: '@To', name: 'date_to', valu: $f_to, type: 'date', wdth: '150px');
htm_Button(labl: '@Filter', type: 'primary', attr: 'data-hint="'.lang('@Apply the filters above').'"');
if ($f_user !== '' || $f_status !== '' || $f_from !== '' || $f_to !== '') {
    htm_Button(labl: '@Clear', type: 'secondary', link: 'login_history.php', attr: 'data-hint="'.lang('@Reset all filters').'"');
}
echo '</form>';

$sql = "SELECT * FROM login_log $where_sql ORDER BY login_time DESC LIMIT 300";
$res = DB::query($conn, $sql);

$headers = ['@Date', '@Username', '@Status', '@IP Address', '@Browser'];
$data = [];

if (!$res) {
    echo htm_Alert("SQL Error: " . DB::error($conn), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $when = date(CONF_DATE_FORMAT . ' H:i', strtotime(db_ts_local($row['login_time'])));
        // RETTET: 'Failed - 2FA' (forkert 2FA-kode) blev vist som et grønt "Lykkedes"
        $is_failed = (stripos((string)$row['status'], 'failed') === 0);
        $status_html = $is_failed
            ? '<strong style="color:var(--color-danger);"><i class="fa-solid fa-circle-xmark"></i> ' . lang('@Failed') . (stripos((string)$row['status'], '2fa') !== false ? ' (2FA)' : '') . '</strong>'
            : '<span style="color:var(--color-success);"><i class="fa-solid fa-circle-check"></i> ' . lang('@Success') . '</span>';

        // En hel mislykket-forsøgs-række fremhæves diskret (svag rød baggrund
        // på selve brugernavnet), så et mønster af flere fejlede forsøg for
        // samme bruger springer i øjnene ved et hurtigt scroll ned gennem
        // listen, uden at det bliver et helt rødt skærmbillede.
        $user_html = $is_failed
            ? '<span style="color:var(--color-danger); font-weight:bold;">' . htmlspecialchars($row['logged_username']) . '</span>'
            : htmlspecialchars($row['logged_username']);

        $data[] = [
            $when,
            $user_html,
            $status_html,
            htmlspecialchars($row['ip_address'] ?? '—'),
            '<span style="font-size:0.85em; color:var(--text-muted);" title="'.htmlspecialchars($row['user_agent'] ?? '').'">' . htmlspecialchars(mb_strimwidth($row['user_agent'] ?? '—', 0, 40, '…')) . '</span>',
        ];
    }
}

if (empty($data) && $res) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No login attempts found for the selected filters.') . "</p>";
} elseif (!empty($data)) {
    htm_Table($headers, $data, 'loginHistTbl', 300, '', true, [], '600px', 'login_history_export.csv');
    echo '<p style="color:var(--text-muted); font-size:0.8em; margin-top:10px;">' . lang('@Showing the 300 most recent matching entries.') . '</p>';
}

htm_Card_end();
htm_Footer();
?>
