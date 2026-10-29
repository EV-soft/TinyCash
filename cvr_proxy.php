<?php # /cvr_proxy.php v:1.3.1 d:2026-09-15 i:evs
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
#
# RETTET (BEK 811/2026-gennemgang, se [[digital-standard-bookkeeping-
# registration-goal]]): selve opslagslogikken (cache/kvote/cvrapi.dk-kald)
# flyttet til den delte inc/cvr_lookup.inc.php, så den nye, server-side
# company_cvr_sync_action.php (låste egne stamoplysninger) genbruger NØJAGTIG
# samme cache/kvote i stedet for at risikere en parallel, dobbelt-tællende
# implementation. Denne fil er nu en tynd wrapper - ekstern adfærd uændret.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/cvr_lookup.inc.php';

header('Content-Type: application/json; charset=utf-8');

$cvr = preg_replace('/\D/', '', $_GET['cvr'] ?? '');
echo json_encode(cvr_lookup_by_number($conn, $cvr), JSON_UNESCAPED_UNICODE);
ob_end_flush();
?>
