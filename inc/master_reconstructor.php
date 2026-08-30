<?php # /inc/master_reconstructor.php v:1.3.0 d:2026-08-30 i:evs
# v1.2.0: KRITISK - intet PHP-login-tjek. Skriver filer ud fra indhold
# parset fra tekstfiler, kun beskyttet af inc/.htaccess's "afvis alt"-
# standard. Tilføjet admin-tjek som et uafhængigt, ekstra lag. Samme
# fundklasse som Master_Advisor.php. Gennemgang af resterende inc-filer.
chdir(dirname(__DIR__));
require_once 'inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$processStartTime = time(); // Fallback timestamp (processtart)
$exportDir = __DIR__ . '/ai_export/';

// --- NY REKONSTRUKTIONS-MAPPE (Undermappe i ai_export) ---
$targetBaseDir = $exportDir . 'reconstructed/'; 

if (!file_exists($exportDir)) {
    die("Error: Eksportmappen '$exportDir' eksisterer ikke.");
}

// Hent alle kildekode-eksportfiler (vi springer upload_01_blueprint.txt over)
$sourceFiles = glob($exportDir . 'upload_[0-9][0-9]_source.txt');
sort($sourceFiles);

if (empty($sourceFiles)) {
    die("Ingen upload_*_source.txt filer fundet i $exportDir");
}

echo "<div style='font-family:monospace; padding:20px; background:#1e1e1e; color:#d4d4d4; line-height:1.5;'>";
echo "<h2 style='color:#4fc1ff;'>🚀 Genskabelse til undermappe startet...</h2>";
echo "<div style='color:#e5c07b; margin-bottom:15px;'>Målmappe: $targetBaseDir</div>";

foreach ($sourceFiles as $sourceFile) {
    echo "<div style='color:#858585; margin-top:10px;'>Læser bulk-fil: " . basename($sourceFile) . "</div>";
    
    $lines = file($sourceFile);
    $currentFileRelativePath = null;
    $currentFileContent = [];

    foreach ($lines as $line) {
        // Tjek om linjen indikerer en ny fil-blok
        if (preg_match('/^### FILE:\s*(.+)$/', trim($line), $matches)) {
            // Hvis vi allerede var i gang med en fil, skal den gemmes først
            if ($currentFileRelativePath !== null) {
                saveReconstructedFile($targetBaseDir, $currentFileRelativePath, $currentFileContent, $processStartTime);
            }
            
            // Nulstil til den næste fil
            $currentFileRelativePath = trim($matches[1]);
            $currentFileContent = [];
            continue;
        }

        // Spring over bulk-filens egen top-overskrift samt de dekorative hash-separatorer
        if (str_starts_with($line, '### TinyCash Source Code') || str_starts_with($line, '################################################################')) {
            continue;
        }

        // Opsaml kildekode til den aktive fil
        if ($currentFileRelativePath !== null) {
            $currentFileContent[] = $line;
        }
    }

    // Gem den absolut sidste fil i den aktuelle bulk-fil
    if ($currentFileRelativePath !== null) {
        saveReconstructedFile($targetBaseDir, $currentFileRelativePath, $currentFileContent, $processStartTime);
    }
}

echo "<h2 style='color:#4ec9b0; margin-top:20px;'>✅ Genskabelse fuldført i undermappen!</h2>";
echo "</div>";

/**
 * Funktion til at skrive filen i undermappen og manipulere fildatoen
 */
function saveReconstructedFile($targetBaseDir, $relativePath, $contentLines, $fallbackTimestamp) {
    // Kombiner målmappen med filens relative sti (f.eks. inc/ai_export/reconstructed/inc/menu.inc.php)
    $fullPath = $targetBaseDir . $relativePath;
    $dir = dirname($fullPath);

    // Opret de nødvendige undermapper i den nye struktur
    if (!file_exists($dir)) {
        mkdir($dir, 0775, true);
    }

    // Saml filens fulde indhold
    $contentString = implode('', $contentLines);
    $contentString = trim($contentString, "\r\n") . "\n"; 

    // Skriv filen til den sikre placering
    file_put_contents($fullPath, $contentString);

    // Analyser filens absolut FØRSTE linje for at finde d:YYYY-MM-DD
    $targetTimestamp = $fallbackTimestamp;
    if (!empty($contentLines)) {
        $firstLine = $contentLines[0];
        if (preg_match('/d:([0-9]{4})-([0-9]{2})-([0-9]{2})/', $firstLine, $dateMatches)) {
            $year  = (int)$dateMatches[1];
            $month = (int)$dateMatches[2];
            $day   = (int)$dateMatches[3];
            
            // Opret timestamp sat til kl. 12:00:00 på den givne dato
            $targetTimestamp = mktime(12, 0, 0, $month, $day, $year);
        }
    }

    // Tving den genskabte fil til at få den specifikke dato
    touch($fullPath, $targetTimestamp);

    // Udskriv status på skærmen med det relative resultat
    $displayDate = date('Y-m-d', $targetTimestamp);
    $isCustom = ($targetTimestamp !== $fallbackTimestamp) ? "🟢 Første linje" : "🟡 Processtart";
    echo "<div style='padding-left:20px; color:#b5cea8;'>↳ Oprettet: <strong style='color:#ce9178;'>inc/ai_export/reconstructed/$relativePath</strong> ($displayDate via $isCustom)</div>";
}
?>