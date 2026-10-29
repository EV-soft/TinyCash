<?php # /webhook_invoice.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet: "webhook til automatisk fakturaoprettelse fra
# webshop/POS"). JSON-indgang der lader et EKSTERNT system oprette en kladde-
# faktura i TinyCash uden noget normalt browser-login. Godkendelse sker
# udelukkende via en API-nøgle (se inc/webhook.lib.php + webhook_keys.php),
# ALDRIG via $_SESSION/inc/auth.inc.php - denne fil inkluderer derfor
# bevidst IKKE auth.inc.php. Nøglen sendes enten som
# "Authorization: Bearer <nøgle>" eller (mere robust på delt hosting, hvor
# Authorization-headeren ofte fjernes af PHP-CGI/FastCGI FØR PHP ser den)
# som "X-Webhook-Key: <nøgle>".
#
# Opretter BEVIDST kun kladder, aldrig bogførte fakturaer direkte - samme
# princip som inc/recurring_invoices.inc.php: en ekstern/automatisk kilde må
# aldrig selv postere til hovedbogen, kun oprette noget en bogholder
# efterfølgende gennemgår og selv sender/bogfører (se invoice_edit.php).
#
# Payload (JSON, POST-body):
# {
#   "external_id": "SHOP-ORDER-12345",   // valgfri, men anbefalet - et
#                                          // gentaget kald (netværks-retry)
#                                          // med samme external_id afvises
#                                          // (409) i stedet for at oprette
#                                          // en dublet-faktura
#   "customer": {
#     "email": "kunde@example.com",       // påkrævet - matcher kunden
#     "name": "Kunde Navn",               // påkrævet ved førstegangs-oprettelse
#     "phone": "...", "address": "...", "cvr": "..."   // alle valgfrie
#   },
#   "lines": [
#     {"sku": "GLASSKO-1", "quantity": 2, "price_each": 500.00, "vat_rate": 25}
#     // price_each/vat_rate er valgfrie - mangler de, bruges produktets egen
#     // salgspris hhv. dets kontos momssats (samme opslag som invoice_edit.php
#     // bruger til $prod_vat_map)
#   ],
#   "note": "valgfri fritekst-note på fakturaen"
# }
#
# Svar: {"success": true, "invoice_id": 123, "status": "draft"} (HTTP 201),
# ellers {"success": false, "error": "..."} (HTTP 400/401/404/409/405/500).
# Se webhook_keys.php for nøgleadministration + et fuldt curl-eksempel.
ob_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST is supported.']);
    exit;
}

require_once __DIR__ . '/inc/webhook.lib.php';

// --- 1. Godkendelse via API-nøgle (intet session-/cookie-baseret login) ---
$provided_key = trim((string)($_SERVER['HTTP_X_WEBHOOK_KEY'] ?? ''));
if ($provided_key === '') {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', trim($auth_header), $m)) {
        $provided_key = trim($m[1]);
    }
}
if ($provided_key === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing API key - send it as "X-Webhook-Key" or "Authorization: Bearer <key>".']);
    exit;
}

$key_entry = webhook_key_verify($provided_key);
if (!$key_entry) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or revoked API key.']);
    exit;
}

// --- 2. Forbind til det RIGTIGE regnskab (kun relevant ved flere regnskaber,
// se inc/account.lib.php). Sat FØR db_connect.inc.php, samme mønster som
// login.php. Ingen cookie sendes retur til kalderen - sessionen findes kun
// for varigheden af selve dette ene kald (nøglen ER legitimationen, ikke en
# browser-session), så den skal ikke lække til den eksterne klient.
if (!empty($key_entry['account_id'])) {
    ini_set('session.use_cookies', '0');
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION['account_id'] = $key_entry['account_id'];
}
require_once __DIR__ . '/inc/db_connect.inc.php';
require_once __DIR__ . '/inc/audit.inc.php';

// --- Webbutik: hører nøglen til en butiksforbindelse (shop_connections.php), bestemmer butikkens valg hvad
// ordren bliver til (inc/shop.inc.php) - ellers som altid nedenfor: en kladde i TinyCash' eget format.
require_once __DIR__ . '/inc/shop.inc.php';
if (shop_ready($conn) && ($shop = shop_get_by_key($conn, (string)($key_entry['id'] ?? '')))) {
    $shop_res = shop_handle_request($conn, $shop, (string)file_get_contents('php://input'), function_exists('getallheaders') ? (array)getallheaders() : []);
    http_response_code($shop_res['http']);
    echo json_encode($shop_res['body'], JSON_UNESCAPED_UNICODE);
    exit;
}
// RETTET: en butik sat til "Aktiv: Nej" ("ordrerne afvises", shop_connections.php) blev ikke fundet af
// shop_get_by_key() (kun aktive) - og ordren faldt så igennem til den gamle vej herunder og blev til en kladde
// og evt. en ny kunde. En nøgle der hører til en INAKTIV butik afvises nu.
if (shop_ready($conn) && ($key_id_chk = (string)($key_entry['id'] ?? '')) !== ''
    && DB::fetch_assoc(DB::query($conn, "SELECT shop_id FROM shop_connections WHERE key_id = '" . DB::escape($conn, $key_id_chk) . "' AND active = 0"))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This shop is deactivated in TinyCash - its orders are refused.']);
    exit;
}

// --- 3. Læs + valider payload ----------------------------------------------
$raw     = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing JSON body.']);
    exit;
}

$external_id = isset($payload['external_id']) ? trim((string)$payload['external_id']) : '';
$customer    = $payload['customer'] ?? null;
$lines_in    = $payload['lines'] ?? null;
$note        = isset($payload['note']) ? trim((string)$payload['note']) : '';

if (!is_array($customer) || trim((string)($customer['email'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'customer.email is required.']);
    exit;
}
if (!is_array($lines_in) || count($lines_in) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one line item is required in "lines".']);
    exit;
}

$cust_email = trim((string)$customer['email']);
if (filter_var($cust_email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'customer.email is not a valid email address.']);
    exit;
}

// --- 4. Idempotens: samme external_id afvises (409) med det EKSISTERENDE
// faktura-id i stedet for at oprette en dublet. Stille sprunget over hvis
# kolonnen ikke er migreret endnu (db-setup/migrate_invoice_external_ref.php)
# - samme "stille degradering"-mønster som resten af kodebasen bruger for
# nyere, valgfrie kolonner.
$has_external_ref_col = false;
if (DB::is_sqlite()) {
    $c = DB::query($conn, "PRAGMA table_info(invoices)");
    if ($c) { while ($cr = DB::fetch_assoc($c)) { if ($cr['name'] === 'external_ref') { $has_external_ref_col = true; break; } } }
} else {
    $c = DB::query($conn, "SHOW COLUMNS FROM invoices LIKE 'external_ref'");
    $has_external_ref_col = ($c && DB::num_rows($c) > 0);
}
if ($has_external_ref_col && $external_id !== '') {
    $dup = DB::fetch_assoc(DB::query($conn, "SELECT inv_id FROM invoices WHERE external_ref = '" . DB::escape($conn, $external_id) . "'"));
    if ($dup) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'An invoice for this external_id already exists.', 'invoice_id' => (int)$dup['inv_id']]);
        exit;
    }
}

// --- 5. Varelinjer: KRÆVER kendt produkt-SKU (bruger-valgt) - samme
// vat_rate-opslag (produktets konto) som invoice_edit.php selv bruger. Hele
# kaldet afvises HELT hvis blot ÉN linje ikke kan matches, INGEN delvis
# faktura - samme atomicitets-princip som resten af fakturaflowet.
$resolved_lines = [];
foreach ($lines_in as $i => $l) {
    if (!is_array($l) || trim((string)($l['sku'] ?? '')) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "lines[$i].sku is required."]);
        exit;
    }
    $sku = trim((string)$l['sku']);
    $qty = isset($l['quantity']) ? ((is_int($l['quantity']) || is_float($l['quantity'])) ? (float)$l['quantity'] : parse_dk_number((string)$l['quantity'])) : 0.0;      // RETTET: "2,5" blev til 2
    if (!is_finite($qty) || $qty <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "lines[$i].quantity must be greater than 0."]);
        exit;
    }
    $prod = DB::fetch_assoc(DB::query($conn, "SELECT p.prod_id, p.prod_name, p.prod_price, a.vat_rate
        FROM products p LEFT JOIN accounts a ON p.acc_id = a.acc_id
        WHERE p.prod_sku = '" . DB::escape($conn, $sku) . "'"));
    if (!$prod) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => "lines[$i]: no product found with sku \"$sku\"."]);
        exit;
    }
    $price = isset($l['price_each']) ? ((is_int($l['price_each']) || is_float($l['price_each'])) ? (float)$l['price_each'] : parse_dk_number((string)$l['price_each'])) : (float)$prod['prod_price'];
    $vat   = isset($l['vat_rate'])   ? (float)$l['vat_rate']   : (($prod['vat_rate'] !== null) ? (float)$prod['vat_rate'] : 25.0);
    // RETTET: pris og momssats fra butikken blev ikke kontrolleret - en negativ pris (en "rabatlinje") eller en
    // momssats på fx 250 eller -25 gav en kladde, hvis beløb og moms var forkerte, men så rigtige nok ud til at blive
    // bogført. Et tal der ikke er et tal (NaN/INF) gav en ugyldig SQL.
    if (!is_finite($price) || $price < 0 || !is_finite($vat) || $vat < 0 || $vat > 100) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "lines[$i]: price_each must be 0 or more and vat_rate between 0 and 100."]);
        exit;
    }
    $resolved_lines[] = ['prod_id' => (int)$prod['prod_id'], 'text' => (string)$prod['prod_name'], 'qty' => $qty, 'price' => $price, 'vat' => $vat];
}

// --- 6. Kunde: match på e-mail (case-insensitive), opret automatisk hvis
// ikke fundet (bruger-valgt) ------------------------------------------------
$s                = get_settings($conn);
$default_currency = $s['currency'] ?? 'DKK';
$default_terms    = is_numeric(trim((string)($s['default_payment_terms'] ?? ''))) ? (int)$s['default_payment_terms'] : 8;      // RETTET: feltet er en betalingstekst - (int) af teksten gav 0 dage

// RETTET: kun aktive kunder - ikke en kunde i papirkurven (se inc/shop.inc.php shop_customer())
$cust_row = DB::fetch_assoc(DB::query($conn, "SELECT cust_id, cust_payment_days FROM customers WHERE LOWER(cust_email) = LOWER('" . DB::escape($conn, $cust_email) . "') AND " . customers_active_filter($conn)));
if ($cust_row) {
    $cust_id = (int)$cust_row['cust_id'];
    $terms   = ($cust_row['cust_payment_days'] !== null) ? (int)$cust_row['cust_payment_days'] : $default_terms;
} else {
    $cust_name = trim((string)($customer['name'] ?? ''));
    if ($cust_name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'customer.name is required when the customer does not already exist (matched by email).']);
        exit;
    }
    $db_name      = DB::escape($conn, $cust_name);
    $db_email     = DB::escape($conn, $cust_email);
    $cust_phone   = DB::escape($conn, trim((string)($customer['phone']   ?? '')));
    $cust_address = DB::escape($conn, trim((string)($customer['address'] ?? '')));
    $cust_cvr     = DB::escape($conn, trim((string)($customer['cvr']     ?? '')));
    $ins = DB::query($conn, "INSERT INTO customers (cust_name, cust_email, cust_phone, cust_address, cust_cvr, cust_payment_days)
        VALUES ('$db_name', '$db_email', '$cust_phone', '$cust_address', '$cust_cvr', $default_terms)");
    if (!$ins) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create customer: ' . DB::error($conn)]);
        exit;
    }
    $cust_id = DB::insert_id($conn);
    $terms   = $default_terms;
    log_action($conn, 'CREATE_CUSTOMER_WEBHOOK', 'customers', $cust_id, null, ['cust_name' => $cust_name, 'cust_email' => $cust_email]);
}

// --- 7. Opret selve kladde-fakturaen (samme status/felter som
// invoice_edit.php's egen INSERT-gren for en helt ny faktura) --------------
$inv_date     = date('Y-m-d');
$inv_due_date = date('Y-m-d', strtotime("+{$terms} days"));
$db_note      = DB::escape($conn, $note);
$db_currency  = DB::escape($conn, $default_currency);
$ext_ref_col  = $has_external_ref_col ? ", external_ref" : "";
$ext_ref_val  = $has_external_ref_col ? ($external_id !== '' ? ", '" . DB::escape($conn, $external_id) . "'" : ", NULL") : "";

$ins = DB::query($conn, "INSERT INTO invoices
    (cust_id, inv_date, inv_due_date, inv_status, inv_note, currency$ext_ref_col)
    VALUES ($cust_id, '$inv_date', '$inv_due_date', 'draft', '$db_note', '$db_currency'$ext_ref_val)");
if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create invoice: ' . DB::error($conn)]);
    exit;
}
$inv_id = DB::insert_id($conn);

foreach ($resolved_lines as $rl) {
    $txt = DB::escape($conn, $rl['text']);
    DB::query($conn, "INSERT INTO invoice_lines (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id)
        VALUES ($inv_id, '$txt', {$rl['qty']}, {$rl['price']}, {$rl['vat']}, {$rl['prod_id']})");
}

log_action($conn, 'CREATE_DRAFT_INVOICE_WEBHOOK', 'invoices', $inv_id, null, [
    'cust_id' => $cust_id, 'external_id' => ($external_id !== '' ? $external_id : null), 'webhook_key_label' => $key_entry['label'] ?? null,
]);

http_response_code(201);
echo json_encode(['success' => true, 'invoice_id' => $inv_id, 'status' => 'draft']);
