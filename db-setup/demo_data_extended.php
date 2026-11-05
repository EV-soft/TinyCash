<?php # /db-setup/demo_data_extended.php v:1.0.0 d:2026-11-05 i:evs
# Udvidede demodata til aftestning af alle moduler - se demo_data_extended.core.php. Kun for administrator, kun på et
# demo-/testregnskab: det bogfører fakturaer, betalinger og udgifter. Kører kun én gang pr. regnskab.
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...') virker fra db-setup/ (ellers 500)
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/demo_data_extended.core.php';
header('Content-Type: text/plain; charset=utf-8');

// Bogfører i regnskabet - kræver et bevidst klik (POST), ikke et besøg
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['run'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Udvidede demodata</title><body style="font-family:sans-serif; max-width:640px; margin:40px auto;">'
       . '<h2>Udvidede demodata</h2><p>Opretter kunder, leverandører, fakturaer i alle tilstande (betalt, delvist betalt, forfalden, rykket, EUR, '
       . 'krediteret, afskrevet), tilbud, udgifter, faste fakturaer/udgifter, timer, budgetter, banklinjer til afstemning m.m. - '
       . '<b>bogført i det aktive regnskab</b>. Brug det kun på et demo-/testregnskab. Kører kun én gang.</p>'
       . '<form method="post">' . csrf_field(false) . '<input type="hidden" name="run" value="1">'
       . '<button type="submit" style="padding:10px 20px;">Opret udvidede demodata</button></form></body>';
    exit;
}
[$ok, $err] = seed_demo_data_extended($conn);
echo "\n" . ($err ? "Der var fejl - se ovenfor.\n" : "Alt oprettet.\n");
