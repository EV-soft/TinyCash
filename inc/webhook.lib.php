<?php # /inc/webhook.lib.php v:1.3.1 d:2026-09-15 i:evs
# Registrering og verifikation af API-nøgler til webhook_invoice.php (§webhook-
# invoice-feature). Samme rene fil-I/O-mønster som inc/account.lib.php - en
# flock()-beskyttet kritisk sektion om ÉN samlet læs+skriv, ingen DB-
# forbindelse nødvendig (kan derfor bruges FØR db_connect.inc.php kører,
# nøjagtig samme begrundelse som account.lib.php's egen header-kommentar).
# Findes inc/data/webhook_keys.json ikke, opfører alt sig som "ingen nøgler
# oprettet endnu" - filen oprettes aldrig automatisk her, kun ved den første
# webhook_key_generate()-kaldelse.
#
# Kun nøglens SHA-256-hash gemmes, ALDRIG klarteksten - klarteksten
# returneres én gang, af webhook_key_generate() selv, og skal vises til
# brugeren med det samme (samme "vises kun én gang"-princip som fx et
# API-nøgle-flow hos enhver tredjepartstjeneste).

define('WEBHOOK_KEYS_FILE', __DIR__ . '/data/webhook_keys.json');

function _webhook_keys_read_all(): array {
    if (!file_exists(WEBHOOK_KEYS_FILE)) return [];
    $raw = @file_get_contents(WEBHOOK_KEYS_FILE);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Samme "læs+skriv i ÉN låst kritisk sektion"-mønster som
// inc/account.lib.php's _account_update_all() - undgår den tabte-opdatering-
// race, der ramte regnskabsregistret, se dens egen kommentar for detaljen.
function _webhook_keys_update_all(callable $mutator): bool {
    $dir = dirname(WEBHOOK_KEYS_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fp = @fopen(WEBHOOK_KEYS_FILE, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $entries = [];
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $entries = $decoded;
    }

    $entries = $mutator($entries);

    $json = json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = false;
    if ($json !== false) {
        rewind($fp);
        ftruncate($fp, 0);
        $ok = (fwrite($fp, $json) !== false);
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

// --- Offentlig API -------------------------------------------------------

function webhook_key_list(): array {
    return _webhook_keys_read_all();
}

// Genererer + gemmer en ny nøgle (kun dens hash) og returnerer klarteksten -
// kaldes KUN når brugeren aktivt beder om en ny nøgle, aldrig automatisk.
// $account_id: for en installation med flere regnskaber (se account.lib.php)
// angiver dette hvilket regnskab nøglen giver adgang til - null betyder
// "installationens eneste/standard-regnskab" (almindeligt ét-regnskabs-setup).
function webhook_key_generate(string $label, ?string $account_id = null): string {
    $plain = 'tcwh_' . bin2hex(random_bytes(24));
    $entry = [
        'id'           => bin2hex(random_bytes(6)),
        'label'        => $label,
        'key_hash'     => hash('sha256', $plain),
        'key_last4'    => substr($plain, -4),
        'account_id'   => $account_id,
        'created_at'   => date('Y-m-d H:i:s'),
        'last_used_at' => null,
        'revoked'      => false,
    ];
    _webhook_keys_update_all(function (array $all) use ($entry) {
        $all[] = $entry;
        return $all;
    });
    return $plain;
}

function webhook_key_revoke(string $id): bool {
    return _webhook_keys_update_all(function (array $all) use ($id) {
        foreach ($all as &$e) {
            if (($e['id'] ?? null) === $id) { $e['revoked'] = true; }
        }
        return $all;
    });
}

// Verificerer en klartekst-nøgle fremsendt af en ekstern kalder. Returnerer
// registreringsposten (inkl. account_id) ved succes, ellers null. Opdaterer
// last_used_at bedste-forsøg - en fejlet skrivning her blokerer ALDRIG selve
// godkendelsen, den er kun til visning i webhook_keys.php.
function webhook_key_verify(string $plain): ?array {
    $hash  = hash('sha256', $plain);
    $found = null;
    _webhook_keys_update_all(function (array $all) use ($hash, &$found) {
        foreach ($all as &$e) {
            if (empty($e['revoked']) && hash_equals((string)($e['key_hash'] ?? ''), $hash)) {
                $e['last_used_at'] = date('Y-m-d H:i:s');
                $found = $e;
                break;
            }
        }
        return $all;
    });
    return $found;
}
