<?php # /inc/registry_nordic.inc.php v:1.3.1 d:2026-09-15 i:evs
// Firmaopslag i de nordiske registre (landepakkens company_id.registry): Norge = Brønnøysundregistrene
// (data.brreg.no), Finland = PRH/YTJ (avoindata.prh.fi). Begge er åbne og uden nøgle. Svaret har samme form
// som cvr_lookup_by_number(), så kunde-, leverandør- og firmakortet kan bruge det uændret. Svar caches 90 dage.

// Kontrolcifre: Norge mod 11 (vægt 3,2,7,6,5,4,3,2), Finland Y-tunnus (vægt 7,9,10,5,8,4,2), Sverige Luhn, Island kennitala,
// Belgien mod 97, Estland/Litauen/Letland mod 11, Polen NIP
function tc_company_id_valid(string $id, ?string $scheme = null): bool {
    $scheme = $scheme ?? (string)tc_country_pack('company_id.checksum', '');
    $d = preg_replace('/\D/', '', $id);
    switch ($scheme) {
        case 'no_mod11':
            if (strlen($d) !== 9) return false;
            $w = [3, 2, 7, 6, 5, 4, 3, 2];  $sum = 0;
            for ($i = 0; $i < 8; $i++) $sum += (int)$d[$i] * $w[$i];
            $c = 11 - $sum % 11;
            return $c !== 10 && ($c === 11 ? 0 : $c) === (int)$d[8];
        case 'fi_ytunnus':
            if (strlen($d) === 7) $d = '0' . $d;      // (gamle numre har kun 6 cifre før stregen)
            if (strlen($d) !== 8) return false;
            $w = [7, 9, 10, 5, 8, 4, 2];  $sum = 0;
            for ($i = 0; $i < 7; $i++) $sum += (int)$d[$i] * $w[$i];
            $r = $sum % 11;
            return $r !== 1 && ($r === 0 ? 0 : 11 - $r) === (int)$d[7];
        case 'se_luhn':
            if (strlen($d) === 12) $d = substr($d, 2);      // (personnummer med århundrede)
            return strlen($d) === 10 && tc_luhn_digit(substr($d, 0, 9)) === (int)$d[9];
        case 'is_kennitala':
            // 10 cifre DDMMÅÅ-NNKÅ: kontrolciffer på plads 9 (vægt 3,2,7,6,5,4,3,2 - mod 11), plads 10 = århundrede (9/0)
            if (strlen($d) !== 10 || !in_array($d[9], ['8', '9', '0'], true)) return false;
            $w = [3, 2, 7, 6, 5, 4, 3, 2];  $sum = 0;
            for ($i = 0; $i < 8; $i++) $sum += (int)$d[$i] * $w[$i];
            $c = 11 - $sum % 11;
            return $c !== 10 && ($c === 11 ? 0 : $c) === (int)$d[8];
        case 'be_mod97':
            // Belgisk ondernemingsnummer: 10 cifre der starter med 0 eller 1; de sidste to = 97 - (de første 8 mod 97)
            if (strlen($d) === 9) $d = '0' . $d;      // (gamle numre uden det foranstillede 0)
            if (strlen($d) !== 10 || !in_array($d[0], ['0', '1'], true)) return false;
            return 97 - ((int)substr($d, 0, 8) % 97) === (int)substr($d, 8, 2);
        case 'ee_mod11':
        case 'lt_mod11':
            // Estisk registrikood (8 cifre) / litauisk įmonės kodas (9 cifre): vægt 1,2,3... og ved rest 10 vægt 3,4,5...
            $n = $scheme === 'ee_mod11' ? 8 : 9;
            if (strlen($d) !== $n) return false;
            $mod = function (int $start) use ($d, $n) { $sum = 0; for ($i = 0; $i < $n - 1; $i++) $sum += (int)$d[$i] * (($start + $i - 1) % 9 + 1); return $sum % 11; };
            $c = $mod(1);
            if ($c === 10) { $c = $mod(3);  if ($c === 10) $c = 0; }
            return $c === (int)$d[$n - 1];
        case 'lv_mod11':
            // Lettisk registreringsnummer for en juridisk person (11 cifre, starter med 4-9): vægt 9,1,4,8,3,10,2,5,7,6,1 - summen mod 11 = 3
            if (strlen($d) !== 11 || (int)$d[0] < 4) return false;
            $w = [9, 1, 4, 8, 3, 10, 2, 5, 7, 6, 1];  $sum = 0;
            for ($i = 0; $i < 11; $i++) $sum += (int)$d[$i] * $w[$i];
            return $sum % 11 === 3;
        case 'pl_nip':
            // Polsk NIP (10 cifre): vægt 6,5,7,2,3,4,5,6,7; sum mod 11 = sidste ciffer (10 er ugyldigt)
            if (strlen($d) !== 10) return false;
            $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];  $sum = 0;
            for ($i = 0; $i < 9; $i++) $sum += (int)$d[$i] * $w[$i];
            return $sum % 11 !== 10 && $sum % 11 === (int)$d[9];
        case 'dk_mod11':
            if (strlen($d) !== 8) return false;
            $w = [2, 7, 6, 5, 4, 3, 2, 1];  $sum = 0;
            for ($i = 0; $i < 8; $i++) $sum += (int)$d[$i] * $w[$i];
            return $sum % 11 === 0;
    }
    return true;      // (ingen kontrol kendt for landet)
}

// Fælles HTTP + cache. $parse(array $json): ?array giver resultatet, null = ikke fundet.
function tc_registry_fetch(string $key, string $url, callable $parse): array {
    $cache_dir  = __DIR__ . '/../storage/cvr_cache/';
    $cache_file = $cache_dir . preg_replace('/[^a-z0-9_]/', '', $key) . '.json';
    if (is_file($cache_file) && time() - filemtime($cache_file) < 90 * 24 * 3600) {
        $c = json_decode((string)file_get_contents($cache_file), true);
        if (is_array($c)) return $c;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: TinyCash (self-hosted bookkeeping)\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    if ($raw === false || $status >= 500 || $status === 0) {
        return ['success' => false, 'error' => lang('@Could not reach the company register. Please try again later or fill in the details manually.')];
    }
    $data = json_decode($raw, true);
    $res = ($status === 200 && is_array($data)) ? $parse($data) : null;
    if (!$res) return ['success' => false, 'error' => lang('@No company found with this number.')];
    $res = ['success' => true] + $res;
    $res['address'] = trim($res['street'] . "\n" . trim($res['zipcode'] . ' ' . $res['city']));
    $res += ['phone' => '', 'email' => ''];
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0775, true);
    @file_put_contents($cache_file, json_encode($res, JSON_UNESCAPED_UNICODE));
    return $res;
}

function tc_registry_no_brreg(string $id): array {
    $d = preg_replace('/\D/', '', $id);
    if (!tc_company_id_valid($d, 'no_mod11')) return ['success' => false, 'error' => lang('@Please enter a valid organisation number.')];
    return tc_registry_fetch('no_' . $d, 'https://data.brreg.no/enhetsregisteret/api/enheter/' . $d, function (array $j) use ($d) {
        if (empty($j['navn'])) return null;
        $a = $j['forretningsadresse'] ?? $j['postadresse'] ?? [];
        return ['cvr' => $d, 'name' => (string)$j['navn'], 'street' => trim(implode(', ', (array)($a['adresse'] ?? []))),
                'zipcode' => (string)($a['postnummer'] ?? ''), 'city' => (string)($a['poststed'] ?? ''), 'country' => (string)($a['landkode'] ?? 'NO')];
    });
}

function tc_registry_fi_prh(string $id): array {
    $d = preg_replace('/\D/', '', $id);
    if (strlen($d) === 7) $d = '0' . $d;
    if (!tc_company_id_valid($d, 'fi_ytunnus')) return ['success' => false, 'error' => lang('@Please enter a valid business ID (Y-tunnus).')];
    $bid = substr($d, 0, 7) . '-' . $d[7];
    return tc_registry_fetch('fi_' . $d, 'https://avoindata.prh.fi/opendata-ytj-api/v3/companies?businessId=' . $bid, function (array $j) use ($bid) {
        $co = $j['companies'][0] ?? null;
        if (!is_array($co)) return null;
        // Gældende navn (uden slutdato, type 1 = firmanavn); adresse: besøgsadresse (type 1) før postadresse
        $name = '';
        foreach ((array)($co['names'] ?? []) as $n) if (empty($n['endDate']) && (string)($n['type'] ?? '1') === '1') { $name = (string)$n['name'];  break; }
        if ($name === '') return null;
        $addrs = (array)($co['addresses'] ?? []);
        usort($addrs, fn($a, $b) => (int)($a['type'] ?? 9) <=> (int)($b['type'] ?? 9));
        $a = $addrs[0] ?? [];
        $street = trim(implode(' ', array_filter([(string)($a['street'] ?? ''), (string)($a['buildingNumber'] ?? ''), (string)($a['entrance'] ?? ''), (string)($a['apartmentNumber'] ?? '')])));
        if ($street === '' && !empty($a['postOfficeBox'])) $street = 'PL ' . $a['postOfficeBox'];
        $city = '';
        foreach ((array)($a['postOffices'] ?? []) as $po) { $city = (string)($po['city'] ?? '');  if ((string)($po['languageCode'] ?? '') === '1') break; }
        return ['cvr' => $bid, 'name' => $name, 'street' => $street, 'zipcode' => (string)($a['postCode'] ?? ''), 'city' => $city, 'country' => 'FI'];
    });
}
