<?php # /cvr_proxy.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: CVR-opslag (bruger-anmodet, fra "hvilke funktioner mangler
# TinyCash"-gennemgangen: "ingen automatisk udfyldning af kundeoplysninger
# fra et CVR-nummer"). Slår et CVR-nummer op via cvrapi.dk - et gratis,
# offentligt tilgængeligt API der IKKE kræver registrering/nøgle, kun et
# rigtigt, identificerende User-Agent (se https://cvrapi.dk/documentation -
# "Venligst ikke brug standard useragent", ellers INVALID_UA). Samme
# "ingen nøgle nødvendig"-princip som currency_proxy.php's frankfurter.app-
# integration. Cacher hvert opslag lokalt i storage/cvr_cache/ (uden for
# web-roden, samme deny-all-.htaccess som resten af storage/) - tjenesten
# tillader kun 50 gratis opslag/dag/IP, og et CVR-nummers stamdata (navn/
# adresse) skifter sjældent, så et allerede opslået nummer skal ikke bruge
# af kvoten igen med det samme.
#
# NYT (§bugs-batch-28-review): fund #3 - dette endpoint havde INGEN egen
# gennemtænkt beskyttelse mod at opbruge den delte dagskvote. Enhver logget
# ind bruger (uanset niveau - siden har intet $rLev) kunne før tømme hele
# installationens 50/dag ved blot at slå 50 forskellige, ikke-cachede CVR-
# numre op i træk (nysgerrighed, en fejlbehæftet CSV-import med forkerte
# CVR-numre, eller ondsindet) - og dermed stille ødelægge funktionen for
# resten af virksomheden resten af dagen, uden nogen advarsel undervejs, kun
# cvrapi.dk's egen uspecifikke QUOTA_EXCEEDED når kvoten allerede var brugt
# op. Tilføjet en lokal daglig tæller (samme fil-baserede mønster som
# cachen selv) der stopper UDGÅENDE kald til den tredjeparts-tjeneste ved 40
# (ikke 50) - giver et sikkerhedsmargin, og fejler med en klar, tidlig
# besked om selv at udfylde felterne, i stedet for stille at bruge kvoten
# helt op og risikere gentagne QUOTA_EXCEEDED/BANNED-svar fra tjenesten selv.
#
# v1.3.1 (§bugs-batch-29-review): SELVFUNDET FUND - selve kvote-tælleren fra
# ovenstående rettelse var en klassisk læs-så-skriv-race, IKKE atomisk. To
# næsten-samtidige forespørgsler (to faner, eller flere brugere) kunne begge
# læse count=39 FØR nogen af dem nåede at skrive 40 tilbage - begge ville så
# tro sig under grænsen, begge kalde det eksterne API, og kun ét af de to
# skriv ville "vinde" (den anden overskrives), så tælleren reelt kun steg
# med 1 selvom to kald blev foretaget. Under normal, lav samtidighed er
# konsekvensen lille (højst nogle få kald ud over grænsen), men princippet
# er det samme som enhver anden kapløbsrettelse denne session - løst med en
# ægte fil-lås (flock), som holder læs+tjek+skriv sammen som ÉN atomisk
# operation, i stedet for tre separate trin.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

header('Content-Type: application/json; charset=utf-8');

$cvr = preg_replace('/\D/', '', $_GET['cvr'] ?? '');
if (strlen($cvr) !== 8) {
    echo json_encode(['success' => false, 'error' => lang('@Please enter a valid 8-digit CVR number.')]);
    exit;
}

$cache_dir  = __DIR__ . '/storage/cvr_cache/';
$cache_file = $cache_dir . $cvr . '.json';
$cache_ttl  = 90 * 24 * 3600; // 90 dage - CVR-registrerede stamdata skifter sjældent, og kvoten er kun 50/dag/IP

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    echo file_get_contents($cache_file);
    exit;
}

// Daglig lokal kvote-vagt, FØR vi overhovedet rammer det eksterne API - se
// begrundelsen øverst i filen. Kun cache-MISSES tæller (et cache-hit ovenfor
// er allerede returneret og bruger ikke af kvoten). Læs+tjek+skriv holdes
// sammen som ÉN atomisk operation via en ægte fil-lås (flock) - se
// §bugs-batch-29-review ovenfor for hvorfor et simpelt læs-så-skriv var en race.
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
    // Kunne ikke låse filen (fx et skrivebeskyttet filsystem) - fejler åbent
    // (tillader kaldet) frem for at blokere funktionen helt pga. et lokalt
    // filsystemproblem; den eksterne tjenestes egen QUOTA_EXCEEDED er stadig
    // det yderste sikkerhedsnet.
    $quota_ok = true;
    if ($fh) fclose($fh);
}

if (!$quota_ok) {
    echo json_encode(['success' => false, 'error' => lang('@Daily CVR lookup quota reached for this installation. Please fill in the details manually, or try again tomorrow.')]);
    exit;
}

// cvrapi.dk kræver et RIGTIGT, identificerende User-Agent (firmanavn +
// projektnavn + kontaktoplysning) - bygget dynamisk fra Firmaindstillinger,
// så det er den faktiske installations egne oplysninger, ikke en hårdkodet
// streng delt af alle TinyCash-installationer (som i teorien kunne udløse
// BANNED, hvis den blev misbrugt af mange installationer under samme navn -
// kvoten er ganske vist pr. IP, ikke pr. User-Agent, men en reel
// identifikation er selve API'ets brugsbetingelse, ikke bare et høfligt tip).
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
    echo json_encode(['success' => false, 'error' => lang('@Could not reach the CVR lookup service. Please try again later or fill in the details manually.')]);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || isset($data['error'])) {
    // cvrapi.dk's fejlformat: {"error": "NOT_FOUND"/"QUOTA_EXCEEDED"/..., "message": "..."}
    $err_code = $data['error'] ?? '';
    if ($err_code === 'QUOTA_EXCEEDED') {
        $msg = lang('@Daily CVR lookup quota exceeded (free tier: 50/day). Please fill in the details manually, or try again tomorrow.');
    } elseif ($err_code === 'NO_HITS' || $err_code === 'NOT_FOUND') {
        $msg = lang('@No company found for this CVR number.');
    } else {
        $msg = $data['message'] ?? lang('@CVR lookup failed. Please fill in the details manually.');
    }
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Normaliseret svar til klienten - kun de felter customer_edit.php/
// supplier_edit.php reelt bruger, uafhængigt af cvrapi.dk's fulde skema
// (som bl.a. også indeholder produktionsenheder, branchekode, ejere osv.,
// ikke relevant her).
$result = [
    'success' => true,
    'cvr'     => (string)($data['vat'] ?? $cvr),
    'name'    => (string)($data['name'] ?? ''),
    'address' => trim(trim((string)($data['address'] ?? '')) . "\n" . trim(trim((string)($data['zipcode'] ?? '')) . ' ' . trim((string)($data['city'] ?? '')))),
    'phone'   => (string)($data['phone'] ?? ''),
    'email'   => (string)($data['email'] ?? ''),
];

if (!is_dir($cache_dir)) @mkdir($cache_dir, 0775, true);
@file_put_contents($cache_file, json_encode($result, JSON_UNESCAPED_UNICODE));

echo json_encode($result, JSON_UNESCAPED_UNICODE);
ob_end_flush();
?>
