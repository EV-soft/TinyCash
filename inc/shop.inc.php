<?php # /inc/shop.inc.php v:1.3.1 d:2026-09-15 i:evs
# Webbutikker (bruger-anmodet: "tilknyt en webbutik - valgene træffes når butikken tilkobles").
#
# En BUTIKSFORBINDELSE (shop_connections) hører til én webhook-nøgle (inc/webhook.lib.php) og bærer
# butikkens valg: platform (adapter), model, automatisk bogføring, priser inkl./ekskl. moms, betalt ved
# bestilling, mellemregningskonto, salgs-/fragtkonto og lager. Valgene kan ændres når som helst - de
# gælder de ordrer der kommer ind derefter; allerede bogførte ordrer røres aldrig.
#
#   Platform-adapter (inc/shop_adapters/<platform>.php): butikkens egen JSON -> det FÆLLES ordreformat
#   shop_process(): det fælles ordreformat -> det butikkens valg siger:
#     model 'invoice' (A): en faktura pr. ordre - kladde, eller bogført (invoice_post()) og betalt
#                          (invoice_register_payment() på mellemregningskontoen) - returnering = kreditnota
#     model 'daily'   (B): ordren noteres (shop_orders) - en dags ordrer bogføres samlet (shop_book_days()):
#                          DEBET mellemregning / KREDIT salg, fragt og moms. Butikkens kvitteringer er bilagene.
#
# Det fælles ordreformat:
#   ['kind' => 'order'|'refund', 'external_id' => ordrenummer, 'order_ref' => (refund) ordrenummeret der
#    returneres fra, 'date' => 'Y-m-d', 'currency' => null|'DKK', 'paid' => null|bool, 'prices_incl_vat' =>
#    null|bool (null: butikkens valg), 'customer' => [email, name, phone, address, cvr], 'delivery_address',
#    'lines' => [['type' => product|shipping|fee|discount|text, 'sku', 'text', 'qty', 'price', 'vat_rate']],
#    'total' => null|beløb inkl. moms (kontrol), 'note']
#
# Ikke migreret (shop_ready() = false): webhook_invoice.php virker som før (kun kladder, eget format).
#
# TRE MÅDER ordrerne kommer ind (bruger-anmodet "forbedre webbutik integration"):
#   - TinyCash JSON (native): butikken POSTer til webhook_invoice.php med butikkens API-nøgle (X-Webhook-Key)
#   - WooCommerce / Shopify: platformens EGNE ordre-webhooks POSTes til webhook_shop.php?acc=..&shop=.. og
#     godkendes med platformens HMAC-signatur (butikkens hemmelighed, shop_connections.secret). Et gentaget kald og
#     en ordre der ikke er betalt endnu, svares med 200 (ellers slår platformen webhooken fra efter nogle fejl).
#   - PrestaShop: TinyCash HENTER selv nye ordrer via butikkens Webservice-API (api_url + api_key) - knappen
#     "Hent nye ordrer", eller en cron-adresse (webhook_shop.php?..&fetch=<hemmelighed>). last_remote_id husker hvor.
# Udbetalingen fra betalingsudbyderen (Bambora, Stripe, Shopify Payments ...) afstemmes i bankafstemningen mod
# butikkens mellemregningskonto - gebyret bogføres samtidig (reconcile_shop_payout(), inc/reconcile.inc.php).
require_once __DIR__ . '/invoice_post.inc.php';

const SHOP_PLATFORMS = ['native' => 'TinyCash JSON', 'woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'prestashop' => 'PrestaShop', 'magento' => 'Magento'];
const SHOP_LINE_TYPES = ['product', 'shipping', 'fee', 'discount', 'text'];

class ShopError extends Exception {
    public int $http;
    public function __construct(string $message, int $http = 400) { parent::__construct($message);  $this->http = $http; }
}
// Et kald der bevidst IKKE bliver til noget (en ubetalt / annulleret ordre, en anden webhook-type) - svares med 200
class ShopIgnore extends Exception {}

// Platforme hvor TinyCash selv henter ordrerne (i stedet for at butikken sender dem)
const SHOP_PULL_PLATFORMS = ['prestashop'];
// Platforme der sender deres egne webhooks med en HMAC-signatur (webhook_shop.php)
const SHOP_SIGNED_PLATFORMS = ['woocommerce', 'shopify'];

function shop_tbl_exists($conn, string $t, ?bool $is_sqlite = null): bool {
    $is_sqlite = $is_sqlite ?? DB::is_sqlite();
    $r = $is_sqlite ? DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='" . DB::escape($conn, $t) . "'")
                    : DB::query($conn, "SHOW TABLES LIKE '" . DB::escape($conn, $t) . "'");
    return $is_sqlite ? (bool)($r && DB::fetch_assoc($r)) : ($r && DB::num_rows($r) > 0);
}

function shop_ready($conn): bool {
    static $ready = null;
    if ($ready === null) $ready = shop_tbl_exists($conn, 'shop_connections') && shop_tbl_exists($conn, 'shop_orders');
    return $ready;
}

// Tabellerne (idempotent) - kaldt af db-setup/migrate_shop_connections.php og create_all_tables_for()
function shop_ensure_schema($conn, bool $is_sqlite): array {
    $out = [];
    $id  = $is_sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $txt = $is_sqlite ? 'TEXT' : 'VARCHAR(190)';
    $big = $is_sqlite ? 'TEXT' : 'MEDIUMTEXT';
    $int = $is_sqlite ? 'INTEGER' : 'INT';
    $num = $is_sqlite ? 'REAL' : 'DECIMAL(15,2)';
    $eng = $is_sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $tables = [
        'shop_connections' => "CREATE TABLE shop_connections (
            shop_id $id, name $txt NOT NULL, platform $txt NOT NULL, key_id $txt, mode $txt NOT NULL, mode_since $txt,
            auto_post $int DEFAULT 0, paid_default $int DEFAULT 1, prices_incl_vat $int DEFAULT 1, track_stock $int DEFAULT 1,
            clearing_acc $int, sales_acc $int, shipping_acc $int, shipping_vat $num DEFAULT 25, active $int DEFAULT 1,
            created_at $txt, updated_at $txt, secret $txt, api_url $txt, api_key $txt, last_remote_id $txt, last_fetch_at $txt, last_fetch_msg $big)$eng",
        'shop_orders' => "CREATE TABLE shop_orders (
            order_id $id, shop_id $int NOT NULL, kind $txt NOT NULL, external_id $txt NOT NULL, order_ref $txt, order_date $txt,
            mode $txt, cust_id $int, customer_name $txt, net_sales $num DEFAULT 0, net_shipping $num DEFAULT 0, vat $num DEFAULT 0,
            gross $num DEFAULT 0, paid $int DEFAULT 0, payment_done $int DEFAULT 0, inv_id $int, credit_inv_id $int, day_jou_id $int,
            status $txt, message $big, payload $big, created_at $txt)$eng",
    ];
    foreach ($tables as $t => $sql) {
        if (shop_tbl_exists($conn, $t, $is_sqlite)) { $out[] = "[SPRUNGET OVER] Tabellen '$t' findes allerede.";  continue; }
        $out[] = DB::query($conn, $sql) ? "[OK] Tabellen '$t' oprettet." : "[FEJL] $t: " . DB::error($conn);
    }
    // Ét ordrenummer pr. butik og art: et gentaget kald (retry) afvises i stedet for at give en dublet
    $has_idx = $is_sqlite
        ? (bool)DB::fetch_assoc(DB::query($conn, "SELECT name FROM sqlite_master WHERE type='index' AND name='ux_shop_orders_ext'"))
        : (bool)DB::fetch_assoc(DB::query($conn, "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'ux_shop_orders_ext'"));
    if (!$has_idx) $out[] = DB::query($conn, "CREATE UNIQUE INDEX ux_shop_orders_ext ON shop_orders (shop_id, kind, external_id)") ? "[OK] Indeks ux_shop_orders_ext oprettet." : "[FEJL] indeks: " . DB::error($conn);
    // Platform-integrationerne (signeret webhook / hentning): kolonner på en ældre shop_connections
    foreach (['secret' => $txt, 'api_url' => $txt, 'api_key' => $txt, 'last_remote_id' => $txt, 'last_fetch_at' => $txt, 'last_fetch_msg' => $big] as $col => $type) {
        if (shop_col_exists($conn, 'shop_connections', $col)) continue;
        $out[] = DB::query($conn, "ALTER TABLE shop_connections ADD COLUMN $col $type") ? "[OK] Kolonnen shop_connections.$col tilføjet." : "[FEJL] $col: " . DB::error($conn);
    }
    return $out;
}
// Saldoen på en mellemregningskonto (det betalingsudbyderen skylder) - også brugt af bankafstemningen
if (!function_exists('shop_clearing_balance')) {
    function shop_clearing_balance($conn, int $acc): float {
        $r = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) FROM ledger WHERE acc_id = $acc"));
        return round((float)($r[0] ?? 0), 2);
    }
}
// Har shop_connections platform-kolonnerne? (shop_connections.php opgraderer selv, hvis ikke)
function shop_platform_ready($conn): bool {
    return shop_ready($conn) && shop_col_exists($conn, 'shop_connections', 'secret');
}

function shop_list($conn): array {
    $list = [];
    $res = DB::query($conn, "SELECT * FROM shop_connections ORDER BY name");
    if ($res) while ($r = DB::fetch_assoc($res)) $list[(int)$r['shop_id']] = $r;
    return $list;
}
function shop_get($conn, int $shop_id): ?array {
    return DB::fetch_assoc(DB::query($conn, "SELECT * FROM shop_connections WHERE shop_id = $shop_id")) ?: null;
}
function shop_get_by_key($conn, string $key_id): ?array {
    if ($key_id === '') return null;
    return DB::fetch_assoc(DB::query($conn, "SELECT * FROM shop_connections WHERE key_id = '" . DB::escape($conn, $key_id) . "' AND active = 1")) ?: null;
}

// Konti til valglisterne: [acc_id => "1000 - Salg"] for de givne kontotyper
function shop_account_options($conn, array $types, array $keep = []): array {
    require_once __DIR__ . '/accounts.inc.php';     // slukkede konti udeladt (de valgte beholdes), dæmpede nederst
    $in = implode(',', array_map(fn($t) => "'" . DB::escape($conn, $t) . "'", $types));
    $opts = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE acc_type IN ($in) AND " . acc_visible_sql($conn, '', $keep) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
    if ($res) while ($r = DB::fetch_assoc($res)) $opts[(int)$r['acc_id']] = $r['acc_id'] . ' - ' . acc_label($r['acc_name'], $r['acc_status'] ?? null);
    return $opts;
}
function shop_account_exists($conn, int $acc_id): bool {
    return $acc_id > 0 && (bool)DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $acc_id"));
}
// "Mellemregning webbutik" (balancekonto) - første ledige nummer 5850-5899
function shop_create_clearing_account($conn, string $name): int {
    // RETTET: 5850-5899 ligger midt i standardkontoplanens "Kapitalandele i associerede virksomheder" (5852-5885) - en
    // clearingkonto kunne få et standardnummer (konflikt ved import / skift), og den var aldrig knyttet til en
    // standardkonto, så SAF-T-eksporten manglede den. Standardnumre springes nu over, og kontoen knyttes til 6330
    // "Øvrige tilgodehavender (omsætningsaktiv)", når standardkontoplanen er indlæst.
    $std = [];
    $doc = json_decode((string)@file_get_contents(__DIR__ . '/../json-data/standardkontoplan_2026.json'), true);
    foreach ((array)($doc['accounts'] ?? []) as $sa) $std[(int)$sa['no']] = true;
    $ref = DB::fetch_assoc(@DB::query($conn, "SELECT std_id FROM std_accounts WHERE std_id = 6330")) ? 6330 : null;
    for ($n = 5850; $n <= 5899; $n++) {
        if (isset($std[$n]) || shop_account_exists($conn, $n) || DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM ledger WHERE acc_id = $n LIMIT 1"))) continue;
        $row = ['acc_id' => $n, 'acc_name' => $name, 'acc_type' => 'asset', 'vat_rate' => 0];
        if ($ref) $row['std_ref_id'] = $ref;
        if (!DB::insert($conn, 'accounts', $row)) throw new Exception(DB::error($conn));
        log_action($conn, 'CREATE_ACCOUNT', 'accounts', $n, null, ['acc_name' => $name, 'reason' => 'webbutik mellemregning']);
        return $n;
    }
    throw new Exception('Ingen ledige kontonumre mellem 5850 og 5899.');
}

// --- Adaptere ---------------------------------------------------------------------------------
function shop_adapter_available(string $platform): bool {
    return isset(SHOP_PLATFORMS[$platform]) && is_file(__DIR__ . '/shop_adapters/' . $platform . '.php');
}
function shop_parse(array $shop, string $raw, array $headers): array {
    $platform = (string)$shop['platform'];
    if (!shop_adapter_available($platform)) throw new ShopError('No adapter for the platform "' . $platform . '" yet - choose "TinyCash JSON" or send the TinyCash format.', 501);
    require_once __DIR__ . '/shop_adapters/' . $platform . '.php';
    $fn = 'shop_adapter_' . $platform;
    return $fn($raw, $headers, $shop);
}
// Kaldets headere - getallheaders() findes ikke under alle PHP-opsætninger (fx ældre FastCGI); ellers fra $_SERVER
function shop_request_headers(): array {
    if (function_exists('getallheaders')) { $h = getallheaders();  if (is_array($h) && $h) return $h; }
    $out = [];
    foreach ($_SERVER as $k => $v) if (strpos($k, 'HTTP_') === 0) $out[str_replace('_', '-', substr($k, 5))] = $v;
    return $out;
}
// Headernavne uden forskel på store/små bogstaver: ['x-wc-webhook-signature' => '...']
function shop_headers_lc(array $headers): array {
    $out = [];
    foreach ($headers as $k => $v) $out[strtolower((string)$k)] = is_array($v) ? (string)reset($v) : (string)$v;
    return $out;
}
// HMAC-SHA256 i base64 (WooCommerce og Shopify bruger begge den form) - sammenlignes i konstant tid
function shop_hmac_ok(string $raw, string $secret, string $given): bool {
    if ($secret === '' || $given === '') return false;
    return hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), trim($given));
}
// En ny hemmelighed til en butik (vises i shop_connections.php - indsættes i butikkens webhook-opsætning)
function shop_new_secret(): string {
    return 'tcs_' . bin2hex(random_bytes(20));
}
// Tal / ja-nej fra en butiks JSON (bruges af alle adaptere)
if (!function_exists('shop_num')) {
    function shop_num($v): float {
        if (is_int($v) || is_float($v)) return (float)$v;
        return parse_dk_number(preg_replace('/[^0-9,.\-]/', '', (string)$v));
    }
}
if (!function_exists('shop_bool')) {
    function shop_bool($v): bool {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'ja', 'on', 'y'], true);
    }
}

// Et SIGNERET kald fra en platform (webhook_shop.php): signaturen kontrolleres af adapterens shop_verify_<platform>(),
// derefter som ethvert andet kald. En gentagelse (409) og en ignoreret webhook svares med 200.
function shop_handle_signed_request($conn, array $shop, string $raw, array $headers): array {
    $platform = (string)$shop['platform'];
    if (!in_array($platform, SHOP_SIGNED_PLATFORMS, true) || !shop_adapter_available($platform)) {
        return ['http' => 400, 'body' => ['success' => false, 'error' => 'This shop does not receive signed platform webhooks.']];
    }
    require_once __DIR__ . '/shop_adapters/' . $platform . '.php';
    $verify = 'shop_verify_' . $platform;
    $h = shop_headers_lc($headers);
    if (!$verify($raw, $h, (string)($shop['secret'] ?? ''))) return ['http' => 401, 'body' => ['success' => false, 'error' => 'Invalid or missing signature.']];
    try {
        $res = shop_process($conn, $shop, shop_parse($shop, $raw, $h));
    } catch (ShopIgnore $e) {
        return ['http' => 200, 'body' => ['success' => true, 'ignored' => $e->getMessage()]];
    } catch (ShopError $e) {
        return ['http' => $e->http, 'body' => ['success' => false, 'error' => $e->getMessage()]];
    } catch (Throwable $e) {
        error_log('[shop] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return ['http' => 500, 'body' => ['success' => false, 'error' => $e->getMessage()]];
    }
    if ($res['http'] === 409) { $res['http'] = 200;  $res['body']['success'] = true;  $res['body']['duplicate'] = true;  unset($res['body']['error']); }
    return $res;
}

// HENT nye ordrer fra en pull-platform (PrestaShop) og behandl dem. Returnerer ['fetched' => n, 'created' => n,
// 'ignored' => n, 'errors' => [ordre => fejl]]. last_remote_id flyttes kun frem forbi ordrer der er behandlet eller
// bevidst sprunget over - en ordre der fejlede, prøves igen næste gang (den og alt efter den).
function shop_fetch_orders($conn, array $shop, int $limit = 50): array {
    $platform = (string)$shop['platform'];
    $out = ['fetched' => 0, 'created' => 0, 'ignored' => 0, 'duplicates' => 0, 'errors' => []];
    if (!in_array($platform, SHOP_PULL_PLATFORMS, true) || !shop_adapter_available($platform)) { $out['errors']['*'] = 'This platform is not fetched by TinyCash.';  return $out; }
    require_once __DIR__ . '/shop_adapters/' . $platform . '.php';
    $pull = 'shop_pull_' . $platform;
    $sid  = (int)$shop['shop_id'];
    $last = (string)($shop['last_remote_id'] ?? '');
    try {
        $items = $pull($shop, $last, $limit);            // [['remote_id' => .., 'order' => fælles format | null, 'ignore' => grund | null], ...] stigende
    } catch (Throwable $e) {
        $out['errors']['*'] = $e->getMessage();
        $items = [];
    }
    // En ordre der venter på betaling, eller som fejlede, holder last_remote_id tilbage (den hentes igen næste gang) -
    // de senere ordrer behandles alligevel; når de hentes igen, er de dubletter (409) og røres ikke.
    $new_last = $last;  $blocked = false;
    foreach ($items as $it) {
        $out['fetched']++;
        $rid = (string)$it['remote_id'];
        if (!empty($it['ignore'])) {
            $out['ignored']++;
            if (!empty($it['wait'])) $blocked = true; elseif (!$blocked) $new_last = $rid;
            continue;
        }
        try {
            $res = shop_process($conn, $shop, $it['order']);
            if ($res['http'] === 409) $out['duplicates']++; else $out['created']++;
            if (!$blocked) $new_last = $rid;
        } catch (ShopIgnore $e) {
            $out['ignored']++;
            if (!$blocked) $new_last = $rid;
        } catch (Throwable $e) {
            $out['errors'][$rid] = $e->getMessage();
            $blocked = true;
        }
    }
    $msg = sprintf('%s: %d hentet, %d oprettet, %d dubletter, %d sprunget over', date('Y-m-d H:i'), $out['fetched'], $out['created'], $out['duplicates'], $out['ignored'])
         . ($out['errors'] ? ' - fejl: ' . implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($out['errors']), $out['errors'])) : '');
    DB::query($conn, "UPDATE shop_connections SET last_remote_id = '" . DB::escape($conn, $new_last) . "', last_fetch_at = '" . date('Y-m-d H:i:s')
        . "', last_fetch_msg = '" . DB::escape($conn, mb_substr($msg, 0, 2000)) . "' WHERE shop_id = $sid");
    if (function_exists('log_action')) log_action($conn, 'SHOP_FETCH', 'shop_connections', $sid, null, $out + ['last_remote_id' => $new_last]);
    return $out;
}

// Et kald fra butikken: adapter + behandling -> ['http' => kode, 'body' => svar]
function shop_handle_request($conn, array $shop, string $raw, array $headers): array {
    try {
        return shop_process($conn, $shop, shop_parse($shop, $raw, $headers));
    } catch (ShopError $e) {
        return ['http' => $e->http, 'body' => ['success' => false, 'error' => $e->getMessage()]];
    } catch (Throwable $e) {
        error_log('[shop] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return ['http' => 500, 'body' => ['success' => false, 'error' => $e->getMessage()]];
    }
}

// --- Linjer -----------------------------------------------------------------------------------
// Linjerne i DKK ekskl./inkl. moms. Pris inkl. moms: linjens BRUTTO er det butikken tog - netto og moms
// regnes ud af den (så brutto passer på øren). Varenummer: kendt produkt -> produktets navn/konto-moms;
// ukendt -> fritekst (advarsel). Fragt/gebyr/rabat/fritekst kræver intet varenummer.
function shop_resolve_lines($conn, array $shop, array $order, array &$warnings): array {
    $incl = $order['prices_incl_vat'] ?? null;
    $incl = ($incl === null) ? !empty($shop['prices_incl_vat']) : (bool)$incl;
    $out = [];
    foreach ($order['lines'] as $i => $l) {
        $type = in_array($l['type'] ?? 'product', SHOP_LINE_TYPES, true) ? ($l['type'] ?? 'product') : 'product';
        $qty  = (float)($l['qty'] ?? 1);
        if ($qty <= 0 && $type !== 'discount') throw new ShopError("lines[$i]: the quantity must be greater than 0.");
        if ($type === 'discount') $qty = 1;
        $sku  = trim((string)($l['sku'] ?? ''));
        $prod = null;
        if ($sku !== '') {
            $prod = DB::fetch_assoc(DB::query($conn, "SELECT p.prod_id, p.prod_name, p.prod_price, a.vat_rate FROM products p
                LEFT JOIN accounts a ON p.acc_id = a.acc_id WHERE p.prod_sku = '" . DB::escape($conn, $sku) . "'"));
            if (!$prod && $type === 'product') $warnings[] = "lines[$i]: no product with sku \"$sku\" - booked as a text line";
        }
        $text = trim((string)($l['text'] ?? '')) !== '' ? trim((string)$l['text']) : ($prod['prod_name'] ?? ($type === 'shipping' ? 'Fragt' : ($type === 'discount' ? 'Rabat' : ($type === 'fee' ? 'Gebyr' : ''))));
        if ($text === '') throw new ShopError("lines[$i]: a text is needed for a line without a known sku.");
        $vat = isset($l['vat_rate']) ? (float)$l['vat_rate']
             : ($prod && $prod['vat_rate'] !== null ? (float)$prod['vat_rate'] : ($type === 'shipping' ? (float)($shop['shipping_vat'] ?? 25) : 25.0));
        if (!isset($l['price']) && !$prod) throw new ShopError("lines[$i]: a price is needed for a line without a known sku.");
        $price = isset($l['price']) ? (float)$l['price'] : (float)$prod['prod_price'] * ($incl ? (1 + $vat / 100) : 1);
        // RETTET: momssats og pris blev ikke kontrolleret - vat_rate -100 gav division med nul (fatal fejl, ordren gik
        // tabt med en 500), en sats på fx 250 eller en negativ varepris (ikke en rabatlinje) gav forkert moms og omsætning
        if (!is_finite($vat) || $vat < 0 || $vat > 100) throw new ShopError("lines[$i]: vat_rate must be between 0 and 100.");
        if (!is_finite($price) || !is_finite($qty) || ($price < 0 && $type !== 'discount')) throw new ShopError("lines[$i]: the price must be 0 or more (use a line of type discount for a discount).");
        if ($type === 'discount') $price = -abs($price);
        $line_amount = round($qty * $price, 2);                        // what the shop charged for the line (incl. or excl. VAT)
        if ($incl) { $gross = $line_amount;  $net = round($gross / (1 + $vat / 100), 2);  $line_vat = round($gross - $net, 2); }
        else       { $net = $line_amount;  $line_vat = round($net * $vat / 100, 2);  $gross = round($net + $line_vat, 2); }
        $out[] = ['type' => $type, 'prod_id' => $prod ? (int)$prod['prod_id'] : 0, 'text' => $text, 'qty' => $qty, 'vat' => $vat,
                  'net' => $net, 'line_vat' => $line_vat, 'gross' => $gross, 'unit_net' => $qty != 0 ? round($net / $qty, 4) : $net];
    }
    return $out;
}

// --- Kunde ------------------------------------------------------------------------------------
// Match på e-mail - ellers oprettes kunden; uden e-mail: butikkens fælles "kunde uden e-mail"
function shop_customer($conn, array $shop, array $c): int {
    $s = get_settings($conn);
    // RETTET: default_payment_terms er firmaets betalingsTEKST (OIOUBL, "Betaling bedes overført ...") - ikke et
    // antal dage. (int) af teksten gav 0: hver ny webkunde fik 0 dages kredit, og fakturaen forfaldt samme dag.
    $terms = is_numeric(trim((string)($s['default_payment_terms'] ?? ''))) ? (int)$s['default_payment_terms'] : 8;
    $email = trim((string)($c['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new ShopError('customer.email is not a valid email address.');
    // RETTET: en kunde i papirkurven (customer_edit.php "Slet") blev fundet og fik ordren - fakturaen kom på en
    // kunde, der ikke ses nogen steder. Kun aktive kunder matches nu; ellers oprettes kunden på ny.
    $active = customers_active_filter($conn);
    if ($email === '') {
        $name = 'Webbutik ' . $shop['name'] . ' - kunder uden e-mail';
        $row = DB::fetch_assoc(DB::query($conn, "SELECT cust_id FROM customers WHERE cust_name = '" . DB::escape($conn, $name) . "' AND (cust_email IS NULL OR cust_email = '') AND $active"));
        if ($row) return (int)$row['cust_id'];
        DB::insert($conn, 'customers', ['cust_name' => $name, 'cust_email' => '', 'cust_payment_days' => 0]);
        return (int)DB::insert_id($conn);
    }
    $row = DB::fetch_assoc(DB::query($conn, "SELECT cust_id FROM customers WHERE LOWER(cust_email) = LOWER('" . DB::escape($conn, $email) . "') AND $active"));
    if ($row) return (int)$row['cust_id'];
    $name = trim((string)($c['name'] ?? '')) ?: $email;
    if (!DB::insert($conn, 'customers', ['cust_name' => $name, 'cust_email' => $email, 'cust_phone' => trim((string)($c['phone'] ?? '')),
            'cust_address' => trim((string)($c['address'] ?? '')), 'cust_cvr' => trim((string)($c['cvr'] ?? '')), 'cust_payment_days' => $terms])) {
        throw new Exception('Kunne ikke oprette kunden: ' . DB::error($conn));
    }
    $id = (int)DB::insert_id($conn);
    log_action($conn, 'CREATE_CUSTOMER_WEBHOOK', 'customers', $id, null, ['cust_name' => $name, 'cust_email' => $email, 'shop' => $shop['name']]);
    return $id;
}

// --- Behandling -------------------------------------------------------------------------------
function shop_process($conn, array $shop, array $order): array {
    global $global_settings;
    $kind = ($order['kind'] ?? 'order') === 'refund' ? 'refund' : 'order';
    $ext  = trim((string)($order['external_id'] ?? ''));
    if ($ext === '') throw new ShopError('external_id (the order / refund number of the shop) is required.');
    $base = strtoupper($global_settings['currency'] ?? 'DKK');
    if (!empty($order['currency']) && strtoupper((string)$order['currency']) !== $base) throw new ShopError('Orders in ' . $order['currency'] . ' are not supported yet - only ' . $base . '.', 422);
    $date = (string)($order['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = date('Y-m-d');
    // RETTET: en dato i fremtiden fik den automatiske dagsbogføring til at bogføre dagens endnu ufærdige salg
    // (dage før ordrens dato) - og en dato i en låst periode gav en serverfejl (model A) eller en afvisning (B).
    if ($date > date('Y-m-d')) throw new ShopError("The order date $date is in the future.", 422);
    $date_note = '';
    if (is_date_locked($conn, $date)) { $date_note = "order date $date is in a locked period - booked " . date('Y-m-d');  $date = date('Y-m-d'); }
    if (empty($order['lines']) || !is_array($order['lines'])) throw new ShopError('At least one line is required in "lines".');
    $ref = trim((string)($order['order_ref'] ?? ''));
    if ($kind === 'refund' && $ref === '') throw new ShopError('refund_of (the order number that is refunded) is required for a refund.');

    $dup = DB::fetch_assoc(DB::query($conn, "SELECT order_id, inv_id, credit_inv_id, status FROM shop_orders WHERE shop_id = " . (int)$shop['shop_id']
        . " AND kind = '$kind' AND external_id = '" . DB::escape($conn, $ext) . "'"));
    if ($dup) return ['http' => 409, 'body' => ['success' => false, 'error' => 'This ' . $kind . ' is already received.', 'order_id' => (int)$dup['order_id'],
                      'invoice_id' => (int)($dup['inv_id'] ?: $dup['credit_inv_id']) ?: null, 'status' => $dup['status']]];

    $warnings = $date_note !== '' ? [$date_note] : [];
    $lines = shop_resolve_lines($conn, $shop, $order, $warnings);
    $gross = round(array_sum(array_column($lines, 'gross')), 2);
    // Kontrol mod butikkens total: op til 1 kr forskel er øreafrunding (en linje uden moms) - mere er en fejl
    if (isset($order['total']) && $order['total'] !== null) {
        $diff = round((float)$order['total'] - $gross, 2);
        if (abs($diff) > 1.00) throw new ShopError("The lines add up to $gross, but the order total is " . (float)$order['total'] . '.', 422);
        if (abs($diff) >= 0.01) {
            $lines[] = ['type' => 'text', 'prod_id' => 0, 'text' => 'Øreafrunding', 'qty' => 1, 'vat' => 0, 'net' => $diff, 'line_vat' => 0, 'gross' => $diff, 'unit_net' => $diff];
            $gross = round($gross + $diff, 2);
        }
    }
    $paid = ($order['paid'] ?? null) === null ? !empty($shop['paid_default']) : (bool)$order['paid'];
    $mode = ($shop['mode'] ?? 'invoice') === 'daily' ? 'daily' : 'invoice';
    $sign = $kind === 'refund' ? -1 : 1;
    // RETTET: model B (samlet salg pr. dag) tog imod en returnering af en ordre den aldrig havde fået - og af
    // mere end ordren var på (eller den samme ordre returneret flere gange under nye numre). Dagens bilag
    // trak så salg og moms ned, der aldrig var bogført. Model A tjekker det på fakturaen (shop_make_credit).
    if ($mode === 'daily' && $kind === 'refund') {
        $o = DB::fetch_assoc(DB::query($conn, "SELECT gross FROM shop_orders WHERE shop_id = " . (int)$shop['shop_id'] . " AND kind = 'order' AND external_id = '" . DB::escape($conn, $ref) . "'"));
        if (!$o) throw new ShopError("The order \"$ref\" is not known in TinyCash - a refund needs the order first.", 404);
        $done = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(gross), 0) FROM shop_orders WHERE shop_id = " . (int)$shop['shop_id'] . " AND kind = 'refund' AND order_ref = '" . DB::escape($conn, $ref) . "'"));
        $left = round((float)$o['gross'] + (float)($done[0] ?? 0), 2);         // refunderinger er gemt negative
        if ($gross > $left + 0.01) throw new ShopError("The refund ($gross) is more than what is left of order \"$ref\" ($left).", 422);
    }
    $net_sales = 0.0;  $net_ship = 0.0;  $vat = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'shipping') $net_ship += $l['net']; else $net_sales += $l['net'];
        $vat += $l['line_vat'];
    }
    $row = ['shop_id' => (int)$shop['shop_id'], 'kind' => $kind, 'external_id' => $ext, 'order_ref' => $ref, 'order_date' => $date, 'mode' => $mode,
            'customer_name' => trim((string)($order['customer']['name'] ?? ($order['customer']['email'] ?? ''))),
            'net_sales' => round($sign * $net_sales, 2), 'net_shipping' => round($sign * $net_ship, 2), 'vat' => round($sign * $vat, 2), 'gross' => round($sign * $gross, 2),
            'paid' => $paid ? 1 : 0, 'payment_done' => 0, 'message' => $warnings ? implode("\n", $warnings) : null,
            'payload' => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => date('Y-m-d H:i:s')];

    DB::begin_transaction($conn);
    try {
        if ($mode === 'daily') {
            $row['status'] = 'pending_day';
            if (!empty($shop['track_stock'])) foreach ($lines as $l) if ($l['prod_id'] > 0) {
                DB::query($conn, "UPDATE products SET prod_stock = prod_stock - " . ($sign * $l['qty']) . " WHERE prod_id = {$l['prod_id']}");
            }
            $answer = ['status' => 'pending_day'];
        } elseif ($kind === 'order') {
            $answer = shop_make_invoice($conn, $shop, $order, $lines, $date, $paid, $row);
        } else {
            $answer = shop_make_credit($conn, $shop, $lines, $date, $paid, $ref, $row);
        }
        if (!DB::insert($conn, 'shop_orders', $row)) throw new Exception('Kunne ikke gemme ordren: ' . DB::error($conn));
        $answer['order_id'] = (int)DB::insert_id($conn);
        DB::commit($conn);
    } catch (Throwable $e) {
        DB::rollback($conn);
        throw $e;
    }
    if ($mode === 'daily' && !empty($shop['auto_post'])) {                   // Model B, automatisk: dage FØR denne ordres dag er hele - de bogføres
        $booked = shop_book_days($conn, $shop, $date);                          // (en sen ordre fra i går bogfører altså ikke i går - først en ordre fra i dag gør)
        if ($booked['days']) $answer['booked_days'] = $booked['days'];
    }
    return ['http' => 201, 'body' => ['success' => true, 'kind' => $kind, 'mode' => $mode] + $answer + ($warnings ? ['warnings' => $warnings] : [])];
}

// Model A, ordre: kladde - og bogført + betalt, hvis butikken har valgt det
function shop_make_invoice($conn, array $shop, array $order, array $lines, string $date, bool $paid, array &$row): array {
    global $global_settings;
    $cust_id = shop_customer($conn, $shop, (array)($order['customer'] ?? []));
    $row['cust_id'] = $cust_id;
    $c = DB::fetch_assoc(DB::query($conn, "SELECT cust_payment_days FROM customers WHERE cust_id = $cust_id"));
    $due = date('Y-m-d', strtotime($date . ' +' . (int)($c['cust_payment_days'] ?? 8) . ' days'));
    $inv = ['cust_id' => $cust_id, 'inv_date' => $date, 'inv_due_date' => $due, 'inv_status' => 'draft',
            'inv_note' => trim((string)($order['note'] ?? '')), 'cust_reference' => 'Ordre ' . $row['external_id'],
            'delivery_address' => trim((string)($order['delivery_address'] ?? '')), 'currency' => $global_settings['currency'] ?? 'DKK'];
    if (shop_col_exists($conn, 'invoices', 'external_ref')) $inv['external_ref'] = $shop['shop_id'] . ':' . $row['external_id'];
    if (!DB::insert($conn, 'invoices', $inv)) throw new Exception('Kunne ikke oprette fakturaen: ' . DB::error($conn));
    $inv_id = (int)DB::insert_id($conn);
    foreach ($lines as $l) {
        if (!DB::insert($conn, 'invoice_lines', ['inv_id' => $inv_id, 'line_text' => $l['text'], 'quantity' => $l['qty'], 'price_each' => $l['unit_net'],
                'line_vat_rate' => $l['vat'], 'prod_id' => $l['prod_id']])) throw new Exception('Kunne ikke gemme en linje: ' . DB::error($conn));
    }
    // RETTET: på MySQL har en linjes stykpris 2 decimaler - ved priser inkl. moms kunne fakturaens total derfor
    // afvige nogle øre fra det kunden betalte. Forskellen lægges på en øreafrundingslinje (uden moms).
    $want = round(array_sum(array_column($lines, 'gross')), 2);
    $diff = round($want - invoice_dkk_totals($conn, $inv_id)['incl'], 2);
    if (abs($diff) >= 0.01 && abs($diff) <= 1.00) {
        DB::insert($conn, 'invoice_lines', ['inv_id' => $inv_id, 'line_text' => 'Øreafrunding', 'quantity' => 1, 'price_each' => $diff, 'line_vat_rate' => 0, 'prod_id' => 0]);
    }
    $row['inv_id'] = $inv_id;
    log_action($conn, 'CREATE_DRAFT_INVOICE_WEBHOOK', 'invoices', $inv_id, null, ['shop' => $shop['name'], 'external_id' => $row['external_id']]);
    if (empty($shop['auto_post'])) { $row['status'] = 'draft';  return ['invoice_id' => $inv_id, 'status' => 'draft']; }
    $no = invoice_post($conn, $inv_id, !empty($shop['track_stock']));
    $row['status'] = 'posted';
    if ($paid) {
        $amount = invoice_open_dkk($conn, $inv_id);
        invoice_register_payment($conn, $inv_id, is_date_locked($conn, $date) ? date('Y-m-d') : $date, $amount, (int)$shop['clearing_acc'], 'webbutik ' . $shop['name'] . ', ordre ' . $row['external_id']);
        $row['payment_done'] = 1;
        $row['status'] = 'paid';
    }
    return ['invoice_id' => $inv_id, 'invoice_no' => $no, 'status' => $row['status']];
}

// Model A, returnering: kreditnota på den oprindelige ordres faktura (linje for linje) - og tilbagebetaling
function shop_make_credit($conn, array $shop, array $lines, string $date, bool $paid, string $ref, array &$row): array {
    $orig = DB::fetch_assoc(DB::query($conn, "SELECT * FROM shop_orders WHERE shop_id = " . (int)$shop['shop_id'] . " AND kind = 'order' AND external_id = '" . DB::escape($conn, $ref) . "'"));
    if (!$orig || empty($orig['inv_id'])) throw new ShopError("The order \"$ref\" is not known as an invoice in TinyCash.", 404);
    $inv_id = (int)$orig['inv_id'];
    $st = DB::fetch_assoc(DB::query($conn, "SELECT inv_status FROM invoices WHERE inv_id = $inv_id"));
    if (!$st || strtolower((string)$st['inv_status']) === 'draft') throw new ShopError("The invoice of order \"$ref\" is still a draft - correct the draft in TinyCash instead.", 409);
    // Hver returneret linje findes på fakturaen: samme produkt, ellers samme tekst
    $inv_lines = [];
    $res = DB::query($conn, "SELECT line_id, prod_id, line_text, quantity FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id");
    while ($l = DB::fetch_assoc($res)) $inv_lines[] = $l;
    $qtys = [];
    foreach ($inv_lines as $l) $qtys[(int)$l['line_id']] = 0.0;
    foreach ($lines as $i => $l) {
        if ($l['text'] === 'Øreafrunding') continue;
        $hit = null;
        foreach ($inv_lines as $il) {
            $same = $l['prod_id'] > 0 ? ((int)$il['prod_id'] === $l['prod_id']) : (mb_strtolower(trim($il['line_text'])) === mb_strtolower($l['text']));
            if ($same && $qtys[(int)$il['line_id']] < abs((float)$il['quantity'])) { $hit = $il;  break; }
        }
        if (!$hit) throw new ShopError("lines[$i]: \"{$l['text']}\" is not on the invoice of order \"$ref\".", 422);
        $qtys[(int)$hit['line_id']] = min(abs((float)$hit['quantity']), $qtys[(int)$hit['line_id']] + ($l['type'] === 'discount' ? abs((float)$hit['quantity']) : $l['qty']));
    }
    $inv_dt = (string)(DB::fetch_assoc(DB::query($conn, "SELECT inv_date FROM invoices WHERE inv_id = $inv_id"))['inv_date'] ?? '');
    $cr_date = is_date_locked($conn, $date) ? date('Y-m-d') : $date;
    if ($inv_dt !== '' && $cr_date < $inv_dt) $cr_date = $inv_dt;      // (aldrig før fakturaen - fx når ordren blev bogført i dag pga. en låst periode)
    $credit_id = invoice_create_credit($conn, $inv_id, $qtys, $cr_date, 'Returnering ' . $row['external_id'] . ' fra webbutik ' . $shop['name'], !empty($shop['track_stock']));
    $row['inv_id'] = $inv_id;
    $row['credit_inv_id'] = $credit_id;
    $row['status'] = 'credited';
    $credit_gross = invoice_dkk_totals($conn, $credit_id)['incl'];          // negative
    if ($paid && !empty($orig['payment_done'])) {                           // pengene er sendt tilbage til kunden
        invoice_register_payment($conn, $inv_id, is_date_locked($conn, $date) ? date('Y-m-d') : $date, $credit_gross, (int)$shop['clearing_acc'], 'webbutik ' . $shop['name'] . ', returnering ' . $row['external_id']);
        $row['payment_done'] = 1;
    }
    return ['invoice_id' => $inv_id, 'credit_note_id' => $credit_id, 'status' => 'credited'];
}

function shop_col_exists($conn, string $table, string $col): bool {
    static $cache = [];
    $k = "$table.$col";
    if (!isset($cache[$k])) {
        if (DB::is_sqlite()) {
            $cache[$k] = false;
            $r = DB::query($conn, "PRAGMA table_info($table)");
            if ($r) while ($c = DB::fetch_assoc($r)) if ($c['name'] === $col) { $cache[$k] = true;  break; }
        } else {
            $r = DB::query($conn, "SHOW COLUMNS FROM $table LIKE '" . DB::escape($conn, $col) . "'");
            $cache[$k] = $r && DB::num_rows($r) > 0;
        }
    }
    return $cache[$k];
}

// En faktura bogført i hånden (invoice_post_action.php): hører den til en BETALT webordre, registreres betalingen nu
// (kalderens transaktion)
function shop_after_invoice_posted($conn, int $inv_id): void {
    $o = DB::fetch_assoc(DB::query($conn, "SELECT o.*, s.clearing_acc, s.name AS shop_name FROM shop_orders o JOIN shop_connections s ON s.shop_id = o.shop_id
        WHERE o.inv_id = $inv_id AND o.kind = 'order' AND o.paid = 1 AND o.payment_done = 0"));
    if (!$o) { DB::query($conn, "UPDATE shop_orders SET status = 'posted' WHERE inv_id = $inv_id AND kind = 'order' AND status = 'draft'");  return; }
    $date = is_date_locked($conn, (string)$o['order_date']) ? date('Y-m-d') : (string)$o['order_date'];
    invoice_register_payment($conn, $inv_id, $date, invoice_open_dkk($conn, $inv_id), (int)$o['clearing_acc'], 'webbutik ' . $o['shop_name'] . ', ordre ' . $o['external_id']);
    DB::query($conn, "UPDATE shop_orders SET payment_done = 1, status = 'paid' WHERE order_id = " . (int)$o['order_id']);
}

// Model B: bogfør hver hele dag før $before_date samlet - én journalpost pr. dag (eget bilagsnummer):
//   DEBET mellemregning = brutto   KREDIT salg = netto varer   KREDIT fragt = netto fragt   KREDIT moms = moms
// Returneringer indgår med modsat fortegn. Returnerer ['days' => [dato => bilagsnr], 'skipped' => [dato => grund]]
function shop_book_days($conn, array $shop, string $before_date): array {
    global $global_settings;
    $sid = (int)$shop['shop_id'];
    $out = ['days' => [], 'skipped' => []];
    $acc_sales = shop_account_exists($conn, (int)$shop['sales_acc']) ? (int)$shop['sales_acc'] : (int)($global_settings['conf_acc_sales'] ?? 1000);
    $acc_ship  = shop_account_exists($conn, (int)$shop['shipping_acc']) ? (int)$shop['shipping_acc'] : $acc_sales;
    $acc_vat   = (int)($global_settings['conf_acc_vat'] ?? 6900);
    $acc_clear = (int)$shop['clearing_acc'];
    if (!shop_account_exists($conn, $acc_clear)) { $out['skipped']['*'] = 'Butikken har ingen gyldig mellemregningskonto.';  return $out; }
    $days = [];
    $res = DB::query($conn, "SELECT DISTINCT order_date FROM shop_orders WHERE shop_id = $sid AND status = 'pending_day' AND order_date < '" . DB::escape($conn, $before_date) . "' ORDER BY order_date");
    if ($res) while ($r = DB::fetch_assoc($res)) $days[] = (string)$r['order_date'];
    foreach ($days as $day) {
        if (is_date_locked($conn, $day)) { $out['skipped'][$day] = 'låst periode';  continue; }
        DB::begin_transaction($conn);
        try {
            $sum = DB::fetch_assoc(DB::query($conn, "SELECT COUNT(*) AS n, SUM(CASE WHEN kind = 'refund' THEN 1 ELSE 0 END) AS n_ref,
                    COALESCE(SUM(net_sales), 0) AS ns, COALESCE(SUM(net_shipping), 0) AS nf, COALESCE(SUM(vat), 0) AS v, COALESCE(SUM(gross), 0) AS g
                FROM shop_orders WHERE shop_id = $sid AND status = 'pending_day' AND order_date = '$day'"));
            $voucher = next_voucher_no($conn);
            $text = DB::escape($conn, 'Webbutik ' . $shop['name'] . ': salg ' . $day . ' (' . (int)$sum['n'] . ' ordrer' . ((int)$sum['n_ref'] ? ', heraf ' . (int)$sum['n_ref'] . ' returneringer' : '') . ')');
            $cur = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
            $cb = journal_created_by_sql($conn);
            if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
                                  VALUES ('$day', '$text', 'shop_day', $voucher, '$cur'{$cb['val']})") === false) throw new Exception(DB::error($conn));
            $jou_id = (int)DB::insert_id($conn);
            $g = round((float)$sum['g'], 2);  $ns = round((float)$sum['ns'], 2);  $nf = round((float)$sum['nf'], 2);  $v = round((float)$sum['v'], 2);
            if (abs($g - $ns - $nf - $v) > 0.005) throw new Exception("Dagen $day balancerer ikke ($g <> $ns + $nf + $v).");
            ledger_post($conn, $jou_id, $acc_clear, $g);
            if (abs($ns) >= 0.005) ledger_post($conn, $jou_id, $acc_sales, -$ns);
            if (abs($nf) >= 0.005) ledger_post($conn, $jou_id, $acc_ship, -$nf);
            if (abs($v)  >= 0.005) ledger_post($conn, $jou_id, $acc_vat, -$v);
            // RETTET (kapløb): dagsbogføringen køres automatisk ved HVER indkommende ordre - to næsten samtidige ordrer
            // (MySQL) kunne begge summere den samme dag og begge bogføre den: dagens salg bogført to gange. Kun den
            // kørsel der selv flytter ALLE dagens ordrer til 'booked', må committe; ellers rulles den tilbage.
            $upd = DB::query($conn, "UPDATE shop_orders SET status = 'booked', day_jou_id = $jou_id WHERE shop_id = $sid AND status = 'pending_day' AND order_date = '$day'");
            if (!$upd || DB::affected_rows($conn, $upd) !== (int)$sum['n']) throw new Exception("Dagen $day blev bogført af en anden, samtidig kørsel (kapløb) - intet blev dublet.");
            log_action($conn, 'SHOP_BOOK_DAY', 'journal', $jou_id, null, ['shop' => $shop['name'], 'date' => $day, 'orders' => (int)$sum['n'], 'gross' => $g, 'voucher_no' => $voucher]);
            DB::commit($conn);
            $out['days'][$day] = $voucher;
        } catch (Throwable $e) {
            DB::rollback($conn);
            $out['skipped'][$day] = $e->getMessage();
        }
    }
    return $out;
}
