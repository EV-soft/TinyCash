<?php # /currency_proxy.php v:1.3.0 d:2026-08-30 i:evs
# Proxy til frankfurter.app — cacher kurser i 6 timer så API'et ikke overbelastes
# Kræver: allow_url_fopen = On i php.ini (standard på NordicWay/cPanel)
# v1.3.0: manglede login-krav, i modsætning til resten af systemet. Ingen
# hemmeligheder lækkes (proxier en offentlig valutakurs-API), men tilføjet
# for konsekvens. Gennemgang af tilbageværende sider.
chdir(__DIR__);
require_once 'inc/auth.inc.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cache_file = __DIR__ . '/storage/currency_rates.json';
$cache_ttl  = 6 * 3600; // 6 timer

// Brug cache hvis den findes og er frisk
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    echo file_get_contents($cache_file);
    exit;
}

// Hent fra API
$url  = 'https://api.frankfurter.app/latest';
$ctx  = stream_context_create(['http' => ['timeout' => 5]]);
$data = @file_get_contents($url, false, $ctx);

if ($data === false) {
    // Returner gammel cache hvis tilgængelig, ellers fejl
    if (file_exists($cache_file)) {
        echo file_get_contents($cache_file);
    } else {
        http_response_code(503);
        echo json_encode(['error' => 'Could not fetch rates']);
    }
    exit;
}

// Gem i cache og returner
file_put_contents($cache_file, $data);
echo $data;
?>
