<?php # set_lang.php v:0.8 d:2026-04-12 i:Gemini m:1
session_start();

// 1. Hent den valgte sprogkode (f.eks. 'da' eller 'en')
$lang = $_GET['l'] ?? 'en';

// 2. Validering: Tillad kun koder på 2 bogstaver (sikkerhed)
if (preg_match('/^[a-z]{2}$/', $lang)) {
    $_SESSION['lang'] = $lang;
}

// 3. Send brugeren tilbage til den side, de kom fra
if (isset($_SERVER['HTTP_REFERER'])) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
} else {
    header("Location: index.page.php");
}
exit;