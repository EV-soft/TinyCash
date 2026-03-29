<?php # /tools/file_dependency_analyzer.php
header('Content-Type: text/plain; charset=utf-8');

// RETTELSE HER: Vi peger på mappen ovenover (..)
$targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$phpFiles = glob($targetDir . "*.php"); 

$dependencies = [];
$fileNamesOnly = [];

// 1. Forbered lister
foreach ($phpFiles as $fullPath) {
    $fileName = basename($fullPath);
    $fileNamesOnly[] = $fileName;
    $dependencies[$fileName] = [];
}

// 2. Scan hver fil i parent mappen
foreach ($phpFiles as $fullPath) {
    $currentFile = basename($fullPath);
    $content = file_get_contents($fullPath);
    
    foreach ($fileNamesOnly as $candidate) {
        if ($currentFile === $candidate) continue;

        if (strpos($content, $candidate) !== false) {
            $dependencies[$candidate][] = $currentFile;
        }
    }
}

// 3. Udskriv rapport
echo "FIL-AFHÆNGIGHEDS-RAPPORT (Scanning af: $targetDir)\n";
echo str_repeat("=", 80) . "\n";
printf("%-35s | %-10s | %s\n", "Filnavn", "Antal kald", "Kaldt af");
echo str_repeat("-", 80) . "\n";

foreach ($dependencies as $file => $callers) {
    $count = count($callers);
    $callerList = implode(", ", array_unique($callers));
    printf("%-35s | %-10d | %s\n", $file, $count, $callerList);
}