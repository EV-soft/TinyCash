<?php # /picking_list.php v:1.0.0 d:2026-11-05 i:evs
# Pluk- og pakkeliste (bruger-anmodet): et internt lagerpapir. Vælg ordrer (fakturakladder, accepterede tilbud,
# nyligt sendte fakturaer) - pluklisten samler alle varer pr. vare (sorteret efter kategori og varenr., så lageret
# gennemgås én gang) med beholdning og mangel; derefter én pakkeliste pr. ordre (ny side ved udskrift).
# Beholdningen er allerede nedskrevet for bogførte fakturaer (invoice_post) - "mangler" regnes kun af det ubogførte.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$days   = 14;
$since  = date('Y-m-d', strtotime("-$days days"));
$sel_i  = array_values(array_filter(array_map('intval', (array)($_GET['inv'] ?? [])), fn($i) => $i > 0));
$sel_q  = array_values(array_filter(array_map('intval', (array)($_GET['q'] ?? [])), fn($i) => $i > 0));
$has_q  = db_has_table($conn, 'quotes');

// Ordrer der kan plukkes: fakturakladder, accepterede tilbud (ordrer), fakturaer sendt de sidste $days dage
$cand = [];
$res = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_status, c.cust_name FROM invoices i JOIN customers c ON c.cust_id = i.cust_id
    WHERE i.credit_ref IS NULL AND (LOWER(i.inv_status) = 'draft' OR (LOWER(i.inv_status) IN ('sent', 'paid') AND i.inv_date >= '$since'))
      AND EXISTS (SELECT 1 FROM invoice_lines l WHERE l.inv_id = i.inv_id AND l.quantity > 0)
    ORDER BY i.inv_date, i.inv_id");
while ($res && ($r = DB::fetch_assoc($res))) {
    $draft = strtolower((string)$r['inv_status']) === 'draft';
    $cand[] = ['k' => 'inv', 'id' => (int)$r['inv_id'], 'posted' => !$draft, 'date' => $r['inv_date'], 'cust' => $r['cust_name'],
               'label' => $draft ? lang('@Draft invoice') . ' (' . (int)$r['inv_id'] . ')' : lang('@Invoice') . ' #' . str_pad((string)$r['invoice_no'], 6, '0', STR_PAD_LEFT)];
}
if ($has_q) {
    $res = DB::query($conn, "SELECT q.quote_id, q.quote_no, q.quote_date, c.cust_name FROM quotes q JOIN customers c ON c.cust_id = q.cust_id
        WHERE q.status = 'accepted' AND EXISTS (SELECT 1 FROM quote_lines l WHERE l.quote_id = q.quote_id AND l.quantity > 0) ORDER BY q.quote_date, q.quote_id");
    while ($res && ($r = DB::fetch_assoc($res))) {
        $cand[] = ['k' => 'q', 'id' => (int)$r['quote_id'], 'posted' => false, 'date' => $r['quote_date'], 'cust' => $r['cust_name'],
                   'label' => lang('@Order') . ' T-' . str_pad((string)$r['quote_no'], 6, '0', STR_PAD_LEFT)];
    }
}

htm_Header('@Picking and Packing List');
showMenu();

// --- Valg af ordrer ---
echo '<div class="no-print">';
htm_Card_(capt: '@Picking and Packing List', wdth: 1000);
htm_Note(sprintf(lang('@Choose the orders to pick: draft invoices, accepted quotes and invoices sent in the last %d days. The picking list collects all items, sorted by category and item number; then follows a packing list per order (a new page when printed).'), $days), 'font-size:0.9em; margin-top:-5px;');
if (!$cand) {
    htm_Note(lang('@No orders to pick right now.'), 'font-style:italic;');
} else {
    $rows = [];
    foreach ($cand as $c) {
        $checked = in_array($c['id'], $c['k'] === 'inv' ? $sel_i : $sel_q, true) || (!$sel_i && !$sel_q && !$c['posted']);      // (første gang: alt ubogført)
        $rows[] = ['<input type="checkbox" class="pl-pick" name="' . $c['k'] . '[]" value="' . $c['id'] . '"' . ($checked ? ' checked' : '') . '>',
                   htmlspecialchars($c['label']), date(CONF_DATE_FORMAT, strtotime((string)$c['date'])), htmlspecialchars((string)$c['cust']),
                   $c['posted'] ? lang('@Posted - stock already deducted') : lang('@Not posted')];
    }
    echo '<form method="get" action="picking_list.php">';
    htm_Table(['&nbsp;<input type="checkbox" data-hint="' . htmlspecialchars(lang('@Select all')) . '" onchange="document.querySelectorAll(\'.pl-pick\').forEach(c => c.checked = this.checked)">', '@Order', '@Date', '@Customer', '@Status'],
        $rows, 'plPickTbl', 100, '', true);
    echo '<div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">';
    htm_Button(icon: 'fa-list-check', labl: '@Make picking list', type: 'primary');
    if ($sel_i || $sel_q) htm_Button(icon: 'fa-print', labl: '@Print', type: 'secondary', attr: 'onclick="window.print(); return false;"');      // (knappen er en submit-knap - return false)
    echo '</div></form>';
}
htm_Card_end();
echo '</div>';

// --- De valgte ordrer og deres linjer ---
$orders = [];
foreach ($cand as $c) {
    if (!in_array($c['id'], $c['k'] === 'inv' ? $sel_i : $sel_q, true)) continue;
    $id = $c['id'];
    $head = $c['k'] === 'inv'
        ? DB::fetch_assoc(DB::query($conn, "SELECT i.delivery_address, i.cust_reference, c.cust_name, c.cust_address, c.cust_phone FROM invoices i JOIN customers c ON c.cust_id = i.cust_id WHERE i.inv_id = $id"))
        : DB::fetch_assoc(DB::query($conn, "SELECT q.delivery_address, q.cust_reference, c.cust_name, c.cust_address, c.cust_phone FROM quotes q JOIN customers c ON c.cust_id = q.cust_id WHERE q.quote_id = $id"));
    $lsql = $c['k'] === 'inv'
        ? "SELECT l.line_text, l.quantity, l.prod_id, p.prod_sku, p.prod_name, p.prod_category, p.prod_stock FROM invoice_lines l LEFT JOIN products p ON p.prod_id = l.prod_id WHERE l.inv_id = $id AND l.quantity > 0 ORDER BY l.line_id"
        : "SELECT l.line_text, l.quantity, l.prod_id, p.prod_sku, p.prod_name, p.prod_category, p.prod_stock FROM quote_lines l LEFT JOIN products p ON p.prod_id = l.prod_id WHERE l.quote_id = $id AND l.quantity > 0 ORDER BY l.line_id";
    $lines = [];  $res = DB::query($conn, $lsql);
    while ($res && ($l = DB::fetch_assoc($res))) $lines[] = $l;
    $orders[] = $c + ['head' => $head ?: [], 'lines' => $lines];
}

if ($orders) {
    $qf = fn($n) => rtrim(rtrim(fmt_dk((float)$n, 3), '0'), ',');
    $box = '<span style="display:inline-block; width:13px; height:13px; border:1px solid #555; vertical-align:middle;"></span>';
    $th = 'text-align:left; padding:6px 8px; border-bottom:2px solid #333; background:var(--bg-panel, #f8f9fa); font-size:13px;';
    $td = 'padding:6px 8px; border-bottom:1px solid var(--border-color, #ddd); font-size:13px; vertical-align:top;';

    // Plukliste: pr. vare
    $items = [];  $free = [];
    foreach ($orders as $o) foreach ($o['lines'] as $l) {
        if ((int)$l['prod_id'] > 0 && $l['prod_name'] !== null) {
            $k = (int)$l['prod_id'];
            $items[$k] = $items[$k] ?? ['sku' => (string)$l['prod_sku'], 'name' => $l['prod_name'], 'cat' => (string)$l['prod_category'], 'stock' => (float)$l['prod_stock'], 'qty' => 0.0, 'open' => 0.0, 'from' => []];
            $items[$k]['qty'] += (float)$l['quantity'];
            if (!$o['posted']) $items[$k]['open'] += (float)$l['quantity'];
            $items[$k]['from'][] = $o['label'] . ' (' . $qf($l['quantity']) . ')';
        } else {
            $free[] = ['text' => $l['line_text'], 'qty' => (float)$l['quantity'], 'from' => $o['label']];
        }
    }
    uasort($items, fn($a, $b) => [$a['cat'], $a['sku'], $a['name']] <=> [$b['cat'], $b['sku'], $b['name']]);

    htm_Card_(capt: '@Picking List', wdth: 1000);
    htm_Note(sprintf(lang('@%d order(s), %d item(s). Made %s.'), count($orders), count($items), date(CONF_DATE_FORMAT . ' H:i')), 'font-size:0.85em;');
    echo '<table style="width:100%; border-collapse:collapse;"><tr><th style="' . $th . ' width:5%;"></th><th style="' . $th . '">' . lang('@Item No.') . '</th><th style="' . $th . '">' . lang('@Item')
       . '</th><th style="' . $th . '">' . lang('@Category') . '</th><th style="' . $th . ' text-align:right;">' . lang('@Pick') . '</th><th style="' . $th . ' text-align:right;">' . lang('@In Stock')
       . '</th><th style="' . $th . ' text-align:right;">' . lang('@Missing') . '</th><th style="' . $th . '">' . lang('@For') . '</th></tr>';
    foreach ($items as $it) {
        $short = max(0, $it['open'] - $it['stock']);
        echo '<tr><td style="' . $td . ' text-align:center;">' . $box . '</td><td style="' . $td . ' white-space:nowrap;">' . htmlspecialchars($it['sku']) . '</td><td style="' . $td . '">' . htmlspecialchars($it['name'])
           . '</td><td style="' . $td . '">' . htmlspecialchars($it['cat']) . '</td><td style="' . $td . ' text-align:right; font-weight:bold;">' . $qf($it['qty'])
           . '</td><td style="' . $td . ' text-align:right;">' . $qf($it['stock']) . '</td><td style="' . $td . ' text-align:right;' . ($short > 0 ? ' color:var(--color-danger, #c0392b); font-weight:bold;' : '') . '">'
           . ($short > 0 ? $qf($short) : '-') . '</td><td style="' . $td . ' font-size:12px;">' . htmlspecialchars(implode(', ', $it['from'])) . '</td></tr>';
    }
    echo '</table>';
    if ($free) {
        echo '<h4 style="margin:16px 0 6px;">' . lang('@Other lines (no product)') . '</h4><table style="width:100%; border-collapse:collapse;">';
        foreach ($free as $f) echo '<tr><td style="' . $td . ' width:5%; text-align:center;">' . $box . '</td><td style="' . $td . '">' . htmlspecialchars((string)$f['text']) . '</td><td style="' . $td . ' text-align:right; width:10%;">' . $qf($f['qty']) . '</td><td style="' . $td . ' width:30%; font-size:12px;">' . htmlspecialchars($f['from']) . '</td></tr>';
        echo '</table>';
    }
    htm_Note(lang('@Missing = what the unposted orders need beyond the stock. Posted invoices have already been deducted from the stock.'), 'font-size:0.8em; margin-top:8px;');
    htm_Card_end();

    // Pakkelister: én pr. ordre
    foreach ($orders as $o) {
        $h = $o['head'];  $addr = trim((string)($h['delivery_address'] ?? '')) !== '' ? $h['delivery_address'] : (string)($h['cust_address'] ?? '');
        echo '<div class="pl-pack">';
        htm_Card_(capt: lang('@Packing List') . ' - ' . $o['label'], wdth: 1000);
        echo '<div style="display:flex; gap:30px; flex-wrap:wrap; margin-bottom:10px; font-size:13px;"><div><small style="color:var(--text-muted);">' . lang('@Deliver to') . ':</small><br><strong>'
           . htmlspecialchars((string)($h['cust_name'] ?? $o['cust'])) . '</strong><br>' . nl2br(htmlspecialchars($addr))
           . (!empty($h['cust_phone']) ? '<br>' . lang('@Phone') . ': ' . htmlspecialchars($h['cust_phone']) : '') . '</div>'
           . (!empty($h['cust_reference']) ? '<div><small style="color:var(--text-muted);">' . lang('@Your Reference') . ':</small><br>' . htmlspecialchars($h['cust_reference']) . '</div>' : '')
           . '<div><small style="color:var(--text-muted);">' . lang('@Date') . ':</small><br>' . date(CONF_DATE_FORMAT, strtotime((string)$o['date'])) . '</div></div>';
        echo '<table style="width:100%; border-collapse:collapse;"><tr><th style="' . $th . ' width:5%;"></th><th style="' . $th . '">' . lang('@Item No.') . '</th><th style="' . $th . '">' . lang('@Description')
           . '</th><th style="' . $th . ' text-align:right;">' . lang('@Qty') . '</th></tr>';
        foreach ($o['lines'] as $l) {
            echo '<tr><td style="' . $td . ' text-align:center;">' . $box . '</td><td style="' . $td . ' white-space:nowrap;">' . htmlspecialchars((string)($l['prod_sku'] ?? '')) . '</td><td style="' . $td . '">'
               . htmlspecialchars((string)$l['line_text']) . '</td><td style="' . $td . ' text-align:right; font-weight:bold;">' . $qf($l['quantity']) . '</td></tr>';
        }
        echo '</table><div style="display:flex; gap:30px; margin-top:24px; font-size:12px;"><div style="flex:1; border-top:1px solid #333; padding-top:3px;">' . lang('@Packed by')
           . '</div><div style="flex:1; border-top:1px solid #333; padding-top:3px;">' . lang('@Number of parcels') . '</div></div>';
        htm_Card_end();
        echo '</div>';
    }
}
// Udskrift: kun listerne, hver pakkeliste på sin egen side
echo '<style>@media print { .no-print, .floating-action-bar, nav, .top-nav { display:none !important; } .pl-pack { break-before:page; page-break-before:always; } }</style>';
htm_Footer();
