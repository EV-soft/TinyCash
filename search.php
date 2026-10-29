<?php # /search.php v:1.3.2 d:2026-09-15 i:evs
# Global søgning på tværs af systemet (§bogholder-behov-gennemgang
# 2026-09-01) - før fandtes kun lokale filtre pr. liste (fakturaer, udgifter,
# kunder hver for sig); ingen måde at slå "den faktura til kunde X fra marts"
# op uden at vide hvilken liste den lå i og lede der. Søger på tværs af
# kunder/fakturaer/udgifter/leverandører/produkter i én omgang og linker
# direkte videre til hver post.
#
# Leverandørsøgningen degraderer stille (samme @-mønster som aging_report.php)
# hvis leverandørmodulets tabel ikke er migreret endnu.
#
# GET (ikke POST) med query-param 'q' - bevidst, så en søgning kan bogmærkes/
# deles/åbnes direkte via URL, samme konvention som fx trial_balance.php's
# periodevalg.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$q = trim($_GET['q'] ?? '');
$RESULT_LIMIT = 20;

htm_Header('@Search');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<form method="get" style="margin-bottom:20px;">';
echo '<div style="display:flex; gap:10px;">';
echo '<input type="text" name="q" value="'.htmlspecialchars($q).'" autofocus placeholder="'.htmlspecialchars(lang('@Search customers, invoices, expenses, suppliers, products, quotes, assets, time entries...')).'" style="flex:1; padding:12px; font-size:1.1em; border:1px solid var(--border-color); border-radius:6px; background:var(--bg-card); color:var(--text-main);">';
htm_Button('fa-search', '@Search', 'primary', '', '', 'type="submit"');
echo '</div>';
echo '</form>';

if (strlen($q) < 2) {
    if ($q !== '') {
        htm_Alert(lang('@Please enter at least 2 characters.'), 'info', 700, true);
    }
    echo "</div>";
    htm_Footer();
    ob_end_flush();
    exit;
}

$like = '%' . DB::escape($conn, $q) . '%';
$any_results = false;

// --- KUNDER ---
// RETTET: kunder og leverandører i papirkurven blev fundet og åbnet som almindelige, aktive poster
$cust_res = DB::query($conn, "SELECT cust_id, cust_name, cust_email, cust_cvr FROM customers
    WHERE (cust_name LIKE '$like' OR cust_email LIKE '$like' OR cust_cvr LIKE '$like') AND " . customers_active_filter($conn) . "
    ORDER BY cust_name LIMIT $RESULT_LIMIT");
$cust_rows = [];
if ($cust_res) { while ($r = DB::fetch_assoc($cust_res)) { $cust_rows[] = $r; } }

if (!empty($cust_rows)) {
    $any_results = true;
    htm_Card_(sprintf(lang('@Customers (%d)'), count($cust_rows)), 1000);
    $data = [];
    foreach ($cust_rows as $r) {
        $data[] = [
            '<a href="customer_statement.php?id=' . (int)$r['cust_id'] . '">' . htmlspecialchars($r['cust_name']) . '</a>',
            htmlspecialchars($r['cust_email'] ?? ''),
            htmlspecialchars($r['cust_cvr'] ?? ''),
        ];
    }
    htm_Table(['@Name', '@Email', '@CVR'], $data, 'search_cust_tbl', 100);
    htm_Card_end();
}

// --- FAKTURAER ---
$inv_res = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_status, i.inv_date, c.cust_name
    FROM invoices i JOIN customers c ON i.cust_id = c.cust_id
    WHERE i.invoice_no LIKE '$like' OR c.cust_name LIKE '$like' OR i.cust_reference LIKE '$like'
    ORDER BY i.inv_date DESC LIMIT $RESULT_LIMIT");
$inv_rows = [];
if ($inv_res) { while ($r = DB::fetch_assoc($inv_res)) { $inv_rows[] = $r; } }

if (!empty($inv_rows)) {
    $any_results = true;
    htm_Card_(sprintf(lang('@Invoices (%d)'), count($inv_rows)), 1000);
    $data = [];
    foreach ($inv_rows as $r) {
        $label = !empty($r['invoice_no']) ? '#' . $r['invoice_no'] : lang('@Draft');
        $data[] = [
            '<a href="invoice_view.php?id=' . (int)$r['inv_id'] . '">' . htmlspecialchars($label) . '</a>',
            htmlspecialchars($r['cust_name']),
            date(CONF_DATE_FORMAT, strtotime($r['inv_date'])),
            htmlspecialchars(ucfirst($r['inv_status'])),
        ];
    }
    htm_Table(['@Invoice', '@Customer', '@Date', '@Status'], $data, 'search_inv_tbl', 100);
    htm_Card_end();
}

// --- UDGIFTER ---
$exp_res = DB::query($conn, "SELECT exp_id, exp_date, supplier, description, amount, voucher_no FROM expenses
    WHERE supplier LIKE '$like' OR description LIKE '$like'
    ORDER BY exp_date DESC LIMIT $RESULT_LIMIT");
$exp_rows = [];
if ($exp_res) { while ($r = DB::fetch_assoc($exp_res)) { $exp_rows[] = $r; } }

if (!empty($exp_rows)) {
    $any_results = true;
    htm_Card_(sprintf(lang('@Expenses (%d)'), count($exp_rows)), 1000);
    $data = [];
    foreach ($exp_rows as $r) {
        $data[] = [
            '<a href="expense_edit.php?id=' . (int)$r['exp_id'] . '">' . htmlspecialchars($r['supplier']) . '</a>',
            htmlspecialchars($r['description']),
            date(CONF_DATE_FORMAT, strtotime($r['exp_date'])),
            number_format((float)$r['amount'], 2, ',', '.'),
            empty($r['voucher_no']) ? '<span style="color:var(--text-muted);">' . lang('@Draft') . '</span>' : lang('@Posted'),
        ];
    }
    htm_Table(['@Supplier', '@Description', '@Date', '@Amount', '@Status'], $data, 'search_exp_tbl', 100);
    htm_Card_end();
}

// --- LEVERANDØRER (leverandørmodul kan mangle - degraderer stille) ---
$sup_res = @DB::query($conn, "SELECT supplier_id, supplier_name, cvr FROM suppliers
    WHERE supplier_name LIKE '$like' AND " . suppliers_active_filter($conn) . " ORDER BY supplier_name LIMIT $RESULT_LIMIT");
if ($sup_res !== false) {
    $sup_rows = [];
    while ($r = DB::fetch_assoc($sup_res)) { $sup_rows[] = $r; }
    if (!empty($sup_rows)) {
        $any_results = true;
        htm_Card_(sprintf(lang('@Suppliers (%d)'), count($sup_rows)), 1000);
        $data = [];
        foreach ($sup_rows as $r) {
            $data[] = [
                '<a href="supplier_statement.php?id=' . (int)$r['supplier_id'] . '">' . htmlspecialchars($r['supplier_name']) . '</a>',
                htmlspecialchars($r['cvr'] ?? ''),
            ];
        }
        htm_Table(['@Name', '@CVR'], $data, 'search_sup_tbl', 100);
        htm_Card_end();
    }
}

// --- PRODUKTER ---
$prod_res = DB::query($conn, "SELECT prod_id, prod_sku, prod_name, prod_price, prod_stock FROM products
    WHERE prod_name LIKE '$like' OR prod_sku LIKE '$like'
    ORDER BY prod_name LIMIT $RESULT_LIMIT");
$prod_rows = [];
if ($prod_res) { while ($r = DB::fetch_assoc($prod_res)) { $prod_rows[] = $r; } }

if (!empty($prod_rows)) {
    $any_results = true;
    htm_Card_(sprintf(lang('@Products (%d)'), count($prod_rows)), 1000);
    $data = [];
    foreach ($prod_rows as $r) {
        $data[] = [
            '<a href="product_edit.php?id=' . (int)$r['prod_id'] . '">' . htmlspecialchars($r['prod_name']) . '</a>',
            htmlspecialchars($r['prod_sku'] ?? ''),
            number_format((float)$r['prod_price'], 2, ',', '.'),
            $r['prod_stock'],
        ];
    }
    htm_Table(['@Name', '@SKU', '@Price', '@Stock'], $data, 'search_prod_tbl', 100);
    htm_Card_end();
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): søgningen dækkede
// kun de fem oprindelige moduler - Tilbud/Anlægsaktiver/Timeregistrering
// (alle tre bygget SENERE end selve global-søgningen) blev aldrig koblet
// på. En søgning på et firmanavn fandt derfor ikke et tilbud lavet til dem,
// selvom tilbuddet er et fuldgyldigt dokument på lige fod med en faktura.
// Samme "stille degradering hvis ikke migreret endnu"-mønster som
// leverandørsøgningen ovenfor for alle tre - ingen af de tre tabeller findes
// nødvendigvis på en frisk installation.

// --- TILBUD ---
$quote_res = @DB::query($conn, "SELECT q.quote_id, q.quote_no, q.status, q.quote_date, c.cust_name
    FROM quotes q JOIN customers c ON q.cust_id = c.cust_id
    WHERE q.quote_no LIKE '$like' OR c.cust_name LIKE '$like' OR q.cust_reference LIKE '$like'
    ORDER BY q.quote_date DESC LIMIT $RESULT_LIMIT");
if ($quote_res !== false) {
    $quote_rows = [];
    while ($r = DB::fetch_assoc($quote_res)) { $quote_rows[] = $r; }
    if (!empty($quote_rows)) {
        $any_results = true;
        htm_Card_(sprintf(lang('@Quotes (%d)'), count($quote_rows)), 1000);
        $data = [];
        foreach ($quote_rows as $r) {
            $data[] = [
                '<a href="quote_view.php?id=' . (int)$r['quote_id'] . '">T-' . str_pad((string)$r['quote_no'], 6, '0', STR_PAD_LEFT) . '</a>',
                htmlspecialchars($r['cust_name']),
                date(CONF_DATE_FORMAT, strtotime($r['quote_date'])),
                htmlspecialchars(ucfirst($r['status'])),
            ];
        }
        htm_Table(['@Quote', '@Customer', '@Date', '@Status'], $data, 'search_quote_tbl', 100);
        htm_Card_end();
    }
}

// --- ANLÆGSAKTIVER ---
$asset_res = @DB::query($conn, "SELECT asset_id, asset_name, description, acquisition_date, status FROM fixed_assets
    WHERE asset_name LIKE '$like' OR description LIKE '$like'
    ORDER BY acquisition_date DESC LIMIT $RESULT_LIMIT");
if ($asset_res !== false) {
    $asset_rows = [];
    while ($r = DB::fetch_assoc($asset_res)) { $asset_rows[] = $r; }
    if (!empty($asset_rows)) {
        $any_results = true;
        htm_Card_(sprintf(lang('@Fixed Assets (%d)'), count($asset_rows)), 1000);
        $data = [];
        foreach ($asset_rows as $r) {
            $data[] = [
                '<a href="fixed_asset_edit.php?id=' . (int)$r['asset_id'] . '">' . htmlspecialchars($r['asset_name']) . '</a>',
                htmlspecialchars($r['description'] ?? ''),
                date(CONF_DATE_FORMAT, strtotime($r['acquisition_date'])),
                htmlspecialchars(ucfirst($r['status'])),
            ];
        }
        htm_Table(['@Name', '@Description', '@Acquired', '@Status'], $data, 'search_asset_tbl', 100);
        htm_Card_end();
    }
}

// --- TIMEREGISTRERING ---
$time_res = @DB::query($conn, "SELECT t.entry_id, t.entry_date, t.description, t.hours, p.proj_no
    FROM time_entries t LEFT JOIN projects p ON t.proj_id = p.proj_id
    WHERE t.description LIKE '$like' OR p.proj_no LIKE '$like'
    ORDER BY t.entry_date DESC LIMIT $RESULT_LIMIT");
if ($time_res !== false) {
    $time_rows = [];
    while ($r = DB::fetch_assoc($time_res)) { $time_rows[] = $r; }
    if (!empty($time_rows)) {
        $any_results = true;
        htm_Card_(sprintf(lang('@Time Entries (%d)'), count($time_rows)), 1000);
        $data = [];
        foreach ($time_rows as $r) {
            $data[] = [
                '<a href="time_entry_edit.php?id=' . (int)$r['entry_id'] . '">' . htmlspecialchars($r['description'] ?? '') . '</a>',
                htmlspecialchars($r['proj_no'] ?? ''),
                date(CONF_DATE_FORMAT, strtotime($r['entry_date'])),
                number_format((float)$r['hours'], 2, ',', '.'),
            ];
        }
        htm_Table(['@Description', '@Project', '@Date', '@Hours'], $data, 'search_time_tbl', 100);
        htm_Card_end();
    }
}

if (!$any_results) {
    htm_Alert(sprintf(lang('@No results found for "%s".'), htmlspecialchars($q)), 'info', 700, true);
}

echo "</div>";
htm_Footer();
ob_end_flush();
?>
