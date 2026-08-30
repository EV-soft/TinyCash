<?php # /inc/translate_system.php v:1.3.0 d:2026-08-30 i:evs
# HTML-optimeret version til PHP 8.5+ - ser ud til at være en duplikat af
# tools/translate_system.php, se [[php-header-standardization]].
# RETTET (§bugs-batch-21-review): KRITISK - denne fil havde INTET PHP-niveau
# adgangstjek overhovedet (kun inc/.htaccess' "Require all denied" som
# standard, som Nginx slet ikke respekterer, i modsætning til Apache), og
# havde en ægte DeepL API-nøgle hårdkodet direkte i kildekoden i stedet for i
# env.ini (projektets egen konvention for hemmeligheder, se OPENAI_API_KEY).
# Kræver nu admin-niveau (samme mønster som TranslationExtractor.php/
# Master_Advisor.php) og læser nøglen fra env.ini.
chdir(dirname(__DIR__));
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

set_time_limit(0);
ini_set('max_execution_time', 0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==========================================
// 1. API-NØGLEN HENTES NU FRA env.ini (se ovenfor)
// RETTET: env.ini flyttet til inc/data/env.ini - genbruger $env_file, som
// inc/db_connect.inc.php (allerede kørt ovenfor) selv fandt frem til, i
// stedet for at gætte en hårdkodet sti igen.
// ==========================================
$env_ini = parse_ini_file($env_file);
define('DEEPL_API_KEY', trim($env_ini['DEEPL_API_KEY'] ?? '', '"\' '));

define('SOURCE_JSON', dirname(__DIR__) . '/json-data/help_system.json');
define('TARGET_DIR', dirname(__DIR__) . '/json-data/languages/'); 

$apiUrl = (substr(trim(DEEPL_API_KEY), -3) === ':fx') 
    ? 'https://api-free.deepl.com/v2/translate' 
    : 'https://api.deepl.com/v2/translate';
/* 
$languages = [
    'DA' => 'dansk', 'DE' => 'tysk', 'FR' => 'fransk', 
    'ES' => 'spansk', 'IT' => 'italiensk', 'NL' => 'hollandsk', 
    'PL' => 'polsk', 'SV' => 'svensk', 'NB' => 'norsk', 'FI' => 'finsk'
]; */
$languages = [
    /* 'DA' => 'dansk', 'DE' => 'tysk', 'FR' => 'fransk', 
    'ES' => 'spansk', 'IT' => 'italiensk', 'NL' => 'hollandsk', 
    'PL' => 'polsk', 'SV' => 'svensk', */ 'NB' => 'norsk',/*  'FI' => 'finsk' */
];


if (!file_exists(SOURCE_JSON)) {
    die("Fejl: Kunne ikke finde master-filen: " . SOURCE_JSON . "<br>");
}

if (!is_dir(TARGET_DIR)) {
    mkdir(TARGET_DIR, 0755, true);
}

$master_data = json_decode(file_get_contents(SOURCE_JSON), true);
if (!$master_data) {
    die("Fejl: Master-JSON kunne ikke tolkes! JSON-fejl: " . json_last_error_msg() . "<br>");
}

echo "<h2>=== TinyCash Automatisk Oversættelse startet ===</h2>";
echo "Bruger API URL: <strong>$apiUrl</strong><br><br>";
flush();

foreach ($languages as $code => $name) {
    echo "Oversætter til <strong>{$name} ({$code})</strong>... ";
    flush();

    $translated_data = [];

    foreach ($master_data as $page => $lines) {
        $texts_to_translate = [];
        $line_mapping = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                // Konverter rå særtegn som & til reelle HTML-enheder før afsendelse
                $texts_to_translate[] = htmlspecialchars_decode(htmlentities($trimmed, ENT_QUOTES, 'UTF-8'));
                $line_mapping[] = $index;
            }
            $translated_data[$page][$index] = $line; 
        }

        if (!empty($texts_to_translate)) {
            $translated_texts = translateBulkWithDeepL($texts_to_translate, $code, $apiUrl);
            
            foreach ($translated_texts as $i => $translated_text) {
                $original_index = $line_mapping[$i];
                $translated_data[$page][$original_index] = $translated_text;
            }
        }
    }

    $file_code = ($code === 'NB') ? 'no' : strtolower($code);
    $output_file = TARGET_DIR . "help_system_" . $file_code . ".json";
    file_put_contents($output_file, json_encode($translated_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    echo "<span style='color:green;'>Færdig og gemt!</span><br>\n";
    flush();
}

echo "<h3>=== ALLE SPROG ER OPDATERET UDEN FEJL! ===</h3>";

function translateBulkWithDeepL($text_array, $target_lang, $apiUrl) {
    if ($target_lang === 'EN') return $text_array;

    $data = [
        'text' => $text_array,
        'target_lang' => $target_lang,
        'source_lang' => 'EN',
        'tag_handling' => 'html' // Ændret fra xml til html for at tillade løse <li> tags og særtegn
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: DeepL-Auth-Key ' . DEEPL_API_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo "<br><span style='color:red;'>[Forbindelsesfejl (cURL): " . curl_error($ch) . "]</span><br>";
    }

    if ($http_status === 200) {
        $result = json_decode($response, true);
        if (isset($result['translations']) && is_array($result['translations'])) {
            $output = [];
            foreach ($result['translations'] as $translation) {
                $translated = $translation['text'];
                if ($target_lang === 'DA') {
                    $translated = str_ireplace(['værdikupon', 'kuponnummer'], ['bilag', 'Bilagsnummer'], $translated);
                }
                $output[] = $translated;
            }
            return $output;
        }
    } else {
        echo "<br><span style='color:red; font-weight:bold;'>[DeepL API Fejl: HTTP Kode $http_status - Svar fra DeepL: $response]</span><br>";
    }

    return $text_array; 
}
