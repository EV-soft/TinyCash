<?php # /audit_log.php v:1.3.0 d:2026-08-30 i:evs
# Revisionsspor-visning. audit_log-tabellen findes og bliver allerede
# skrevet til (log_action() i inc/audit.inc.php, kaldt fra bl.a.
# expense_actions.php, invoice_credit.php, invoice_post_action.php,
# ledger_view.php ved annullering/sletning), men der fandtes ingen side,
# hvor man kunne SE loggen - data blev indsamlet, men aldrig vist.
# Bruger-anmodet ("revisionsspor-visning").
$rLev = 3; // kun admin - kan indeholde følsomme før/efter-værdier
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header('@Audit Log');
showMenu();

// --- FILTRE (alle valgfrie, kombinerbare) ---
$f_action = trim($_GET['action_type'] ?? '');
$f_table  = trim($_GET['table_name'] ?? '');
$f_user   = (int)($_GET['user_id'] ?? 0);
$f_from   = trim($_GET['date_from'] ?? '');
$f_to     = trim($_GET['date_to'] ?? '');

$where = [];
if ($f_action !== '') $where[] = "a.action_type = '" . DB::escape($conn, $f_action) . "'";
if ($f_table  !== '') $where[] = "a.table_name = '" . DB::escape($conn, $f_table) . "'";
if ($f_user   > 0)    $where[] = "a.user_id = $f_user";
if ($f_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $where[] = "a.log_date >= '$f_from 00:00:00'";
if ($f_to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $where[] = "a.log_date <= '$f_to 23:59:59'";
$where_sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

// --- Datagrundlag til filter-dropdowns (kun værdier der faktisk findes i loggen) ---
$action_options = ['' => '-- ' . lang('@All') . ' --'];
$res = DB::query($conn, "SELECT DISTINCT action_type FROM audit_log ORDER BY action_type ASC");
if ($res) while ($r = DB::fetch_assoc($res)) { $action_options[$r['action_type']] = $r['action_type']; }

$table_options = ['' => '-- ' . lang('@All') . ' --'];
$res = DB::query($conn, "SELECT DISTINCT table_name FROM audit_log ORDER BY table_name ASC");
if ($res) while ($r = DB::fetch_assoc($res)) { $table_options[$r['table_name']] = $r['table_name']; }

$user_options = ['0' => '-- ' . lang('@All') . ' --'];
$res = DB::query($conn, "SELECT DISTINCT u.user_id, u.username FROM audit_log a JOIN users u ON a.user_id = u.user_id ORDER BY u.username ASC");
if ($res) while ($r = DB::fetch_assoc($res)) { $user_options[$r['user_id']] = $r['username']; }

htm_Card_(capt: '@Audit Log', wdth: 1100);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@A read-only record of cancellations, deletions, and postings — who did what, and when.') .
    '</p>';

// --- FILTERFORM ---
echo '<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:20px; padding:12px; background:var(--bg-panel); border-radius:6px;">';
htm_Field(icon: 'fa-bolt', labl: '@Action', name: 'action_type', valu: $f_action, type: 'sele', opti: $action_options, wdth: '180px');
htm_Field(icon: 'fa-table', labl: '@Table', name: 'table_name', valu: $f_table, type: 'sele', opti: $table_options, wdth: '160px');
htm_Field(icon: 'fa-user', labl: '@User', name: 'user_id', valu: (string)$f_user, type: 'sele', opti: $user_options, wdth: '160px');
htm_Field(icon: 'fa-calendar', labl: '@From', name: 'date_from', valu: $f_from, type: 'date', wdth: '150px');
htm_Field(icon: 'fa-calendar', labl: '@To', name: 'date_to', valu: $f_to, type: 'date', wdth: '150px');
htm_Button(labl: '@Filter', type: 'primary', attr: 'data-hint="'.lang('@Apply the filters above').'"');
if ($f_action !== '' || $f_table !== '' || $f_user > 0 || $f_from !== '' || $f_to !== '') {
    htm_Button(labl: '@Clear', type: 'secondary', link: 'audit_log.php', attr: 'data-hint="'.lang('@Reset all filters').'"');
}
echo '</form>';

// --- Badge-farve pr. handlingstype: rødlige for sletning/annullering, blålige for oprettelse/postering ---
function audit_action_badge_type($action) {
    if (strpos($action, 'DELETE') !== false || strpos($action, 'CANCEL') !== false) return 'danger';
    if (strpos($action, 'CREATE') !== false || strpos($action, 'POST')   !== false) return 'success';
    return 'secondary';
}

$sql = "SELECT a.*, u.username
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.user_id
        $where_sql
        ORDER BY a.log_date DESC
        LIMIT 300";
$res = DB::query($conn, $sql);

$headers = ['@Date', '@User', '@Action', '@Table', '@Row ID', '@Details'];
$data = [];

if (!$res) {
    echo htm_Alert("SQL Error: " . DB::error($conn), "error");
} else {
    while ($row = DB::fetch_assoc($res)) {
        $when = date(CONF_DATE_FORMAT . ' H:i', strtotime($row['log_date']));
        $who  = htmlspecialchars($row['username'] ?? ('#' . (int)$row['user_id']));
        $badge = htm_Badge($row['action_type'], audit_action_badge_type($row['action_type']), false);

        // Kompakt diff-visning: viser kun de felter, der faktisk ændrede sig
        // (eller hele new_values, hvis old_values er tom - dvs. en oprettelse).
        $old = $row['old_values'] ? json_decode($row['old_values'], true) : null;
        $new = $row['new_values'] ? json_decode($row['new_values'], true) : null;
        $diff_parts = [];
        if (is_array($old) || is_array($new)) {
            $keys = array_unique(array_merge(array_keys($old ?? []), array_keys($new ?? [])));
            foreach ($keys as $k) {
                $ov = $old[$k] ?? null;
                $nv = $new[$k] ?? null;
                if ($ov === $nv) continue;
                $diff_parts[] = htmlspecialchars($k) . ': ' .
                    htmlspecialchars($ov === null ? '—' : (string)$ov) . ' → ' .
                    htmlspecialchars($nv === null ? '—' : (string)$nv);
            }
        }
        $diff_text = empty($diff_parts) ? '<span style="color:var(--text-muted);">—</span>' : implode('<br>', $diff_parts);
        $full_json = htmlspecialchars(
            ($old !== null ? lang('@Before:') . ' ' . json_encode($old, JSON_UNESCAPED_UNICODE) . "\n" : '') .
            ($new !== null ? lang('@After:') . ' '  . json_encode($new, JSON_UNESCAPED_UNICODE) : ''),
            ENT_QUOTES
        );
        $details = '<span data-hint="' . $full_json . '" style="cursor:help; text-decoration:underline dotted; font-size:0.85em;">' . $diff_text . '</span>';

        $data[] = [
            $when,
            $who,
            $badge,
            '<span style="font-family:monospace; font-size:0.85em;">' . htmlspecialchars($row['table_name']) . '</span>',
            (int)$row['row_id'],
            $details,
        ];
    }
}

if (empty($data) && $res) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No audit log entries found for the selected filters.') . "</p>";
} elseif (!empty($data)) {
    htm_Table($headers, $data, 'auditTbl', 300, '', true, [], '600px', 'audit_log_export.csv');
    echo '<p style="color:var(--text-muted); font-size:0.8em; margin-top:10px;">' . lang('@Showing the 300 most recent matching entries.') . '</p>';
}

htm_Card_end();
htm_Footer();
?>
