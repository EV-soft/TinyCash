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

echo "TinyCash - konvertering af databasemotor\n";
echo "Fra:  $src_type\n";
echo "Til:  " . $target['type'] . ($target['type'] === 'sqlite' ? ' (' . ($override ?? $target['path']) . ')' : ' (' . ($override ?? ($target['name'] ?: '(DB_NAME mangler i env.ini)')) . ')') . "\n\n";

if (!$do_run) {
    echo "Dette DROPPER og genskaber alle tabeller i måldatabasen og kopierer det aktive regnskab over.\n";
    echo "Tag en backup først, og konvertér til en TOM/ny måldatabase.\n\n";
    echo $cli ? "Kør igen med --run (evt. --target=<sti/navn>) for at udføre.\n"
              : "Tilføj ?run=1 til adressen for at udføre (evt. &target=<sti/navn>).\n";
    exit;
}

try {
    $dst = tc_eng_open_target($target, ($override !== null && $override !== '') ? $override : null);
    // Sikkerhed: målet må aldrig være kilden selv (samme SQLite-fil) - det ville droppe den aktive database
    if ($dst['type'] === 'sqlite' && $src_type === 'sqlite') {
        $src_abs = realpath($db_path_abs ?? '');
        $dst_abs = realpath($dst['where']);
        if ($src_abs && $dst_abs && $src_abs === $dst_abs) {
            throw new Exception('Målet er den samme SQLite-fil som kilden - vælg et andet --target.');
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
