<?php # collector.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Hent værdier (med checkbox tjek)
$mask    = $_REQUEST['mask'] ?? '*.php';
$age     = $_REQUEST['age'] ?? 'all';
$sub     = isset($_REQUEST['sub']); // Ny: Sandt hvis checkbox er sat
$run     = isset($_REQUEST['run']);
$msg     = "";

// Alder-logik
$ageLimit = 0;
if ($age == '1h')  $ageLimit = 3600;
if ($age == '4h')  $ageLimit = 4*3600;
if ($age == '24h') $ageLimit = 86400;
if ($age == '7d')  $ageLimit = 604800;

if ($run) {
    $sourceDir = dirname(__DIR__); 
    $outputFile = "collected_project_files.txt";
    $fullPath = __DIR__ . '/' . $outputFile;
    
    // Vælg mellem simpel scanning eller rekursiv
    if ($sub) {
        $directory = new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);
    } else {
        $iterator = new DirectoryIterator($sourceDir);
    }
    
    $regexMask = str_replace(['.', '*'], ['\.', '.*'], $mask);
    $handle = fopen($fullPath, 'w');
    fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
    
    $count = 0; $totalLines = 0; $summaryList = [];
    
    fwrite($handle, "FILE COLLECTION (" . date('Y-m-d H:i:s') . ")\n");
    fwrite($handle, "MASK: $mask | SUBDIRS: " . ($sub ? 'Yes' : 'No') . " | AGE: $age\n" . str_repeat("=", 50) . "\n\n");

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) continue;
        
        $filePath = $fileInfo->getPathname();
        $fileName = $fileInfo->getFilename();

        if (!preg_match("/^$regexMask$/i", $fileName)) continue;

        $mtime = $fileInfo->getMTime();
        if ($ageLimit > 0 && (time() - $mtime) > $ageLimit) continue;
        if ($fileName == basename(__FILE__) || $fileName == $outputFile) continue;

        $content = file_get_contents($filePath);
        $lines = substr_count($content, "\n") + 1;
        $relativeName = str_replace($sourceDir, "", $filePath);
        
        $summaryList[] = sprintf("%-40s | %-20s | %d lines", $relativeName, date("Y-m-d H:i:s", $mtime), $lines);
        
        fwrite($handle, "### FILE: $relativeName | DATE: " . date("Y-m-d H:i:s", $mtime) . "\n\n" . $content . "\n\n");
        $count++; $totalLines += $lines;
    }
    
    fwrite($handle, "\nCOLLECTION SUMMARY\nTotal files: $count | Total lines: $totalLines\n" . implode("\n", $summaryList));
    fclose($handle);
    
    $msg = ($count > 0) ? "<div style='background:#d4edda; padding:15px;'>Success! $count filer indsamlet.<br><br><a href='$outputFile' target='_blank'><b>Åbn / Download fil</b></a></div>" : "Ingen filer fundet.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>TinyCash Collector Pro</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 40px; }
        .card { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: auto; }
        .form-group { margin-bottom: 15px; }
    </style>
</head>
<body>
<div class="card">
    <h2>🛠 Collector Pro</h2>
    <form method="POST">
        <div class="form-group">
            <label>Fil Maske:</label><br>
            <input type="text" name="mask" value="<?php echo htmlspecialchars($mask); ?>" style="width:100%; padding:8px;">
        </div>
        <div class="form-group">
            <input type="checkbox" name="sub" id="sub" <?php if($sub) echo 'checked'; ?>>
            <label for="sub">Inkluder undermapper (Rekursiv)</label>
        </div>
        <div class="form-group">
            <label>Filernes alder:</label>
            <select name="age" style="width:100%; padding:8px;">
                <option value="all" <?php if($age=='all') echo 'selected'; ?>>Alle filer</option>
                <option value="1h" <?php if($age=='1h') echo 'selected'; ?>>Sidste time</option>
                <option value="4h" <?php if($age=='4h') echo 'selected'; ?>>Sidste 4 timer</option>
                <option value="24h" <?php if($age=='24h') echo 'selected'; ?>>Sidste 24 timer</option>
            </select>
        </div>
        <input type="hidden" name="run" value="1">
        <button type="submit" style="padding:10px 20px; background:#007bff; color:white; border:none; width:100%;">Scan nu</button>
    </form>
    <div style="margin-top:20px;"><?php echo $msg; ?></div>
    <a href="../index.page.php" style="display:block; margin-top:20px; text-align:center;">← Tilbage</a>
</div>
</body>
</html>