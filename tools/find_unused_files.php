<?php
# file_analyzer.php
header('Content-Type: text/plain; charset=utf-8');

$allFiles = scandir(__DIR__);
$phpFiles = [];
$stats = [];

// 1. Find alle PHP-filer og forbered tælleren
foreach ($allFiles as $file) {
    if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) == 'php') {
        if ($file != basename(__FILE__)) {
            $phpFiles[] = $file;
            $stats[$file] = 0; // Start tæller på 0
        }
    }
}

// 2. Scan hver fil for referencer til de andre
foreach ($phpFiles as $fileToSearchIn) {
    $content = file_get_contents($fileToSearchIn);
    
    foreach ($stats as $candidate => $count) {
        // Vi tæller antallet af gange filnavnet optræder
        // substr_count er effektiv til dette formål
        $occurrences = substr_count($content, $candidate);
        $stats[$candidate] += $occurrences;
    }
}

// 3. Sorter resultatet (flest kald først)
arsort($stats);

echo "FIL-ANALYSE: HVOR MANGE GANGE BLIVER FILEN NÆVNT?\n";
echo str_repeat("=", 60) . "\n";
printf("%-35s | %-10s | %-10s\n", "Filnavn", "Kald", "Størrelse");
echo str_repeat("-", 60) . "\n";

foreach ($stats as $file => $count) {
    $size = number_format(filesize($file) / 1024, 1) . " KB";
    
    // Markering af kritiske filer
    $note = "";
    if ($count == 0) $note = " <-- MÅSKE OVERFLØDIG?";
    if ($count > 20) $note = " [Central fil]";
    if (filesize($file) == 0) $note = " !!! TOM FIL !!!";

    printf("%-35s | %-10d | %-10s %s\n", $file, $count, $size, $note);
}

echo str_repeat("=", 60) . "\n";
echo "TIP: Hvis en fil har 0 kald, så tjek om det er en 'indgangsside'\n";
echo "(f.eks. index.page.php), som du selv indtaster i browseren.\n";