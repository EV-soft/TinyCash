<?php # translation_manager.php v:1.1.0 d:2026-07-02 i:evs
require_once 'inc/auth.inc.php'; 
require_once 'inc/db_connect.inc.php'; 
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
    
    $paths = [
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

    // (Nu med spansk og portugisisk):
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

    $ch = curl_init($url);
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
        curl_close($ch);
        return $clean;
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
    
    if (preg_match_all('/lang\(\s*(["\'])(.*?)\1\s*\)/i', $content, $matches)) {
        foreach ($matches[2] as $matched_phrase) {
            $clean_phrase = (strpos($matched_phrase, '@') === 0) ? $matched_phrase : '@' . $matched_phrase;
            $used_phrases_in_code[$clean_phrase] = true; 
            
            if (!isset($master_data['language'][$lang_index]['translation'][$clean_phrase])) {
                $master_data['language'][$lang_index]['translation'][$clean_phrase] = '';
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

        if (!empty($new_key)) {
            if (strpos($new_key, '@') !== 0) {
                $new_key = '@' . $new_key;
            }

            if (!empty($old_key) && $old_key !== $new_key) {
                unset($master_data['language'][$lang_index]['translation'][$old_key]);
            }
            $master_data['language'][$lang_index]['translation'][$new_key] = $value;
            ksort($master_data['language'][$lang_index]['translation']);
            
            if (file_put_contents($json_file, json_encode($master_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                header("Location: translation_manager.php?msg=saved");
                exit;
            }
        }
    }
}

$msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved') $msg = "<div style='background:rgba(46,204,113,0.2); color:#27ae60; padding:10px; margin: 20px auto 0 auto; max-width:1400px; border-radius:4px; font-weight:bold;'>" . lang("@Phrase saved successfully!") . "</div>";
}

$edit_key = $_GET['edit'] ?? null;
$edit_value = $edit_key ? ($master_data['language'][$lang_index]['translation'][$edit_key] ?? '') : '';

$ai_suggestion = $edit_key ? getAiSuggestion($edit_key, $current_edit_lang, true) : '';

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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_edit_lang); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo lang("@Language & Phrase Editor"); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.2/css/flag-icons.min.css">
    <style>
        :root {
            --bg-main: #f4f7f6;
            --bg-card: #ffffff;
            --color-primary: #3498db;
            --color-dark: #2c3e50;
            --color-danger: #e74c3c;
            --border-color: #dee2e6;
            --color-ai: #9b59b6;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-main); margin: 0; padding: 0; color: #333; }
        .container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        .wrapper { display: grid; grid-template-columns: 1fr 1.4fr; gap: 20px; }
        .card { background: var(--bg-card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h3 { margin-top: 0; border-bottom: 2px solid var(--color-primary); padding-bottom: 8px; color: var(--color-dark); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 0.9em; }
        .form-group input[type="text"] { width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing: border-box; background: #fdfdfd; }
        
        .status-overview-bar { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; text-align: center; }
        .status-stat-card { padding: 10px; border-radius: 6px; color: white; font-size: 12px; font-weight: bold; }
        .stat-oversat { background: #2ecc71; }
        .stat-mangler { background: #f1c40f; color: #2c3e50; }
        .stat-ikke-kilde { background: #e74c3c; }
        .stat-kun-kilde { background: #3498db; }
        .status-stat-card span { display: block; font-size: 18px; font-weight: 700; margin-top: 2px; }

        .filter-bar { display: flex; gap: 10px; margin-bottom: 15px; }
        .search-container { position: relative; flex: 1; display: flex; align-items: center; }
        .search-container input[type="text"] { width: 100%; padding: 8px 30px 8px 10px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing: border-box; }
        
        .clear-search-btn { position: absolute; right: 10px; background: none; border: none; color: #aaa; font-size: 16px; cursor: pointer; display: none; font-weight: bold; padding: 0; line-height: 1; }
        .clear-search-btn:hover { color: var(--color-danger); }

        .filter-bar select { padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; background: #fff; cursor: pointer; font-weight: 600; color: var(--color-dark); }

        table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        th, td { padding: 10px; border-bottom: 1px solid var(--border-color); text-align: left; word-wrap: break-word; vertical-align: middle; }
        th { background: #f8f9fa; }
        tr:hover { background: #fdfdfd; }

        .btn { padding: 4px 8px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 11px; font-weight: bold; display: inline-block; }
        .btn-primary { background: var(--color-primary); color: white; }
        .btn-secondary { background: #95a5a6; color: white; }

        /* RETTELSE: Fjernet white-space: nowrap så teksten kan ombrydes hvis nødvendigt, og tilføjet tekst-centrering */
        .status-badge { display: block; padding: 4px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; color: white; text-align: center; line-height: 1.2; }
        .badge-oversat { background: #2ecc71; }
        .badge-mangler { background: #f1c40f; color: #2c3e50; }
        .badge-ikke-kildekode { background: #e74c3c; }
        .badge-kun-kildekode { background: #3498db; }
        
        .ai-suggestion-box { background: #f5eef8; border: 1px dashed var(--color-ai); padding: 10px; border-radius: 4px; margin-top: 5px; font-size: 12px; display: flex; justify-content: space-between; align-items: center; }
        .guide-box { background: #f8f9fa; border-left: 4px solid var(--color-primary); padding: 12px; margin-bottom: 15px; font-size: 13px; line-height: 1.5; }
    </style>
</head>
<body>

<?php showMenu(); ?>
<?php echo $msg; ?>

<div class="container">
    <div class="wrapper">
        
        <div class="card">
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
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="old_key" value="<?php echo htmlspecialchars($edit_key); ?>">
                    
                    <div class="form-group">
                        <label><?php echo lang("@System Key (From source code)"); ?>:</label>
                        <input type="text" id="system_key_field" name="key" readonly value="<?php echo htmlspecialchars($edit_key); ?>" style="background:#e9ecef; color:#495057; cursor:not-allowed;">
                    </div>

                    <div class="form-group">
                        <label><?php echo lang("@Translation"); ?>:</label>
                        <input type="text" id="translation_field" name="value" required value="<?php echo htmlspecialchars($edit_value); ?>" autofocus>
                        
                        <div class="ai-suggestion-box">
                            <span><i class="fa fa-robot" style="color:var(--color-ai);"></i> <strong>OpenAI GPT:</strong> <span id="ai_val"><?php echo htmlspecialchars($ai_suggestion); ?></span></span>
                            <button type="button" class="btn" style="background:var(--color-ai); color:white;" id="ai_btn" onclick="applyAiSuggestion()">
                                <?php echo lang("@Use suggestion"); ?>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?php echo lang("@Save changes"); ?></button>
                    <a href="translation_manager.php" class="btn btn-secondary"><?php echo lang("@Cancel"); ?></a>
                </form>
            <?php endif; ?>
        </div>

        <div class="card" style="max-height: 80vh; overflow-y: auto;">
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
                
                <select id="status_filter" onchange="filterPhrases()">
                    <option value="all">🔍 <?php echo lang("@All statuses"); ?></option>
                    <option value="oversat">🟢 <?php echo lang("@Translated (OK)"); ?></option>
                    <option value="mangler">🟡 <?php echo lang("@Missing translation"); ?></option>
                    <option value="ikke_i_kildekode">🔴 <?php echo lang("@Not in source code"); ?></option>
                    <option value="kun_kildekode">🔵 <?php echo lang("@Only in source code"); ?></option>
                </select>
            </div>

            <table id="phrase_table">
                <thead>
                    <tr>
                        <th style="width: 38%;"><?php echo lang("@System Key"); ?></th>
                        <th style="width: 34%;"><?php echo lang("@Translation"); ?></th>
                        <th style="width: 20%;"><?php echo lang("@Status"); ?></th> <th style="width: 8%; text-align: right;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($master_data['language'][$lang_index]['translation'] as $phrase => $val): 
                        $in_json = isset($json_translations[$phrase]);
                        $in_code = isset($used_phrases_in_code[$phrase]);
                        $is_empty = empty($val);

                        // RETTELSE: Alle kildekode-nøgler i lang() er nu udelukkende på engelsk
                        if ($in_code && $in_json && !$is_empty) {
                            $status_label = lang("@Translated (OK)");
                            $status_class = "badge-oversat";
                            $status_text = "oversat";
                        } elseif ($in_code && $in_json && $is_empty) {
                            $status_label = lang("@Missing translation");
                            $status_class = "badge-mangler";
                            $status_text = "mangler";
                        } elseif (!$in_code && $in_json) {
                            $status_label = lang("@Not in source code");
                            $status_class = "badge-ikke-kildekode";
                            $status_text = "ikke_i_kildekode";
                        } else {
                            $status_label = lang("@Only in source code");
                            $status_class = "badge-kun-kildekode";
                            $status_text = "kun_kildekode";
                        }
                    ?>
                        <tr data-status="<?php echo $status_text; ?>">
                            <td><code style="font-weight: bold; font-size:12px;"><?php echo htmlspecialchars($phrase); ?></code></td>
                            <td style="color:#555;"><?php echo htmlspecialchars($val); ?></td>
                            <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
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
</body>
</html>