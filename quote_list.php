<?php # /quote_list.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Tilbud/Ordrebekræftelse (bruger-anmodet) - oversigt. Samme
# rolle på tilbudssiden som sales_hub.php's fakturaoversigt har for fakturaer.
# "Udløbet" er en ren visnings-badge (beregnet her, ikke en gemt status) for
# et tilbud der stadig kun er 'sent' men hvis valid_until er overskredet -
# samme "beregn ved visning"-princip som aging_report.php's aldersgrupper.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header('@Quotes', 1300);
showMenu();

if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if ($m === 'deleted')   htm_Alert(lang('@Deleted successfully'), 'success');
    elseif ($m === 'sent')      htm_Alert(lang('@Quote marked as sent.'), 'success');
    elseif ($m === 'accepted')  htm_Alert(lang('@Quote marked as accepted.'), 'success');
    elseif ($m === 'rejected')  htm_Alert(lang('@Quote marked as rejected.'), 'success');
    elseif ($m === 'reopened')  htm_Alert(lang('@Quote reopened to draft.'), 'success');
    elseif ($m === 'converted') htm_Alert(lang('@Quote converted to a draft invoice. Review and post it from the Sales Hub.'), 'success');
    elseif ($m === 'bad_status') htm_Alert(lang('@This action is not valid for the quote\'s current status.'), 'error');
}

$table_exists = @DB::query($conn, "SELECT 1 FROM quotes LIMIT 1") !== false;
if (!$table_exists) {
    htm_Card_(capt: '@Quotes', wdth: 1300);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The quotes database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

$tools = htm_Button(icon: 'fa-plus', labl: '@New Quote', type: 'success', link: 'quote_edit.php?id=0', attr: 'data-hint="'.lang('@Create a new quote').'"', echo: false);
htm_Card_(capt: '@Quotes', wdth: 1300, tool: $tools);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Quotes and order confirmations sent to customers before invoicing. A quote never affects the ledger, VAT return or aging report - only converting it to an invoice does.') .
    '</p>';

$res = DB::query($conn, "SELECT q.*, c.cust_name,
        (SELECT COALESCE(SUM(quantity * price_each * (100 + line_vat_rate) / 100.0), 0) FROM quote_lines WHERE quote_id = q.quote_id) AS total
    FROM quotes q JOIN customers c ON q.cust_id = c.cust_id
    ORDER BY q.status = 'draft' DESC, q.quote_date DESC, q.quote_id DESC");

$today = date('Y-m-d');
$status_labels = [
    'draft'     => ['@Draft',     'var(--text-muted)'],
    'sent'      => ['@Sent',      'var(--color-info)'],
    'accepted'  => ['@Accepted',  'var(--color-success)'],
    'rejected'  => ['@Rejected',  'var(--color-danger)'],
    'converted' => ['@Converted to Invoice', 'var(--color-primary)'],
];

$headers = ['@Quote No', '@Customer', '@Date', '@Valid Until', '@Total', '@Status', '@Actions'];
$data = [];

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $qid    = (int)$row['quote_id'];
        $status = $row['status'];
        $lbl    = $status_labels[$status] ?? [$status, 'var(--text-muted)'];

        $status_html = '<span style="color:'.$lbl[1].'; font-weight:bold;">'.lang($lbl[0]).'</span>';
        if ($status === 'sent' && !empty($row['valid_until']) && $row['valid_until'] < $today) {
            $status_html .= ' <span style="color:var(--color-warning); font-size:0.85em;">('.lang('@Expired').')</span>';
        }

        $actions = [
            ['icon' => 'fa-eye', 'link' => 'quote_view.php?id='.$qid, 'hint' => '@View / Print', 'type' => 'primary'],
        ];
        if ($status === 'draft') {
            $actions[] = ['icon' => 'fa-pencil', 'link' => 'quote_edit.php?id='.$qid, 'hint' => '@Edit', 'type' => 'secondary'];
        }
        if ($status === 'accepted') {
            $actions[] = ['icon' => 'fa-file-invoice', 'link' => 'quote_actions.php?action=convert&id='.$qid, 'hint' => '@Convert to Invoice', 'type' => 'success', 'confirm' => '@Convert this quote to a draft invoice? You can still review it before posting.'];
        }
        $btns = htm_ActionButtons($actions, false);
        if ($status === 'draft') {
            $btns .= htm_ConfirmLink(icon: 'fa-trash', labl: '', link: 'quote_edit.php?id='.$qid.'&del=1',
                mess: '@Are you sure you want to delete this quote? This cannot be undone.',
                type: 'danger', styl: 'display:inline-block; margin-left:4px; padding:4px 8px;', echo: false);
        }

        $data[] = [
            'T-' . str_pad((string)$row['quote_no'], 6, '0', STR_PAD_LEFT),
            '<a href="quote_view.php?id='.$qid.'">'.htmlspecialchars($row['cust_name']).'</a>',
            date(CONF_DATE_FORMAT, strtotime($row['quote_date'])),
            !empty($row['valid_until']) ? date(CONF_DATE_FORMAT, strtotime($row['valid_until'])) : '-',
            number_format((float)$row['total'], 2, ',', '.') . ' ' . $cur,
            $status_html,
            $btns,
        ];
    }
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No quotes registered yet.') . "</p>";
} else {
    htm_Table($headers, $data, 'quoteTbl', 100, '', true,
        ['width:110px;', '', 'width:110px;', 'width:110px;', 'width:140px; text-align:right;', 'width:170px;', 'width:150px;']);
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
