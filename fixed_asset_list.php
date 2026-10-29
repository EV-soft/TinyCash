<?php # /fixed_asset_list.php v:1.3.2 d:2026-09-15 i:evs
# NY FUNKTION: Anlægsaktiver/afskrivninger - anlægskartotek. Viser hvert
# aktiv med anskaffelsessum, akkumulerede afskrivninger og bogført værdi
# (netto), samt en samlet "Kør afskrivninger"-knap der bogfører den
# skyldige afskrivning for ALLE aktive aktiver frem til i dag i én omgang
# (se fixed_asset_actions.php?action=run_depreciation).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/fixed_assets.inc.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header('@Fixed Assets', 1200);
showMenu();

if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if ($m === 'created')     htm_Alert(lang('@Asset registered and posted successfully.'), 'success');
    elseif ($m === 'saved')   htm_Alert(lang('@Asset saved successfully.'), 'success');
    elseif ($m === 'disposed') htm_Alert(lang('@Asset disposed successfully.'), 'success');
    elseif ($m === 'cancelled') htm_Alert(lang('@Asset registration undone - the acquisition posting has been reversed.'), 'success');
    elseif ($m === 'cannot_cancel') htm_Alert(lang('@This asset cannot be undone - it has already been depreciated or disposed of. Use Dispose instead.'), 'error');
    elseif ($m === 'depreciated') {
        $n = (int)($_GET['count'] ?? 0);
        htm_Alert($n > 0 ? sprintf(lang('@Depreciation posted for %d asset(s).'), $n) : lang('@No depreciation was due for any active asset.'), 'success');
    }
}

// Findes tabellen endnu (kun tilfældet efter migrate_fixed_assets.php er kørt)?
$table_exists = @DB::query($conn, "SELECT 1 FROM fixed_assets LIMIT 1") !== false;

if (!$table_exists) {
    htm_Card_(capt: '@Fixed Assets', wdth: 1200);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The fixed assets database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): "Kør
// afskrivninger" var en helt blind knap - man trykkede den, og fik FØRST
// bagefter at vide (i et grønt banner) om der overhovedet var noget skyldigt
// at bogføre. Beregner nu det samme (via den delte
// fa_calculate_due_depreciation(), se inc/fixed_assets.inc.php) uden at
// bogføre noget, og viser resultatet FØR knappen trykkes - samme princip
// som time_list.php's egen "X ufakturerede timer"-boks, blot for
// afskrivninger. Ren beregning, ingen skrivning - derfor helt sikkert at
// køre ved hver sidevisning.
$due_today = date('Y-m-d');
$due_count = 0;
$due_total = 0.0;
$active_res = DB::query($conn, "SELECT * FROM fixed_assets WHERE status = 'active'");
if ($active_res) {
    while ($a = DB::fetch_assoc($active_res)) {
        $due = fa_calculate_due_depreciation($a, $due_today);
        if ($due !== null) { $due_count++; $due_total += $due['amount']; }
    }
}

$tools  = htm_Button(icon: 'fa-plus', labl: '@New Asset', type: 'success', link: 'fixed_asset_edit.php?id=0', attr: 'data-hint="'.lang('@Register a new fixed asset').'"', echo: false);
$run_confirm = $due_count > 0
    ? sprintf(lang('@Post %s in depreciation across %d asset(s)? This creates real ledger postings.'), number_format($due_total, 2, ',', '.') . ' ' . $cur, $due_count)
    : lang('@No depreciation is currently due for any active asset. Run anyway?');
$tools .= htm_Button(icon: 'fa-calculator', labl: '@Run Depreciation', type: 'primary', link: 'fixed_asset_actions.php?action=run_depreciation',
    attr: 'onclick="return confirm(\''.addslashes($run_confirm).'\');" data-hint="'.lang('@Calculate and post depreciation for all active assets through today').'"', echo: false);
htm_Card_(capt: '@Fixed Assets', wdth: 1200, tool: $tools);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Register of fixed assets (equipment, machinery etc.) with straight-line monthly depreciation. Acquisition is posted immediately; depreciation is posted whenever you run it (monthly is recommended).') .
    '</p>';

if ($due_count > 0) {
    echo '<div style="margin-bottom:15px; padding:12px 15px; background:var(--bg-panel); border-radius:8px; display:flex; align-items:center; gap:10px;">'
        . '<i class="fa-solid fa-calculator" style="color:var(--color-primary);"></i> '
        . sprintf(lang('@%d active asset(s) have depreciation due, totaling %s.'), $due_count, number_format($due_total, 2, ',', '.') . ' ' . $cur)
        . '</div>';
}

$res = DB::query($conn, "SELECT * FROM fixed_assets ORDER BY status ASC, acquisition_date DESC");

$headers = ['@Asset', '@Acquisition Date', '@Acquisition Cost', '@Accum. Depreciation', '@Net Book Value', '@Status', '@Actions'];
$data = [];

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $aid = (int)$row['asset_id'];
        $nbv = (float)$row['acquisition_cost'] - (float)$row['accumulated_depreciation'];

        if ($row['status'] === 'disposed') {
            $status_html = '<span style="color:var(--text-muted);"><i class="fa-solid fa-box-open"></i> ' . lang('@Disposed') . '</span>';
        } elseif ($row['status'] === 'cancelled') {
            // NYT (§bugs-batch-26-review): se fixed_asset_actions.php?action=cancel
            $status_html = '<span style="color:var(--text-muted);"><i class="fa-solid fa-rotate-left"></i> ' . lang('@Cancelled') . '</span>';
        } elseif ($nbv <= 0.01) {
            $status_html = '<span style="color:var(--color-secondary);">' . lang('@Fully Depreciated') . '</span>';
        } else {
            $status_html = '<span style="color:var(--color-success);">' . lang('@Active') . '</span>';
        }

        $actions = [
            ['icon' => 'fa-pencil', 'link' => 'fixed_asset_edit.php?id='.$aid, 'hint' => '@Edit', 'type' => 'primary'],
        ];
        $btns = htm_ActionButtons($actions, false);

        $data[] = [
            '<strong>' . htmlspecialchars($row['asset_name']) . '</strong>',
            date(CONF_DATE_FORMAT, strtotime($row['acquisition_date'])),
            number_format((float)$row['acquisition_cost'], 2, ',', '.') . ' ' . $cur,
            number_format((float)$row['accumulated_depreciation'], 2, ',', '.') . ' ' . $cur,
            '<strong>' . number_format($nbv, 2, ',', '.') . ' ' . $cur . '</strong>',
            $status_html,
            $btns,
        ];
    }
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No fixed assets registered yet.') . "</p>";
} else {
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): CSV-eksport
    // tilføjet - stort set enhver anden liste i systemet (expense_list.php/
    // supplier_list.php/time_list.php/inventory_status.php m.fl.) har det
    // allerede, men manglede her - og anlægskartoteket er typisk netop den
    // liste en revisor beder om ved årsafslutning.
    htm_Table($headers, $data, 'fixedAssetTbl', 100, '', true,
        ['', 'width:120px;', 'width:140px; text-align:right;', 'width:150px; text-align:right;', 'width:150px; text-align:right;', 'width:120px;', 'width:80px;'],
        '500px', 'anlaegskartotek.csv');
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
