<?php # set_lang.php v:1.1.0 d:2026-07-02 i:evs
// 1. Start sessionen så data gemmes
session_start();

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