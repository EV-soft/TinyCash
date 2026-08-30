<?php # /inc/account.lib.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION (bruger-anmodet, flere regnskaber pr. installation): hvert
# regnskab er en helt separat database (egen SQLite-fil eller egen MySQL-
# database) - denne fil er registret over hvilke regnskaber der findes.
# Bevidst navngivet "account" (ikke en fordansket "regnskab"-stavning) i
# selve koden - matcher den eksisterende konvention i kodebasen (engelske
# funktions-/filnavne, danske UI-tekster via lang()). Selve begrebet hedder
# fortsat "regnskab" i brugerfladen.
#
# Ren fil-I/O uden nogen DB-forbindelse, så filen trygt kan inkluderes fra
# login.php FØR nogen database forbindes, og fra inc/db_connect.inc.php selv.
# Findes inc/data/accounts.json ikke, returnerer alle læse-funktioner
# tomt/null med det samme, og filen oprettes ALDRIG automatisk her - det er
# det, der gør bagudkompatibiliteten for eksisterende ét-regnskabs-
# installationer strukturel, ikke tilfældig (se inc/db_connect.inc.php).
#
# Indeholder ALDRIG legitimationsoplysninger - MySQL-poster har kun et
# db_name, host/bruger/kodeord kommer altid fra env.inis ene, delte
# [mysql_config]-sektion (se account_resolve_settings()).

define('ACCOUNTS_REGISTRY_FILE', __DIR__ . '/data/accounts.json');
define('ACCOUNTS_SQLITE_DIR', __DIR__ . '/data/accounts');

// --- Interne hjælpefunktioner (fil-læsning/-skrivning) ------------------

// Læser hele registreringen. Returnerer altid et array (tomt hvis filen
// mangler eller er ugyldig JSON) - aldrig false/null - så kaldere ikke
// behøver egne isset/is_array-tjek før de looper over resultatet.
function _account_read_all(): array {
    if (!file_exists(ACCOUNTS_REGISTRY_FILE)) return [];
    $raw = @file_get_contents(ACCOUNTS_REGISTRY_FILE);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// RETTET (§find-fem-fejl, flere-regnskaber-sweep): den oprindelige
// _account_write_all() tog kun en flock() om selve SKRIVningen - men
// kalderen (account_save()) havde allerede LÆST den daværende tilstand
// FØR låsen blev taget (via en helt separat _account_read_all()). To
// samtidige skriv-forsøg (fx to admin-faner der opretter hvert sit nye
// regnskab omtrent samtidig) kunne derfor begge læse den samme
// udgangstilstand, hver tilføje deres egen post til deres EGEN kopi i
// hukommelsen, og den sidste af de to skriv-låse ville overskrive den
// først skrevne ændring fuldstændigt (klassisk tabt-opdatering-kapløb) -
// den tabte regnskabspost ville derefter være lige så usynlig/utilgængelig
// som det oprindelige "regnskabet forsvandt fra login-vælgeren"-gap.
// Løsning: læs OG skriv nu inde i ÉN samlet, låst kritisk sektion (samme
// filhåndtag genbruges til begge dele), styret af en $mutator-callback der
// modtager den FRISKE, låste tilstand og returnerer den nye.
function _account_update_all(callable $mutator): bool {
    $dir = dirname(ACCOUNTS_REGISTRY_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fp = @fopen(ACCOUNTS_REGISTRY_FILE, 'c+');
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

function account_list(bool $include_inactive = false): array {
    $all = _account_read_all();
    if ($include_inactive) return $all;
    return array_values(array_filter($all, fn($e) => !empty($e['active'])));
}

function account_get(string $id): ?array {
    foreach (_account_read_all() as $entry) {
        if (($entry['id'] ?? null) === $id) return $entry;
    }
    return null;
}

// Upsert på 'id'. Kan sende et delvist array ved opdatering af enkelte felter
// (fx account_save(['id' => $id, 'active' => false]) - se account_deactivate())
// - eksisterende felter bevares, kun de medsendte overskrives/tilføjes.
function account_save(array $entry): bool {
    if (empty($entry['id'])) return false;

    return _account_update_all(function (array $all) use ($entry) {
        $found = false;
        foreach ($all as $i => $existing) {
            if (($existing['id'] ?? null) === $entry['id']) {
                $all[$i] = array_merge($existing, $entry);
                $found   = true;
                break;
            }
        }
        if (!$found) {
            $all[] = array_merge([
                'name'       => $entry['id'],
                'engine'     => 'sqlite',
                'is_demo'    => false,
                'active'     => true,
                'created_at' => date('Y-m-d H:i:s'),
            ], $entry);
        }
        return $all;
    });
}

// Blid deaktivering ALTID - sætter kun active:false, rører aldrig selve
// databasen/filen. Ingen hård-sletningsfunktion findes bevidst i denne fil
// (bruger-bekræftet: reel sletning skal aldrig være muligt for noget
// regnskab, hverken demo eller rigtigt).
function account_deactivate(string $id): bool {
    return account_save(['id' => $id, 'active' => false]);
}

// Slug afledt af navnet + kort tilfældig endelse for entydighed. Id'er er
// ikke hemmelige - læsbarhed vægtes over ren tilfældighed.
function account_generate_id(string $name): string {
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    if ($slug === '') $slug = 'regnskab';
    $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
    return $slug . '-' . $suffix;
}

// Oversætter en accounts.json-post til samme $db_settings-form som
// inc/db_connect.inc.php allerede bruger internt for de to motorer - så
// resultatet kan bruges direkte, uden at ændre selve forbindelseskoden.
// $mysql_config er den delte [mysql_config]-sektion fra env.ini (host/
// bruger/kodeord) - regnskabets egen post indeholder ALDRIG
// legitimationsoplysninger, kun hvilken database den peger på.
function account_resolve_settings(array $entry, array $mysql_config): array {
    if (($entry['engine'] ?? '') === 'mysql') {
        return [
            'DB_TYPE' => 'mysql',
            'DB_HOST' => $mysql_config['DB_HOST'] ?? 'localhost',
            'DB_USER' => $mysql_config['DB_USER'] ?? '',
            'DB_PASS' => $mysql_config['DB_PASS'] ?? '',
            'DB_NAME' => $entry['db_name'] ?? '',
        ];
    }
    // SQLite: db_path er relativ til inc/ (samme konvention som env.inis
    // egen DB_PATH), så db_connect.inc.php's eksisterende sti-opbygning
    // (mkdir + realpath) genbruges helt uændret for nye regnskaber.
    return [
        'DB_TYPE' => 'sqlite',
        'DB_PATH' => $entry['db_path'] ?? ('data/accounts/' . ($entry['id'] ?? 'unknown') . '.sqlite'),
    ];
}
