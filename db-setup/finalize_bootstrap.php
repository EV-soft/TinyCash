<?php # /db-setup/finalize_bootstrap.php v:1.3.0 d:2026-08-30 i:evs
/* ==========================================================================
   Sidste trin i bootstrap_index.php (den midlertidige førstegangs-
   opsætningsside i ZIP-fresh-install-pakken): omdøber index.real.php til
   index.php.

   Ligger BEVIDST i sin egen, selvstændige fil i stedet for inde i selve
   index.php: rename() kunne under test IKKE overskrive den fil der lige
   nu aktivt udføres af PHP (bekræftet på Windows - filen er låst, mens
   scriptet kører). Ved at lade denne helt separate fil stå for selve
   ombytningen, rammes den låsning aldrig, uanset platform.
   ========================================================================== */

$root = dirname(__DIR__);
$real_path = $root . '/index.real.php';

// Hvis index.real.php ikke findes længere, er ombytningen sandsynligvis
// allerede gennemført ved en tidligere anmodning - gå bare videre.
$swapped = !file_exists($real_path) || @rename($real_path, $root . '/index.php');

if ($swapped) {
    header('Location: ../login.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>TinyCash - opsætning</title></head>
<body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#2c3e50;">
<div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:30px;">
<h2>⚠️ Kunne ikke gennemføre ombytningen</h2>
<p>Databasen er sat korrekt op, men <code>index.real.php</code> kunne ikke omdøbes til
<code>index.php</code> automatisk (manglende skriverettighed på serveren?).</p>
<p>Gør det manuelt via FTP/SSH/filhåndtering, eller giv webserverens bruger skriverettighed til
projektmappen og prøv igen.</p>
<p><a href="finalize_bootstrap.php">Prøv igen →</a> ·
<a href="../login.php">Gå til login alligevel</a></p>
</div></body></html>
