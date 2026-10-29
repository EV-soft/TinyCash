<?php # /quote_list.php v:1.3.2 d:2026-09-15 i:evs
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
    elseif ($m === 'expired')    htm_Alert(lang('@The quote has expired and cannot be accepted - reopen it and extend the validity date first.'), 'error');
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

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 24): status
// pr. tilbud fandtes allerede (kolonnen $status_labels herunder), men intet
// samlet overblik over konverteringsraten - kladder tælles bevidst ikke med
// (aldrig sendt, intet kundesvar at måle), og "sent" er "afventer" (endnu
// intet svar) - kun 'accepted'/'converted' (vundet) vs. 'rejected' (tabt)
// indgår i selve procenten, samme "won vs. lost af de afgjorte"-definition
// quote_actions.php's egen statusmaskine allerede lægger op til (et tilbud
// SKAL være 'accepted' før det kan blive 'converted', se dens WHERE-clause).
$qcr_res = @DB::query($conn, "SELECT status, COUNT(*) AS n FROM quotes GROUP BY status");
$qcr_counts = ['sent' => 0, 'accepted' => 0, 'rejected' => 0, 'converted' => 0];
if ($qcr_res) {
    while ($qcr_row = DB::fetch_assoc($qcr_res)) {
        if (isset($qcr_counts[$qcr_row['status']])) $qcr_counts[$qcr_row['status']] = (int)$qcr_row['n'];
    }
}
$qcr_won     = $qcr_counts['accepted'] + $qcr_counts['converted'];
$qcr_lost    = $qcr_counts['rejected'];
$qcr_pending = $qcr_counts['sent'];
$qcr_decided = $qcr_won + $qcr_lost;
if ($qcr_decided > 0) {
    $qcr_rate = round($qcr_won / $qcr_decided * 100, 1);
    echo '<div style="display:flex; gap:25px; flex-wrap:wrap; margin:10px 0 15px 0; padding:12px 15px; background:var(--theme-card-alt-bg, rgba(0,0,0,0.03)); border-radius:6px;">';
    echo '<div><div style="color:var(--text-muted); font-size:0.8em;">' . lang('@Conversion Rate') . '</div><div style="font-weight:bold; font-size:1.3em; color:var(--color-primary);">' . number_format($qcr_rate, 1, ',', '.') . ' %</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.8em;">' . lang('@Won') . '</div><div style="font-weight:bold; font-size:1.3em; color:var(--color-success);">' . $qcr_won . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.8em;">' . lang('@Lost') . '</div><div style="font-weight:bold; font-size:1.3em; color:var(--color-danger);">' . $qcr_lost . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.8em;">' . lang('@Pending Response') . '</div><div style="font-weight:bold; font-size:1.3em; color:var(--color-warning);">' . $qcr_pending . '</div></div>';
    echo '</div>';
}

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
        // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): samme
        // alders-markering som fakturakladder/udgiftskladder fik samme
        // runde - en tilbudskladde ældre end 30 dage fremhæves nu direkte.
        if ($status === 'draft' && !empty($row['quote_date'])) {
            $draft_age_days = days_between($row['quote_date'], $today);
            if ($draft_age_days > 30) {
                $status_html .= ' <span style="color:var(--color-danger); font-weight:bold; font-size:0.85em;" data-hint="'.htmlspecialchars(sprintf(lang('@This draft was created %d days ago and may have been forgotten'), $draft_age_days)).'">(' . sprintf(lang('@%d days'), $draft_age_days) . ')</span>';
            }
        }
        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): et
        // accepteret tilbud fik ALDRIG nogen alders-advarsel - kun tilbuds-
        // kladder havde det (lige ovenfor). quote_followup.php dækker kun
        // "sendt, nær udløb" - et tilbud kunden allerede har sagt ja til, og
        // som bare venter på at blive konverteret til en faktura, kunne
        // derfor blive glemt uden nogensinde at blive faktureret, i modsætning
        // til en kladde. Bruger accepted_at (ikke quote_date) - alderen skal
        // måles fra hvornår tilbuddet reelt blev accepteret, ikke oprettet.
        if ($status === 'accepted' && !empty($row['accepted_at'])) {
            $accepted_age_days = days_between($row['accepted_at'], $today);
            if ($accepted_age_days > 14) {
                $status_html .= ' <span style="color:var(--color-danger); font-weight:bold; font-size:0.85em;" data-hint="'.htmlspecialchars(sprintf(lang('@This quote was accepted %d days ago and has not been invoiced yet'), $accepted_age_days)).'">(' . sprintf(lang('@%d days'), $accepted_age_days) . ')</span>';
            }
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
                type: 'danger', styl: 'display:inline-block; margin-left:4px; padding:4px 8px;', attr: 'data-hint="' . lang('@Delete the quote (you are asked first)') . '"', echo: false);
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
