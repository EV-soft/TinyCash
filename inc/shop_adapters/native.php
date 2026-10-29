<?php # /inc/shop_adapters/native.php v:1.3.1 d:2026-09-15 i:evs
# Adapter "TinyCash JSON": TinyCash' eget format (det webhook_invoice.php altid har taget imod) - udvidet med
# returneringer, linjetyper uden varenummer, priser inkl. moms, betalt-flag, dato og total. Bagudkompatibel:
# de gamle felter (sku, quantity, price_each, vat_rate) virker uændret.
#
# {
#   "type": "order",                       // eller "refund" (så: "refund_of": "<ordrenummeret>")
#   "external_id": "SHOP-12345",           // påkrævet: butikkens ordre-/returneringsnummer (et gentaget kald afvises)
#   "date": "2026-10-27",                  // valgfri - ellers i dag
#   "paid": true,                          // valgfri - ellers butikkens valg "betalt ved bestilling"
#   "prices_include_vat": true,            // valgfri - ellers butikkens valg
#   "currency": "DKK",                     // valgfri - kun regnskabets valuta understøttes
#   "customer": {"email": "...", "name": "...", "phone": "...", "address": "...", "cvr": "..."},
#   "delivery_address": "...",
#   "lines": [
#     {"sku": "GLASSKO-1", "quantity": 2, "price": 625.00},               // vare - pris/moms fra produktet hvis de mangler
#     {"type": "shipping", "text": "Fragt GLS", "price": 49.00},
#     {"type": "discount", "text": "Rabatkode JUL10", "price": 50.00},    // trækkes fra
#     {"type": "fee", "text": "Gebyr", "price": 10.00, "vat_rate": 25}
#   ],
#   "total": 1874.00,                       // valgfri kontrol (inkl. moms) - op til 1 kr forskel = øreafrunding
#   "note": "..."
# }

// RETTET: (float) af et beløb sendt som TEKST med decimalkomma ("12,50") gav 12 - ørerne forsvandt stille - og
// (bool) af teksten "false" gav SAND (ordren blev registreret som betalt). Tal og ja/nej læses nu robust.
// shop_num() / shop_bool() bor nu i inc/shop.inc.php (fælles for alle adaptere)

function shop_adapter_native(string $raw, array $headers, array $shop): array {
    $p = json_decode($raw, true);
    if (!is_array($p)) throw new ShopError('Invalid or missing JSON body.');
    $lines = [];
    foreach ((array)($p['lines'] ?? []) as $i => $l) {
        if (!is_array($l)) throw new ShopError("lines[$i] must be an object.");
        $lines[] = [
            'type'     => (string)($l['type'] ?? 'product'),
            'sku'      => (string)($l['sku'] ?? ''),
            'text'     => (string)($l['text'] ?? ''),
            'qty'      => shop_num($l['quantity'] ?? $l['qty'] ?? 1),
            'price'    => isset($l['price']) ? shop_num($l['price']) : (isset($l['price_each']) ? shop_num($l['price_each']) : null),
            'vat_rate' => isset($l['vat_rate']) ? shop_num($l['vat_rate']) : null,
        ];
        if ($lines[$i]['price'] === null) unset($lines[$i]['price']);
        if ($lines[$i]['vat_rate'] === null) unset($lines[$i]['vat_rate']);
    }
    return [
        'kind'             => (($p['type'] ?? 'order') === 'refund') ? 'refund' : 'order',
        'external_id'      => trim((string)($p['external_id'] ?? '')),
        'order_ref'        => trim((string)($p['refund_of'] ?? '')),
        'date'             => (string)($p['date'] ?? ''),
        'currency'         => isset($p['currency']) ? (string)$p['currency'] : null,
        'paid'             => isset($p['paid']) ? shop_bool($p['paid']) : null,
        'prices_incl_vat'  => isset($p['prices_include_vat']) ? shop_bool($p['prices_include_vat']) : null,
        'customer'         => is_array($p['customer'] ?? null) ? $p['customer'] : [],
        'delivery_address' => (string)($p['delivery_address'] ?? ''),
        'lines'            => $lines,
        'total'            => isset($p['total']) ? shop_num($p['total']) : null,
        'note'             => (string)($p['note'] ?? ''),
    ];
}
