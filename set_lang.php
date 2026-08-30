<?php # /set_lang.php v:1.3.0 d:2026-08-30 i:evs
# Rettet: manglende session_name() gav sprogvalget til en forkert, isoleret session
# v1.3.0: KRITISK - $_GET['l'] blev gemt i $_SESSION['lang'] helt uvalideret
# (kun trim()), og inc/htm_page.lib.php ekkoer den værdi UESCAPET direkte ind
# i <html lang="...">-attributten - et link som ?l="><script>...</script>
# kunne bryde ud af attributten og køre vilkårlig JavaScript for enhver der
# klikkede det (XSS). Valideres nu til nøjagtig samme mønster som
# auth.inc.php's egen sprogvælger bruger. Desuden rettet en åben
# omdirigering: Location sendte FØR direkte til $_SERVER['HTTP_REFERER'],
# som ikke er til at stole på. Fundet ved en gennemgang af tilbageværende sider.

// RETTET: session_name() SKAL sættes til nøjagtig samme værdi som resten af
// systemet (login.php, auth.inc.php) bruger. Uden denne linje starter PHP en
// helt separat session under standardnavnet (PHPSESSID), og $_SESSION['lang']
// bliver gemt der - usynligt for resten af appen, som leder i TCC_V100_SESSION.
// Det var derfor sprogvalget "ikke virkede": værdien blev rent faktisk gemt,
// bare i den forkerte session. Cookie-parametrene herunder er kopieret 1:1
// fra auth.inc.php for fuld konsistens (relevant hvis denne fil nogensinde
// rammes som allerførste side i en helt frisk browser-session).
if (session_status() === PHP_SESSION_NONE) {
    $session_time = 14400; // 4 timer - matcher auth.inc.php
    ini_set('session.gc_maxlifetime', $session_time);
    session_name('TCC_V100_SESSION');
    session_set_cookie_params([
        'lifetime' => $session_time,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 2. Hent sprogkoden fra menuens link (?l=da eller ?l=en) - valideres til
// præcis 2 små bogstaver, samme mønster som auth.inc.php's egen sprogvælger.
// Uden dette kunne en vilkårlig streng gemmes i $_SESSION['lang'] og senere
// blive ekkoet uescapet i <html lang="..."> (inc/htm_page.lib.php) - XSS.
$requested_lang = isset($_GET['l']) ? trim($_GET['l']) : 'da';
$lang = preg_match('/^[a-z]{2}$/', $requested_lang) ? $requested_lang : 'da';

// 3. Opdater BEGGE session-variabler så både menu og bibliotek forstår det
$_SESSION['lang'] = $lang;

if ($lang === 'en') {
    $_SESSION['proglang'] = 'en : English';
} else {
    $_SESSION['proglang'] = 'da : Dansk'; // Standard fallback
}

// 4. Send brugeren tilbage til den side, de kom fra - KUN hvis Referer peger
// på samme site. HTTP_REFERER er en klient-styret header og må ikke bruges
// ukritisk til en omdirigering (åben omdirigering) - faldt før direkte
// tilbage til hvad som helst klienten sendte.
$referer   = $_SERVER['HTTP_REFERER'] ?? '';
// PHP_URL_HOST har ALDRIG port med, så $_SERVER['HTTP_HOST'] (som KAN have
// ':port') skal renses på samme måde før sammenligning - ellers ville
// "localhost:8105" aldrig matche "localhost", og selv et helt legitimt,
// samme-site referer ville fejlagtigt blive afvist.
$self_host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? '';
$ref_host  = $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;
$redirect_to = ($ref_host !== null && $self_host !== '' && strcasecmp($ref_host, $self_host) === 0)
    ? $referer
    : 'index.php';
header("Location: " . $redirect_to);
exit;
?>