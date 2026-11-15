<?php # /stock_count.php v:1.0.0 d:2026-11-05 i:evs
# Lageroptælling: den optalte beholdning pr. vare - kun varer med et udfyldt "Optalt" der afviger, reguleres og logges
# (stock_movements, se inc/stock.inc.php). Valgfrit bogføres lagerværdien (beholdning x kostpris) pr. optællingsdatoen:
# varelagerkontoen sættes til værdien mod reguleringskontoen - typisk ved årsafslutningen, så lageret står i balancen.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/stock.inc.php';

$has_cost = db_has_column($conn, 'products', 'prod_cost_price');
$has_cat  = db_has_column($conn, 'products', 'prod_category');
// Optællingen må alle lave; at bogføre lagerværdien er en postering i hovedbogen - samme niveau som manuel postering
$can_post = (int)($_SESSION['user_level'] ?? 1) >= 3;
$prods = [];
$res = DB::query($conn, "SELECT prod_id, prod_sku, prod_name, prod_stock" . ($has_cost ? ", prod_cost_price" : "") . ($has_cat ? ", prod_category" : "")
    . " FROM products ORDER BY " . ($has_cat ? "COALESCE(prod_category, ''), " : "") . "COALESCE(prod_sku, ''), prod_name");
while ($res && ($r = DB::fetch_assoc($res))) $prods[(int)$r['prod_id']] = $r;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_count'])) {
    $date = (string)($_POST['count_date'] ?? '');
    if (!tc_is_date($date) || $date > date('Y-m-d')) redirect('stock_count.php', '@Please enter a valid date (not in the future).', 'error');
    $post_value = !empty($_POST['post_value']);
    if ($post_value && !$can_post) redirect('stock_count.php', '@Only an administrator (level 3) can post the inventory value.', 'error');
    if ($post_value && is_date_locked($conn, $date)) redirect('stock_count.php', '@The date is in a locked accounting period.', 'error');
    // En optælling dateret bagud sammenlignes med beholdningen PÅ den dato (salg og modtagelser siden er trukket fra) -
    // kun muligt fra den dag alle lagerbevægelser logges; før det gav et salg i januar en forkert regulering pr. 31/12
    $log_from = stock_ready($conn) ? stock_log_from($conn) : date('Y-m-d');
    if ($date < $log_from) redirect('stock_count.php', sprintf(lang('@A stock count can be dated %s at the earliest - all stock movements are logged from that day.'), date(CONF_DATE_FORMAT, strtotime($log_from))), 'error');
    $at = stock_qty_at($conn, $date);
    $changes = [];
    foreach ((array)($_POST['counted'] ?? []) as $pid => $raw) {
        $pid = (int)$pid;  $raw = trim((string)$raw);
        if ($raw === '' || !isset($prods[$pid])) continue;      // (tomt felt = ikke talt)
        $cnt = tc_num($raw, 0);
        if ($cnt === null) redirect('stock_count.php', sprintf(lang('@%s: the counted quantity must be a number of 0 or more.'), htmlspecialchars((string)$prods[$pid]['prod_name'])), 'error');      // (flash-beskeder vises som HTML - varenavnet blev ikke escapet)
        $diff = round((float)$cnt - (float)($at[$pid] ?? 0), 3);
        if (abs($diff) >= 0.0005) $changes[$pid] = $diff;
    }
    $voucher = 0;  $posted = 0.0;
    DB::begin_transaction($conn);
    try {
        foreach ($changes as $pid => $diff) stock_move($conn, $pid, $diff, 'count', $date, ['note' => lang('@Stock count')]);
        if ($post_value) [$voucher, $posted] = stock_post_value($conn, stock_value($conn, $date)[0], $date);
        log_action($conn, 'STOCK_COUNT', 'products', 0, null, ['date' => $date, 'changes' => $changes, 'value_voucher' => $voucher, 'value_diff' => $posted]);
        DB::commit($conn);
    } catch (\Throwable $e) {
        DB::rollback($conn);
        redirect('stock_count.php', lang('@Error:') . ' ' . htmlspecialchars($e->getMessage()), 'error');
    }
    $msg = sprintf(lang('@%d product(s) adjusted to the counted quantity.'), count($changes));
    if ($post_value) $msg .= ' ' . ($voucher ? sprintf(lang('@The inventory value was posted (voucher %d, change %s).'), $voucher, fmt_dk($posted)) : lang('@The inventory value already matches the books - nothing posted.'));
    redirect('stock_count.php', $msg);
}

[$value, $no_cost] = stock_value($conn);
$inv_acc = stock_acc_inventory($conn);
$booked = $can_post && tc_account_ok($conn, $inv_acc) ? (float)(DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) FROM ledger WHERE acc_id = $inv_acc"))[0] ?? 0) : null;
$cur = setting('currency', 'DKK');

htm_Header('@Stock Count');
showMenu();
htm_Card_(capt: '@Stock Count', wdth: 1000);
htm_Note(lang('@Enter the counted quantity for the products you have counted - an empty field is left unchanged. Only differences are adjusted and logged.') . ' '
    . lang('@With a date in the past (e.g. the end of the year), the count is compared with the stock on that date - sales and receipts since then are kept.'), 'font-size:0.9em; margin-top:-5px;');
if (!stock_ready($conn)) htm_Banner(lang('@Stock movements need a database update: System -> Maintenance -> Database migration (Goods receipt and stock count).'), 'warning');
if (!$prods) {
    htm_Note(lang('@No products yet.'), 'font-style:italic;');
} else {
    echo '<form method="post">';
    csrf_field();
    htm_Field(icon: 'fa-calendar', labl: '@Count date', name: 'count_date', valu: date('Y-m-d'), type: 'date', wdth: '200px');
    $inp = 'style="width:100px; padding:5px; text-align:right;" inputmode="decimal"';
    echo '<table style="width:100%; border-collapse:collapse; margin-top:8px;"><tr><th style="text-align:left;">' . lang('@Item No.') . '</th><th style="text-align:left;">' . lang('@Product')
       . '</th>' . ($has_cat ? '<th style="text-align:left;">' . lang('@Category') . '</th>' : '') . '<th style="text-align:right;">' . lang('@In Stock') . '</th><th style="text-align:right;">'
       . lang('@Counted') . '</th>' . ($has_cost ? '<th style="text-align:right;">' . lang('@Cost Price') . '</th>' : '') . '</tr>';
    foreach ($prods as $pid => $p) {
        echo '<tr style="border-bottom:1px solid var(--border-color);"><td style="padding:5px;">' . htmlspecialchars((string)$p['prod_sku']) . '</td><td>' . htmlspecialchars($p['prod_name']) . '</td>'
           . ($has_cat ? '<td>' . htmlspecialchars((string)$p['prod_category']) . '</td>' : '')
           . '<td style="text-align:right;">' . rtrim(rtrim(fmt_dk((float)$p['prod_stock'], 3), '0'), ',') . '</td>'
           . '<td style="text-align:right;"><input type="text" name="counted[' . $pid . ']" ' . $inp . '></td>'
           . ($has_cost ? '<td style="text-align:right;">' . ($p['prod_cost_price'] !== null ? fmt_dk((float)$p['prod_cost_price']) : '<span style="color:var(--color-warning);">' . lang('@missing') . '</span>') . '</td>' : '')
           . '</tr>';
    }
    echo '</table>';
    if ($has_cost && $can_post) {
        htm_Note(sprintf(lang('@Inventory value at cost price now: %s. Booked on the inventory account %d: %s.'), fmt_dk($value) . ' ' . $cur, $inv_acc,
            $booked === null ? lang('@the account does not exist') : fmt_dk($booked) . ' ' . $cur)
            . ($no_cost ? ' <span style="color:var(--color-warning);">' . sprintf(lang('@%d product(s) in stock have no cost price and are not included.'), $no_cost) . '</span>' : ''), 'margin-top:10px;');
        echo '<label style="display:block; margin:8px 0;"><input type="checkbox" name="post_value" value="1"> ' . lang('@Post the inventory value per the count date (inventory account against the stock adjustment account)') . '</label>';
    }
    htm_Button(icon: 'fa-clipboard-check', labl: '@Save the count', type: 'success', attr: 'name="save_count" value="1"');
    echo '</form>';
}
htm_Card_end();
htm_Footer();
