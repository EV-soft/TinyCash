<?php # /stock_receipt.php v:1.0.0 d:2026-11-05 i:evs
# Varemodtagelse: varer der kommer ind på lageret - beholdningen tælles op, og kostprisen opdateres (vægtet gennemsnit,
# seneste pris eller uændret). Hver linje logges i stock_movements (se inc/stock.inc.php). Købsfakturaen bogføres som
# hidtil som en udgift - modtagelsen flytter kun antal og kostpris.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/stock.inc.php';

$has_cost = db_has_column($conn, 'products', 'prod_cost_price');
$prods = [];
$res = DB::query($conn, "SELECT prod_id, prod_sku, prod_name, prod_stock" . ($has_cost ? ", prod_cost_price" : "") . " FROM products ORDER BY prod_name");
while ($res && ($r = DB::fetch_assoc($res))) $prods[(int)$r['prod_id']] = $r;
$sups = [];
if (db_has_table($conn, 'suppliers')) {
    $res = DB::query($conn, "SELECT supplier_id, supplier_name FROM suppliers WHERE " . suppliers_active_filter($conn) . " ORDER BY supplier_name");
    while ($res && ($r = DB::fetch_assoc($res))) $sups[(int)$r['supplier_id']] = $r['supplier_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive'])) {
    $date   = (string)($_POST['rcv_date'] ?? '');
    $sup    = (int)($_POST['supplier_id'] ?? 0);
    $ref    = trim((string)($_POST['reference'] ?? ''));
    $method = in_array($_POST['cost_method'] ?? '', ['average', 'latest', 'keep'], true) ? $_POST['cost_method'] : 'average';
    if (!tc_is_date($date) || $date > date('Y-m-d')) redirect('stock_receipt.php', '@Please enter a valid date (not in the future).', 'error');
    if ($sup > 0 && !isset($sups[$sup])) $sup = 0;
    // Linjerne: vare + antal > 0; en indkøbspris er valgfri (uden den røres kostprisen ikke)
    $lines = [];
    foreach ((array)($_POST['line_prod'] ?? []) as $i => $pid) {
        $pid = (int)$pid;
        $qraw = trim((string)($_POST['line_qty'][$i] ?? ''));
        if ($pid <= 0 && $qraw === '') continue;
        $qty  = tc_num($qraw, 0.001);
        $craw = trim((string)($_POST['line_cost'][$i] ?? ''));
        $cost = $craw === '' ? null : tc_num($craw, 0);
        if (!isset($prods[$pid]) || $qty === null || ($craw !== '' && $cost === null)) {
            redirect('stock_receipt.php', sprintf(lang('@Line %d: choose a product, and enter a quantity above 0 and a valid purchase price.'), $i + 1), 'error');
        }
        $lines[] = [$pid, (float)$qty, $cost];
    }
    if (!$lines) redirect('stock_receipt.php', '@Add at least one line.', 'error');
    DB::begin_transaction($conn);
    try {
        foreach ($lines as [$pid, $qty, $cost]) {
            $p = DB::fetch_assoc(DB::query($conn, "SELECT prod_stock" . ($has_cost ? ", prod_cost_price" : "") . " FROM products WHERE prod_id = $pid"));
            if ($has_cost && $cost !== null && $method !== 'keep') {
                $old_q = max(0.0, (float)$p['prod_stock']);  $old_c = $p['prod_cost_price'];
                $new_c = ($method === 'average' && $old_c !== null && $old_q > 0) ? ($old_q * (float)$old_c + $qty * $cost) / ($old_q + $qty) : $cost;
                DB::query($conn, "UPDATE products SET prod_cost_price = " . round($new_c, 2) . " WHERE prod_id = $pid");
            }
            stock_move($conn, $pid, $qty, 'receipt', $date, ['unit_cost' => $cost, 'supplier_id' => $sup ?: null, 'reference' => $ref !== '' ? $ref : null]);
        }
        log_action($conn, 'STOCK_RECEIPT', 'products', 0, null, ['date' => $date, 'supplier_id' => $sup, 'reference' => $ref, 'lines' => $lines, 'cost_method' => $method]);
        DB::commit($conn);
    } catch (\Throwable $e) {
        DB::rollback($conn);
        redirect('stock_receipt.php', lang('@Error:') . ' ' . htmlspecialchars($e->getMessage()), 'error');
    }
    redirect('stock_receipt.php', sprintf(lang('@%d line(s) received into stock.'), count($lines)));
}

htm_Header('@Goods Receipt');
showMenu();
htm_Card_(capt: '@Goods Receipt', wdth: 1000);
htm_Note(lang('@Goods that arrive in stock: the stock is counted up and the cost price is updated. Book the purchase invoice as an expense as usual - the receipt only moves quantities and cost prices.'), 'font-size:0.9em; margin-top:-5px;');
if (!stock_ready($conn)) htm_Banner(lang('@Stock movements need a database update: System -> Maintenance -> Database migration (Goods receipt and stock count).'), 'warning');
if (!$prods) {
    htm_Note(lang('@No products yet.'), 'font-style:italic;');
} else {
    echo '<form method="post">';
    csrf_field();
    echo '<div style="display:flex; gap:12px; flex-wrap:wrap;">';
    htm_Field(icon: 'fa-calendar', labl: '@Date', name: 'rcv_date', valu: date('Y-m-d'), type: 'date', wdth: '180px');
    if ($sups) htm_Field(icon: 'fa-truck', labl: '@Supplier', name: 'supplier_id', valu: 0, type: 'sele', opti: [0 => '-'] + $sups, wdth: '260px');
    htm_Field(icon: 'fa-hashtag', labl: '@Reference', name: 'reference', valu: '', type: 'text', wdth: '220px', hint: '@E.g. the number of the supplier\'s delivery note or invoice');
    echo '</div>';
    $opt = '<option value="0">-</option>';
    foreach ($prods as $p) $opt .= '<option value="' . (int)$p['prod_id'] . '" data-cost="' . htmlspecialchars((string)($p['prod_cost_price'] ?? '')) . '">'
        . htmlspecialchars(trim(($p['prod_sku'] ? $p['prod_sku'] . ' - ' : '') . $p['prod_name'])) . ' (' . rtrim(rtrim(fmt_dk((float)$p['prod_stock'], 3), '0'), ',') . ')</option>';
    $inp = 'style="width:100%; padding:6px; box-sizing:border-box; text-align:right;"';
    echo '<table id="rcvTbl" style="width:100%; border-collapse:collapse; margin-top:10px;"><tr><th style="text-align:left;">' . lang('@Product') . ' (' . lang('@In Stock') . ')</th><th style="width:16%;">' . lang('@Qty')
       . '</th><th style="width:20%;">' . lang('@Purchase price per unit') . '</th></tr>';
    for ($i = 0; $i < 8; $i++) {
        echo '<tr><td><select name="line_prod[]" style="width:100%; padding:6px;" onchange="rcvCost(this)">' . $opt . '</select></td>'
           . '<td><input type="text" inputmode="decimal" name="line_qty[]" ' . $inp . '></td><td><input type="text" inputmode="decimal" name="line_cost[]" ' . $inp . '></td></tr>';
    }
    echo '</table>';
    if ($has_cost) {
        htm_Field(icon: 'fa-calculator', labl: '@Cost price', name: 'cost_method', valu: 'average', type: 'sele', wdth: '360px',
            opti: ['average' => lang('@Weighted average of stock and receipt'), 'latest' => lang('@Latest purchase price'), 'keep' => lang('@Keep the cost price')]);
    }
    echo '<div style="margin-top:12px;">';
    htm_Button(icon: 'fa-dolly', labl: '@Receive into stock', type: 'success', attr: 'name="receive" value="1"');
    echo '</div></form>';
    echo '<script>function rcvCost(s){ const c = s.selectedOptions[0].dataset.cost, f = s.closest("tr").querySelector("[name=\'line_cost[]\']"); if (c && f && f.value === "") f.value = String(c).replace(".", ","); }</script>';
}
htm_Card_end();

// Seneste modtagelser
if (stock_ready($conn)) {
    $res = DB::query($conn, "SELECT m.move_date, m.qty, m.unit_cost, m.reference, p.prod_name, p.prod_sku, s.supplier_name
        FROM stock_movements m JOIN products p ON p.prod_id = m.prod_id LEFT JOIN suppliers s ON s.supplier_id = m.supplier_id
        WHERE m.kind = 'receipt' ORDER BY m.move_id DESC LIMIT 50");
    $rows = [];
    while ($res && ($r = DB::fetch_assoc($res))) {
        $rows[] = [date(CONF_DATE_FORMAT, strtotime($r['move_date'])), htmlspecialchars(trim(($r['prod_sku'] ? $r['prod_sku'] . ' - ' : '') . $r['prod_name'])),
                   rtrim(rtrim(fmt_dk((float)$r['qty'], 3), '0'), ','), $r['unit_cost'] !== null ? fmt_dk((float)$r['unit_cost']) : '-',
                   htmlspecialchars((string)($r['supplier_name'] ?? '')), htmlspecialchars((string)($r['reference'] ?? ''))];
    }
    if ($rows) {
        htm_Card_(capt: '@Latest Receipts', wdth: 1000);
        htm_Table(['@Date', '@Product', '@Qty', '@Purchase price per unit', '@Supplier', '@Reference'], $rows, 'rcvList', 100, '', true,
            ['', '', 'text-align:right;', 'text-align:right;', '', ''], '400px', 'varemodtagelser.csv');
        htm_Card_end();
    }
}
htm_Footer();
