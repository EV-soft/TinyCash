<?php # /inc/master_advisor.php v:0.9.6 d:2026-05-19 i:evs m:1 ok
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- NY DYNAMISK LINJEGRÆNSE VIA URL ---
// Henter 'limit' fra URL'en. Standard er 300, og den må ikke være mindre end 50.
$maxLines = isset($_GET['limit']) ? (int)$_GET['limit'] : 300;
if ($maxLines < 50) {
    $maxLines = 50; 
}

function scanProjectFunctions($dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $phpFiles = new RegexIterator($files, '/\.php$/i');
    $foundFunctions = [];

    foreach ($phpFiles as $file) {
        $filename = $file->getRealPath();
        if (basename($filename) === 'master_advisor.php') continue;
        
        $relativePath = str_replace(realpath($dir), '', $filename);
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        
        if (str_contains($relativePath, '/') && !str_starts_with($relativePath, 'inc/')) {
            continue;
        }
        if (str_contains($relativePath, 'PHPMailer') || str_contains($relativePath, 'phpmailer')) {
            continue;
        }

        $content = file_get_contents($filename);
        $tokens = token_get_all($content);
        
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                $funcName = "";
                $params = "";
                
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $funcName = $tokens[$j][1];
                        break;
                    }
                }

                $inParentheses = false;
                for ($k = $j + 1; $k < $count; $k++) {
                    if ($tokens[$k] === '(') {
                        $inParentheses = true;
                        continue;
                    }
                    if ($tokens[$k] === ')') {
                        break;
                    }
                    if ($inParentheses) {
                        $params .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    }
                }

                if ($funcName) {
                    $foundFunctions[] = [
                        'file'   => $relativePath,
                        'name'   => $funcName,
                        'params' => empty(trim($params)) ? 'none' : htmlspecialchars(trim($params))
                    ];
                }
            }
        }
    }
    return $foundFunctions;
}

if (file_exists('db_connect.inc.php')) {
    require_once 'db_connect.inc.php';
} else {
    die("Error: db_connect.inc.php not found in this directory.");
}

$sourceDir = dirname(__DIR__); 

$exportDir = __DIR__ . '/ai_export/';
if (!file_exists($exportDir)) {
    mkdir($exportDir, 0775, true);
} else {
    $oldFiles = glob($exportDir . 'upload_*.txt');
    if ($oldFiles) {
        foreach ($oldFiles as $oldFile) {
            if (is_file($oldFile)) {
                unlink($oldFile);
            }
        }
    }
}

// A: BLUEPRINT
$mainFile = $exportDir . 'upload_01_blueprint.txt';
$mainHandle = fopen($mainFile, 'w');

fwrite($mainHandle, "================================================================\n");
fwrite($mainHandle, "TINYASH PROJECT SNAPSHOT - PART 01: BLUEPRINT\n");
fwrite($mainHandle, "================================================================\n\n");

$functions = scanProjectFunctions($sourceDir);
fwrite($mainHandle, "### FUNCTION MAP ###\n");
foreach ($functions as $func) {
    fwrite($mainHandle, "File: {$func['file']} | Function: {$func['name']}({$func['params']})\n");
}
fwrite($mainHandle, "\n\n### DATABASE SCHEMA ###\n");

$tables_res = mysqli_query($conn, "SHOW TABLES");
if ($tables_res) {
    while ($table_row = mysqli_fetch_array($tables_res)) {
        $tableName = $table_row[0];
        fwrite($mainHandle, "Table: $tableName\n");
        $cols_res = mysqli_query($conn, "DESCRIBE `$tableName`");
        $cols = [];
        while ($col = mysqli_fetch_assoc($cols_res)) {
            $cols[] = $col['Field'] . " (" . $col['Type'] . ")";
        }
        fwrite($mainHandle, "Columns: " . implode(" | ", $cols) . "\n\n");
    }
}
fclose($mainHandle);


// B: SOURCE CODE
$directory = new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($directory);

$partIndex = 2;
$linesInCurrentFile = 0;
$currentFileHandle = null;

foreach ($iterator as $fileInfo) {
    if ($fileInfo->isDir()) continue;
    
    $filePath = $fileInfo->getPathname();
    $fileName = $fileInfo->getFilename();

    if (!preg_match("/\.(php|inc)$/i", $fileName) || $fileName == basename(__FILE__)) continue;
    if (str_contains($filePath, 'udgaaet')) continue; 
    
    $relativeName = str_replace($sourceDir, "", $filePath);
    $relativeName = ltrim(str_replace('\\', '/', $relativeName), '/');

    if (str_contains($relativeName, '/') && !str_starts_with($relativeName, 'inc/')) {
        continue;
    }
    if (str_contains($relativeName, 'PHPMailer') || str_contains($relativeName, 'phpmailer')) {
        continue;
    }

    if (!$currentFileHandle) {
        $formattedIndex = sprintf('%02d', $partIndex);
        $currentFileHandle = fopen($exportDir . "upload_{$formattedIndex}_source.txt", 'w');
        fwrite($currentFileHandle, "### TinyCash Source Code - Part $formattedIndex ###\n\n");
        $linesInCurrentFile = 2;
    }

    $separator = "################################################################\n### FILE: $relativeName\n################################################################\n\n";
    fwrite($currentFileHandle, $separator);
    $linesInCurrentFile += 4;

    $fileLines = file($filePath);
    foreach ($fileLines as $line) {
        fwrite($currentFileHandle, $line);
        $linesInCurrentFile++;

        // Variablen $maxLines bruges nu dynamisk i stedet for den hårdkodede værdi 300
        if ($linesInCurrentFile >= $maxLines) {
            fclose($currentFileHandle);
            $partIndex++;
            $formattedIndex = sprintf('%02d', $partIndex);
            $currentFileHandle = fopen($exportDir . "upload_{$formattedIndex}_source.txt", 'w');
            fwrite($currentFileHandle, "### TinyCash Source Code - Part $formattedIndex ###\n\n");
            $linesInCurrentFile = 2;
        }
    }
    fwrite($currentFileHandle, "\n\n");
}

if ($currentFileHandle) {
    fclose($currentFileHandle);
}

ob_end_clean();
echo "<div style='font-family:sans-serif; padding:30px; max-width:600px; margin:0 auto;'>";
echo "<h2 style='color:#2ecc71;'>🚀 Eksport genstartet og renset!</h2>";
echo "<p>Grænse brugt ved denne kørsel: <strong>" . $maxLines . " linjer</strong> pr. fil.</p>";
echo "<p>Mappen ".'inc/ai_export/'."indeholder nu følgende filer:</p>";
echo "<ul>";
foreach (glob($exportDir . "upload_*") as $file) {
    echo "<li>" . basename($file) . " (" . count(file($file)) . " linjer)</li>";
}
echo "</ul>";
echo "</div>";
?>