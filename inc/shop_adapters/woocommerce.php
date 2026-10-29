<?php # /inc/shop_adapters/woocommerce.php v:1.3.1 d:2026-09-15 i:evs
# Adapter "WooCommerce": WooCommerce' EGNE ordre-webhooks (WooCommerce -> Indstillinger -> Avanceret -> Webhooks):
#   Emne: "Ordre oprettet" og "Ordre opdateret" - Leverings-URL: webhook_shop.php?acc=..&shop=.. (vises i
#   shop_connections.php) - Hemmelighed: butikkens hemmelighed fra TinyCash. API-version: WP REST API v3.
# WooCommerce signerer hvert kald (X-WC-Webhook-Signature = base64(HMAC-SHA256(body, hemmelighed))).
#
# Kun BETALTE ordrer bliver til noget (status "processing" / "completed"): en ordre "on-hold" (fx bankoverførsel)
# eller "pending" svares med 200 og venter - WooCommerce sender "Ordre opdateret", når den er betalt, og så kommer den
# ind. Annullerede / mislykkede ordrer ignoreres. En returnering i WooCommerce laves som kreditnota i TinyCash
# (WooCommerce' webhook har ikke returneringens linjer).
#
# WooCommerce' linjebeløb ("total" + "total_tax") er EFTER rabatkoder og altid ekskl. moms - TinyCash får linjens
# pris inkl. moms (total + total_tax) / antal, så linjens brutto passer på øren, og momssatsen udledes af beløbene.

function shop_verify_woocommerce(string $raw, array $h, string $secret): bool {
    return shop_hmac_ok($raw, $secret, (string)($h['x-wc-webhook-signature'] ?? ''));
}

// Momssatsen ud fra netto og moms - afrundet til en hel sats, når den ligger tæt på (25,00 - ikke 24,99)
function shop_rate_from(float $net, float $tax): float {
    if (abs($net) < 0.005) return 0.0;
    $r = $tax / $net * 100;
    return abs($r - round($r)) < 0.2 ? (float)round($r) : round($r, 2);
}

function shop_adapter_woocommerce(string $raw, array $h, array $shop): array {
    $topic = strtolower((string)($h['x-wc-webhook-topic'] ?? ''));
    if ($topic !== '' && !in_array($topic, ['order.created', 'order.updated'], true)) throw new ShopIgnore("Webhook topic $topic is not used by TinyCash.");
    $p = json_decode($raw, true);
    if (!is_array($p) || empty($p['id'])) throw new ShopError('Invalid WooCommerce order JSON.');
    $status = strtolower((string)($p['status'] ?? ''));
    if (in_array($status, ['pending', 'on-hold', 'checkout-draft'], true)) throw new ShopIgnore("Order {$p['id']} is $status (not paid yet) - it comes in when it is paid.");
    if (!in_array($status, ['processing', 'completed'], true)) throw new ShopIgnore("Order {$p['id']} is $status - not booked.");

    $lines = [];
    foreach ((array)($p['line_items'] ?? []) as $li) {
        $qty = shop_num($li['quantity'] ?? 1);
        if ($qty <= 0) continue;
        $net = shop_num($li['total'] ?? 0);  $tax = shop_num($li['total_tax'] ?? 0);
        $lines[] = ['type' => 'product', 'sku' => (string)($li['sku'] ?? ''), 'text' => (string)($li['name'] ?? ''), 'qty' => $qty,
                    'price' => ($net + $tax) / $qty, 'vat_rate' => shop_rate_from($net, $tax)];
    }
    foreach ((array)($p['shipping_lines'] ?? []) as $sl) {
        $net = shop_num($sl['total'] ?? 0);  $tax = shop_num($sl['total_tax'] ?? 0);
        if (abs($net + $tax) < 0.005) continue;
        $lines[] = ['type' => 'shipping', 'text' => 'Fragt ' . trim((string)($sl['method_title'] ?? '')), 'qty' => 1, 'price' => $net + $tax, 'vat_rate' => shop_rate_from($net, $tax)];
    }
    foreach ((array)($p['fee_lines'] ?? []) as $fl) {
        $net = shop_num($fl['total'] ?? 0);  $tax = shop_num($fl['total_tax'] ?? 0);
        if (abs($net + $tax) < 0.005) continue;
        $neg = ($net + $tax) < 0;
        $lines[] = ['type' => $neg ? 'discount' : 'fee', 'text' => trim((string)($fl['name'] ?? '')) ?: 'Gebyr', 'qty' => 1, 'price' => abs($net + $tax), 'vat_rate' => shop_rate_from(abs($net), abs($tax))];
    }
    $b = (array)($p['billing'] ?? []);  $s = (array)($p['shipping'] ?? []);
    $addr = fn(array $a) => trim(implode(', ', array_filter([trim(($a['address_1'] ?? '') . ' ' . ($a['address_2'] ?? '')), trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')), ($a['country'] ?? '') !== 'DK' ? ($a['country'] ?? '') : ''])));
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    if (!empty($b['company'])) $name = $b['company'] . ($name !== '' ? ' (' . $name . ')' : '');
    $cvr = '';
    foreach ((array)($p['meta_data'] ?? []) as $m) if (in_array($m['key'] ?? '', ['_billing_vat', '_billing_vat_number', '_billing_cvr', 'vat_number'], true)) $cvr = (string)$m['value'];
    $ship_addr = $s ? $addr($s) : '';
    return [
        'kind'             => 'order',
        'external_id'      => (string)$p['id'],
        'date'             => substr((string)($p['date_paid'] ?? '') ?: (string)($p['date_created'] ?? ''), 0, 10),
        'currency'         => (string)($p['currency'] ?? '') ?: null,
        'paid'             => true,
        'prices_incl_vat'  => true,
        'customer'         => ['email' => (string)($b['email'] ?? ''), 'name' => $name, 'phone' => (string)($b['phone'] ?? ''), 'address' => $addr($b), 'cvr' => $cvr],
        'delivery_address' => $ship_addr !== $addr($b) ? $ship_addr : '',
        'lines'            => $lines,
        'total'            => shop_num($p['total'] ?? 0),
        'note'             => trim('WooCommerce #' . ($p['number'] ?? $p['id']) . (!empty($p['payment_method_title']) ? ' - ' . $p['payment_method_title'] : '') . (!empty($p['customer_note']) ? "\n" . $p['customer_note'] : '')),
    ];
}
