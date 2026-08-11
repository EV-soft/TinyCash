<?php # set_lang.php v:1.2.0 d:2026-07-10 i:claude (Rettet: manglende session_name() gav sprogvalget til en forkert, isoleret session)

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

// 2. Hent sprogkoden fra menuens link (?l=da eller ?l=en)
$lang = isset($_GET['l']) ? trim($_GET['l']) : 'da';

// 3. Opdater BEGGE session-variabler så både menu og bibliotek forstår det
$_SESSION['lang'] = $lang;

if ($lang === 'en') {
    $_SESSION['proglang'] = 'en : English';
} else {
    $_SESSION['proglang'] = 'da : Dansk'; // Standard fallback
}

// 4. Send brugeren tilbage til den side, de kom fra
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
header("Location: " . $referer);
exit;
?>