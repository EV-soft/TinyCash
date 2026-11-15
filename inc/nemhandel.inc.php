<?php # /inc/nemhandel.inc.php v:1.3.1 d:2026-09-15 i:evs
// Opslag i Nemhandelsregistret (Erhvervsstyrelsen): kan en modtager få e-fakturaer via Nemhandel? Bruger den offentlige
// opslagsside (registration.nemhandel.dk, "kræver ikke login") - registret har ingen offentlig maskine-API til opslag
// (PoRS2-API'et er til administration og kræver klientcertifikat). Svaret caches i 7 dage, og en ændret side giver
// "ukendt", aldrig et forkert ja. Afsendelse via Nemhandel kræver en access point-udbyder og er ikke bygget.

const NEMHANDEL_LOOKUP_URL = 'https://registration.nemhandel.dk/NemHandelRegisterWeb/public/participant/info';
const NEMHANDEL_CACHE_DAYS = 7;

// Rå HTML -> ['found' => bool, 'name', 'invoice' => kan modtage fakturaer, 'profiles' => [[rolle, profil, standard]]],
// eller null hvis siden ikke kan læses (ukendt format)
function nemhandel_parse(string $html): ?array {
    if (stripos($html, 'participantinfosearch') === false) return null;
    $t = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', preg_replace('#<(br|/?(div|td|th|tr|p|li|h\d|span|a|dt|dd))\b[^>]*>#i', "\n", $html))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $t)), 'strlen'));
    $start = null;
    foreach ($lines as $i => $l) if (stripos($l, 'Supported profiles for') === 0) { $start = $i;  break; }
    if ($start === null) return ['found' => false, 'name' => '', 'invoice' => false, 'profiles' => []];
    $profiles = [];
    for ($i = $start + 1; $i < count($lines) - 2; $i++) {
        if (stripos($lines[$i], 'Last update') === 0) break;
        if (in_array($lines[$i], ['Customer', 'Supplier'], true) && $lines[$i + 1] !== 'Profile') $profiles[] = [$lines[$i], $lines[$i + 1], $lines[$i + 2]];
    }
    // Fakturaer modtages af rollen "Customer" med en profil der omfatter fakturering (OIOUBL "BilSim"/"Bil...", Peppol billing)
    $invoice = false;
    foreach ($profiles as [$role, $prof]) if ($role === 'Customer' && preg_match('/bil|billing|invoice/i', $prof)) $invoice = true;
    return ['found' => true, 'name' => (string)($lines[$start + 1] ?? ''), 'invoice' => $invoice, 'profiles' => $profiles];
}

// $type: 'GLN' (EAN), 'DK:CVR', 'DK:SE' eller 'DK:P'. ['success' => bool, + nemhandel_parse()-felterne, 'checked' => tidspunkt]
function nemhandel_lookup(string $type, string $id): array {
    $id = preg_replace('/\D/', '', $id);
    $ok = ['GLN' => 13, 'DK:CVR' => 8, 'DK:SE' => 8, 'DK:P' => 10];
    if (!isset($ok[$type]) || strlen($id) !== $ok[$type]) return ['success' => false, 'error' => 'invalid id'];
    $dir = __DIR__ . '/../storage/nemhandel_cache/';
    $file = $dir . strtolower(str_replace(':', '_', $type)) . '_' . $id . '.json';
    if (is_file($file) && time() - filemtime($file) < NEMHANDEL_CACHE_DAYS * 86400) {
        $c = json_decode((string)file_get_contents($file), true);
        if (is_array($c)) return $c;
    }
    $url = NEMHANDEL_LOOKUP_URL . '?' . http_build_query(['keytype' => $type, 'key' => $id, 'lang' => 'en']);
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "User-Agent: TinyCash (self-hosted bookkeeping)\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    $res = ($raw !== false && $status === 200) ? nemhandel_parse($raw) : null;
    if ($res === null) return ['success' => false, 'error' => 'unreachable'];
    $res = ['success' => true, 'type' => $type, 'id' => $id, 'checked' => date('Y-m-d H:i')] + $res;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($file, json_encode($res, JSON_UNESCAPED_UNICODE));
    return $res;
}

// Kundens Nemhandel-id: EAN (offentlige kunder) før CVR. [type, id] eller null
function nemhandel_customer_key(array $cust): ?array {
    $ean = preg_replace('/\D/', '', (string)($cust['cust_ean'] ?? ''));
    if (strlen($ean) === 13) return ['GLN', $ean];
    $cvr = preg_replace('/\D/', '', (string)($cust['cust_cvr'] ?? ''));
    $cc = strtoupper(trim((string)($cust['cust_country'] ?? '')));
    if (strlen($cvr) === 8 && ($cc === '' || $cc === 'DK')) return ['DK:CVR', $cvr];
    return null;
}

// Statuslinjen ("Kan modtage e-faktura via Nemhandel" ...) som udfyldes af assets/tc-nemhandel.js; '' uden dansk e-faktura
function htm_nemhandel_status(int $cust_id, string $styl = ''): string {
    static $js = false;
    if ($cust_id <= 0 || !tc_pack_feature('oioubl')) return '';
    $a = fn(string $k, string $t) => ' data-nh-' . $k . '="' . htmlspecialchars(lang($t), ENT_QUOTES) . '"';
    $h = '<span data-nh-cust="' . $cust_id . '" style="display:none; font-size:0.85em; ' . htmlspecialchars($styl, ENT_QUOTES) . '"'
       . $a('yes', '@✔ Can receive e-invoices via Nemhandel') . $a('noinv', '@Registered in Nemhandel, but not for invoices')
       . $a('no', '@Not registered in Nemhandel - send the invoice as PDF') . $a('nokey', '@No CVR or EAN number - Nemhandel cannot be checked')
       . $a('err', '@Nemhandel could not be checked right now') . '></span>';
    if (!$js) { $h .= '<script src="' . tc_asset('assets/tc-nemhandel.js') . '"></script>';  $js = true; }
    return $h;
}
