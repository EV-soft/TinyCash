<?php # /inc/enablebanking.lib.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Rigtig bankintegration, PSD2 via Enable Banking - erstatter GoCardless efter deres lukning for nye tilmeldinger juli 2025
# v1.1.0: eb_start_authorization() beder nu om den ASPSP-specifikke
# "maximum_consent_validity" (fra /aspsps, i sekunder) i stedet for altid
# blindt 90 dage - se eb_start_authorization()'s egen kommentar. Forsøg på at
# rette en vedholdende, ubegrundet "server_error" fra Enable Banking under
# selve bank-godkendelsen (psu_type-skiftet alene løste den ikke).
# API-klient til Enable Banking (https://enablebanking.com) - bygger på det
# officielle PHP-eksempel (github.com/enablebanking/enablebanking-api-samples,
# php_example/enablebanking.php), men uden deres Composer-afhængighed
# (firebase/php-jwt) - JWT'en signeres i stedet selv med PHP's indbyggede
# openssl-udvidelse, samme "ingen pakkehåndtering"-princip som resten af
# projektet. RS256-signeringen er selv-verificeret ved en signér→verificér-
# rundtur mod nøglens egen offentlige del (se kommentar ved eb_build_jwt()).
#
# VIGTIG FORSKEL FRA GoCardless (som denne fil erstatter):
# - Godkendelse er en RS256 JWT signeret med et privat RSA-nøglepar
#   tilknyttet en "application" i Enable Bankings kontrolpanel - ikke et
#   simpelt secret_id/secret_key-bytte mod et bearer-token.
# - redirect_url er ÉN fast, forudregistreret adresse (sat i kontrolpanelet),
#   IKKE en dynamisk URL pr. forbindelse som hos GoCardless - konteksten
#   (hvilken bank_connections-række der fuldføres) bæres derfor via
#   "state"-parametret i stedet for en forbindelses-specifik redirect-URL.
# - Transaktionslisten er paginerede (continuation_key).
# - transaction_amount.amount's fortegn er IKKE entydigt dokumenteret på
#   tværs af banker (Berlin Group/NextGenPSD2-baseret, og forskellige banker
#   håndterer det forskelligt) - fortegnet udregnes derfor altid eksplicit
#   fra credit_debit_indicator (CRDT/DBIT) her, aldrig fra amount-feltets
#   eget fortegn, for at være sikker uanset bankens konvention.

const EB_API_BASE = 'https://api.enablebanking.com';

// RETTET (§bugs-batch-25-review): ALVORLIGT FUND - alle tre EB_*-opslag i
// denne fil (herunder) læste udelukkende $_ENV/$_SERVER, aldrig selve
// inc/env.ini via parse_ini_file() - men det er inc/env.ini, ikke rigtige
// OS-miljøvariabler, der er den dokumenterede konfigurationsvej i hele
// projektet (se env.ini's egen EB_APPLICATION_ID/EB_PRIVATE_KEY_PATH/
// EB_REDIRECT_URL-sektion, og CLAUDE.md). Ingen kode noget sted i projektet
// kopierer nogensinde env.ini's værdier over i $_ENV (ingen putenv()-kald
// findes) - så en administrator kunne udfylde alle tre felter korrekt i
// env.ini, og bankintegrationen ville STADIG opføre sig som om intet var
// konfigureret. Samme fundklasse og samme rettelse som lige fundet i
// inc/help.lib.php's OPENAI_API_KEY-opslag (se dens kommentar for baggrund).
function eb_ini_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $config = [];
    // RETTET: env.ini flyttet til inc/data/env.ini - de to gamle stier
    // bevaret som bagudkompatibel fallback.
    foreach ([dirname(__DIR__) . '/inc/data/env.ini', dirname(__DIR__) . '/inc/env.ini', dirname(__DIR__) . '/env.ini'] as $path) {
        if (file_exists($path)) {
            $ini = parse_ini_file($path);
            if (is_array($ini)) { $config = $ini; break; }
        }
    }
    return $config;
}

function eb_config(string $key): string {
    $ini = eb_ini_config();
    if (!empty($ini[$key])) return trim($ini[$key]);
    // Sidste udvej for miljøer der reelt sætter en rigtig OS-miljøvariabel.
    return trim($_ENV[$key] ?? $_SERVER[$key] ?? '');
}

function eb_credentials_configured(): bool {
    $app_id   = eb_config('EB_APPLICATION_ID');
    $key_path = eb_config('EB_PRIVATE_KEY_PATH');
    return ($app_id !== '' && $key_path !== '' && file_exists($key_path));
}

function eb_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Bygger og signerer selv JWT'en (RS256) - ingen Composer-pakke. Gyldig 1
// time (samme TTL som Enable Bankings eget PHP-eksempel).
//
// Selv-verifikation: da vi ikke har en rigtig Enable Banking-konto at teste
// den fulde godkendelse imod, er selve signeringen i stedet verificeret ved
// at underskrive og BAGEFTER kontrollere signaturen med den offentlige nøgle
// udtrukket af samme private nøgle (openssl_verify) - beviser at JWT'en er
// kryptografisk korrekt konstrueret, uafhængigt af om Enable Banking selv
// accepterer den (se totp-lib.php-mønsteret: verificér det der KAN
// verificeres uden en levende tredjepartskonto).
function eb_build_jwt(string $app_id, string $private_key_pem): ?string {
    $header  = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $app_id];
    $payload = ['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => time(), 'exp' => time() + 3600];

    $signing_input = eb_base64url(json_encode($header)) . '.' . eb_base64url(json_encode($payload));

    $key = openssl_pkey_get_private($private_key_pem);
    if ($key === false) return null;

    $signature = '';
    $ok = openssl_sign($signing_input, $signature, $key, OPENSSL_ALGO_SHA256);
    if (!$ok) return null;

    return $signing_input . '.' . eb_base64url($signature);
}

function eb_get_jwt(): ?string {
    $app_id   = eb_config('EB_APPLICATION_ID');
    $key_path = eb_config('EB_PRIVATE_KEY_PATH');
    if ($app_id === '' || $key_path === '' || !file_exists($key_path)) return null;

    $pem = file_get_contents($key_path);
    if ($pem === false) return null;

    return eb_build_jwt($app_id, $pem);
}

function eb_request(string $method, string $path, ?array $body = null): array {
    $jwt = eb_get_jwt();
    if (!$jwt) return ['error' => 'no_credentials', '_status' => 0];

    $ch = tc_curl_init(EB_API_BASE . $path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $jwt, 'Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    // SSL-verifikation er curl's standard, bevidst IKKE slået fra - se
    // scanner-ocr-review/inc/help.lib.php for hvorfor.
    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) return ['error' => 'curl_error', '_status' => 0];
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = ['error' => 'invalid_json', 'raw' => $raw];
    $data['_status'] = $status;
    return $data;
}

// Liste over banker (ASPSP'er) i et land (ISO 3166-1 alpha-2, fx "DK").
function eb_list_institutions(string $country = 'DK'): array {
    $r = eb_request('GET', '/aspsps?country=' . urlencode($country));
    return $r['aspsps'] ?? [];
}

// Starter selve godkendelsen. $redirect_url SKAL matche én af de URI'er der
// er registreret på applikationen i Enable Bankings kontrolpanel præcist -
// den kan IKKE varieres pr. kald sådan som GoCardless' requisitions kunne.
// $state bæres tilbage uændret ved redirect og bruges her til at genkende
// hvilken bank_connections-række der fuldføres.
//
// $max_validity_seconds - ASPSP'ens EGEN "maximum_consent_validity" (fra
// /aspsps-listen, i sekunder) - hver bank kan have sin egen grænse for hvor
// længe adgang må vare. Enable Bankings dokumentation siger værdien "is
// subject to adjustment" hvis den overskrides (ikke nødvendigvis en fejl),
// men respekteres nu alligevel fra start i stedet for blindt at bede om 90
// dage - lavere risiko for uventet justering/afvisning. Fallder tilbage til
// 90 dage hvis ASPSP'en ikke oplyste nogen grænse.
function eb_start_authorization(string $aspsp_name, string $aspsp_country, string $state, string $redirect_url, string $psu_type = 'business', ?int $max_validity_seconds = null): array {
    $requested_seconds = 90 * 86400;
    if ($max_validity_seconds !== null && $max_validity_seconds > 0) {
        $requested_seconds = min($requested_seconds, $max_validity_seconds);
    }
    $valid_until = date('c', time() + $requested_seconds);
    $r = eb_request('POST', '/auth', [
        'access'       => ['valid_until' => $valid_until],
        'aspsp'        => ['name' => $aspsp_name, 'country' => $aspsp_country],
        'state'        => $state,
        'redirect_url' => $redirect_url,
        'psu_type'     => $psu_type,
        'language'     => 'da',
    ]);
    return ['url' => $r['url'] ?? null, 'authorization_id' => $r['authorization_id'] ?? null, 'error' => $r['_status'] >= 400 ? $r : null];
}

// Udveksler den engangs-autorisationskode banken sendte tilbage (?code=...)
// til en session med kontolisten.
function eb_create_session(string $code): array {
    $r = eb_request('POST', '/sessions', ['code' => $code]);
    return $r;
}

function eb_get_balances(string $account_uid): array {
    $r = eb_request('GET', '/accounts/' . urlencode($account_uid) . '/balances');
    return $r['balances'] ?? [];
}

// Henter ALLE transaktioner i perioden - følger continuation_key til der
// ikke er flere sider (i modsætning til GoCardless, som returnerede alt i
// ét svar).
function eb_get_transactions(string $account_uid, ?string $date_from = null, ?string $date_to = null): array {
    $all = [];
    $continuation_key = null;
    $guard = 0; // sikkerhedsnet mod en uendelig løkke, hvis API'et opfører sig uventet
    do {
        $qs = [];
        if ($date_from) $qs['date_from'] = $date_from;
        if ($date_to)   $qs['date_to']   = $date_to;
        if ($continuation_key) $qs['continuation_key'] = $continuation_key;
        $path = '/accounts/' . urlencode($account_uid) . '/transactions' . (!empty($qs) ? '?' . http_build_query($qs) : '');

        $r = eb_request('GET', $path);
        if (!empty($r['transactions']) && is_array($r['transactions'])) {
            $all = array_merge($all, $r['transactions']);
        }
        $continuation_key = $r['continuation_key'] ?? null;
        $guard++;
    } while ($continuation_key && $guard < 50);

    return $all;
}

// Udregner et signeret DKK/kontovaluta-beløb ud fra credit_debit_indicator -
// ALDRIG ud fra amount-feltets eget fortegn (se filens header-kommentar).
function eb_signed_amount(array $transaction): float {
    $amount = abs((float)($transaction['transaction_amount']['amount'] ?? 0));
    $indicator = strtoupper($transaction['credit_debit_indicator'] ?? '');
    return ($indicator === 'DBIT') ? -$amount : $amount;
}

function eb_redirect_base(): string {
    $configured = eb_config('EB_REDIRECT_URL');
    if ($configured !== '') return $configured;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/bank_integration_callback.php';
}
?>
