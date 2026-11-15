<?php # /run_migrate.php v:1.3.1 d:2026-09-15 i:evs
# v2.0.0: ALVORLIGT FUND - denne side (menuens "Database migration"-link)
# kørte reelt kun ÉN eneste, gammel migration (migrate_cust_reference.php,
# fra 2026-07-13) og var derfor stærkt forældet. De 17 øvrige migrationer
# skrevet siden (fakturavaluta, bogføringslov/append-only, delvis betaling,
# gentagne fakturaer, bankintegration, 2FA m.fl. - se
# pending-deployment-checklist) blev ALDRIG kørt fra denne side, kun ved at
# gætte de enkelte filnavne direkte i browserens adresselinje. Omskrevet til
# et rigtigt migrations-overblik: lister ALLE kendte migrationsscripts i
# db-setup/, med en direkte statustjek pr. migration (findes tabellen/
# kolonnen/kontoen/triggeren allerede?), og et link til at køre hver enkelt.
# Kører IKKE alle på én gang (hver migration er sit eget selvstændige,
# allerede idempotente script med sin egen fejlhåndtering og visning -
# at kæde dem sammen i ét kald ville blande deres separate tekst-outputs
# sammen og risikere Content-Type/header-konflikter for ingen reel gevinst).
ob_start();
require_once 'inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/migrations.inc.php';      // (migration_registry() + statustjekkene)
$migrations = migration_registry();

htm_Header('@Database Migration', 1100);
showMenu();

htm_Card_(capt: '@Database Migration', wdth: 1100);
htm_Note(lang('@All migrations below are idempotent - safe to run more than once. Each opens its own page with the result. Status is checked live against your current database.'), 'font-size:0.9em;');

$headers = ['@Migration', '@Description', '@Status', '@Actions'];
$data = [];
$pending_count = 0;

foreach ($migrations as $m) {
    $applied = false;
    try {
        $applied = (bool)$m['check']($conn);
    } catch (\Throwable $e) {
        $applied = false; // en fejlet statustjek (fx en helt frisk DB uden tabellen) tolkes som "ikke anvendt endnu"
    }
    if (!$applied) $pending_count++;

    $status_html = $applied
        ? '<span style="color:var(--color-success); font-weight:bold;"><i class="fa fa-check-circle"></i> ' . lang('@Applied') . '</span>'
        : '<span style="color:var(--color-warning); font-weight:bold;"><i class="fa fa-exclamation-circle"></i> ' . lang('@Pending') . '</span>';

    $run_label = $applied ? lang('@Run again') : lang('@Run now');
    $run_style = $applied ? 'background:var(--color-secondary);' : 'background:var(--color-primary);';
    $actions = '<a href="db-setup/' . htmlspecialchars($m['file']) . '" target="_blank" style="display:inline-block; padding:5px 12px; ' . $run_style . ' color:#fff; border-radius:4px; text-decoration:none; font-size:0.85em;">' . $run_label . '</a>';

    $data[] = [htmlspecialchars($m['label']), htmlspecialchars($m['desc']), $status_html, $actions];
}

if ($pending_count > 0) {
    // RETTET: htm_Banner (ny, se csrf-protection-added.md) er den rigtige funktion her - en STÅENDE sidenotits,
    // ikke et engangs-handlings-resultat (det er htm_Alert's formål). Erstatter den tidligere håndrullede
    // venstre-kant-boks.
    htm_Banner("<i class='fa fa-exclamation-triangle'></i> " . sprintf(lang('@%d migration(s) not yet applied on this installation.'), $pending_count), 'warning');
} else {
    htm_Alert(lang('@All known migrations are applied on this installation.'), 'success');
}

htm_Table($headers, $data, 'migrationTbl', 50, '', true,
    ['width:220px;', 'width:420px;', 'width:130px;', 'width:130px; text-align:left;']);

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
