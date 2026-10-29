<?php # /inc/shop_adapters/prestashop.php v:1.3.1 d:2026-09-15 i:evs
# Adapter "PrestaShop": TinyCash HENTER selv nye ordrer via PrestaShops Webservice-API (PrestaShop sender ikke
# webhooks uden et ekstra modul). Opsætning i PrestaShop: Avanceret -> Webservice -> slå til, tilføj en nøgle med
# GET-ret på orders, customers og addresses. I TinyCash: butikkens adresse (fx https://shop.dk) og nøglen.
# Hentes med knappen "Hent nye ordrer" (shop_connections.php) eller en cron-adresse (webhook_shop.php?..&fetch=..).
#
# Kun BETALTE ordrer (PrestaShops "valid" = 1, dvs. betaling accepteret) bliver til noget. En ordre der venter på
# betaling (fx bankoverførsel), venter også i TinyCash - den hentes igen næste gang, indtil den er betalt (højst 14
# dage, så springes den over). Annullerede / refunderede / mislykkede ordrer springes over.
#
# Ordrens linjer: varelinjer (enhedspris inkl. moms, satsen udledt af inkl./ekskl.), fragt (carrier_tax_rate),
# rabatter (samlet rabatlinje) og gaveindpakning. Kontrol mod total_paid_tax_incl.

const PS_SKIP_STATES = [6, 7, 8];      // annulleret, refunderet, betalingsfejl (PrestaShops standardstatus-id'er)

function shop_adapter_prestashop(string $raw, array $headers, array $shop): array {
    throw new ShopError('PrestaShop orders are fetched by TinyCash ("Fetch new orders") - the shop does not send them.', 400);
}

// GET mod Webservice-API'et -> array (JSON)
function ps_get(array $shop, string $path, array $query = []): array {
    $base = rtrim((string)($shop['api_url'] ?? ''), '/');
    $key  = (string)($shop['api_key'] ?? '');
    if ($base === '' || $key === '') throw new Exception('The PrestaShop address and API key are missing (Web Shops -> edit the shop).');
    if (!preg_match('~^https?://~i', $base)) throw new Exception('The PrestaShop address must start with http:// or https://.');
    $url = $base . '/api/' . ltrim($path, '/') . '?' . http_build_query($query + ['output_format' => 'JSON']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_USERPWD => $key . ':', CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new Exception('PrestaShop could not be reached: ' . $err);
    if ($code === 401) throw new Exception('PrestaShop refused the API key (401) - check the key and that the Webservice is enabled.');
    if ($code === 403) throw new Exception('The API key has no access to "' . strtok($path, '/') . '" (403) - give it GET rights on orders, customers and addresses.');
    if ($code >= 400) throw new Exception("PrestaShop answered $code on $path.");
    $j = json_decode((string)$body, true);
    if (!is_array($j)) {
        if (trim((string)$body) === '' || trim((string)$body) === '[]') return [];
        throw new Exception('PrestaShop did not answer with JSON on ' . $path . ' (is the address right?).');
    }
    return $j;
}

function ps_rate(float $incl, float $excl): float {
    if (abs($excl) < 0.005) return 0.0;
    $r = ($incl / $excl - 1) * 100;
    return abs($r - round($r)) < 0.2 ? (float)round($r) : round($r, 2);
}

// Nye ordrer efter $last_id (stigende): [['remote_id', 'order' => fælles format | null, 'ignore' => grund, 'wait' => bool]]
function shop_pull_prestashop(array $shop, string $last_id, int $limit = 50): array {
    $filter = ((int)$last_id > 0) ? ['filter[id]' => '>[' . (int)$last_id . ']'] : [];
    $j = ps_get($shop, 'orders', $filter + ['display' => 'full', 'sort' => '[id_ASC]', 'limit' => max(1, min(200, $limit))]);
    $orders = (array)($j['orders'] ?? []);
    usort($orders, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $out = [];
    $cust_cache = [];  $addr_cache = [];
    foreach ($orders as $o) {
        $id = (string)(int)$o['id'];
        $state = (int)($o['current_state'] ?? 0);
        $date  = substr((string)($o['date_add'] ?? ''), 0, 10);
        if (in_array($state, PS_SKIP_STATES, true)) { $out[] = ['remote_id' => $id, 'order' => null, 'ignore' => "order $id: status $state (cancelled / refunded / payment error)"];  continue; }
        if ((string)($o['valid'] ?? '0') !== '1') {
            $old = $date !== '' && $date < date('Y-m-d', strtotime('-14 days'));
            $out[] = ['remote_id' => $id, 'order' => null, 'ignore' => "order $id: not paid" . ($old ? ' after 14 days - skipped' : ' yet - waits'), 'wait' => !$old];
            continue;
        }
        $lines = [];
        foreach ((array)($o['associations']['order_rows'] ?? []) as $r) {
            $qty = shop_num($r['product_quantity'] ?? 0);
            if ($qty <= 0) continue;
            $incl = shop_num($r['unit_price_tax_incl'] ?? 0);  $excl = shop_num($r['unit_price_tax_excl'] ?? 0);
            $lines[] = ['type' => 'product', 'sku' => (string)($r['product_reference'] ?? ''), 'text' => (string)($r['product_name'] ?? ''), 'qty' => $qty,
                        'price' => $incl, 'vat_rate' => ps_rate($incl, $excl)];
        }
        $ship = shop_num($o['total_shipping_tax_incl'] ?? 0);
        if ($ship >= 0.005) $lines[] = ['type' => 'shipping', 'text' => 'Fragt', 'qty' => 1, 'price' => $ship,
                                        'vat_rate' => isset($o['carrier_tax_rate']) ? shop_num($o['carrier_tax_rate']) : ps_rate($ship, shop_num($o['total_shipping_tax_excl'] ?? $ship))];
        $wrap = shop_num($o['total_wrapping_tax_incl'] ?? 0);
        if ($wrap >= 0.005) $lines[] = ['type' => 'fee', 'text' => 'Gaveindpakning', 'qty' => 1, 'price' => $wrap, 'vat_rate' => ps_rate($wrap, shop_num($o['total_wrapping_tax_excl'] ?? $wrap))];
        $disc = shop_num($o['total_discounts_tax_incl'] ?? 0);
        if ($disc >= 0.005) $lines[] = ['type' => 'discount', 'text' => 'Rabat', 'qty' => 1, 'price' => $disc, 'vat_rate' => ps_rate($disc, shop_num($o['total_discounts_tax_excl'] ?? $disc))];

        $cid = (int)($o['id_customer'] ?? 0);
        $aid = (int)($o['id_address_invoice'] ?? ($o['id_address_delivery'] ?? 0));
        $did = (int)($o['id_address_delivery'] ?? 0);
        if ($cid && !isset($cust_cache[$cid])) $cust_cache[$cid] = (array)(ps_get($shop, 'customers/' . $cid)['customer'] ?? []);
        foreach ([$aid, $did] as $a) if ($a && !isset($addr_cache[$a])) $addr_cache[$a] = (array)(ps_get($shop, 'addresses/' . $a)['address'] ?? []);
        $c = $cust_cache[$cid] ?? [];  $ai = $addr_cache[$aid] ?? [];  $ad = $addr_cache[$did] ?? [];
        $addr = fn(array $a) => trim(implode(', ', array_filter([trim(($a['address1'] ?? '') . ' ' . ($a['address2'] ?? '')), trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? ''))])));
        $name = trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? ''));
        $company = trim((string)($ai['company'] ?? ($c['company'] ?? '')));
        if ($company !== '') $name = $company . ($name !== '' ? ' (' . $name . ')' : '');
        $out[] = ['remote_id' => $id, 'order' => [
            'kind'             => 'order',
            'external_id'      => $id,
            'date'             => $date,
            'currency'         => null,
            'paid'             => true,
            'prices_incl_vat'  => true,
            'customer'         => ['email' => (string)($c['email'] ?? ''), 'name' => $name, 'phone' => (string)($ai['phone_mobile'] ?? '') ?: (string)($ai['phone'] ?? ''),
                                   'address' => $addr($ai), 'cvr' => (string)($ai['vat_number'] ?? '')],
            'delivery_address' => ($did && $did !== $aid) ? $addr($ad) : '',
            'lines'            => $lines,
            'total'            => shop_num($o['total_paid_tax_incl'] ?? 0),
            'note'             => trim('PrestaShop ' . ($o['reference'] ?? $id) . (!empty($o['payment']) ? ' - ' . $o['payment'] : '')),
        ]];
    }
    return $out;
}
