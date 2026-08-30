<?php # /tools/tinycash.boot.php v:1.3.0 d:2026-08-30 i:evs
# NY FIL (flere-regnskaber-funktionen, Fase 3) - STANDALONE installations-
# script. Uploades til en NY, TOM, web-tilgængelig mappe SAMMEN MED
# tinycash.boot.zip (bygget af tools/build_boot_zip.php) og køres derefter
# ÉN GANG ved at åbne den i en browser. Ligger uden for selve TinyCash-
# kodetræet (findes kun her i tools/ som kildefil for distributionen -
# tools/ er i forvejen blokeret af tools/.htaccess på DENNE installation,
# hvilket er fuldstændig korrekt: denne fil skal aldrig ligge tilgængelig
# fra den FÆRDIGE installations egen tools/-mappe, kun distribueres separat).
#
# MODSAT den ældre ZIP-fresh-install-pakke (tools/build_demo_zip.php +
# db-setup/bootstrap_index.php + db-setup/finalize_bootstrap.php), som
# bygger demo-databasen LIVE ved serverens allerførste sidekald og derfor må
# bytte index.php/index.real.php om bagefter (se finalize_bootstrap.php's
# egen begrundelse om fillåsning under kørsel), leverer denne pakke et
# ALLEREDE opsat, færdig-seedet demo - dette scripts eneste arbejde er selve
# udpakningen. Ingen ombytning, ingen live database-opbygning.
#
# Selv-oprydning af de to boot-filer er BEVIDST udskudt til et manuelt trin
# (samme årsag som finalize_bootstrap.php dokumenterer for sin egen
# ombytning: en fil kan ikke altid slette/omdøbe sig selv pålideligt, mens
# den aktivt udføres) - se "Vigtigt"-teksten i succes-siden nedenfor.

function tcb_fail(string $title, string $message): void {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - installation</title></head>'
       . '<body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#2c3e50;">'
       . '<div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">'
       . '<h2>⚠️ ' . htmlspecialchars($title) . '</h2>'
       . '<p>' . $message . '</p>'
       . '</div></body></html>';
    exit;
}

// --- Nægt at overskrive en eksisterende installation -----------------------
// Kun beregnet til en helt tom mappe - en anden fil ved samme navn ville
// ellers kunne udpakke demo-pakken oven i rigtige data ved et uheld.
if (file_exists(__DIR__ . '/index.php') || file_exists(__DIR__ . '/inc/data/env.ini')) {
    tcb_fail(
        'Der findes allerede en installation her',
        'Denne mappe ser ud til allerede at indeholde en TinyCash-installation '
        . '(<code>index.php</code> eller <code>inc/data/env.ini</code> findes allerede). '
        . 'Kør kun dette script i en helt tom mappe, for ikke at risikere at overskrive rigtige data.'
    );
}

$zip_path = __DIR__ . '/tinycash.boot.zip';
if (!file_exists($zip_path)) {
    tcb_fail(
        'tinycash.boot.zip mangler',
        '<code>tinycash.boot.zip</code> blev ikke fundet i samme mappe som dette script. '
        . 'Upload begge filer sammen, og prøv igen.'
    );
}

if (!class_exists('ZipArchive')) {
    tcb_fail(
        'PHP mangler zip-understøttelse',
        'PHP\'s zip-udvidelse (<code>ext-zip</code>) er ikke installeret/aktiveret på denne server. '
        . 'Kontakt din hostingudbyder, eller udpak <code>tinycash.boot.zip</code> manuelt via FTP/filhåndtering i stedet.'
    );
}

// --- Udpakning ---------------------------------------------------------
$zip = new ZipArchive();
if ($zip->open($zip_path) !== true) {
    tcb_fail('Kunne ikke åbne pakken', '<code>tinycash.boot.zip</code> kunne ikke åbnes - filen er muligvis beskadiget under upload. Prøv at uploade den igen (som binær/FTP, ikke tekst-tilstand).');
}
if (!$zip->extractTo(__DIR__)) {
    $zip->close();
    tcb_fail('Kunne ikke udpakke pakken', 'Tjek at webserverens bruger har skriverettighed til denne mappe, og prøv igen.');
}
$zip->close();

// --- Bekræft at det udpakkede demo faktisk er der og er brugbart --------
$env_path = __DIR__ . '/inc/data/env.ini';
$db_path  = __DIR__ . '/inc/data/tinycash.sqlite';
if (!file_exists($env_path) || !file_exists($db_path)) {
    tcb_fail(
        'Pakken er ufuldstændig',
        'Udpakningen gennemførtes, men <code>inc/data/env.ini</code> eller <code>inc/data/tinycash.sqlite</code> mangler bagefter - '
        . 'pakken er muligvis bygget forkert. Kontakt den, der har lavet pakken.'
    );
}
if (!is_writable($db_path) || !is_writable(dirname($db_path))) {
    tcb_fail(
        'Databasen er ikke skrivbar',
        '<code>inc/data/tinycash.sqlite</code> (eller dens mappe) er ikke skrivbar for webserveren - nødvendigt for løbende brug '
        . '(fakturering, bogføring m.m.). Ret filrettighederne (fx <code>chmod 664</code> på filen og <code>775</code> på mappen '
        . 'via FTP/SSH) og genindlæs siden.'
    );
}

// --- Registrér det udpakkede demo som regnskab #1 (best-effort) ---------
// Klar til flere-regnskaber-funktionen fra dag ét (se inc/account.lib.php)
// - med kun ét registreret regnskab viser login.php dog ingen vælger, det
// vælges automatisk, så login-oplevelsen er uændret uanset om dette trin
// lykkes. Fejler det (fx skrive-rettighedsproblem), er det derfor IKKE
// fatalt for selve installationen - login virker under alle omstændigheder
// via den almindelige env.ini-forbindelse.
$account_registered = false;
$account_lib = __DIR__ . '/inc/account.lib.php';
if (file_exists($account_lib)) {
    require_once $account_lib;
    if (function_exists('account_save')) {
        $account_registered = account_save([
            'id'      => 'demo-boot',
            'name'    => 'Demo (H.C. Andersen)',
            'engine'  => 'sqlite',
            'db_path' => 'data/tinycash.sqlite',
            'is_demo' => true,
            'active'  => true,
        ]);
    }
}

// --- Færdig --------------------------------------------------------------
// NY (bruger-anmodet installationssporing): et Matomo-pageview-kald til
// evs' egen selv-hostede Matomo-instans - tæller hvor mange gange denne 
// succes-side reelt bliver vist, dvs. hvor mange NYE installationer der
// reelt gennemføres i praksis. Klient-side
// (browserens JS kalder ud, ikke serveren selv), udløses PRÆCIS ÉN gang pr.
// vellykket installation, og UDELUKKENDE her på selve boot-installerens
// succes-side - ingen tilsvarende sporing er tilføjet noget andet sted i
// selve den installerede applikation (login.php, index.php m.fl. er urørt).
// Bruger-bekræftet valg: altid slået til, intet tilvalg (afveget bevidst fra
// resten af appens ellers gennemgående "intet udadtil uden dit eget
// samtykke/opsætning"-princip - se tinycash.boot.txt for den tilhørende
// gennemsigtigheds-note til den der installerer pakken).
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - installation gennemført</title>
<!-- Matomo -->
<script>
  var _paq = window._paq = window._paq || [];
  /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u="//viuff.info/piwik/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '22']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Matomo Code --></head>
<body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#2c3e50;">
<div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">
<h2>✅ TinyCash er klar</h2>
<p>Pakken er udpakket, og en færdig demo-database (tema: H.C. Andersens eventyr) er allerede sat op -
ingen yderligere opsætning nødvendig.</p>
<p>Log ind med:</p>
<ul>
    <li>Brugernavn: <b>admin</b></li>
    <li>Kodeord: <b>eventyr123</b></li>
</ul>
<?php if (!$account_registered): ?>
<p style="font-size:13px;color:#e67e22;">Bemærk: regnskabet kunne ikke registreres i regnskabs-vælgeren automatisk
(harmløst - login virker som normalt, men "Opret nyt demo-regnskab" i Regnskaber-menuen kan opsætte den, hvis du
ønsker det senere).</p>
<?php endif; ?>
<p><a href="login.php" style="display:inline-block;background:#2ecc71;color:#fff;padding:10px 20px;
    border-radius:4px;text-decoration:none;font-weight:bold;">Fortsæt til login →</a></p>
<?php
// NY (bruger-anmodet: "kan udbygges så den tilsidst viser/omtaler
// færdiggørelsen") - kort, sammenfoldet oversigt over de valgfrie trin fra
// tinycash.boot.txt, direkte på selve succes-siden, i stedet for at kræve
// at brugeren finder og læser den separate fil selv. Holdt bevidst kort
// (én linje pr. punkt) - den fulde forklaring med eksempler/env.ini-nøgler
// ligger stadig kun i tinycash.boot.txt, som denne boks henviser videre til.
$boot_txt_exists = file_exists(__DIR__ . '/tinycash.boot.txt');
?>
<details style="margin-top:20px; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
    <summary style="cursor:pointer; font-weight:bold; color:#2c3e50;">🚀 Næste skridt: gør det til et fuldgyldigt program (valgfrit)</summary>
    <p style="font-size:13px; color:#555; margin-top:10px;">Alt herunder er valgfrit. De fleste punkter slås til/fra i <code>inc/data/env.ini</code> - ingen genstart nødvendig, blot gem og genindlæs siden:</p>
    <ul style="font-size:13px; color:#555;">
        <li><b>Dine egne firmaoplysninger</b> - udskift demo-firmaets navn/adresse/CVR/logo under <b>System -> Indstillinger</b>, FØR du udsteder en rigtig faktura.</li>
        <li><b>Leverandører/Anlægsaktiver/Tilbud/Timeregistrering</b> - denne pakke leverer bevidst kun grundskemaet; kør de relevante migrationer under <b>System -> Vedligeholdelse -> Database migration</b> (<code>run_migrate.php</code>) for at låse op for de moduler du vil bruge.</li>
        <li><b>Regnskabsår</b> (kun nødvendigt før Årsafslutning/Årsrapport) - kør <code>db-setup/migrate_fiscal_years.php</code> én gang for at angive dit faktiske regnskabsårs start-/slutdato.</li>
        <li><b>MySQL-database</b> i stedet for SQLite - udfyld <code>[mysql_config]</code>, sæt <code>ACTIVE_DB="mysql_config"</code>, kør <code>db-setup/create_all_tables.php</code> én gang (bemærk: demo-dataen flyttes ikke automatisk med).</li>
        <li><b>Afsendelse af mail</b> (fakturaer, rykkere) - udfyld <code>[mail_config]</code>.</li>
        <li><b>Modtagelse af mail</b> (auto-import af kvitteringer/fakturaer) - de tre <code>IMAP_*</code>-postkasser.</li>
        <li><b>AI-bilagsskanning og oversættelseshjælp</b> - <code>OPENAI_API_KEY</code> / <code>DEEPL_API_KEY</code>.</li>
        <li><b>Krypteret off-site backup</b> - to ting kræves: <code>[backup_config]</code> <code>BACKUP_PASSWORD</code> i env.ini OG en modtager-mail under <b>System -> Backup-administration -> Automatisk Backup</b> (uden begge dele sker der intet).</li>
        <li><b>Bankintegration (PSD2)</b> - <code>[enablebanking_config]</code>, kræver en gratis konto på enablebanking.com.</li>
    </ul>
    <p style="font-size:13px; color:#555;">
        <?php if ($boot_txt_exists): ?>
        Fuld forklaring af hvert punkt (nøjagtige env.ini-nøgler, eksempler) står i <code>tinycash.boot.txt</code>, som ligger lige her sammen med dette script.
        <?php else: ?>
        Fuld forklaring af hvert punkt findes i <code>tinycash.boot.txt</code> - den fil ser ikke ud til at være uploadet sammen med de to andre; upload den også, hvis du mangler detaljerne.
        <?php endif; ?>
        <code>inc/data/template_env.ini</code> (allerede i pakken) er samme fil med alle felter tomme, til opslag.
    </p>
</details>
<p style="font-size:13px;color:#7f8c8d;"><b>Vigtigt:</b> skift kodeordet efter første login, og slet
<code>tinycash.boot.php</code> og <code>tinycash.boot.zip</code> fra serveren, når du er færdig - de skal ikke
ligge tilgængelige permanent. Slet <b>ikke</b> <code>db-setup/</code>-mappen, selvom ældre installations-
vejledninger siger det - Database migration-siden og Regnskaber-funktionen afhænger begge af filer derinde
til løbende brug.</p>
</div></body></html>
