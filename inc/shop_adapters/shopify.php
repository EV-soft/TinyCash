<?php # /inc/shop_adapters/shopify.php v:1.3.1 d:2026-09-15 i:evs
# Adapter "Shopify": Shopifys EGNE webhooks (Shopify admin -> Indstillinger -> Notifikationer -> Webhooks, eller en
# app): "Ordrebetaling" (orders/paid) og "Refusion oprettet" (refunds/create) - format JSON - URL:
# webhook_shop.php?acc=..&shop=.. (vises i shop_connections.php). Hemmeligheden: den Shopify viser under webhooks
# ("Dine webhooks signeres med ...") indsættes i TinyCash som butikkens hemmelighed.
# Shopify signerer hvert kald (X-Shopify-Hmac-Sha256 = base64(HMAC-SHA256(body, hemmelighed))).
#
# Kun BETALTE ordrer bliver til noget (financial_status "paid" / "partially_refunded" / "refunded"): kommer
# "orders/create" for en ordre der ikke er betalt, svares med 200 - "orders/paid" kommer, når den er det.
# En refusion (refunds/create) bliver en kreditnota / en negativ dagspost - linjerne findes i den oprindelige ordre,
# som TinyCash har gemt, så prisen og momsen er præcis den kunden betalte.

function shop_verify_shopify(string $raw, array $h, string $secret): bool {
    return shop_hmac_ok($raw, $secret, (string)($h['x-shopify-hmac-sha256'] ?? ''));
}

function shopify_rate(array $tax_lines): float {
    foreach ($tax_lines as $t) if (isset($t['rate'])) return round((float)$t['rate'] * 100, 2);
    return 0.0;
}

function shop_adapter_shopify(string $raw, array $h, array $shop): array {
    $topic = strtolower((string)($h['x-shopify-topic'] ?? ''));
    $p = json_decode($raw, true);
    if (!is_array($p)) throw new ShopError('Invalid Shopify JSON.');
    if ($topic === 'refunds/create' || (isset($p['order_id']) && isset($p['refund_line_items']))) return shopify_refund($p, $shop);
    if ($topic !== '' && !in_array($topic, ['orders/create', 'orders/paid', 'orders/updated'], true)) throw new ShopIgnore("Webhook topic $topic is not used by TinyCash.");
    if (empty($p['id'])) throw new ShopError('Invalid Shopify order JSON.');
    $fs = strtolower((string)($p['financial_status'] ?? ''));
    if (in_array($fs, ['pending', 'authorized', 'partially_paid'], true)) throw new ShopIgnore("Order {$p['id']} is $fs (not paid yet) - it comes in with orders/paid.");
    if (!in_array($fs, ['paid', 'partially_refunded', 'refunded'], true)) throw new ShopIgnore("Order {$p['id']} is $fs - not booked.");
    if (!empty($p['cancelled_at'])) throw new ShopIgnore("Order {$p['id']} is cancelled - not booked.");

    $incl  = !empty($p['taxes_included']);
    $lines = [];
    foreach ((array)($p['line_items'] ?? []) as $li) {
        $qty = shop_num($li['quantity'] ?? 1);
        if ($qty <= 0) continue;
        $disc = 0.0;
        foreach ((array)($li['discount_allocations'] ?? []) as $d) $disc += shop_num($d['amount'] ?? 0);
        if (empty($li['discount_allocations'])) $disc = shop_num($li['total_discount'] ?? 0);
        $lines[] = ['type' => 'product', 'sku' => (string)($li['sku'] ?? ''), 'text' => trim((string)($li['title'] ?? '') . (!empty($li['variant_title']) ? ' - ' . $li['variant_title'] : '')),
                    'qty' => $qty, 'price' => (shop_num($li['price'] ?? 0) * $qty - $disc) / $qty, 'vat_rate' => shopify_rate((array)($li['tax_lines'] ?? []))];
    }
    foreach ((array)($p['shipping_lines'] ?? []) as $sl) {
        $price = shop_num($sl['discounted_price'] ?? ($sl['price'] ?? 0));
        if (abs($price) < 0.005) continue;
        $lines[] = ['type' => 'shipping', 'text' => 'Fragt ' . trim((string)($sl['title'] ?? '')), 'qty' => 1, 'price' => $price, 'vat_rate' => shopify_rate((array)($sl['tax_lines'] ?? []))];
    }
    $c  = (array)($p['customer'] ?? []);
    $ba = (array)($p['billing_address'] ?? []);  $sa = (array)($p['shipping_address'] ?? []);
    $addr = fn(array $a) => trim(implode(', ', array_filter([trim(($a['address1'] ?? '') . ' ' . ($a['address2'] ?? '')), trim(($a['zip'] ?? '') . ' ' . ($a['city'] ?? '')), ($a['country_code'] ?? 'DK') !== 'DK' ? ($a['country'] ?? '') : ''])));
    $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: trim((string)($ba['name'] ?? ''));
    if (!empty($ba['company'])) $name = $ba['company'] . ($name !== '' ? ' (' . $name . ')' : '');
    $ship = $sa ? $addr($sa) : '';
    return [
        'kind'             => 'order',
        'external_id'      => (string)$p['id'],
        'date'             => substr((string)($p['processed_at'] ?? '') ?: (string)($p['created_at'] ?? ''), 0, 10),
        'currency'         => (string)($p['currency'] ?? '') ?: null,
        'paid'             => true,
        'prices_incl_vat'  => $incl,
        'customer'         => ['email' => (string)($p['email'] ?? ($c['email'] ?? '')), 'name' => $name, 'phone' => (string)($c['phone'] ?? ($ba['phone'] ?? '')), 'address' => $addr($ba)],
        'delivery_address' => $ship !== $addr($ba) ? $ship : '',
        'lines'            => $lines,
        'total'            => shop_num($p['total_price'] ?? 0),
        'note'             => trim('Shopify ' . ($p['name'] ?? $p['id']) . (!empty($p['note']) ? "\n" . $p['note'] : '')),
    ];
}

// Refusion: linjerne slås op i den oprindelige ordre (TinyCash' gemte kopi) - samme pris og moms som kunden betalte
function shopify_refund(array $p, array $shop): array {
    global $conn;
    $order_id = (string)($p['order_id'] ?? '');
    if ($order_id === '' || empty($p['id'])) throw new ShopError('Invalid Shopify refund JSON.');
    $orig = DB::fetch_assoc(DB::query($conn, "SELECT payload FROM shop_orders WHERE shop_id = " . (int)$shop['shop_id'] . " AND kind = 'order' AND external_id = '" . DB::escape($conn, $order_id) . "'"));
    if (!$orig) throw new ShopError("The order $order_id is not known in TinyCash - a refund needs the order first.", 404);
    $o = json_decode((string)$orig['payload'], true) ?: [];
    $olines = (array)($o['lines'] ?? []);
    $lines = [];
    foreach ((array)($p['refund_line_items'] ?? []) as $rli) {
        $li  = (array)($rli['line_item'] ?? []);
        $qty = shop_num($rli['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $hit = null;
        foreach ($olines as $ol) {
            if (($ol['type'] ?? 'product') !== 'product') continue;
            if (($li['sku'] ?? '') !== '' ? ($ol['sku'] ?? '') === $li['sku'] : mb_strtolower((string)($ol['text'] ?? '')) === mb_strtolower(trim((string)($li['title'] ?? '')))) { $hit = $ol;  break; }
        }
        if (!$hit) throw new ShopError('A refunded line "' . ($li['title'] ?? '?') . "\" is not in order $order_id.", 422);
        $lines[] = ['type' => 'product', 'sku' => (string)($hit['sku'] ?? ''), 'text' => (string)$hit['text'], 'qty' => $qty, 'price' => (float)$hit['price'], 'vat_rate' => (float)$hit['vat_rate']];
    }
    foreach ((array)($p['order_adjustments'] ?? []) as $adj) {
        if (($adj['kind'] ?? '') !== 'shipping_refund') continue;
        foreach ($olines as $ol) if (($ol['type'] ?? '') === 'shipping') { $lines[] = $ol;  break; }        // (hele fragten tilbage)
    }
    if (!$lines) throw new ShopIgnore("Refund {$p['id']} has no lines (only an amount) - make the credit note in TinyCash.");
    $paid = false;
    foreach ((array)($p['transactions'] ?? []) as $t) if (($t['kind'] ?? '') === 'refund' && ($t['status'] ?? 'success') === 'success') $paid = true;
    return [
        'kind' => 'refund', 'external_id' => (string)$p['id'], 'order_ref' => $order_id,
        'date' => substr((string)($p['processed_at'] ?? '') ?: (string)($p['created_at'] ?? ''), 0, 10),
        'currency' => null, 'paid' => $paid, 'prices_incl_vat' => $o['prices_incl_vat'] ?? null,
        'customer' => (array)($o['customer'] ?? []), 'lines' => $lines, 'total' => null,
        'note' => trim('Shopify refusion ' . $p['id'] . (!empty($p['note']) ? ' - ' . $p['note'] : '')),
    ];
}
