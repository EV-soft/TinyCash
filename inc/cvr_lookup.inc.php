<?php # /inc/cvr_lookup.inc.php v:1.3.1 d:2026-09-15 i:evs
# NYT (BEK 811/2026-gennemgang, Bilag 3 "låste stamoplysninger", se
# [[digital-standard-bookkeeping-registration-goal]]): selve CVR-opslags-
# logikken (cache/kvote/cvrapi.dk-kald) udtrukket fra cvr_proxy.php til en
# delt funktion, så den nye company_cvr_sync_action.php (server-side,
# AUTORITATIV kilde til egne stamoplysninger) kan genbruge PRÆCIS samme
# cache og kvote-tæller som det eksisterende klient-vendte cvr_proxy.php
# (kunde-/leverandøropslag) - to uafhængige implementationer ville ellers
# kunne dobbelt-tælle/dobbelt-ramme den delte 40/dag-kvote for samme
# CVR-nummer. cvr_proxy.php selv er rettet til en tynd wrapper om denne
# funktion - uændret ekstern HTTP-adfærd/JSON-svar.
function cvr_lookup_by_number($conn, string $cvr): array {
    $cvr = preg_replace('/\D/', '', $cvr);
    if (strlen($cvr) !== 8) {
        return ['success' => false, 'error' => lang('@Please enter a valid 8-digit CVR number.')];
    }

    $cache_dir  = __DIR__ . '/../storage/cvr_cache/';
    $cache_file = $cache_dir . $cvr . '.json';
    $cache_ttl  = 90 * 24 * 3600; // 90 dage - se cvr_proxy.phps oprindelige begrundelse

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached)) return $cached;
    }

    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0775, true);
    $quota_file  = $cache_dir . '_quota.json';
    $today       = date('Y-m-d');
    $quota_limit = 40;
    $quota_ok    = false;

    $fh = @fopen($quota_file, 'c+');
    if ($fh && flock($fh, LOCK_EX)) {
        $raw_quota = stream_get_contents($fh);
        $quota = ['date' => $today, 'count' => 0];
        $saved = json_decode((string)$raw_quota, true);
        if (is_array($saved) && ($saved['date'] ?? '') === $today) $quota = $saved;

        if ($quota['count'] < $quota_limit) {
            $quota['count']++;
            $quota_ok = true;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($quota));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    } else {
        $quota_ok = true;
        if ($fh) fclose($fh);
    }

    if (!$quota_ok) {
        return ['success' => false, 'error' => lang('@Daily CVR lookup quota reached for this installation. Please fill in the details manually, or try again tomorrow.')];
    }

    $s = get_settings($conn);
    $ua_company = trim($s['company_name'] ?? '') ?: 'TinyCash (selvhostet regnskabssystem)';
    $ua_contact = trim($s['company_email'] ?? '') ?: (trim($s['company_phone'] ?? '') ?: 'ingen kontaktoplysning angivet');
    $user_agent = "$ua_company - TinyCash CVR-opslag - $ua_contact";

    $url = 'https://cvrapi.dk/api?search=' . $cvr . '&country=dk';
    $ctx = stream_context_create(['http' => [
        'timeout' => 6,
        'header'  => "User-Agent: " . $user_agent . "\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        return ['success' => false, 'error' => lang('@Could not reach the CVR lookup service. Please try again later or fill in the details manually.')];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || isset($data['error'])) {
        $err_code = $data['error'] ?? '';
        if ($err_code === 'QUOTA_EXCEEDED') {
            $msg = lang('@Daily CVR lookup quota exceeded (free tier: 50/day). Please fill in the details manually, or try again tomorrow.');
        } elseif ($err_code === 'NO_HITS' || $err_code === 'NOT_FOUND') {
            $msg = lang('@No company found for this CVR number.');
        } else {
            $msg = $data['message'] ?? lang('@CVR lookup failed. Please fill in the details manually.');
        }
        return ['success' => false, 'error' => $msg];
    }

    $result = [
        'success' => true,
        'cvr'     => (string)($data['vat'] ?? $cvr),
        'name'    => (string)($data['name'] ?? ''),
        'address' => trim(trim((string)($data['address'] ?? '')) . "\n" . trim(trim((string)($data['zipcode'] ?? '')) . ' ' . trim((string)($data['city'] ?? '')))),
        'street'  => trim((string)($data['address']  ?? '')),
        'zipcode' => trim((string)($data['zipcode'] ?? '')),
        'city'    => trim((string)($data['city']    ?? '')),
        'phone'   => (string)($data['phone'] ?? ''),
        'email'   => (string)($data['email'] ?? ''),
    ];

    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0775, true);
    @file_put_contents($cache_file, json_encode($result, JSON_UNESCAPED_UNICODE));

    return $result;
}
