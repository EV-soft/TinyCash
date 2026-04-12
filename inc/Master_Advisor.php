<?php # /inc/master_advisor.php v:0.8.0 d:2026-04-11 i:evs m:0
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);


function scanProjectFunctions($dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $phpFiles = new RegexIterator($files, '/\.php$/i');
    $foundFunctions = [];

    foreach ($phpFiles as $file) {
        $filename = $file->getRealPath();
        if (basename($filename) === 'Master_Advisor.php') continue;
        $content = file_get_contents($filename);
        $tokens = token_get_all($content);
        
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            // Vi leder efter T_FUNCTION
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                
                $funcName = "";
                $params = "";
                
                // Find navnet (det næste T_STRING efter function)
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $funcName = $tokens[$j][1];
                        break;
                    }
                }

                // Find parametrene (alt inde i parenteserne efter navnet)
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
                        'file'   => str_replace(realpath(__DIR__), '', $filename),
                        'name'   => $funcName,
                        'params' => empty(trim($params)) ? 'none' : htmlspecialchars(trim($params))
                    ];
                }
            }
        }
    }

    // --- VISNING (Samme mørke tema) --- 
    echo '<div style="font-family:monospace; background:#1e1e1e; color:#d4d4d4; padding:20px; border-radius:8px; border:1px solid #333;">';
    echo '<h2 style="color:#4ec9b0; border-bottom:1px solid #333; padding-bottom:10px;">TinyCash Intelligence: Function Map</h2>';
    echo '<table style="width:100%; border-collapse:collapse;">';
    echo '<tr style="text-align:left; background:#252526; color:#9cdcfe;"><th style="padding:10px;">Source File</th><th style="padding:10px;">Function Name</th><th style="padding:10px;">Parameters</th></tr>';

    foreach ($foundFunctions as $func) {
        echo '<tr style="border-bottom:1px solid #2d2d2d;">';
        echo '<td style="padding:8px; color:#808080;">' . $func['file'] . '</td>';
        echo '<td style="padding:8px; color:#dcdcaa; font-weight:bold;">' . $func['name'] . '()</td>';
        echo '<td style="padding:8px; color:#ce9178;">' . $func['params'] . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
}


scanProjectFunctions(dirname(__DIR__));

// 1. Database connection
if (file_exists('db_connect.inc.php')) {
    require_once 'db_connect.inc.php';
} else {
    die("Error: db_connect.inc.php not found in this directory.");
}

echo "<div style='font-family:sans-serif; background:#fff; padding:20px;'>";
echo "<h1>🛠 Project Snapshot for AI Advisor</h1>";
echo "<p>Please copy all content below and paste it into the chat.</p>";

// --- SECTION 1: DATABASE BLUEPRINT ---
echo "<h2>1. Database Schema</h2>";
$tables_res = mysqli_query($conn, "SHOW TABLES");
if ($tables_res) {
    while ($table_row = mysqli_fetch_array($tables_res)) {
        $tableName = $table_row[0];
        echo "<div style='background:#f8f9fa; border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:5px;'>";
        echo "<strong>Table: $tableName</strong><br><small style='color:#555;'>";
        $cols_res = mysqli_query($conn, "DESCRIBE `$tableName`");
        $cols = [];
        while ($col = mysqli_fetch_assoc($cols_res)) {
            $cols[] = $col['Field'] . " (" . $col['Type'] . ")";
        }
        echo implode(" | ", $cols);
        echo "</small></div>";
    }
}

// --- SECTION 2: SOURCE CODE COLLECTION ---
echo "<h2>2. Source Code</h2>";

$sourceDir = dirname(__DIR__); 
$directory = new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($directory);

echo "<pre style='background:#1e1e1e; color:#d4d4d4; padding:20px; border-radius:8px; overflow:auto; font-size:12px; line-height:1.5;'>";

foreach ($iterator as $fileInfo) {
    if ($fileInfo->isDir()) continue;
    
    $filePath = $fileInfo->getPathname();
    $fileName = $fileInfo->getFilename();

    // Include .php and .inc files, skip this file itself
    if (!preg_match("/\.(php|inc)$/i", $fileName) || $fileName == basename(__FILE__)) continue;

    $relativeName = str_replace($sourceDir, "", $filePath);
    $content = file_get_contents($filePath);
    
    echo "################################################################\n";
    echo "### FILE: $relativeName\n";
    echo "################################################################\n\n";
    echo htmlspecialchars($content);
    echo "\n\n";
}

echo "</pre>";
echo "</div>";

$full_report = ob_get_clean();
echo $full_report;
?>