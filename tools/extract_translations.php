<?php # extract_translations.php  v:0.8.1 d:2026-04-11 i:evs m:0
ob_start();

function updateLanguageMaster() {
    $rootDir = dirname(__DIR__); 
    $jsonDataDir = $rootDir . '/json-data';
    $jsonPath = $jsonDataDir . '/languages.json';
    
    if (!file_exists($jsonPath)) die("Fejl: languages.json ikke fundet.");

    $rawContent = file_get_contents($jsonPath);
    $masterData = json_decode(trim($rawContent, "\xef\xbb\xbf "), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) die("JSON Fejl: " . json_last_error_msg());

    // 1. Manuel ordbog (Override)
    $autoTranslate = [
        'da' => ['Revenue' => 'Omsætning', 'Expenses' => 'Udgifter', 'VAT' => 'Moms'],
        'en' => ['Revenue' => 'Revenue', 'Expenses' => 'Expenses', 'VAT' => 'VAT'],
    ];

    // 2. Backup
    file_put_contents($jsonDataDir . '/languages_backup_' . date('Y-m-d_Hi') . '.json', $rawContent);

    // 3. Scan filer for @keys
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS));
    $foundKeys = [];
    foreach ($files as $file) {
        if (!preg_match("/\.(php|inc)$/i", $file->getFilename())) continue;
        if (strpos($file->getRealPath(), 'json-data') !== false || strpos($file->getRealPath(), 'tools') !== false) continue;
        
        preg_match_all("/@([a-zA-Z0-9_\s\-\?\!\.\(\)\:]{1,60})/", file_get_contents($file->getRealPath()), $matches);
        if (!empty($matches[1])) foreach ($matches[1] as $key) $foundKeys[] = trim($key);
    }
    $uniqueKeys = array_unique($foundKeys);
    sort($uniqueKeys);
    $totalKeyCount = count($uniqueKeys);

    // 4. Synkroniser (Gem kun oversættelser)
    $displayStats = []; // Bruges kun til skærmvisning

    // 4. Synkroniser (Hård rensning: Kun nøgler fundet i koden bevares)
    $displayStats = [];

    foreach ($masterData['language'] as &$langObj) {
        $langCode = $langObj['code'];
        $currentTrans = $langObj['translation'] ?? [];
        $newTrans = [];
        $translatedCount = 0;

        // A. BEVAR METADATA (Valgfrit: Nøgler der IKKE starter med @, f.eks. "Note")
        foreach ($currentTrans as $k => $v) {
            if (strpos($k, '@') !== 0) {
                $newTrans[$k] = $v;
            }
        }

        // B. GENOPBYG TRANSLATION (Rensning sker her)
        // Vi kører KUN de nøgler igennem, som scriptet lige har fundet i PHP-filerne
        foreach ($uniqueKeys as $key) {
            $fullKey = "@" . $key;
            $val = "";

            // 1. Tjek om vi allerede har en oversættelse (og at den ikke er tom)
            if (isset($currentTrans[$fullKey]) && trim($currentTrans[$fullKey]) !== "") {
                $val = $currentTrans[$fullKey];
            } 
            // 2. Tjek manuel ordbog (Override)
            elseif (isset($autoTranslate[$langCode][$key])) {
                $val = $autoTranslate[$langCode][$key];
            } 
            // 3. Fallback for engelsk
            elseif ($langCode === 'en') {
                $val = $key; 
            }

            // Tilføj til den NYE liste (gamle @nøgler der ikke findes i $uniqueKeys dør her)
            $newTrans[$fullKey] = $val;
            
            if (trim($val) !== "") {
                $translatedCount++;
            }
        }
        
        // C. SORTERING & OPGRADERING
        ksort($newTrans);
        $langObj['translation'] = $newTrans;

        // Statistik til dashboard
        $pct = ($totalKeyCount > 0) ? round(($translatedCount / $totalKeyCount) * 100) : 0;
        $missingCount = $totalKeyCount - $translatedCount;

        $displayStats[] = [
            'name' => (!empty($langObj['native']) ? $langObj['native'] : (!empty($langObj['name']) ? $langObj['name'] : 'Unknown')),
            'code' => $langCode,
            'pct' => $pct,
            'missing' => $missingCount
        ];
    }

    // 5. Gem den rene datafil
    $jsonOutput = json_encode($masterData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($jsonPath, $jsonOutput)) {
        
        // Dashboard visning
        echo "<div style='font-family:sans-serif; background:#2c3e50; color:white; padding:30px; border-radius:15px; max-width:800px; margin:20px auto; box-shadow:0 10px 30px rgba(0,0,0,0.3);'>";
        echo "<h2 style='margin-top:0; color:#2ecc71; border-bottom:1px solid #34495e; padding-bottom:10px;'>🌍 Sprogfiler opdateret (Ren data)</h2>";
        echo "Nøgler i koden: <strong style='font-size:1.2em;'>$totalKeyCount</strong><br><br>";
        
        foreach($displayStats as $stat) {
            $barColor = ($stat['pct'] > 99.9) ? "#2ecc71" : ($stat['pct'] > 50 ? "#f1c40f" : "#e74c3c");
            
            // Her er din tilretning (sikret mod tomme navne)
            $displayName = (!empty($stat['name']) ? $stat['name'] : 'English');
            
            echo "<div style='margin-bottom:15px;'>";
            echo "<div style='display:flex; justify-content:space-between; margin-bottom:5px;'>";
            echo "<span style='flex:1;'><strong>" . $displayName . "</strong> (" . $stat['code'] . ")</span>";
            echo "<span>" . $stat['pct'] . "%</span>";
            echo "</div>";
            echo "<div style='background:#1a252f; height:8px; border-radius:4px; overflow:hidden;'>";
            echo "<div style='background:$barColor; width:" . $stat['pct'] . "%; height:100%;'></div>";
            echo "</div>";
            echo "</div>";
        }
        echo "</div>";
    }
}
updateLanguageMaster();