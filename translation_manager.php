<?php # /translation_manager.php v:1.3.0 d:2026-08-30 i:evs
# fund fra [[danish-translation-batch-fill]]: scannet brugte rå, u-afkodet kildetekst som nøgle
# v1.4.0: enhver lang()-nøgle med \n (dobbelt-anførte strenge) eller \'
# (enkelt-anførte strenge) kunne aldrig matche i praksis, fordi PHP selv
# afkoder disse FØR lang() ser strengen, men scanneren brugte den rå,
# u-afkodede kildetekst som nøgle. Ny tm_unescape_php_string() retter det.
# RETTET (§bugs-batch-20-review): denne side havde INTET adgangstjek
# overhovedet - enhver logget-ind bruger, uanset niveau, kunne overskrive
# json-data/languages.json (UI-teksten for ALLE brugere, på alle sprog) via
# POST, og kunne udløse live OpenAI-kald (getAiSuggestion($force_live_api))
# på ejerens API-nøgle uden nogen begrænsning. Kræver nu admin-niveau, samme
# mønster som backup_restore.php.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$json_file = __DIR__ . '/json-data/languages.json';

if (!file_exists($json_file)) {
    die(lang("@Error: JSON file not found at path:") . " " . htmlspecialchars($json_file));
}

$master_data = json_decode(file_get_contents($json_file), true);
if ($master_data === null) {
    die(lang("@Error: Could not decode languages.json. Check for syntax errors."));
}

$current_edit_lang = $_SESSION['lang'] ?? 'da';

// Find sprog-index
$lang_index = null;
if (isset($master_data['language']) && is_array($master_data['language'])) {
    foreach ($master_data['language'] as $idx => $lang_obj) {
        if (($lang_obj['code'] ?? '') === $current_edit_lang) {
            $lang_index = $idx;
            break;
        }
    }
}

if ($lang_index === null) {
    die(lang("@Error: Language not found in JSON configuration."));
}

// --- AUTOMATISK INDLÆSNING AF API-NØGLE ---
function getOpenAiApiKey() {
    if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
        return OPENAI_API_KEY;
    }

    // RETTET: env.ini flyttet til inc/data/env.ini - de gamle stier bevaret
    // som bagudkompatibel fallback.
    $paths = [
        __DIR__ . '/inc/data/env.ini',
        __DIR__ . '/inc/env.ini',
        __DIR__ . '/inc/.env',
        __DIR__ . '/env.ini',
        __DIR__ . '/.env'
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            $ini = parse_ini_file($path);
            if (isset($ini['OPENAI_API_KEY']) && !empty($ini['OPENAI_API_KEY'])) {
                return trim($ini['OPENAI_API_KEY'], '"\' ');
            }
        }
    }
    return '';
}

// --- OPENAI DETEKTOR ---
function getAiSuggestion($key, $target_lang, $force_live_api = false) {
    $clean = ltrim($key, '@');

    if (!$force_live_api) {
        $dictionary = [
            'overview' => 'Oversigt', 'sales' => 'Salg', 'purchases' => 'Køb', 'finance' => 'Finans',
            'inventory' => 'Lager', 'production' => 'Produktion', 'system' => 'System', 'logout' => 'Log ud',
            'dashboard' => 'Kontrolpanel', 'error' => 'Fejl', 'missing' => 'Mangler', 'vat' => 'Moms'
        ];
        $lower = strtolower($clean);
        return $dictionary[$lower] ?? $clean;
    }

    $api_key = getOpenAiApiKey();
    if (empty($api_key)) {
        return "[Mangler API Nøgle i env.ini]: " . $clean;
    }

    $lang_names = [
        'da' => 'Danish',
        'en' => 'English',
        'se' => 'Swedish',
        'no' => 'Norwegian',
        'kl' => 'Greenlandic',
        'es' => 'Spanish',
        'pt' => 'Portuguese'
    ];
    $target_lang_name = $lang_names[$target_lang] ?? $target_lang;

    $url = 'https://api.openai.com/v1/chat/completions';
    $prompt = "Translate the following ERP/accounting system UI key or phrase into natural, clean, business-accurate {$target_lang_name}. Do NOT output English unless the target language is English. Return ONLY the raw translation, nothing else.\n\nPhrase: " . $clean;

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => "You are an expert ERP, bookkeeping, and accounting software localization tool. You translate phrases strictly into {$target_lang_name}. You never output explanations, code, markdown, or English text unless explicitly requested."],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.1
    ];

    $ch = tc_curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        // RETTET (bruger-rapport "oversættelsesforslag er stadig blankt"): denne
        // gren faldt tidligere tavst tilbage til $clean (den urørte engelske
        // nøgle) ved ENHVER curl-fejl - inkl. den konkrete, reelt ramte
        // "SSL certificate ... unable to get local issuer certificate", se
        // tc_curl_init() i inc/db_connect.inc.php. Resultatet lignede et
        // gyldigt AI-forslag (success:true) uden nogensinde at være det.
        // Viser nu den faktiske curl-fejl i stedet, så et resterende/nyt
        // netværksproblem er synligt frem for at ligne en (ikke-eksisterende)
        // oversættelse.
        $err = curl_error($ch);
        curl_close($ch);
        return "[AI-kald fejlede: {$err}]";
    }
    curl_close($ch);

    $result = json_decode($response, true);
    $suggested_text = $result['choices'][0]['message']['content'] ?? '';

    return !empty($suggested_text) ? trim($suggested_text, '"\' ') : $clean;
}

// --- AJAX ENDPOINT ---
if (isset($_GET['action']) && $_GET['action'] === 'get_openai_suggestion') {
    header('Content-Type: application/json');
    $phrase_to_translate = $_GET['phrase'] ?? '';
    $target_language_code = $_GET['target_lang'] ?? 'da';

    if (empty($phrase_to_translate)) {
        echo json_encode(['success' => false, 'error' => 'Missing phrase']);
        exit;
    }

    $suggestion = getAiSuggestion($phrase_to_translate, $target_language_code, true);
    echo json_encode(['success' => true, 'suggestion' => $suggestion]);
    exit;
}

// RETTET (fund 2026-08-23, se [[danish-translation-batch-fill]]): scannet
// nedenfor har hidtil brugt den RÅ kildetekst mellem anførselstegnene direkte
// som nøgle - uden at afkode PHP's egne escape-sekvenser først. Det betyder
// enhver lang()-nøgle med et \n (dobbelt-anførte strenge) eller en \'
// (enkelt-anførte strenge) ALDRIG kunne matche i praksis: PHP omdanner selv
// "\n" til et rigtigt linjeskift og '\'' til et rigtigt anførselstegn, FØR
// lang() nogensinde ser strengen - så den gemte (u-afkodede) nøgle og den
// virkelige runtime-nøgle var to forskellige strenge. Ramte konkret bl.a.
// reminder_action.php, year_end_close.php, project_edit.php, annual_report.php.
// Afkoder nu strengen efter samme regler som PHP selv bruger, afhængigt af om
// kildekoden brugde enkelt- eller dobbelt-anførselstegn (bevaret som gruppe 1
// i begge regexer nedenfor).
function tm_unescape_php_string(string $raw, string $quote): string {
    $len = strlen($raw);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($c !== '\\' || $i + 1 >= $len) { $out .= $c; continue; }
        $next = $raw[$i + 1];
        if ($quote === "'") {
            // Enkelt-anførte PHP-strenge: KUN \\ og \' er reelle escapes -
            // alt andet (inkl. \n, \t) forbliver bogstaveligt, også ved runtime.
            if ($next === '\\' || $next === "'") { $out .= $next; $i++; }
            else { $out .= $c; }
        } else {
            // Dobbelt-anførte PHP-strenge: de escapes der reelt forekommer i
            // dette projekts lang()-nøgler. Oktal/hex/unicode-escapes er
            // bevidst ikke understøttet (forekommer ikke i praksis her) -
            // en ukendt sekvens efterlades bogstaveligt, samme fallback som
            // PHPs egen parser bruger for ukendte dobbelt-anførte escapes.
            switch ($next) {
                case 'n': $out .= "\n"; $i++; break;
                case 'r': $out .= "\r"; $i++; break;
                case 't': $out .= "\t"; $i++; break;
                case 'v': $out .= "\v"; $i++; break;
                case 'f': $out .= "\f"; $i++; break;
                case 'e': $out .= "\x1B"; $i++; break;
                case '\\': $out .= '\\'; $i++; break;
                case '"': $out .= '"'; $i++; break;
                case '$': $out .= '$'; $i++; break;
                default: $out .= $c;
            }
        }
    }
    return $out;
}

// --- SCAN KILDEKODE ---
$baseDir = __DIR__ . DIRECTORY_SEPARATOR;
$incDir  = $baseDir . 'inc' . DIRECTORY_SEPARATOR;
$scanDirs = array_filter([$baseDir, $incDir], 'is_dir');

$phpFiles = [];
foreach ($scanDirs as $dir) {
    $files = glob($dir . "*.{php,inc,inc.php,page.php}", GLOB_BRACE);
    if ($files) $phpFiles = array_merge($phpFiles, $files);
}
$phpFiles = array_unique($phpFiles);

$used_phrases_in_code = [];
foreach ($phpFiles as $file) {
    $content = file_get_contents($file);

    // 1) Fanger lang('...') / lang("...") - bevarer eksisterende opførsel.
    //    (Håndterer også nøgler uden '@'-præfiks defensivt, ved at tilføje
    //    det bagefter, ligesom før.)
    if (preg_match_all('/lang\(\s*(["\'])(.*?)\1\s*\)/i', $content, $matches_lang)) {
        foreach ($matches_lang[2] as $idx => $matched_phrase) {
            $matched_phrase = tm_unescape_php_string($matched_phrase, $matches_lang[1][$idx]);
            $clean_phrase = (strpos($matched_phrase, '@') === 0) ? $matched_phrase : '@' . $matched_phrase;
            $used_phrases_in_code[$clean_phrase] = true;

            if (!isset($master_data['language'][$lang_index]['translation'][$clean_phrase])) {
                $master_data['language'][$lang_index]['translation'][$clean_phrase] = '';
            }
        }
    }

    // 2) NYT: Fanger ALLE '@'-præfikserede strengliteraler i filen, uanset
    //    om de står inde i et lang()-kald eller ej. Mange nøgler bliver
    //    aldrig skrevet som lang('@X') i kildekoden - de sendes i stedet
    //    direkte som parameter til en htm_*-hjælpefunktion, f.eks.
    //    htm_Button(labl: '@Save'), htm_Badge('@Status'),
    //    htm_Field(hint: '@Some hint text') - og lang() kaldes først
    //    INDE I selve hjælpefunktionen, så det gamle udtryk aldrig så dem.
    //    Konventionen i hele projektet er, at en oversættelsesnøgle ALTID
    //    starter med '@' som allerførste tegn inde i citationstegnene, så
    //    det er et sikkert og præcist kendetegn at matche på.
    if (preg_match_all('/(["\'])(@(?:\\\\.|(?!\1).)*)\1/', $content, $matches_at)) {
        foreach ($matches_at[2] as $idx => $matched_phrase) {
            $matched_phrase = tm_unescape_php_string($matched_phrase, $matches_at[1][$idx]);
            $used_phrases_in_code[$matched_phrase] = true;

            if (!isset($master_data['language'][$lang_index]['translation'][$matched_phrase])) {
                $master_data['language'][$lang_index]['translation'][$matched_phrase] = '';
            }
        }
    }
}
ksort($master_data['language'][$lang_index]['translation']);

// POST Håndtering
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $old_key = $_POST['old_key'] ?? '';
        $new_key = trim($_POST['key'] ?? '');
        $value = trim($_POST['value'] ?? '');

        // RETTET (bruger-rapport "ophørt med at oversætte efter seneste
        // revision"): reproduceret og bekræftet - EN ENESTE ugyldig UTF-8-byte
        // i den INDSENDTE $_POST['value'] (fx indsat/limet fra Word, en PDF,
        // eller visse ældre input-metoder der fejlkoder æ/ø/å) fik
        // json_encode() til at returnere false for HELE $master_data-
        // strukturen (alle 13 sprog), som derefter blev sendt uændret til
        // file_put_contents() - PHP tvinger `false` til '' i strengkontekst,
        // så hele json-data/languages.json blev tavst trunkeret til 0 byte,
        // uden nogen fejlmeddelelse og uden redirect (siden faldt bare
        // igennem til en almindelig gensidevisning, hvilket nøjagtigt matcher
        // rapporten om at oversættelse "er ophørt"). Reproduceret direkte ved
        // en rigtig test-gemning under denne undersøgelse.
        //
        // Rettelsen har to lag:
        //  1. Afvis eksplicit ugyldig UTF-8 i den indsendte værdi/nøgle FØR
        //     den overhovedet lægges ind i $master_data, med en synlig fejl
        //     til brugeren i stedet for tavs datatab.
        //  2. Tjek json_encode()'s returværdi eksplicit (aldrig stole på
        //     file_put_contents()'s "sandhedsværdi" alene) OG skriv atomisk
        //     via en midlertidig fil + rename(), så en hvilken som helst
        //     uventet fejl undervejs aldrig kan efterlade den LIVE fil
        //     halvskrevet eller trunkeret.
        if (!mb_check_encoding($value, 'UTF-8') || !mb_check_encoding($new_key, 'UTF-8')) {
            $save_error = '@Error: The submitted text contains invalid characters (invalid UTF-8) and was not saved, to protect the translation file.';
        } elseif (empty($new_key)) {
            $save_error = null; // uændret tidligere opførsel: tom nøgle gemmes bare ikke
        } else {
            if (strpos($new_key, '@') !== 0) {
                $new_key = '@' . $new_key;
            }

            $backup = $master_data;
            if (!empty($old_key) && $old_key !== $new_key) {
                unset($master_data['language'][$lang_index]['translation'][$old_key]);
            }
            $master_data['language'][$lang_index]['translation'][$new_key] = $value;
            ksort($master_data['language'][$lang_index]['translation']);

            $encoded = json_encode($master_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                $master_data = $backup; // gendan i hukommelsen, rør ALDRIG selve filen
                $save_error = '@Error: Could not encode the translation file (JSON error).' . ' (' . json_last_error_msg() . ')';
            } else {
                $tmp_file = $json_file . '.tmp_' . uniqid();
                if (file_put_contents($tmp_file, $encoded) !== false && rename($tmp_file, $json_file)) {
                    header("Location: translation_manager.php?msg=saved");
                    exit;
                }
                @unlink($tmp_file);
                $save_error = '@Error: Could not write the translation file to disk.';
            }
        }
    }
}

$msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved') $msg = "@Phrase saved successfully!";
}
$save_error = $save_error ?? null;

$edit_key = $_GET['edit'] ?? null;
$edit_value = $edit_key ? ($master_data['language'][$lang_index]['translation'][$edit_key] ?? '') : '';

// Ved en mislykket gemning (se $save_error ovenfor) vises redigeringsformularen
// igen med brugerens egne, ikke-gemte input - i stedet for at tabe dem og
// falde tilbage til systemguiden, som om intet var forsøgt.
if ($save_error !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_key = $_POST['old_key'] ?? $edit_key;
    $edit_value = $_POST['value'] ?? $edit_value;
}

// RETTET (§bugs-batch-25-review): denne linje kaldte FØR getAiSuggestion()
// med $force_live_api=true ubetinget, hver eneste gang siden blev åbnet med
// ?edit=X i URL'en - dvs. et ægte, betalt OpenAI-kald (op til 8 sekunders
// curl-timeout) ved simpelthen at klikke blyant-ikonet på EN HVILKEN SOM
// HELST sætning, uanset om man reelt ønskede et AI-forslag. Siden har
// allerede en selvstændig, uafhængig AJAX-endpoint til netop dette formål
// (?action=get_openai_suggestion, linje ~137) - men intet i klientens
// JavaScript kaldte den nogensinde; den lå død/ubrugt. Erstattet den
// ubetingede server-side visning med en rigtig "Hent AI-forslag"-knap, der
// bruger den eksisterende AJAX-endpoint on-demand, i stedet for at brænde et
// API-kald pr. sidevisning.
$ai_suggestion = '';

// --- BEREGN STATISTIK / STATUS FOR SPROGET ---
$stats = ['oversat' => 0, 'mangler' => 0, 'ikke_i_kildekode' => 0, 'kun_kildekode' => 0];
$raw_json_data = json_decode(file_get_contents($json_file), true);
$json_translations = $raw_json_data['language'][$lang_index]['translation'] ?? [];

foreach ($master_data['language'][$lang_index]['translation'] as $phrase => $val) {
    $in_json = isset($json_translations[$phrase]);
    $in_code = isset($used_phrases_in_code[$phrase]);
    $is_empty = empty($val);

    if ($in_code && $in_json && !$is_empty) {
        $stats['oversat']++;
    } elseif ($in_code && $in_json && $is_empty) {
        $stats['mangler']++;
    } elseif (!$in_code && $in_json) {
        $stats['ikke_i_kildekode']++;
    } else {
        $stats['kun_kildekode']++;
    }
}

// =============================================================================
// VISNING - bruger nu htm_Header()/htm_Footer() som resten af projektet.
// Dette retter to fejl på én gang:
//  1. Tema-skift virkede ikke, fordi setTheme() og data-theme-cookien kun
//     sættes op inde i htm_Header() - siden byggede tidligere sit eget
//     <head><body> og fik derfor aldrig den funktionalitet med.
//  2. data-hint-tooltips virkede ikke, fordi selve mouseover-lytteren og
//     #tc-hint-boksen kun findes inde i htm_Footer(), som siden aldrig kaldte.
// =============================================================================
htm_Header('@Language & Phrase Editor');
showMenu();
?>
<style>
    /* Kun side-specifikke klasser her - farve-/temavariabler (--bg-card,
       --text-main, --border-color osv.) kommer nu fra htm_Header()'s
       centrale :root/[data-theme]-definitioner og behøver ikke gentages. */
    .container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
    .wrapper { display: grid; grid-template-columns: 1fr 1.4fr; gap: 20px; }
    .tm-card { background: var(--bg-card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); color: var(--text-main); }
    .tm-card h3 { margin-top: 0; border-bottom: 2px solid var(--color-primary); padding-bottom: 8px; color: var(--text-main); }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 0.9em; }
    .form-group input[type="text"] { width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing: border-box; background: var(--bg-card); color: var(--text-main); }

    .status-overview-bar { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; text-align: center; }
    .status-stat-card { padding: 10px; border-radius: 6px; color: white; font-size: 12px; font-weight: bold; }
    .stat-oversat { background: var(--color-success); }
    .stat-mangler { background: var(--color-warning); color: var(--color-dark); }
    .stat-ikke-kilde { background: var(--color-danger); }
    .stat-kun-kilde { background: var(--theme-primary, #3498db); /*  var(--color-primary); */ }
    .status-stat-card span { display: block; font-size: 18px; font-weight: 700; margin-top: 2px; }

    .filter-bar { display: flex; gap: 10px; margin-bottom: 15px; }
    .search-container { position: relative; flex: 1; display: flex; align-items: center; }
    .search-container input[type="text"] { width: 100%; padding: 8px 30px 8px 10px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing: border-box; background: var(--bg-card); color: var(--text-main); }

    .clear-search-btn { position: absolute; right: 10px; background: none; border: none; color: var(--text-muted); font-size: 16px; cursor: pointer; display: none; font-weight: bold; padding: 0; line-height: 1; }
    .clear-search-btn:hover { color: var(--color-danger); }

    .filter-bar select { padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-card); color: var(--text-main); cursor: pointer; font-weight: 600; }

    #phrase_table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
    #phrase_table th, #phrase_table td { padding: 10px; border-bottom: 1px solid var(--border-color); text-align: left; word-wrap: break-word; vertical-align: middle; color: var(--text-main); }
    #phrase_table th { background: var(--bg-panel); }
    #phrase_table tr:hover { background: var(--bg-table-hover); }

    .btn { padding: 4px 8px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 11px; font-weight: bold; display: inline-block; }
    .btn-primary { background: var(--color-primary); color: var(--text-light); }
    .btn-secondary { background: var(--color-secondary); color: var(--text-light); }

    .ai-suggestion-box { background: var(--bg-panel); border: 1px dashed var(--color-purple); padding: 10px; border-radius: 4px; margin-top: 5px; font-size: 12px; display: flex; justify-content: space-between; align-items: center; color: var(--text-main); }
    .guide-box { background: var(--bg-panel); border-left: 4px solid var(--color-primary); padding: 12px; margin-bottom: 15px; font-size: 13px; line-height: 1.5; color: var(--text-main); }
</style>

<?php if ($msg) htm_Alert($msg, 'success'); ?>
<?php if ($save_error) htm_Alert($save_error, 'error'); ?>

<div class="container">
    <div class="wrapper">

        <div class="tm-card">
            <?php if (!$edit_key): ?>
                <h3><i class="fa fa-info-circle" style="color:var(--color-primary);"></i> <?php echo lang("@System Guide"); ?></h3>
                <div class="guide-box">
                    <strong><?php echo lang("@Translation flow in TinyCash:"); ?></strong>
                    <ul>
                        <li><strong><?php echo lang("@Code-driven:"); ?></strong> <?php echo lang("@The system automatically collects your lang() calls."); ?></li>
                        <li><strong><?php echo lang("@Smart suggestions:"); ?></strong> <?php echo lang("@OpenAI integration helps you translate from keys to fluent translations instantly."); ?></li>
                    </ul>
                </div>
            <?php else: ?>
                <h3>📝 <?php echo lang("@Review Phrase"); ?></h3>
                <form action="translation_manager.php" method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="old_key" value="<?php echo htmlspecialchars($edit_key); ?>">

                    <div class="form-group">
                        <label><?php echo lang("@System Key (From source code)"); ?>:</label>
                        <input type="text" id="system_key_field" name="key" readonly value="<?php echo htmlspecialchars($edit_key); ?>" style="background:var(--bg-panel); color:var(--text-muted); cursor:not-allowed;">
                    </div>

                    <div class="form-group">
                        <label><?php echo lang("@Translation"); ?>:</label>
                        <input type="text" id="translation_field" name="value" required value="<?php echo htmlspecialchars($edit_value); ?>" autofocus>

                        <div class="ai-suggestion-box">
                            <span><i class="fa fa-robot" style="color:var(--color-purple);"></i> <strong>OpenAI GPT:</strong> <span id="ai_val"><?php echo htmlspecialchars($ai_suggestion); ?></span></span>
                            <span style="display:flex; gap:5px;">
                                <?php
                                // RETTET (bruger-rapport "der er stadig ingen oversættelsesforslag",
                                // efter at cURL/SSL-rettelsen alene ikke løste det): json_encode()
                                // omslutter altid sin streng med "-tegn - når den skrives direkte ind
                                // i et onclick-attribut der SELV er afgrænset med "-tegn, uden
                                // htmlspecialchars(), lukker browseren attributten ved det allerførste
                                // anførselstegn i JSON-strengen. Ramte BOGSTAVELIG TALT hver eneste
                                // sætning (inkl. "@Blue") - knappen har aldrig virket ved et rigtigt
                                // klik, kun det direkte AJAX-endpoint (testet med curl uden om selve
                                // knappen) virkede. ENT_QUOTES sikrer at både " og ' bliver til HTML-
                                // entiteter, som browseren afkoder korrekt til bogstavelige
                                // anførselstegn, FØR JavaScript ser attributindholdet.
                                $ai_key_js = htmlspecialchars(json_encode($edit_key), ENT_QUOTES);
                                $ai_lang_js = htmlspecialchars(json_encode($current_edit_lang), ENT_QUOTES);
                                ?>
                                <button type="button" class="btn" style="background:var(--color-secondary); color:var(--text-light);" id="ai_fetch_btn" onclick="fetchAiSuggestion(<?php echo $ai_key_js; ?>, <?php echo $ai_lang_js; ?>)">
                                    <i class="fa fa-cloud-download-alt"></i> <?php echo lang("@Fetch AI suggestion"); ?>
                                </button>
                                <button type="button" class="btn" style="background:var(--color-purple); color:var(--text-light);" id="ai_btn" onclick="applyAiSuggestion()">
                                    <?php echo lang("@Use suggestion"); ?>
                                </button>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?php echo lang("@Save changes"); ?></button>
                    <a href="translation_manager.php" class="btn btn-secondary"><?php echo lang("@Cancel"); ?></a>
                </form>
            <?php endif; ?>
        </div>

        <div class="tm-card" style="max-height: 80vh; overflow-y: auto;">
            <h3>🔤 <?php echo lang("@Current Phrases"); ?></h3>

            <div class="status-overview-bar">
                <div class="status-stat-card stat-oversat">
                    <?php echo lang("@Translated (OK)"); ?>
                    <span><?php echo $stats['oversat']; ?></span>
                </div>
                <div class="status-stat-card stat-mangler">
                    <?php echo lang("@Missing translation"); ?>
                    <span><?php echo $stats['mangler']; ?></span>
                </div>
                <div class="status-stat-card stat-ikke-kilde">
                    <?php echo lang("@Not in source code"); ?>
                    <span><?php echo $stats['ikke_i_kildekode']; ?></span>
                </div>
                <div class="status-stat-card stat-kun-kilde">
                    <?php echo lang("@Only in source code"); ?>
                    <span><?php echo $stats['kun_kildekode']; ?></span>
                </div>
            </div>

            <div class="filter-bar">
                <div class="search-container">
                    <input type="text" id="search_phrase" onkeyup="filterPhrases()" placeholder="<?php echo lang("@Search in keys or translations..."); ?>">
                    <button type="button" id="clear_btn" class="clear-search-btn" onclick="clearSearch()">&times;</button>
                </div>

                <?php
                htm_Select('status_filter', [
                    'all'              => '🔍 ' . lang('@All statuses'),
                    'oversat'          => '🟢 ' . lang('@Translated (OK)'),
                    'mangler'          => '🟡 ' . lang('@Missing translation'),
                    'ikke_i_kildekode' => '🔴 ' . lang('@Not in source code'),
                    'kun_kildekode'    => '🔵 ' . lang('@Only in source code'),
                ], 'all', '', 'onchange="filterPhrases()"');
                ?>
            </div>

            <table id="phrase_table">
                <thead>
                    <tr>
                        <th style="width: 38%;"><?php echo lang("@System Key"); ?></th>
                        <th style="width: 34%;"><?php echo lang("@Translation"); ?></th>
                        <th style="width: 20%;"><?php echo lang("@Status"); ?></th>
                        <th style="width: 8%; text-align: right;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($master_data['language'][$lang_index]['translation'] as $phrase => $val):
                        $in_json = isset($json_translations[$phrase]);
                        $in_code = isset($used_phrases_in_code[$phrase]);
                        $is_empty = empty($val);

                        if ($in_code && $in_json && !$is_empty) {
                            $status_key = "@Translated (OK)";
                            $status_variant = "success";
                            $status_text = "oversat";
                        } elseif ($in_code && $in_json && $is_empty) {
                            $status_key = "@Missing translation";
                            $status_variant = "warning";
                            $status_text = "mangler";
                        } elseif (!$in_code && $in_json) {
                            $status_key = "@Not in source code";
                            $status_variant = "danger";
                            $status_text = "ikke_i_kildekode";
                        } else {
                            $status_key = "@Only in source code";
                            $status_variant = "primary";
                            $status_text = "kun_kildekode";
                        }
                    ?>
                        <tr data-status="<?php echo $status_text; ?>">
                            <td><code style="font-weight: bold; font-size:12px;"><?php echo htmlspecialchars($phrase); ?></code></td>
                            <td><?php echo htmlspecialchars($val); ?></td>
                            <td><?php htm_Badge($status_key, $status_variant); ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="translation_manager.php?edit=<?php echo urlencode($phrase); ?>" class="btn btn-primary" style="padding:3px 8px;"><i class="fa fa-edit"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
function applyAiSuggestion() {
    var suggestion = document.getElementById("ai_val").innerText;
    document.getElementById("translation_field").value = suggestion;
}

// NYT (§bugs-batch-25-review): erstatter det tidligere ubetingede server-side
// live-API-kald ved hver sidevisning - bruger nu den eksisterende, tidligere
// ubrugte AJAX-endpoint (?action=get_openai_suggestion) on-demand, kun når
// brugeren rent faktisk beder om et forslag.
function fetchAiSuggestion(phrase, targetLang) {
    var valSpan = document.getElementById("ai_val");
    var btn = document.getElementById("ai_fetch_btn");
    valSpan.innerText = "…";
    btn.disabled = true;
    fetch("translation_manager.php?action=get_openai_suggestion&phrase=" + encodeURIComponent(phrase) + "&target_lang=" + encodeURIComponent(targetLang))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            valSpan.innerText = (data && data.success) ? data.suggestion : "(fejl)";
        })
        .catch(function() {
            valSpan.innerText = "(kunne ikke hente forslag)";
        })
        .finally(function() { btn.disabled = false; });
}

function clearSearch() {
    var searchInput = document.getElementById("search_phrase");
    searchInput.value = "";
    document.getElementById("status_filter").value = "all";
    filterPhrases();
}

function filterPhrases() {
    var searchInput = document.getElementById("search_phrase");
    var textFilter = searchInput.value.toUpperCase();
    var clearBtn = document.getElementById("clear_btn");

    if (searchInput.value.length > 0) {
        clearBtn.style.display = "block";
    } else {
        clearBtn.style.display = "none";
    }

    var statusSelect = document.getElementById("status_filter");
    var statusFilter = statusSelect.value;

    var table = document.getElementById("phrase_table");
    var tr = table.getElementsByTagName("tr");

    for (var i = 1; i < tr.length; i++) {
        var tdKey = tr[i].getElementsByTagName("td")[0];
        var tdTrans = tr[i].getElementsByTagName("td")[1];
        var rowStatus = tr[i].getAttribute("data-status") || "";

        if (tdKey || tdTrans) {
            var textMatches = tdKey.textContent.toUpperCase().indexOf(textFilter) > -1 ||
                              tdTrans.textContent.toUpperCase().indexOf(textFilter) > -1;

            var statusMatches = (statusFilter === "all") || (rowStatus === statusFilter);

            if (textMatches && statusMatches) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}
</script>

<?php htm_Footer(); ?>