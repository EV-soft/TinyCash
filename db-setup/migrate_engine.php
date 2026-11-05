<?php # /db-setup/migrate_engine.php v:1.3.1 d:2026-10-29 i:evs
# Konvertér det AKTIVE regnskab til den ANDEN databasemotor (SQLite <-> MySQL). Begge veje.
# Se inc/engine_migrate.inc.php for selve kopieringen og dens begrænsninger.
#
# Kilde = den motor sessionen kører på nu (env.ini's ACTIVE_DB). Mål = den anden motor, med forbindelses-
# oplysninger fra env.ini (den sektion der IKKE er aktiv). Konvertér til en TOM/ny måldatabase, og tag en
# backup først - målets tabeller droppes og genskabes.
#
# Admin-gated (niveau 3). Kan køres fra kommandolinjen (php db-setup/migrate_engine.php --run) eller via
# browseren (bekræftelsesskærm -> ?run=1). CLI springer login-gaten over (ingen session).
chdir(dirname(__DIR__));
$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    require_once __DIR__ . '/../inc/auth.inc.php';
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { deny_access_gracefully(); }
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/engine_migrate.inc.php';
if (!$cli) header('Content-Type: text/plain; charset=utf-8');

$src_type = $db_type;                                  // sat af db_connect.inc.php
$target   = tc_eng_target_settings($src_type, $config);
$override  = $cli ? ($argvOverride ?? null) : (isset($_GET['target']) ? (string)$_GET['target'] : null);
foreach (($cli ? array_slice($argv, 1) : []) as $a) if (strpos($a, '--target=') === 0) $override = substr($a, 9);
$do_run = $cli ? in_array('--run', $argv, true) : (($_GET['run'] ?? '') === '1');

// #1 (sikkerhed): selve kørslen dropper og genskaber alle måltabeller. I browseren
// må den derfor ikke kunne udløses af et fremmed CSRF-link - kræv en gyldig
// sessions-token. CLI (shell-adgang = betroet, ingen session) er undtaget.
if ($do_run && !$cli) {
    $tok = $_GET['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($tok) || !hash_equals($_SESSION['csrf_token'], $tok)) {
        http_response_code(400);
        echo "FEJL: Ugyldig eller manglende sikkerhedstoken (CSRF).\n";
        echo "Åbn siden uden ?run=1 og brug linket under \"Udfør\" til at køre konverteringen.\n";
        exit(1);
    }
}

echo "TinyCash - konvertering af databasemotor\n";
echo "Fra:  $src_type\n";
echo "Til:  " . $target['type'] . ($target['type'] === 'sqlite' ? ' (' . ($override ?? $target['path']) . ')' : ' (' . ($override ?? ($target['name'] ?: '(DB_NAME mangler i env.ini)')) . ')') . "\n\n";

if (!$do_run) {
    // Trin-for-trin vejledning til selve konverteringen - retningsbestemt, så
    // brugeren ved præcis hvad der skal være på plads FØR kørsel (den hyppigste
    // fejl er at måldatabasen/forbindelsen ikke er sat op endnu).
    if ($cli) {
        $exec_line = 'Kør kommandoen igen med  --run  (evt. --target=<navn.sqlite>).';
    } else {
        // #1: kør-linket bærer en sikkerhedstoken (CSRF), så kørslen ikke kan
        // udløses af et fremmed link.
        $run_qs = 'run=1&csrf_token=' . urlencode(tc_eng_csrf());
        if ($override !== null && $override !== '') $run_qs .= '&target=' . urlencode($override);
        $exec_line = "åbn denne adresse (bærer en sikkerhedstoken):\n   ?$run_qs";
    }
    $backup_line = "Tag en backup (System -> Vedligeholdelse -> Backup-administration -> Automatisk Backup, eller en manuel backup på samme side).";

    echo "SÅDAN KONVERTERER DU ($src_type -> " . $target['type'] . ")\n";
    echo str_repeat('-', 48) . "\n\n";

    if ($target['type'] === 'mysql') {
        $tname = $override ?? ($target['name'] ?: '<DB_NAME mangler i env.ini>');
        echo "Forbered måldatabasen i cPanel (gøres én gang):\n";
        echo " 1. MySQL Databases: opret en TOM database \"$tname\".\n";
        echo " 2. Opret en MySQL-bruger. Brug IKKE \"root\" - på et webhotel har konto-\n";
        echo "    brugere altid et præfiks (fx evsoftdk_...). Giv den en adgangskode.\n";
        echo " 3. Tilføj brugeren til databasen med ALL PRIVILEGES.\n\n";
        echo "Skriv forbindelsen i inc/data/env.ini under [mysql_config]:\n";
        echo "    DB_HOST=\"localhost\"\n";
        echo "    DB_USER=\"<brugeren fra trin 2>\"\n";
        echo "    DB_PASS=\"<adgangskoden>\"\n";
        echo "    DB_NAME=\"$tname\"\n\n";
        echo "Før kørsel:\n";
        echo " - $backup_line\n";
        echo " - Brug en TOM måldatabase: ALLE tabeller i \"$tname\" droppes og genskabes.\n\n";
        echo "Udfør:\n   $exec_line\n\n";
        echo "Bagefter (for at bruge MySQL-regnskabet):\n";
        echo " - Sæt  ACTIVE_DB=\"mysql_config\"  i inc/data/env.ini og genindlæs.\n";
    } else {
        $tpath = $override ?? ($target['path'] ?? '<sti mangler>');
        echo "Målet er en SQLite-fil: \"$tpath\"\n";
        echo "Filen oprettes automatisk - ingen cPanel/bruger-opsætning nødvendig.\n\n";
        echo "Før kørsel:\n";
        echo " - $backup_line\n";
        echo " - Vælg evt. en anden målfil med target=<sti> (filen må IKKE være den aktive database).\n";
        echo " - Findes filen allerede, droppes og genskabes dens tabeller.\n\n";
        echo "Udfør:\n   $exec_line\n\n";
        echo "Bagefter (for at bruge SQLite-regnskabet):\n";
        echo " - Sæt  ACTIVE_DB=\"sqlite_config\"  i inc/data/env.ini og genindlæs.\n";
    }
    exit;
}

try {
    $dst = tc_eng_open_target($target, ($override !== null && $override !== '') ? $override : null);
    // #4 (sikkerhed/robusthed): et SQLite-mål må ALDRIG være den aktive SQLite-
    // database - hverken kilden selv (sqlite->sqlite) ELLER appens konfigurerede
    // SQLite-fil (mysql->sqlite kunne ellers dumpe oven i den levende database,
    // fordi standardmålet netop ER data/tinycash.sqlite). Gælder uanset kildemotor.
    if ($dst['type'] === 'sqlite') {
        $norm    = fn($p) => strtolower(str_replace('\\', '/', (string)$p));
        $dst_abs = realpath($dst['where']) ?: $dst['where'];
        $sq_rel  = $config['sqlite_config']['DB_PATH'] ?? 'data/tinycash.sqlite';
        $sq_file = dirname(__DIR__) . '/inc/' . $sq_rel;
        $sq_abs  = realpath($sq_file) ?: $sq_file;
        $src_abs = ($src_type === 'sqlite') ? (realpath($db_path_abs ?? '') ?: '') : '';
        if (($src_abs !== '' && $norm($dst_abs) === $norm($src_abs)) || $norm($dst_abs) === $norm($sq_abs)) {
            throw new Exception('Målet er den aktive SQLite-database. Vælg et andet filnavn med target=<navn.sqlite> (fx tinycash_mysql_kopi.sqlite), så den levende database ikke overskrives.');
        }
    }
    $log = [];
    tc_engine_migrate($src_type === 'sqlite' ? $pdo : $conn, $src_type, $dst, $log);
    echo implode("\n", $log) . "\n\n";
    echo "For at TAGE målet i brug: sæt ACTIVE_DB i inc/data/env.ini til \"" . $target['type'] . "_config\" og genindlæs.\n";
    if (function_exists('log_action')) log_action($conn, 'MIGRATE_ENGINE', 'system', 0, ['from' => $src_type], ['to' => $target['type'], 'where' => $dst['where']]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "FEJL: " . $e->getMessage() . "\nIntet blev ændret i måldatabasen (rullet tilbage).\n";
    exit(1);
}

// Sessions-token til #1 (CSRF). Genbruger appens egen csrf_token() hvis den er
// indlæst - ellers samme mekanisme/sessionsnøgle, så de er kompatible.
function tc_eng_csrf(): string {
    if (function_exists('csrf_token')) return csrf_token();
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'] ?? '';
}
