<?php # /shop_connections.php v:1.3.1 d:2026-09-15 i:evs
# Webbutikker: tilknyt en webbutik og træf dens valg (platform, model, automatisk bogføring, priser inkl./ekskl.
# moms, betalt ved bestilling, mellemregnings-/salgs-/fragtkonto, lager). Valgene kan ændres når som helst og
# gælder de ordrer der kommer ind derefter. Hver butik får sin egen webhook-nøgle (vises én gang).
# Model B (samlet salg pr. dag): "Bogfør dage" bogfører hele dage før i dag. Se inc/shop.inc.php.
# NYT (bruger-anmodet "forbedre webbutik integration"): WooCommerce og Shopify sender deres EGNE webhooks (signeret med
# butikkens hemmelighed) til webhook_shop.php; PrestaShop hentes af TinyCash (Webservice-API, knappen "Hent nye
# ordrer" / en cron-adresse). Siden viser opsætningen pr. platform, mellemregningens saldo og seneste hentning.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/webhook.lib.php';
require_once 'inc/shop.inc.php';

$msg = '';  $msg_type = 'success';  $new_key = null;  $new_key_shop = null;  $setup_shop = 0;
$ready = shop_ready($conn);
// Ældre tabel uden platform-kolonnerne: opgraderes stille her (siden kræver niveau 3) - samme shop_ensure_schema()
if ($ready && !shop_platform_ready($conn)) {
    foreach (shop_ensure_schema($conn, DB::is_sqlite()) as $sh_l) if (strpos($sh_l, '[FEJL]') === 0) error_log('[shop] ' . $sh_l);
}
$plat_ready = $ready && shop_platform_ready($conn);
// Adressen butikken / cron-jobbet kalder (webhook_shop.php) - med regnskabet, hvis der er flere
function shop_page_url(array $shop, string $extra = ''): string {
    $base = rtrim((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'dit-domæne') . str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $q = [];
    if (!empty($_SESSION['account_id'])) $q['acc'] = (string)$_SESSION['account_id'];
    $q['shop'] = (int)$shop['shop_id'];
    return $base . '/webhook_shop.php?' . http_build_query($q) . $extra;
}
$yes_no = ['1' => lang('@Yes'), '0' => lang('@No')];

// Ny nøgle til butikken (den gamle tilbagekaldes) - klarteksten vises kun nu
function shop_page_new_key($conn, array $shop): string {
    if (!empty($shop['key_id'])) webhook_key_revoke((string)$shop['key_id']);
    $plain = webhook_key_generate('Webbutik: ' . $shop['name'], $_SESSION['account_id'] ?? null);
    $hash = hash('sha256', $plain);
    foreach (webhook_key_list() as $k) if (($k['key_hash'] ?? '') === $hash) { $key_id = (string)$k['id'];  break; }
    DB::update($conn, 'shop_connections', ['key_id' => $key_id ?? '', 'updated_at' => date('Y-m-d H:i:s')], 'shop_id', (int)$shop['shop_id']);
    log_action($conn, 'SHOP_NEW_KEY', 'shop_connections', (int)$shop['shop_id'], null, ['shop' => $shop['name']]);
    return $plain;
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid = (int)($_POST['shop_id'] ?? 0);
    if (isset($_POST['save_shop'])) {
        $name     = trim((string)($_POST['name'] ?? ''));
        $platform = (string)($_POST['platform'] ?? 'native');
        $mode     = ($_POST['mode'] ?? 'invoice') === 'daily' ? 'daily' : 'invoice';
        $clearing = (int)($_POST['clearing_acc'] ?? 0);
        $paid_def = !empty($_POST['paid_default']) ? 1 : 0;
        $d = ['name' => mb_substr($name, 0, 100), 'platform' => $platform, 'mode' => $mode,
              'auto_post' => !empty($_POST['auto_post']) ? 1 : 0, 'paid_default' => $paid_def,
              'prices_incl_vat' => !empty($_POST['prices_incl_vat']) ? 1 : 0, 'track_stock' => !empty($_POST['track_stock']) ? 1 : 0,
              'clearing_acc' => $clearing, 'sales_acc' => (int)($_POST['sales_acc'] ?? 0), 'shipping_acc' => (int)($_POST['shipping_acc'] ?? 0),
              'shipping_vat' => (float)str_replace(',', '.', (string)($_POST['shipping_vat'] ?? '25')), 'active' => !empty($_POST['active']) ? 1 : 0,
              'updated_at' => date('Y-m-d H:i:s')];
        if ($plat_ready) {
            $old_p = $sid > 0 ? shop_get($conn, $sid) : null;
            $d['api_url'] = rtrim(trim((string)($_POST['api_url'] ?? '')), '/');
            $api_key_in = trim((string)($_POST['api_key'] ?? ''));
            $d['api_key'] = $api_key_in !== '' ? $api_key_in : (string)($old_p['api_key'] ?? '');            // (tomt felt = behold den gemte nøgle)
            $secret_in = trim((string)($_POST['secret'] ?? ''));
            $d['secret'] = $secret_in !== '' ? $secret_in : (string)($old_p['secret'] ?? '');
            if ($d['secret'] === '' && $platform !== 'shopify') $d['secret'] = shop_new_secret();               // (Shopify laver selv sin hemmelighed)
            if (isset($_POST['last_remote_id'])) $d['last_remote_id'] = preg_replace('/[^0-9]/', '', (string)$_POST['last_remote_id']);
            if ($platform === 'prestashop' && $d['api_url'] !== '' && !preg_match('~^https?://~i', $d['api_url'])) { $msg = lang('@The shop address must start with http:// or https://.');  $msg_type = 'error'; }
        }
        if ($msg_type === 'error') { /* (fejlen er sat ovenfor) */ }
        elseif ($name === '') { $msg = lang('@Please enter a name for the web shop.');  $msg_type = 'error'; }
        elseif (!isset(SHOP_PLATFORMS[$platform])) { $msg = lang('@Unknown platform.');  $msg_type = 'error'; }
        elseif (($mode === 'daily' || $paid_def) && !shop_account_exists($conn, $clearing)) { $msg = lang('@Choose the clearing account - it is needed for paid orders and for the daily sales.');  $msg_type = 'error'; }
        else {
            $old = $sid > 0 ? shop_get($conn, $sid) : null;
            if ($old) {
                if ($old['mode'] !== $mode) $d['mode_since'] = date('Y-m-d');
                DB::update($conn, 'shop_connections', $d, 'shop_id', $sid);
                log_action($conn, 'SHOP_UPDATE', 'shop_connections', $sid, array_intersect_key($old, $d), $d);
                $msg = lang('@The web shop is saved - the choices apply to the orders that come in from now on.');
            } else {
                $d += ['mode_since' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'), 'key_id' => ''];
                DB::insert($conn, 'shop_connections', $d);
                $sid = (int)DB::insert_id($conn);
                log_action($conn, 'SHOP_CREATE', 'shop_connections', $sid, null, $d);
                if ($platform === 'native') {                    // (kun TinyCash-formatet bruger en API-nøgle i en header)
                    $new_key = shop_page_new_key($conn, shop_get($conn, $sid));
                    $new_key_shop = shop_get($conn, $sid);
                }
                $msg = lang('@The web shop is connected.');
                $setup_shop = $sid;
            }
            if (!shop_adapter_available($platform)) {
                $msg .= ' ' . sprintf(lang('@Note: TinyCash cannot read the format of %s yet - until then the shop must send the TinyCash format.'), SHOP_PLATFORMS[$platform]);
                $msg_type = 'warning';
            }
            if ($platform === 'shopify' && $plat_ready && ($d['secret'] ?? '') === '') {
                $msg .= ' ' . lang('@Paste the signing secret from Shopify (Settings -> Notifications -> Webhooks) - until then the orders of the shop are refused.');
                $msg_type = 'warning';
            }
            if ($msg_type !== 'error') { unset($_GET['edit']);  $setup_shop = $sid; }
        }
    } elseif (isset($_POST['new_key']) && ($shop = shop_get($conn, $sid))) {
        $new_key = shop_page_new_key($conn, $shop);
        $new_key_shop = shop_get($conn, $sid);
        $msg = lang('@A new key is made - the old one no longer works.');
    } elseif (isset($_POST['book_days']) && ($shop = shop_get($conn, $sid))) {
        $r = shop_book_days($conn, $shop, date('Y-m-d'));
        $parts = [];
        foreach ($r['days'] as $day => $v) $parts[] = $day . ' (' . lang('@voucher') . ' ' . $v . ')';
        $msg = $parts ? lang('@Booked:') . ' ' . implode(', ', $parts) : lang('@No whole days to book.');
        if ($r['skipped']) { $msg .= ' - ' . lang('@Not booked:') . ' ' . implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($r['skipped']), $r['skipped']));  $msg_type = 'warning'; }
    } elseif (isset($_POST['fetch_orders']) && $plat_ready && ($shop = shop_get($conn, $sid))) {
        $r = shop_fetch_orders($conn, $shop);
        if (!empty($shop['auto_post']) && ($shop['mode'] ?? '') === 'daily') shop_book_days($conn, shop_get($conn, $sid), date('Y-m-d'));
        $msg = sprintf(lang('@Fetched %1$d orders: %2$d new, %3$d already received, %4$d waiting or skipped.'), $r['fetched'], $r['created'], $r['duplicates'], $r['ignored']);
        if ($r['errors']) { $msg .= ' ' . lang('@Errors:') . ' ' . htmlspecialchars(implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($r['errors']), $r['errors'])));  $msg_type = 'warning'; }
    } elseif (isset($_POST['new_secret']) && $plat_ready && ($shop = shop_get($conn, $sid)) && $shop['platform'] !== 'shopify') {
        DB::update($conn, 'shop_connections', ['secret' => shop_new_secret(), 'updated_at' => date('Y-m-d H:i:s')], 'shop_id', $sid);
        log_action($conn, 'SHOP_NEW_SECRET', 'shop_connections', $sid, null, ['shop' => $shop['name']]);
        $msg = lang('@A new secret is made - put it into the shop (or the cron address), the old one no longer works.');
        $setup_shop = $sid;
    } elseif (isset($_POST['create_clearing'])) {
        try { $n = shop_create_clearing_account($conn, lang('@Clearing account web shop'));  $msg = sprintf(lang('@The account %d "%s" is created.'), $n, lang('@Clearing account web shop')); }
        catch (Throwable $e) { $msg = $e->getMessage();  $msg_type = 'error'; }
    }
}

htm_Header('@Web Shops', 1100);
showMenu();
echo '<div style="max-width:1100px; margin:20px auto 0; padding:0 5px;"><h1 style="margin:0; text-align:center;"><i class="fa-solid fa-cart-shopping" style="color:var(--color-primary);"></i> ' . lang('@Web Shops') . '</h1></div>';

if (!$ready) {
    htm_Banner('<i class="fa fa-exclamation-triangle"></i> ' . lang('@Web shops need a database update:') . ' <a href="run_migrate.php">' . lang('@Database migration') . '</a> (' . lang('@Web shops') . ').', 'warning');
    htm_Footer();
    exit;
}
if ($msg) htm_Alert($msg, $msg_type, 1000);

$endpoint = rtrim((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'dit-domæne') . str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/webhook_invoice.php';

// --- Ny nøgle: vises kun nu ---
if ($new_key && $new_key_shop) {
    htm_Card_('🔑 ' . lang('@The key of the web shop') . ': ' . htmlspecialchars($new_key_shop['name']), 900);
    echo '<p style="color:var(--color-danger); font-weight:bold;">' . lang('@Copy this key now - it will not be shown again. Reloading this page will not bring it back; you would need to revoke it and generate a new one instead.') . '</p>';
    echo '<input type="text" readonly value="' . htmlspecialchars($new_key, ENT_QUOTES) . '" onclick="this.select();" style="width:100%; box-sizing:border-box; font-family:monospace; padding:10px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-panel); color:var(--text-main);">';
    echo '<p style="font-size:0.9em;">' . lang('@The shop sends its orders to') . ': <code>' . htmlspecialchars($endpoint) . '</code> ' . lang('@with the header') . ' <code>X-Webhook-Key: ' . htmlspecialchars($new_key) . '</code></p>';
    htm_Card_end();
}

$sh_edit = isset($_GET['edit']) ? shop_get($conn, (int)$_GET['edit']) : null;       // (butikkens nuværende konti vises altid - også en dæmpet/slukket)
$sh_keep = $sh_edit ? [(int)$sh_edit['clearing_acc'], (int)$sh_edit['sales_acc'], (int)$sh_edit['shipping_acc']] : [];
$accounts_balance = ['' => '-- ' . lang('@Choose') . ' --'] + shop_account_options($conn, ['asset', 'bank'], $sh_keep);
$accounts_income  = ['0' => lang('@As in the settings (sales account)')] + shop_account_options($conn, ['income'], $sh_keep);
$platform_opts = [];
foreach (SHOP_PLATFORMS as $k => $v) $platform_opts[$k] = $v . (shop_adapter_available($k) ? '' : ' (' . lang('@adapter coming') . ')');

// --- Tilknyt / ret ---
if (isset($_GET['edit'])) {
    $e = ((int)$_GET['edit'] > 0) ? shop_get($conn, (int)$_GET['edit']) : null;
    $e = $e ?: ['shop_id' => 0, 'name' => $_POST['name'] ?? '', 'platform' => 'native', 'mode' => 'invoice', 'auto_post' => 1, 'paid_default' => 1, 'prices_incl_vat' => 1,
                'track_stock' => 1, 'clearing_acc' => '', 'sales_acc' => 0, 'shipping_acc' => 0, 'shipping_vat' => 25, 'active' => 1];
    htm_Card_($e['shop_id'] ? '✏️ ' . lang('@Edit web shop') . ': ' . htmlspecialchars($e['name']) : '🆕 ' . lang('@Connect a web shop'), 900, '', 'shop_form');
    echo '<input type="hidden" name="shop_id" value="' . (int)$e['shop_id'] . '">';
    htm_Field('fa-store', '@Name', 'name', $e['name'], 'text', null, 'required', '100%', '@E.g. the name or address of the shop');
    htm_Field('fa-plug', '@Platform', 'platform', $e['platform'], 'sele', $platform_opts, '', '100%', '@The shop system - TinyCash reads its order format. Until the adapter of a platform exists, the shop must send the TinyCash format.');
    if ($plat_ready) {
        // Platformens egne felter - vises efter det valgte system (JS nederst)
        echo '<div class="sh-plat" data-plat="woocommerce shopify">';
        htm_Field('fa-signature', '@Signing secret', 'secret', '', 'text', null, 'autocomplete="off"', '100%',
            '@WooCommerce: leave empty - TinyCash makes one, which you paste into the webhook in WooCommerce. Shopify: paste the secret Shopify shows under Settings -> Notifications -> Webhooks. Empty when editing = keep the saved one.',
            !empty($e['secret']) ? lang('@saved - leave empty to keep it') : '');
        echo '</div><div class="sh-plat" data-plat="prestashop">';
        htm_Field('fa-link', '@Shop address', 'api_url', (string)($e['api_url'] ?? ''), 'text', null, '', '100%', '@E.g. https://www.myshop.dk - TinyCash calls its Webservice API (/api/orders).', 'https://');
        htm_Field('fa-key', '@Webservice key', 'api_key', '', 'password', null, 'autocomplete="new-password"', '48%',
            '@PrestaShop: Advanced Parameters -> Webservice -> enable, add a key with GET on orders, customers and addresses. Empty when editing = keep the saved key.',
            !empty($e['api_key']) ? lang('@saved - leave empty to keep it') : '');
        htm_Field('fa-forward', '@Fetch orders after order no.', 'last_remote_id', (string)($e['last_remote_id'] ?? ''), 'text', null, 'inputmode="numeric"', '48%',
            '@The order number TinyCash has fetched up to. Empty: all orders of the shop are fetched the first time - put the newest order number here to start from now.');
        echo '</div>';
    }
    htm_Field('fa-diagram-project', '@Booking model', 'mode', $e['mode'], 'sele',
        ['invoice' => lang('@An invoice per order (A)'), 'daily' => lang('@The sales of a day booked together (B)')], '', '100%',
        '@A: each order becomes an invoice in TinyCash (customer, number, PDF). B: the shop issues the receipts - TinyCash books the sales of each day as one entry, the receipts of the shop are the vouchers. Changing it applies to the orders from now on.');
    htm_Field('fa-bolt', '@Book automatically', 'auto_post', (string)(int)$e['auto_post'], 'sele', $yes_no, '', '48%',
        '@A: the invoice is posted (and the payment registered) at once - no: it waits as a draft. B: a whole day is booked when the first order of a later day arrives - no: with the button Book days.');
    htm_Field('fa-credit-card', '@Paid at checkout', 'paid_default', (string)(int)$e['paid_default'], 'sele', $yes_no, '', '48%',
        '@Card / MobilePay in the checkout: the payment is registered on the clearing account. An order can say otherwise ("paid": false, e.g. a bank transfer).');
    htm_Field('fa-percent', '@Prices include VAT', 'prices_incl_vat', (string)(int)$e['prices_incl_vat'], 'sele', $yes_no, '', '48%', '@What the shop sends - an order can say otherwise ("prices_include_vat").');
    htm_Field('fa-boxes-stacked', '@TinyCash keeps the stock', 'track_stock', (string)(int)$e['track_stock'], 'sele', $yes_no, '', '48%', '@Yes: the stock of the products in TinyCash goes down with each order. No: the shop keeps the stock.');
    htm_Field('fa-building-columns', '@Clearing account', 'clearing_acc', (string)$e['clearing_acc'], 'sele', $accounts_balance, '', '100%',
        '@The account the payment provider owes you on - the paid orders go here, the payouts to the bank are reconciled against it (and the fee as a cost).');
    htm_Field('fa-sack-dollar', '@Sales account (model B)', 'sales_acc', (string)(int)$e['sales_acc'], 'sele', $accounts_income, '', '48%', '@The goods of the daily sales.');
    htm_Field('fa-truck', '@Shipping account (model B)', 'shipping_acc', (string)(int)$e['shipping_acc'], 'sele', $accounts_income, '', '48%', '@Shipping of the daily sales - empty: the sales account.');
    htm_Field('fa-percent', '@VAT on shipping (%)', 'shipping_vat', number_format((float)$e['shipping_vat'], 2, ',', ''), 'text', null, '', '48%', '@When the shop does not send it.');
    htm_Field('fa-power-off', '@Active', 'active', (string)(int)$e['active'], 'sele', $yes_no, '', '48%', '@No: the orders of the shop are refused.');
    echo '<div style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">';
    htm_Button(icon: 'fa-save', labl: '@Save', type: 'success', attr: 'name="save_shop" value="1"');
    htm_Button(icon: 'fa-xmark', labl: '@Cancel', type: 'secondary', link: 'shop_connections.php', attr: 'data-hint="' . lang('@Discard the changes and return to the list of web shops') . '"');
    echo '</div>';
    htm_Card_end();
    if (count($accounts_balance) <= 1 || !array_filter(array_keys($accounts_balance), fn($k) => $k >= 5850 && $k <= 5899)) {
        htm_Card_('🏦 ' . lang('@Clearing account'), 900, '', 'clearing_form');
        echo '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@No clearing account for a payment provider yet? TinyCash can make one ("Clearing account web shop", a balance account 5850-5899).') . '</p>';
        htm_Button(icon: 'fa-plus', labl: '@Create the clearing account', type: 'secondary', attr: 'name="create_clearing" value="1" formnovalidate');
        htm_Card_end();
    }
}

// --- Opsætningen i butikken (pr. platform) ---
$setup = $setup_shop ? shop_get($conn, $setup_shop) : ((isset($_GET['setup'])) ? shop_get($conn, (int)$_GET['setup']) : null);
if ($plat_ready && $setup && $setup['platform'] !== 'native') {
    $code = fn($t) => '<input type="text" readonly value="' . htmlspecialchars($t, ENT_QUOTES) . '" onclick="this.select();" style="width:100%; box-sizing:border-box; font-family:monospace; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-panel); color:var(--text-main);">';
    htm_Card_('🔌 ' . lang('@Set up the shop') . ': ' . htmlspecialchars($setup['name']) . ' (' . htmlspecialchars(SHOP_PLATFORMS[$setup['platform']] ?? $setup['platform']) . ')', 1000);
    if ($setup['platform'] === 'woocommerce') {
        echo '<ol style="line-height:1.7; padding-left:20px;"><li>' . lang('@In WordPress: WooCommerce -> Settings -> Advanced -> Webhooks -> Add webhook.') . '</li>'
           . '<li>' . lang('@Topic: "Order created". Status: Active. API version: WP REST API Integration v3.') . '</li>'
           . '<li>' . lang('@Delivery URL:') . $code(shop_page_url($setup)) . '</li>'
           . '<li>' . lang('@Secret:') . $code((string)$setup['secret']) . '</li>'
           . '<li>' . lang('@Save - and make one more webhook the same way with the topic "Order updated" (an order paid later by bank transfer comes in then).') . '</li></ol>'
           . '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@Only paid orders (processing / completed) are booked. A refund in WooCommerce is made as a credit note in TinyCash.') . '</p>';
    } elseif ($setup['platform'] === 'shopify') {
        echo '<ol style="line-height:1.7; padding-left:20px;"><li>' . lang('@In Shopify: Settings -> Notifications -> Webhooks -> Create webhook.') . '</li>'
           . '<li>' . lang('@Event: "Order payment". Format: JSON. URL:') . $code(shop_page_url($setup)) . '</li>'
           . '<li>' . lang('@Create one more with the event "Refund create" and the same URL.') . '</li>'
           . '<li>' . lang('@Copy the secret Shopify shows below the webhooks ("Your webhooks will be signed with ...") into the shop in TinyCash (Edit -> Signing secret).') . '</li></ol>'
           . (empty($setup['secret']) ? '<p style="color:var(--color-danger); font-weight:bold;">' . lang('@The secret is missing - the orders are refused until it is pasted in.') . '</p>' : '');
    } elseif ($setup['platform'] === 'prestashop') {
        echo '<ol style="line-height:1.7; padding-left:20px;"><li>' . lang('@In PrestaShop: Advanced Parameters -> Webservice -> enable the webservice.') . '</li>'
           . '<li>' . lang('@Add a key with GET rights on orders, customers and addresses - put the key and the address of the shop into TinyCash (Edit).') . '</li>'
           . '<li>' . lang('@Fetch the orders with the button "Fetch new orders" - or let a cron job (the host\'s cron, or an uptime monitor) call this address every 15 minutes:') . $code(shop_page_url($setup, '&fetch=' . rawurlencode((string)$setup['secret']))) . '</li></ol>'
           . '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@Only paid orders are booked; an order waiting for a bank transfer is fetched again until it is paid (at most 14 days). A refund is made as a credit note in TinyCash.') . '</p>';
    }
    if ($setup['platform'] !== 'shopify') {
        echo '<form method="post" style="margin-top:6px;">' . csrf_field(false) . '<input type="hidden" name="shop_id" value="' . (int)$setup['shop_id'] . '">';
        htm_Button(icon: 'fa-rotate', labl: '@New secret', type: 'warning', styl: 'padding:5px 12px; font-size:12px;',
            attr: 'name="new_secret" value="1" data-hint="' . lang('@Make a new secret - the shop (or the cron job) must get the new one') . '" onclick="return confirm(' . htmlspecialchars(json_encode(lang('@The old secret stops working at once. Continue?')), ENT_QUOTES) . ')"');
        echo '</form>';
    }
    echo '<p style="font-size:0.9em; color:var(--text-muted); margin-top:10px;"><i class="fa fa-circle-info"></i> ' . lang('@The payouts from the payment provider are reconciled in Bank Reconciliation: choose "Payout from web shop" on the bank line and put the fee of the provider in the fee field.') . '</p>';
    htm_Card_end();
}

// --- Butikkerne ---
$shops = shop_list($conn);
$keys = [];
foreach (webhook_key_list() as $k) $keys[(string)$k['id']] = $k;
htm_Card_('🛒 ' . lang('@Connected web shops'), 1000, '', false, true, htm_Button('fa-plus', '@Connect a web shop', 'primary', 'shop_connections.php?edit=0', 'padding:6px 12px; font-size:13px;', 'data-hint="' . lang('@Set up a new web shop connection (accounts, payment provider and key)') . '"', '', false));
if (!$shops) {
    echo '<p style="color:var(--text-muted);">' . lang('@No web shops connected yet.') . '</p>';
} else {
    $data = [];
    foreach ($shops as $sid => $s) {
        $cnt = DB::fetch_assoc(DB::query($conn, "SELECT COUNT(*) AS n, SUM(CASE WHEN status = 'pending_day' THEN 1 ELSE 0 END) AS pend,
            SUM(CASE WHEN status = 'pending_day' AND order_date < '" . date('Y-m-d') . "' THEN 1 ELSE 0 END) AS pend_old, MAX(created_at) AS last FROM shop_orders WHERE shop_id = $sid"));
        $k = $keys[(string)$s['key_id']] ?? null;
        $key_txt = $k ? ('…' . htmlspecialchars((string)($k['key_last4'] ?? '')) . (!empty($k['revoked']) ? ' ' . htm_Badge('@Revoked', 'secondary', false) : '')) : '-';
        if ($plat_ready && in_array($s['platform'], SHOP_SIGNED_PLATFORMS, true)) {
            $key_txt = !empty($s['secret']) ? lang('@signed webhook') : '<span style="color:var(--color-danger);">' . lang('@secret missing') . '</span>';
        } elseif ($plat_ready && in_array($s['platform'], SHOP_PULL_PLATFORMS, true)) {
            $key_txt = lang('@fetched by TinyCash') . (!empty($s['last_fetch_at']) ? '<br><span style="font-size:0.8em; color:var(--text-muted);" data-hint="' . htmlspecialchars((string)($s['last_fetch_msg'] ?? '')) . '">' . htmlspecialchars(substr((string)$s['last_fetch_at'], 0, 16)) . (strpos((string)($s['last_fetch_msg'] ?? ''), 'fejl') !== false ? ' ⚠' : '') . '</span>' : '');
        }
        $clear_bal = ((int)$s['clearing_acc'] > 0 && function_exists('shop_clearing_balance')) ? shop_clearing_balance($conn, (int)$s['clearing_acc']) : null;
        $actions = htm_Button('fa-pen', '@Edit', 'secondary', 'shop_connections.php?edit=' . $sid, 'padding:4px 10px; font-size:12px;', 'data-hint="' . lang('@Edit the accounts and settings of this web shop') . '"', '', false) . ' '
                 . htm_Button('fa-list', '@Orders', 'info', 'shop_connections.php?orders=' . $sid, 'padding:4px 10px; font-size:12px;', 'data-hint="' . lang('@See the orders received from this web shop and their status') . '"', '', false) . ' '
                 . '<form method="post" style="display:inline;">' . csrf_field(false) . '<input type="hidden" name="shop_id" value="' . $sid . '">'
                 . ((int)$cnt['pend_old'] > 0 ? htm_Button('fa-calendar-check', '@Book days', 'success', '', 'padding:4px 10px; font-size:12px;', 'name="book_days" value="1"', '', false) . ' ' : '')
                 . ($plat_ready && in_array($s['platform'], SHOP_PULL_PLATFORMS, true)
                    ? htm_Button('fa-download', '@Fetch new orders', 'success', '', 'padding:4px 10px; font-size:12px;', 'name="fetch_orders" value="1" data-hint="' . lang('@Fetch the new paid orders from the shop now') . '"', '', false) . ' ' : '')
                 . ($s['platform'] === 'native' || !$plat_ready
                    ? htm_Button('fa-key', '@New key', 'warning', '', 'padding:4px 10px; font-size:12px;', 'name="new_key" value="1" onclick="return confirm(' . htmlspecialchars(json_encode(lang('@The old key stops working at once - the shop must get the new one. Continue?')), ENT_QUOTES) . ')"', '', false)
                    : htm_Button('fa-plug-circle-check', '@Setup', 'secondary', 'shop_connections.php?setup=' . $sid, 'padding:4px 10px; font-size:12px;', 'data-hint="' . lang('@Show how the shop is set up (address and secret)') . '"', '', false))
                 . '</form>';
        $data[] = [
            htmlspecialchars($s['name']) . (empty($s['active']) ? ' ' . htm_Badge('@Inactive', 'secondary', false) : ''),
            htmlspecialchars(SHOP_PLATFORMS[$s['platform']] ?? $s['platform']),
            ($s['mode'] === 'daily' ? lang('@Daily sales (B)') : lang('@Invoice per order (A)')) . ' ' . (!empty($s['auto_post']) ? htm_Badge('@automatic', 'success', false) : ''),
            (int)$cnt['n'] . ((int)$cnt['pend'] > 0 ? ' (' . (int)$cnt['pend'] . ' ' . lang('@not booked') . ')' : ''),
            $cnt['last'] ? htmlspecialchars(substr((string)$cnt['last'], 0, 16)) : '-',
            $clear_bal === null ? '-' : '<span style="float:right;" data-hint="' . htmlspecialchars(lang('@What the payment provider owes you - reconciled when the payout reaches the bank')) . '">' . number_format($clear_bal, 2, ',', '.') . '</span>',
            $key_txt,
            $actions,
        ];
    }
    htm_Table(['@Web shop', '@Platform', '@Booking model', '@Orders', '@Latest order', '@Clearing balance', '@Connection', '@Actions'], $data, 'shopTbl', 100, '', true, [], '400px');
}
htm_Card_end();

// --- En butiks ordrer ---
if (isset($_GET['orders']) && ($s = shop_get($conn, (int)$_GET['orders']))) {
    $status_txt = ['draft' => lang('@Draft'), 'posted' => lang('@Posted'), 'paid' => lang('@Paid'), 'credited' => lang('@Credited'),
                   'pending_day' => lang('@Waits for the day'), 'booked' => lang('@Booked'), 'draft_deleted' => lang('@Draft deleted')];
    htm_Card_('📦 ' . lang('@Orders') . ': ' . htmlspecialchars($s['name']), 1000);
    $res = DB::query($conn, "SELECT o.*, (SELECT j.voucher_no FROM journal j WHERE j.jou_id = o.day_jou_id) AS day_voucher FROM shop_orders o WHERE o.shop_id = " . (int)$s['shop_id'] . " ORDER BY o.order_id DESC LIMIT 300");
    $data = [];
    while ($o = DB::fetch_assoc($res)) {
        $doc = '';
        if ($o['credit_inv_id']) $doc = '<a href="invoice_view.php?id=' . (int)$o['credit_inv_id'] . '">' . lang('@Credit Note') . '</a>';
        elseif ($o['inv_id'])    $doc = '<a href="invoice_view.php?id=' . (int)$o['inv_id'] . '">' . lang('@Invoice') . '</a>';
        elseif ($o['day_jou_id']) $doc = lang('@Voucher') . ' ' . (int)$o['day_voucher'];
        $data[] = [
            htmlspecialchars($o['order_date']),
            htmlspecialchars($o['external_id']) . ($o['kind'] === 'refund' ? ' ' . htm_Badge('@Refund', 'warning', false) . ' ← ' . htmlspecialchars((string)$o['order_ref']) : ''),
            htmlspecialchars((string)$o['customer_name']),
            '<span style="float:right;">' . number_format((float)$o['gross'], 2, ',', '.') . '</span>',
            ($status_txt[$o['status']] ?? htmlspecialchars((string)$o['status'])) . (!empty($o['paid']) ? ' · ' . lang('@paid at checkout') : ''),
            $doc,
            $o['message'] ? '<span style="color:var(--color-warning); font-size:0.85em;">' . nl2br(htmlspecialchars((string)$o['message'])) . '</span>' : '',
        ];
    }
    if ($data) htm_Table(['@Date', '@Order', '@Customer', '@Amount incl. VAT', '@Status', '@Document', '@Note'], $data, 'shopOrdTbl', 300, '', true, [], '500px');
    else echo '<p style="color:var(--text-muted);">' . lang('@No orders received yet.') . '</p>';
    htm_Card_end();
}

// --- Formatet (eget format) ---
htm_Card_('📖 ' . lang('@How the shop sends an order'), 1000, '', false, true, '', 'closed');
echo '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@POST to the address below with the key of the shop in the header X-Webhook-Key. The TinyCash format:') . ' <code>' . htmlspecialchars($endpoint) . '</code></p>';
echo '<pre style="background:var(--bg-panel); padding:12px; border-radius:6px; overflow-x:auto; font-size:0.85em;">' . htmlspecialchars('{
  "external_id": "SHOP-12345",
  "date": "' . date('Y-m-d') . '",
  "paid": true,
  "prices_include_vat": true,
  "customer": {"email": "kunde@example.com", "name": "Kunde Navn", "address": "Vej 1, 8000 Aarhus"},
  "lines": [
    {"sku": "GLASSKO-1", "quantity": 2, "price": 625.00},
    {"type": "shipping", "text": "Fragt GLS", "price": 49.00},
    {"type": "discount", "text": "Rabatkode JUL10", "price": 50.00}
  ],
  "total": 1249.00
}

Returnering:
{"type": "refund", "external_id": "RET-77", "refund_of": "SHOP-12345",
 "lines": [{"sku": "GLASSKO-1", "quantity": 1, "price": 625.00}]}') . '</pre>';
htm_Card_end();

?>
<script>
// Platformens felter: kun dem der hører til det valgte system vises
(function () {
    var sel = document.querySelector('select[name="platform"]');
    if (!sel) return;
    function upd() { document.querySelectorAll('.sh-plat').forEach(function (d) { d.style.display = (' ' + d.dataset.plat + ' ').indexOf(' ' + sel.value + ' ') >= 0 ? '' : 'none'; }); }
    sel.addEventListener('change', upd);  upd();
})();
</script>
<?php
htm_Footer();
