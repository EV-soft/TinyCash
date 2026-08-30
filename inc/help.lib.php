<?php # /inc/help.lib.php v:1.3.0 d:2026-08-30 i:evs
# v1.2.0: scanBilagMedOpenAI() sendte PDF-bilag til Chat Completions'
# image_url, som OpenAI aldrig har understøttet PDF via (kun png/jpeg/webp/
# gif) - ethvert PDF-bilag (både rene billed-PDF'er og PDF'er med et OCR-
# tekstlag) fejlede derfor altid ved AI-scanning. PDF sendes nu til Responses
# API'et som input_file i stedet. Samtidig: fejl blev før slugt tavst
# (returnerede null) - returnerer nu ['error'=>...] så expense_edit.php's
# eksisterende fejlvisning rent faktisk vises. Se funktionens egen
# hoved-kommentar for fuld detalje.

# -------------------------------------------------------------------------
# INTERN DATA-LOGIK
# -------------------------------------------------------------------------

// RETTET (§bugs-batch-25-review): ALVORLIGT FUND - begge OpenAI-kaldssteder i
// denne fil (help-tekst-oversættelse nedenfor OG scanBilagMedOpenAI(), selve
// bilags-AI-scanningen) læste udelukkende $_ENV/$_SERVER/getenv() for
// OPENAI_API_KEY - men CLAUDE.md/db_connect.inc.php's egen konvention er at
// hemmeligheder ALTID ligger i inc/env.ini, indlæst via parse_ini_file(),
// IKKE som rigtige OS-miljøvariabler. Intet sted i hele kodebasen kopierer
// nogensinde env.ini's værdier over i $_ENV (bekræftet: ingen putenv()-kald
// findes noget sted) - så uanset hvor korrekt en bruger udfylder
// OPENAI_API_KEY i inc/env.ini, ville begge disse funktioner ALTID falde
// tilbage til den tomme streng og vise "API-nøgle er ikke sat op", som om
// nøglen aldrig var konfigureret. AI-scan-funktionen (hele drag-drop-UI'en i
// expense_edit.php) kunne derfor reelt ALDRIG fungere via den dokumenterede
// opsætning. translation_manager.php havde uafhængigt allerede fundet den
// rigtige løsning i sin egen getOpenAiApiKey() - samme mønster genbruges her.
if (!function_exists('_help_get_openai_key')) {
    function _help_get_openai_key(): string {
        if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
            return OPENAI_API_KEY;
        }
        // RETTET: env.ini flyttet til inc/data/env.ini - de to gamle stier
        // bevaret som bagudkompatibel fallback.
        $paths = [
            dirname(__DIR__) . '/inc/data/env.ini',
            dirname(__DIR__) . '/inc/env.ini',
            dirname(__DIR__) . '/env.ini',
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $ini = parse_ini_file($path);
                if (!empty($ini['OPENAI_API_KEY'])) {
                    return trim($ini['OPENAI_API_KEY'], '"\' ');
                }
            }
        }
        // Bevaret som allersidste udvej, for miljøer der reelt SÆTTER en
        // rigtig OS-miljøvariabel (fx visse container-/CI-opsætninger).
        return $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? (function_exists('getenv') ? (getenv('OPENAI_API_KEY') ?: '') : '');
    }
}

function _help_get_content($current_page, $target_lang) {
    // RETTET (§bugs-batch-17-review): kaldes to gange PR. SIDEVISNING -
    // htm_FloatingActionBar() (via _help_has_text()) for at afgøre om
    // hjælpe-knappen skal være aktiv, og htm_HelpSystem() lige efter for det
    // faktiske indhold - begge fra htm_Footer() på hver eneste sidevisning.
    // Uden caching betyder det: to fulde filindlæsninger+JSON-afkodninger af
    // master-filen for hver sidevisning, og - værre - hvis oversættelsen for
    // denne side+sprog endnu ikke er cachet på disk, et fuldt OpenAI-
    // API-kald (op til 15 sek. timeout) AFFYRET TO GANGE i træk for præcis
    // samme resultat, før den første besvarelse når at skrive sin egen
    // disk-cache. Simpel per-request static cache løser det - samme
    // $current_page+$target_lang genbruger blot det første svar.
    static $cache = [];
    $cache_key = $current_page . '|' . $target_lang;
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    // 1. Tving sprogkoden til små bogstaver (så 'DA' bliver til 'da')
    $lang = strtolower(trim($target_lang));

    // 2. Definer stien til den specifikke sprogfil
    $lang_file   = dirname(__DIR__) . "/json-data/languages/help_system_" . $lang . ".json";
    $master_file = dirname(__DIR__) . '/json-data/help_system.json';
    
    if (!file_exists($master_file)) return $cache[$cache_key] = false;
    $master_data = json_decode(file_get_contents($master_file), true);
    if (!$master_data || !isset($master_data[$current_page])) return $cache[$cache_key] = false;

    $help_lines = $master_data[$current_page];

    // Hvis sproget ikke er engelsk, forsøger vi at hente eller generere oversættelsen online
    if ($lang !== 'en') {
        $lang_data = [];
        if (file_exists($lang_file)) {
            $lang_data = json_decode(file_get_contents($lang_file), true) ?? [];
        }

        if (isset($lang_data[$current_page])) {
            // Sektionen findes allerede på det valgte sprog
            $help_lines = $lang_data[$current_page];
        } else {
            // Sektionen mangler! Vi oversætter automatisk via OpenAI API
            $apiKey = _help_get_openai_key();
            
            if (!empty($apiKey)) {
                $payload = [
                    "model" => "gpt-4o-mini", // Hurtig og præcis til strukturerede tekst-oversættelser
                    "messages" => [
                        [
                            "role" => "system",
                            "content" => "You are a professional software translator. Translate the following application help text into HTML blocks. Translate to language code: " . strtoupper($target_lang) . ". Keep all HTML tags, structure, and technical terminology intact. Return ONLY the translated lines inside a raw JSON array of strings, matching the source format. No markdown, no wrapper."
                        ],
                        [
                            "role" => "user",
                            "content" => json_encode($help_lines)
                        ]
                    ]
                ];

                $ch = tc_curl_init("https://api.openai.com/v1/chat/completions");
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $apiKey]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $response = curl_exec($ch);
                curl_close($ch);

                if ($response) {
                    $res_data = json_decode($response, true);
                    $ai_json = $res_data['choices'][0]['message']['content'] ?? '';
                    $translated_lines = json_decode(trim($ai_json), true);
                    
                    if (is_array($translated_lines)) {
                        // Gem den nye oversættelse i sprogfilen, så den er klar til næste gang
                        $lang_data[$current_page] = $translated_lines;
                        $lang_dir = dirname($lang_file);
                        if (!is_dir($lang_dir)) {
                            @mkdir($lang_dir, 0755, true);
                        }
                        file_put_contents($lang_file, json_encode($lang_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        $help_lines = $translated_lines;
                    }
                }
            }
        }
    }
    return $cache[$cache_key] = implode("\n", $help_lines);
}

function _help_has_text($current_page, $target_lang) {
    $content = _help_get_content($current_page, $target_lang);
    return ($content !== false && !empty($content));
}


# -------------------------------------------------------------------------
# BRUGERFLADE-FUNKTIONER (HTM_*)
# -------------------------------------------------------------------------

# SYSTEM POPUP HJÆLP 
function htm_HelpSystem() {
    $current_page = basename($_SERVER['SCRIPT_NAME']);
    $target_lang  = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
    
    $help_text = _help_get_content($current_page, $target_lang);
    if (!$help_text) return;
    
    echo '
    <div id="help-modal-container" style="display:none; position:fixed; top:80px; right:30px; width:450px; background:white; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.25); border:1px solid #bdc3c7; z-index:99999; font-family:sans-serif; touch-action:none;">
        <div id="help-modal-header" style="background:#2c3e50; color:white; padding:12px 15px; border-radius:7px 7px 0 0; cursor:move; display:flex; justify-content:space-between; align-items:center; user-select:none;">
            <span style="font-weight:bold; font-size:14px;"><i class="fa-solid fa-circle-question" style="color:#e67e22; margin-right:6px;"></i>' . lang('@Hjælpesystem') . '</span>
            <button onclick="closeHelpSystem()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#bdc3c7; line-height:1; margin-left:auto; padding:0 5px;">&times;</button>
        </div>
        <div style="padding:20px;">
            <div id="help-modal-content" style="color:#2c3e50; line-height:1.6; font-size:14px; max-height:350px; overflow-y:auto;">
                ' . $help_text . '
            </div>
            <div style="margin-top:15px; padding-top:10px; border-top:1px solid #eee; text-align:right; font-size:11px; color:#95a5a6;">
                <i class="fa-solid fa-language"></i> Sprog: ' . strtoupper($target_lang) . '
            </div>
        </div>
    </div>';
    ?>
    <script>
    if (typeof interact === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js';
        document.head.appendChild(script);
    }
    function initHelpDrag() {
        if (typeof interact === 'undefined') { setTimeout(initHelpDrag, 100); return; }
        if (window.helpDragInitialized) return;
        window.helpDragInitialized = true;
        interact('#help-modal-container').draggable({
            allowFrom: '#help-modal-header',
            listeners: {
                move(event) {
                    var target = event.target, x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx, y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;
                    target.style.transform = 'translate(' + x + 'px, ' + y + 'px)'; target.setAttribute('data-x', x); target.setAttribute('data-y', y);
                }
            }
        });
    }
    function openHelpSystem() { var c = document.getElementById('help-modal-container'); if (c) { c.style.display = 'block'; initHelpDrag(); } }
    function closeHelpSystem() { var c = document.getElementById('help-modal-container'); if (c) c.style.display = 'none'; }
    </script>
    <?php
}

# FLOATING ACTION BAR
function htm_FloatingActionBar() {
    $current_page = basename($_SERVER['SCRIPT_NAME']);
    $lang         = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';

    if (_help_has_text($current_page, $lang)) {
        $btn_style = 'background: #e67e22; border-radius: 4px; opacity: 1; cursor: pointer;';
        $btn_onclick = 'openHelpSystem(); return false;';
        $btn_hint = '@Help available';
    } else {
        $btn_style = 'background: #7f8c8d; border-radius: 4px; opacity: 0.4; cursor: not-allowed;';
        $btn_onclick = 'return false;';
        $btn_hint = '@No help text for this page';
    }
    ?>
    <div class="floating-action-bar">
        <a href="invoice_edit.php?id=0" class="fab-item"><span class="fab-dot dot-invoice"></span><i class="fa fa-file-invoice"></i> <?php echo lang('@New Invoice'); ?></a>
        <a href="expense_edit.php?id=0" class="fab-item"><span class="fab-dot dot-receipt"></span><i class="fa fa-receipt"></i> <?php echo lang('@New Expense'); ?></a>
        <a href="product_edit.php?id=0" class="fab-item"><span class="fab-dot dot-account"></span><i class="fa fa-box-open"></i> <?php echo lang('@New Product'); ?></a>
        <a href="customer_edit.php?id=0" class="fab-item"><i class="fa fa-user-plus"></i> <?php echo lang('@New Customer'); ?></a>
        <div id="fab-scroll-top" class="fab-top" style="display:none;" onclick="window.scrollTo({top: 0, behavior: 'smooth'});" data-hint="<?php echo lang('@Go to top'); ?>"><i class="fa fa-arrow-up"></i>&nbsp;<span><?php echo lang('@Top'); ?></span></div>
        <a href="#" class="fab-item" onclick="<?php echo $btn_onclick; ?>" style="<?php echo $btn_style; ?>" data-hint="<?php echo lang($btn_hint); ?>">
            <i class="fa-solid fa-circle-question"></i> <?php echo lang('@Help'); ?>
        </a>
    </div>
    <?php
}

# -------------------------------------------------------------------------
# INTELIGENT UTILITY LOGIK (OpenAI Bilagsscanning)
# -------------------------------------------------------------------------

/**
 * Scanner et bilag (billede eller PDF) vha. OpenAI og returnerer strukturerede data.
 *
 * v1.2.0-RETTELSE (fund: bruger spurgte om både "billed-PDF" (rent scannet,
 * intet tekstlag) og "OCR-PDF" (har et reelt tekstlag) kunne vises/tolkes):
 * docblock'en her har ALTID påstået PDF blev understøttet, men funktionen
 * sendte enhver fil - også PDF'er - som 'image_url' til Chat Completions
 * (/v1/chat/completions). OpenAIs egen dokumentation (platform.openai.com/
 * docs/guides/vision) siger image_url KUN understøtter png/jpeg/webp/gif -
 * aldrig application/pdf. Ethvert PDF-bilag har derfor fejlet ved AI-scanning
 * siden funktionen blev skrevet - uanset om det var en billed-PDF eller en
 * OCR-PDF, samme kode ramte begge ens. PDF'er sendes nu i stedet til
 * Responses API'et (/v1/responses) som 'input_file' - ifølge OpenAIs egen
 * PDF-guide udtrækker den BÅDE sidernes tekstlag (hvis det findes, dvs. en
 * OCR-PDF) OG en visuel gengivelse af hver side (til rene billed-PDF'er uden
 * tekstlag) og sender begge dele til modellen - dækker derfor netop begge de
 * to sager brugeren spurgte om. Almindelige billedfiler (jpg/png/webp) sendes
 * fortsat uændret via Chat Completions/image_url.
 *
 * Samtidig rettet: enhver fejl (manglende nøgle, curl-fejl, HTTP-fejl fra
 * OpenAI, uventet svarformat) blev FØR slugt tavst (funktionen returnerede
 * bare null, og expense_edit.php's eksisterende `isset($ai_data['error'])`-
 * tjek udløste derfor aldrig) - AI-scan-knappen så ud til bare ikke at gøre
 * noget. Returnerer nu ['error' => '...'] i alle disse tilfælde, så den
 * eksisterende fejlvisning i expense_edit.php rent faktisk bliver vist.
 *
 * IKKE live-testet mod en rigtig OpenAI-konto (ingen API-nøgle tilgængelig
 * lokalt) - selve request-formen er verificeret direkte mod OpenAIs egen
 * dokumentation (pdf-files/vision/structured-outputs-guiderne), men den
 * fulde rundtur bør bekræftes med et rigtigt PDF-bilag på en installation
 * med en konfigureret OPENAI_API_KEY.
 *
 * @param string $file_path Relativ eller absolut sti til filen på serveren
 * @return array [dato => 'YYYY-MM-DD', total => 123.45, leverandor => 'Navn'] eller ['error' => '...'] ved fejl
 */
function scanBilagMedOpenAI($file_path) {
    $api_key = _help_get_openai_key();

    if (empty($api_key)) {
        return ['error' => 'OpenAI API-nøgle er ikke sat op (OPENAI_API_KEY i inc/data/env.ini).'];
    }
    if (!file_exists($file_path)) {
        return ['error' => 'Bilagsfilen blev ikke fundet på serveren: ' . $file_path];
    }

    $file_data   = file_get_contents($file_path);
    $mime_type   = mime_content_type($file_path);
    $base64_file = base64_encode($file_data);
    $filename    = basename($file_path);
    $is_pdf      = ($mime_type === 'application/pdf') || strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'pdf';
    $instruction = 'Analyser dette bilag. Find dato (YYYY-MM-DD), totalbeløb og leverandørnavn.';

    $schema_props = [
        'dato' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
        'total' => ['type' => 'number', 'description' => 'Totalbeløb med moms'],
        'leverandor' => ['type' => 'string', 'description' => 'Leverandørnavn']
    ];
    $schema_required = ['dato', 'total', 'leverandor'];

    if ($is_pdf) {
        // Responses API - se funktionens hoved-kommentar for hvorfor PDF skal
        // her hen og ikke til Chat Completions/image_url.
        $endpoint = 'https://api.openai.com/v1/responses';
        $payload = [
            'model' => 'gpt-4o',
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $instruction],
                    ['type' => 'input_file', 'filename' => $filename, 'file_data' => 'data:application/pdf;base64,' . $base64_file],
                ],
            ]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'receipt_extractor',
                'strict' => true,
                'schema' => ['type' => 'object', 'properties' => $schema_props, 'required' => $schema_required, 'additionalProperties' => false],
            ]],
        ];
    } else {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $payload = [
            'model' => 'gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime_type . ';base64,' . $base64_file]],
                ],
            ]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => [
                'name' => 'receipt_extractor',
                'strict' => true,
                'schema' => ['type' => 'object', 'properties' => $schema_props, 'required' => $schema_required, 'additionalProperties' => false],
            ]],
        ];
    }

    $ch = tc_curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    // NYT: eksplicit timeout - der var slet ingen før, så et langsomt/
    // uopnåeligt api.openai.com kunne hænge selve sidevisningen på ubestemt
    // tid (samme fejlklasse som auto_backup.inc.php's manglende SMTP-timeout
    // tidligere i denne session). 60 sek. da PDF-analyse typisk tager længere
    // end et enkelt billede.
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    // SSL-certifikatverifikation er cURLs egen standard, bevidst IKKE slået
    // fra - se scanner-ocr-review/[[scanner-ocr-review]] for hvorfor.

    $response   = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'Kunne ikke kontakte OpenAI: ' . $curl_error];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return ['error' => 'Uventet (ikke-JSON) svar fra OpenAI.'];
    }
    if ($http_status >= 400) {
        return ['error' => $result['error']['message'] ?? ('OpenAI svarede med HTTP ' . $http_status . '.')];
    }

    if ($is_pdf) {
        // Se Responses API'ets output-facit: teksten kan IKKE antages at
        // ligge fast på output[0].content[0].text (OpenAIs egen advarsel) -
        // find i stedet det content-element hvor type=='message', og deri
        // det content-element hvor type=='output_text'.
        $text = null;
        foreach ($result['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'message') {
                foreach ($item['content'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'output_text') { $text = $c['text']; break 2; }
                }
            }
        }
        if ($text === null) {
            return ['error' => 'Uventet svarformat fra OpenAI (intet output_text fundet).'];
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : ['error' => 'Kunne ikke tolke OpenAI-svaret som JSON.'];
    }

    if (isset($result['choices'][0]['message']['content'])) {
        $decoded = json_decode($result['choices'][0]['message']['content'], true);
        return is_array($decoded) ? $decoded : ['error' => 'Kunne ikke tolke OpenAI-svaret som JSON.'];
    }

    return ['error' => 'Uventet svarformat fra OpenAI.'];
}
?>