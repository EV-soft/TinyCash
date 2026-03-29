<?php # php2htm.lib.php

function htm_Header($title = 'Tiny Cash') { 
    echo '<!DOCTYPE html><html lang="da">
       <head><meta charset="UTF-8">
          <title>'.$title.'</title>
          <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
          <style>
            body{font-family:sans-serif;background:#f4f7f6;margin:4px 20px;}
            .cardW500{max-width:500px;margin:20px auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
            .cardW800{max-width:800px;margin:20px auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
            input,select,button{width:100%;padding:10px;margin:8px 0;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;}
            button{cursor:pointer;border:none;font-weight:bold;}
            .btn-primary{background:#3498db;color:white;}
            .btn-success{background:#27ae60;color:white;}
            hr{border:0;border-top:1px solid #eee;margin:20px 0;}
            
            /* Tooltip Styles */
            .tooltip { position: relative; display: inline-block; cursor: help; border-bottom: 1px dotted #bdc3c7; }
            .tooltip .tooltiptext {
                visibility: hidden; width: 220px; background-color: #2c3e50; color: #fff;
                text-align: center; border-radius: 6px; padding: 8px; position: absolute;
                z-index: 999; bottom: 125%; left: 50%; margin-left: -110px;
                opacity: 0; transition: opacity 0.3s; font-size: 0.85em;
                box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            }
            .tooltip .tooltiptext::after {
                content: ""; position: absolute; top: 100%; left: 50%; margin-left: -5px;
                border-width: 5px; border-style: solid; border-color: #2c3e50 transparent transparent transparent;
            }
            .tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }
          </style>
       </head>
    <body>'; 
}

function htm_Footer() { 
    echo '</body></html>'; 
}

function htm_Card_($capt, $width = '600') {
    echo '<div class="card-container" style="
        max-width: '.$width.'px; 
        margin: 20px auto; 
        background: white; 
        padding: 25px; 
        border-radius: 8px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
        font-family: sans-serif;
    ">';
    echo '<h2 style="margin-top:0; color:#2c3e50; border-bottom:2px solid #3498db; 
                     padding-bottom:10px;">'.$capt.'</h2>';
}

function htm_Card_end() { 
    echo '</div>'; 
}

// ... (Dine andre funktioner som htm_Input, htm_Select osv er fine)
if (!function_exists('lang')) {
    function lang($key) {
    // 1. Find ud af hvilket sprog brugeren har valgt (standard 'da')
    $current_lang = $_SESSION['lang'] ?? 'da'; 

    static $translations = null;
    if ($translations === null) {
        $file = __DIR__ . '/_trans.sys.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            // 2. Gå ind i 'language' og find den pakke, der matcher $current_lang
            foreach ($data['language'] as $l) {
                if ($l['code'] === $current_lang) {
                    $translations = $l['translation'];
                    break;
                }
            }
        }
    }
    return $translations[$key] ?? ltrim($key, '@');
}
}
/* 
// DEBUG TEST - Slet dette efter test
$test_file = __DIR__ . '/_trans.sys.json';
if (!file_exists($test_file)) {
    echo "❌ FEJL: Filen findes IKKE på stien: " . $test_file . "<br>";
} else {
    $indhold = file_get_contents($test_file);
    $data = json_decode($indhold, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ FEJL i JSON-format: " . json_last_error_msg() . "<br>";
    } else {
        echo "✅ JSON er indlæst korrekt!<br>";
        echo "Sprog sat til: " . ($current_lang ?? 'IKKE SAT') . "<br>";
    }
} */
?>