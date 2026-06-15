<?php # TranslationExtractor.php
header('Content-Type: text/plain; charset=utf-8');

$directory = __DIR__; 
$found_keys = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
$php_files = new RegexIterator($files, '/\.php$/');

foreach ($php_files as $file) {
    // Spring selve scanner-filen over
    if ($file->getFilename() === basename(__FILE__)) continue;

    $content = file_get_contents($file->getPathname());
    
    // Regex forklaring:
    // Leder efter ['\"]@  -> Start med ' eller " efterfulgt af @
    // (.*?)               -> Fang alt indtil...
    // ['\"]               -> Næste ' eller "
    preg_match_all("/['\"](@[^'\"]+)['\"]/", $content, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $key) {
            // Vi fjerner backslashes hvis de findes i koden (f.eks. lang('@It\'s fine'))
            $clean_key = stripslashes($key);
            $found_keys[$clean_key] = true;
        }
    }
}

ksort($found_keys);

$json_output = [];
foreach (array_keys($found_keys) as $k) {
    // Her fjerner vi @ til selve oversættelsen, men beholder nøglen intakt
    $translation_value = str_replace('@', '', $k);
    $json_output[$k] = $translation_value;
}

// JSON_PRETTY_PRINT: Gør den læselig
// JSON_UNESCAPED_UNICODE: Sørger for at ÆØÅ gemmes korrekt og ikke som \u00e6
// JSON_HEX_QUOT: Escaper " korrekt til \u0022 eller lignende hvis nødvendigt
echo json_encode($json_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT);
?>