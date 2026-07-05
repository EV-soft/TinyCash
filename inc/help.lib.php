<?php # /inc/help.lib.php - v:1.1.0 d:2026-07-02 i:evs

# -------------------------------------------------------------------------
# INTERN DATA-LOGIK
# -------------------------------------------------------------------------

function _help_get_content($current_page, $target_lang) {
    // 1. Tving sprogkoden til små bogstaver (så 'DA' bliver til 'da')
    $lang = strtolower(trim($target_lang));
    
    // 2. Definer stien til den specifikke sprogfil
    $lang_file   = dirname(__DIR__) . "/json-data/languages/help_system_" . $lang . ".json";
    $master_file = dirname(__DIR__) . '/json-data/help_system.json';
    
    if (!file_exists($master_file)) return false;
    $master_data = json_decode(file_get_contents($master_file), true);
    if (!$master_data || !isset($master_data[$current_page])) return false;

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
            $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? (function_exists('getenv') ? getenv('OPENAI_API_KEY') : '') ?? '';
            
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

                $ch = curl_init("https://api.openai.com/v1/chat/completions");
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
    return implode("\n", $help_lines);
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
 * Scanner et bilag (billede eller PDF) vha. OpenAI gpt-4o og returnerer strukturerede data.
 * @param string $file_path Relativ eller absolut sti til filen på serveren
 * @return array|null [dato => 'YYYY-MM-DD', total => 123.45, leverandor => 'Navn'] eller null ved fejl
 */
function scanBilagMedOpenAI($file_path) {
    $api_key = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? (function_exists('getenv') ? getenv('OPENAI_API_KEY') : '') ?? '';

    if (empty($api_key) || !file_exists($file_path)) {
        return null;
    }

    $file_data = file_get_contents($file_path);
    $mime_type = mime_content_type($file_path);
    $base64_file = base64_encode($file_data);

    $json_schema = [
        'name' => 'receipt_extractor',
        'strict' => true,
        'schema' => [
            'type' => 'object',
            'properties' => [
                'dato' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'total' => ['type' => 'number', 'description' => 'Totalbeløb med moms'],
                'leverandor' => ['type' => 'string', 'description' => 'Leverandørnavn']
            ],
            'required' => ['dato', 'total', 'leverandor'],
            'additionalProperties' => false
        ]
    ];

    $payload = [
        'model' => 'gpt-4o',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Analyseer dette bilag. Find dato (YYYY-MM-DD), totalbeløb og leverandørnavn.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime_type . ';base64,' . $base64_file]]
                ]
            ]
        ],
        'response_format' => ['type' => 'json_schema', 'json_schema' => $json_schema]
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    
    // Sikrer at forbindelsen ikke fejler på lokale SSL-opsætninger
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return json_decode($result['choices'][0]['message']['content'], true);
    }

    return null;
}
?>