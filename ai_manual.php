<?php # /ai_manual.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION (bruger-anmodet): "en AI-manual som enhver ai-server kan
# læse". Serverer docs/ai_manual.md - en grundig, bruger-vendt (ikke kode-
# vendt) beskrivelse af hvad TinyCash kan, skrevet specifikt til at blive
# fodret til en AI (chatbot/assistent) som skal svare på en brugers
# spørgsmål om programmets muligheder. Bevidst UDEN inc/auth.inc.php - siden
# skal kunne hentes af en ekstern AI/et automatiseret værktøj uden login,
# nøjagtig som en offentlig produkt-dokumentationsside ville være det.
# Indholdet er ren funktionsbeskrivelse (ingen firma-/regnskabsdata), samme
# følsomhedsniveau som fx en offentlig README - matcher login.php's egen
# undtagelse fra login-kravet, af samme grund.
#
# ?raw=1 returnerer den rå markdown (text/markdown) til maskinel hentning -
# standard-visningen (uden parametret) er en læsevenlig HTML-indpakning af
# PRÆCIS samme tekst, uden nogen markdown-tolkning der kunne forvanske
# indholdet (bevidst \<pre\>, ikke en hjemmelavet markdown-renderer).
$manual_path = __DIR__ . '/docs/ai_manual.md';

if (!file_exists($manual_path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "AI-manualen findes ikke (docs/ai_manual.md mangler).";
    exit;
}

$content = file_get_contents($manual_path);

if (isset($_GET['raw'])) {
    header('Content-Type: text/markdown; charset=utf-8');
    echo $content;
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<title>TinyCash — AI-manual</title>
<meta name="robots" content="index, follow">
<style>
    body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; background:#f4f7f6; color:#2c3e50; margin:0; padding:0 20px 60px; }
    .wrap { max-width: 820px; margin: 0 auto; }
    .banner { background:#2c3e50; color:#fff; padding:25px 20px; margin: 0 -20px 25px; }
    .banner h1 { margin:0 0 8px 0; font-size:1.6em; }
    .banner p { margin:0; color:#bdc3c7; font-size:0.95em; }
    .toolbar { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
    .toolbar a { background:#3498db; color:#fff; text-decoration:none; padding:8px 16px; border-radius:4px; font-size:0.9em; font-weight:600; }
    .toolbar a.secondary { background:#95a5a6; }
    pre.manual { white-space: pre-wrap; word-wrap: break-word; background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:30px; font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size:14.5px; line-height:1.6; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
</style>
</head>
<body>
<div class="banner"><div class="wrap">
    <h1>🤖 TinyCash — AI-manual</h1>
    <p>En maskinlæsbar funktionsoversigt til AI-assistenter og supportværktøjer. Kræver ikke login.</p>
</div></div>
<div class="wrap">
    <div class="toolbar">
        <a href="?raw=1">📄 Rå Markdown (til AI/maskinel hentning)</a>
        <a href="index.php" class="secondary">← Til TinyCash</a>
    </div>
    <pre class="manual"><?php echo htmlspecialchars($content); ?></pre>
</div>
</body>
</html>
