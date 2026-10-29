<?php # /webhook_shop.php v:1.3.1 d:2026-09-15 i:evs
# Webbutikkernes EGNE webhooks og cron-hentning (bruger-anmodet "forbedre webbutik integration") - se inc/shop.inc.php.
#   POST webhook_shop.php?acc=<regnskab>&shop=<butik>          WooCommerce / Shopify sender deres egne ordre-webhooks hertil;
#                                                               de godkendes med platformens HMAC-signatur (butikkens
#                                                               hemmelighed) - ikke med en TinyCash-nøgle i en header,
#                                                               som platformene ikke kan sende.
#   GET  webhook_shop.php?acc=..&shop=..&fetch=<hemmelighed>   henter nye ordrer fra en pull-platform (PrestaShop) - til
#                                                               en cron-job / en overvågningstjeneste der kalder adressen.
# Bevidst UDEN auth.inc.php (intet browser-login) - samme mønster som webhook_invoice.php. Ingen cookie sendes retur.
ob_start();
header('Content-Type: application/json; charset=utf-8');
function ws_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$acc     = trim((string)($_GET['acc'] ?? ''));
$shop_id = (int)($_GET['shop'] ?? 0);
if ($shop_id <= 0) ws_out(400, ['success' => false, 'error' => 'The shop parameter is missing - copy the address from TinyCash (Web Shops).']);

// Regnskabet (flere regnskaber, inc/account.lib.php): sættes FØR db_connect.inc.php, samme mønster som webhook_invoice.php
require_once __DIR__ . '/inc/account.lib.php';
if ($acc !== '') {
    $entry = account_get($acc);
    if (!$entry || empty($entry['active'])) ws_out(404, ['success' => false, 'error' => 'Unknown ledger.']);
    ini_set('session.use_cookies', '0');
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION['account_id'] = $acc;
}
require_once __DIR__ . '/inc/db_connect.inc.php';
require_once __DIR__ . '/inc/audit.inc.php';
require_once __DIR__ . '/inc/shop.inc.php';

if (!shop_platform_ready($conn)) ws_out(503, ['success' => false, 'error' => 'Web shops are not set up in TinyCash (run the database migration).']);
$shop = shop_get($conn, $shop_id);
if (!$shop) ws_out(404, ['success' => false, 'error' => 'Unknown shop.']);
if (empty($shop['active'])) ws_out(403, ['success' => false, 'error' => 'This shop is deactivated in TinyCash - its orders are refused.']);
$secret = (string)($shop['secret'] ?? '');

// --- Cron: hent nye ordrer (pull-platforme) ---
if (isset($_GET['fetch'])) {
    if ($secret === '' || !hash_equals($secret, (string)$_GET['fetch'])) ws_out(401, ['success' => false, 'error' => 'Wrong fetch token.']);
    if (!in_array((string)$shop['platform'], SHOP_PULL_PLATFORMS, true)) ws_out(400, ['success' => false, 'error' => 'This shop sends its orders - nothing to fetch.']);
    $r = shop_fetch_orders($conn, $shop);
    if (!empty($shop['auto_post']) && ($shop['mode'] ?? '') === 'daily') shop_book_days($conn, shop_get($conn, $shop_id), date('Y-m-d'));
    ws_out($r['errors'] && !$r['fetched'] ? 502 : 200, ['success' => !$r['errors']] + $r);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ws_out(405, ['success' => false, 'error' => 'Only POST is supported.']);
$raw = (string)file_get_contents('php://input');
// WooCommerce' "ping", når en webhook gemmes: "webhook_id=123" (ikke en ordre) - kvitteres uden videre
if (preg_match('/^webhook_id=\d+$/', trim($raw))) ws_out(200, ['success' => true, 'pong' => true]);
if (strlen($raw) > 2000000) ws_out(413, ['success' => false, 'error' => 'The body is too large.']);

$res = shop_handle_signed_request($conn, $shop, $raw, shop_request_headers());
ws_out($res['http'], $res['body']);
