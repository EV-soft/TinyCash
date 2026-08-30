<?php # /db-setup/bootstrap_index.php v:1.3.0 d:2026-08-30 i:evs
/* ==========================================================================
   MIDLERTIDIG FØRSTEGANGS-OPSÆTNINGSSIDE.

   Denne fil ligger KUN i ZIP-fresh-install-pakken, hvor den er lagt ind SOM
   index.php (den rigtige index.php følger med i pakken omdøbt til
   index.real.php). Formål: en "supernem" ét-klik-opstart af en frisk
   demo-installation, uden terminal og uden at skulle logge ind som admin
   FØR databasen overhovedet findes (en hønen-og-ægget-udfordring, siden
   create_all_tables.php normalt kræver admin-login).

   Kører db-setup/init_demo_data.php (opretter admin-bruger + demo-data;
   kræver bevidst ikke login i forvejen) og derefter
   db-setup/create_all_tables.core.php (resten af skemaet - normalt kaldt
   via det admin-gatede create_all_tables.php, men her kaldt direkte UDEN
   auth, fordi ingen admin findes endnu). Når begge er kørt, erstatter filen
   sig selv med den rigtige index.php (index.real.php -> index.php) og
   sender brugeren videre til login.

   SIKKERHED: nægter at køre hvis users-tabellen allerede har rækker (samme
   værn som init_demo_data.php selv bruger) - kan IKKE bruges til at
   nulstille eller overskrive en eksisterende installation. Kun relevant for
   en helt frisk/tom database.
   ========================================================================== */

chdir(__DIR__);

// Al output bufres, så header()-kald (herunder dem i de inkluderede
// opsætningsscripts, som selv sætter Content-Type: text/plain) ikke fejler
// med "headers already sent" - se index.php's egen brug af samme mønster
// (ob_start()/ob_end_flush()).
ob_start();

// --- FØR db_connect.inc.php overhovedet inkluderes: findes env.ini? -------
// inc/db_connect.inc.php kalder selv die() med en rå fejlbesked, hvis hverken
// env.ini eller .env findes NOGET af de fire steder den leder (se dens egen
// $SøgeStier) - det sker FØR den når at returnere styringen til denne fil,
// så en pæn fejlside her ville aldrig blive vist. Derfor: samme søgning her,
// FØR require, så vi selv kan vise en hjælpsom side i stedet (og evt. oprette
// env.ini automatisk fra skabelonen - kræver intet udfyldt for SQLite).
// RETTET (bruger-anmodet konsolidering af installationsspecifikke data i
// inc/data/, hvor tinycash.sqlite allerede lå): env.ini og dens skabelon
// oprettes/ledes nu efter i inc/data/ først. De gamle stier bevares som
// søgefallback, så en pakke der (midlertidigt) stadig har env.ini liggende
// i inc/ eller roden ikke fejlagtigt beder om en ny.
$env_search_paths = ['inc/data/env.ini', 'inc/env.ini', 'inc/.env', 'env.ini', '.env'];
$env_found = false;
foreach ($env_search_paths as $p) {
    if (file_exists($p)) { $env_found = true; break; }
}

if (!$env_found) {
    $template_path = file_exists('inc/data/template_env.ini') ? 'inc/data/template_env.ini' : 'inc/template_env.ini';
    $template_exists = file_exists($template_path);

    if (isset($_GET['create_env']) && $_GET['create_env'] === '1' && $template_exists) {
        if (!is_dir('inc/data')) @mkdir('inc/data', 0755, true);
        if (@copy($template_path, 'inc/data/env.ini')) {
            // env.ini findes nu (SQLite-sektionen kræver intet udfyldt) - gå
            // tilbage til denne side uden ?create_env, så den almindelige
            // opsætning starter forfra med den nye forbindelse.
            header('Location: index.php');
            exit;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - opsætning</title></head>
    <body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#2c3e50;">
    <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">
    <h2>⚙️ Databasen er ikke sat op endnu</h2>
    <p><code>inc/data/env.ini</code> findes ikke - den fortæller TinyCash hvilken database den skal
    bruge, og leveres bevidst ikke med i pakken (den ville indeholde dine rigtige adgangskoder).</p>
    <?php if ($template_exists): ?>
    <p>Til en hurtig demo kan den oprettes automatisk med SQLite (ingen adgangskoder at udfylde):</p>
    <p><a href="?create_env=1" style="display:inline-block;background:#3498db;color:#fff;
        padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:bold;">
        Opret env.ini automatisk (SQLite) →</a></p>
    <p style="font-size:13px;color:#7f8c8d;">Skal I bruge MySQL i stedet, så kopiér selv
    <code>inc/data/template_env.ini</code> til <code>inc/data/env.ini</code>, udfyld
    <code>[mysql_config]</code>-sektionen, og genindlæs siden.</p>
    <?php else: ?>
    <p>Opret <code>inc/data/env.ini</code> manuelt (fx ud fra <code>db-setup/README.md</code>) og
    genindlæs siden.</p>
    <?php endif; ?>
    </div></body></html><?php
    ob_end_flush();
    exit;
}

require_once 'inc/db_connect.inc.php';

function bootstrap_users_exist($conn): bool {
    if (!$conn) return false;
    $check = @DB::query($conn, "SELECT COUNT(*) FROM users");
    if (!$check) return false;
    $row = DB::fetch_row($check);
    return ((int)($row[0] ?? 0) > 0);
}

// Opsætningen er allerede kørt (fx hvis selv-udskiftningen af en eller
// anden grund ikke lykkedes ved en tidligere anmodning) - lad
// finalize_bootstrap.php gøre ombytningen færdig og send videre, i stedet
// for at vise opsætningssiden igen.
if (bootstrap_users_exist($conn)) {
    header('Location: db-setup/finalize_bootstrap.php');
    exit;
}

if (!$conn) {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - opsætning</title></head>
    <body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#2c3e50;">
    <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">
    <h2>⚠️ Ingen databaseforbindelse</h2>
    <p>Kunne ikke oprette forbindelse til databasen. Tjek <code>inc/data/env.ini</code> (kopiér evt.
    <code>inc/data/template_env.ini</code> og udfyld den) - bl.a. <code>ACTIVE_DB</code> og den
    tilhørende <code>[mysql_config]</code>/<code>[sqlite_config]</code>-sektion.</p>
    <p>Genindlæs siden når forbindelsen virker.</p>
    </div></body></html><?php
    ob_end_flush();
    exit;
}

if (!isset($_GET['run']) || $_GET['run'] !== '1') {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - førstegangsopsætning</title></head>
    <body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#2c3e50;">
    <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">
    <h2>🚀 Velkommen til TinyCash</h2>
    <p>Databasen er tom. Klik herunder for at oprette en demo-installation med eksempeldata
    (tema: H.C. Andersens eventyr), en kontoplan, momskoder og en admin-bruger.</p>
    <p style="font-size:13px;color:#7f8c8d;">Motor: <?php echo htmlspecialchars($db_type); ?></p>
    <p><a href="?run=1" style="display:inline-block;background:#3498db;color:#fff;padding:10px 20px;
        border-radius:4px;text-decoration:none;font-weight:bold;">Opret demo-database →</a></p>
    </div></body></html><?php
    ob_end_flush();
    exit;
}

// ---- Kør selve opsætningen ----
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - sætter demo op...</title></head>
<body style="font-family:sans-serif;max-width:700px;margin:60px auto;padding:0 20px;color:#2c3e50;">
<div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">
<h2>🚀 Sætter TinyCash-demo op...</h2>
<pre style="white-space:pre-wrap;font-size:13px;background:#fff;padding:15px;border-radius:4px;
    border:1px solid #eee;max-height:400px;overflow:auto;"><?php

// Dobbelttjek lige inden vi skriver (værn mod to samtidige klik/genindlæsninger).
if (bootstrap_users_exist($conn)) {
    echo "Opsætningen er allerede kørt (af en anden anmodning). Går videre...\n";
} else {
    require __DIR__ . '/db-setup/init_demo_data.php';
    echo "\n";
    require __DIR__ . '/db-setup/create_all_tables.core.php';
    create_all_tables_for($conn, $db_type);
}

// De to scripts ovenfor sætter selv Content-Type: text/plain - gendan html
// for resten af siden.
header('Content-Type: text/html; charset=utf-8', true);
?></pre>
<h3>✅ Færdig!</h3>
<p>Log ind med:</p>
<ul>
    <li>Brugernavn: <b>admin</b></li>
    <li>Kodeord: <b>eventyr123</b></li>
</ul>
<p><b>Vigtigt:</b> skift kodeordet efter første login, og slet mapperne <code>setup/</code> og
<code>db-setup/</code>, når du er færdig med opsætningen.</p>
<p><a href="db-setup/finalize_bootstrap.php" style="display:inline-block;background:#2ecc71;
    color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:bold;">
    Fortsæt til login →</a></p>
</div></body></html>
<?php
ob_end_flush();
