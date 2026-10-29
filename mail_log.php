<?php # /mail_log.php v:1.0.0 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): der fandtes
# ingen ét-sted-oversigt over hvad der reelt er sendt af mail (fakturaer/
# rykkere/kontoudtog/tilbudsopfølgning) - kun spredte enkeltfelter der hver
# kun dækker ÉN mailtype. mail_log-tabellen udfyldes nu automatisk af den
# fælles sendTinyMail() (inc/mail_handler.lib.php), denne side er den rene
# visning - samme "data indsamles, men var aldrig vist"-mønster som
# login_history.php lukkede for login_log. Samme admin-only-niveau og
# filter-/tabellayout som login_history.php.
$rLev = 3; // kun admin - fuld mailhistorik (modtageradresser) er følsom
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header('@Sent Mail Log');
showMenu();

$table_exists = @DB::query($conn, "SELECT 1 FROM mail_log LIMIT 1") !== false;
if (!$table_exists) {
    htm_Card_(capt: '@Sent Mail Log', wdth: 1100);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The mail log database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

// --- FILTRE (alle valgfrie, kombinerbare - samme mønster som login_history.php) ---
$f_to     = trim($_GET['to_email'] ?? '');
$f_status = trim($_GET['status'] ?? '');
$f_from   = trim($_GET['date_from'] ?? '');
$f_to_dt  = trim($_GET['date_to'] ?? '');

$where = [];
if ($f_to     !== '') $where[] = "to_email LIKE '%" . DB::escape($conn, $f_to) . "%'";
if ($f_status === 'success') $where[] = "success = 1";
if ($f_status === 'failed')  $where[] = "success = 0";
if ($f_from  !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from))  $where[] = "sent_at >= '" . db_ts_param("$f_from 00:00:00") . "'";
if ($f_to_dt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to_dt)) $where[] = "sent_at <= '" . db_ts_param("$f_to_dt 23:59:59") . "'";
$where_sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

$status_options = [
    ''        => '-- ' . lang('@All') . ' --',
    'success' => lang('@Success'),
    'failed'  => lang('@Failed'),
];

htm_Card_(capt: '@Sent Mail Log', wdth: 1100);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@A read-only record of every outgoing email the system has sent - invoices, reminders, account statements, quote follow-ups and more - captured automatically wherever mail is sent, no matter which page triggered it.') .
    '</p>';

// --- Opsummering: fejlede afsendelser de sidste 7 dage - hurtig "er der
// noget der ikke virker"-indikator, uden at skulle læse hele tabellen. ---
$week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$fail_row = DB::fetch_assoc(DB::query($conn, "SELECT COUNT(*) AS c FROM mail_log WHERE success = 0 AND sent_at >= '$week_ago'"));
$recent_fails = (int)($fail_row['c'] ?? 0);
if ($recent_fails > 0) {
    htm_Banner('<i class="fa-solid fa-triangle-exclamation"></i> ' . sprintf(lang('@%d email(s) failed to send in the last 7 days - check your mail settings.'), $recent_fails), 'warning');
}

// --- FILTERFORM ---
echo '<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:20px; padding:12px; background:var(--bg-panel); border-radius:6px;">';
htm_Field(icon: 'fa-envelope', labl: '@Recipient', name: 'to_email', valu: $f_to, type: 'text', wdth: '200px');
htm_Field(icon: 'fa-shield-halved', labl: '@Status', name: 'status', valu: $f_status, type: 'sele', opti: $status_options, wdth: '160px');
htm_Field(icon: 'fa-calendar', labl: '@From', name: 'date_from', valu: $f_from, type: 'date', wdth: '150px');
htm_Field(icon: 'fa-calendar', labl: '@To', name: 'date_to', valu: $f_to_dt, type: 'date', wdth: '150px');
htm_Button(labl: '@Filter', type: 'primary', attr: 'data-hint="'.lang('@Apply the filters above').'"');
if ($f_to !== '' || $f_status !== '' || $f_from !== '' || $f_to_dt !== '') {
    htm_Button(labl: '@Clear', type: 'secondary', link: 'mail_log.php', attr: 'data-hint="'.lang('@Reset all filters').'"');
}
echo '</form>';

$sql = "SELECT * FROM mail_log $where_sql ORDER BY sent_at DESC LIMIT 300";
$res = DB::query($conn, $sql);

$headers = ['@Date', '@Recipient', '@Subject', '@Status'];
$data = [];

if (!$res) {
    echo htm_Alert("SQL Error: " . DB::error($conn), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $when = date(CONF_DATE_FORMAT . ' H:i', strtotime(db_ts_local($row['sent_at'])));
        $is_failed = !$row['success'];
        $status_html = $is_failed
            ? '<strong style="color:var(--color-danger);" data-hint="'.htmlspecialchars($row['error_message'] ?? '').'"><i class="fa-solid fa-circle-xmark"></i> ' . lang('@Failed') . '</strong>'
            : '<span style="color:var(--color-success);"><i class="fa-solid fa-circle-check"></i> ' . lang('@Success') . '</span>';

        $data[] = [
            $when,
            htmlspecialchars($row['to_email']),
            htmlspecialchars($row['subject'] ?? ''),
            $status_html,
        ];
    }
}

if (empty($data) && $res) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No sent mail found for the selected filters.') . "</p>";
} elseif (!empty($data)) {
    htm_Table($headers, $data, 'mailLogTbl', 300, '', true, [], '600px', 'mail_log_export.csv');
    echo '<p style="color:var(--text-muted); font-size:0.8em; margin-top:10px;">' . lang('@Showing the 300 most recent matching entries.') . '</p>';
}

htm_Card_end();
htm_Footer();
?>
