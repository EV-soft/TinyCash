<?php # /tools/file_dependency_analyzer.php v:1.0.0
$realBase = realpath(dirname(__DIR__)); 
if (!$realBase) {
    die("Error: Could not find root directory.");
}
$baseDir = $realBase . DIRECTORY_SEPARATOR;
$incDir  = $baseDir . 'inc' . DIRECTORY_SEPARATOR;
$dataDir = $baseDir . 'json-data' . DIRECTORY_SEPARATOR; // Typisk placering for JSON

$scanDirs = array_filter([$baseDir, $incDir, $dataDir], 'is_dir');

$phpFiles = [];
foreach ($scanDirs as $dir) {
    // Vi inkluderer nu også .json filer i scanningen
    $files = glob($dir . "*.{php,inc,inc.php,page.php,json}", GLOB_BRACE);
    if ($files) $phpFiles = array_merge($phpFiles, $files);
}
$phpFiles = array_unique($phpFiles);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Fil-analyse v1.0.0</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .container { max-width: 1300px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        h2 { color: #1a73e8; margin: 0 0 20px 0; display: flex; justify-content: space-between; align-items: center; }
        .stats { font-size: 0.5em; background: #e8f0fe; padding: 5px 15px; border-radius: 20px; color: #1967d2; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th { background: #f8f9fa; color: #5f6368; cursor: pointer; padding: 15px; text-align: left; border-bottom: 2px solid #eee; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f1f1; font-size: 13px; word-wrap: break-word; vertical-align: top; }
        tr:hover { background: #fcfcfc; }
        .count-badge { display: inline-block; min-width: 24px; padding: 4px 8px; border-radius: 6px; font-weight: bold; text-align: center; }
        .has-calls { background: #34a853; color: white; }
        .no-calls { background: #ea4335; color: white; }
        .json-file { color: #f29900; font-weight: bold; } /* Markering af JSON filer */
    </style>
</head>
<body>

<div class="container">
    <h2>
        📊 File-analyse & Ressources
        <span class="stats">Found: <?php echo count($phpFiles); ?> ressources</span>
    </h2>

    <?php
    $dependencies = [];
    $fileInfo = [];
    $fileNamesOnly = [];

    foreach ($phpFiles as $fullPath) {
        $fileName = basename($fullPath);
        $relPath = str_replace($baseDir, '', $fullPath);
        
        $fileNamesOnly[$relPath] = $fileName;
        $dependencies[$relPath] = [];
        
        $rawSize = filesize($fullPath);
        $fileInfo[$relPath] = [
            'size_raw' => $rawSize,
            'size_fmt' => $rawSize >= 1024 ? number_format($rawSize / 1024, 1, ',', '.') . ' KB' : $rawSize . ' B',
            'date' => date("Y-m-d H:i", filemtime($fullPath)),
            'mtime' => filemtime($fullPath),
            'is_json' => (pathinfo($fileName, PATHINFO_EXTENSION) === 'json')
        ];
    }

    foreach ($phpFiles as $fullPath) {
        if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'json') continue; // Scan ikke indholdet af JSON-filer
        
        $content = file_get_contents($fullPath);
        $currentRel = str_replace($baseDir, '', $fullPath);

        foreach ($fileNamesOnly as $relPath => $actualName) {
            if ($currentRel === $relPath) continue;
            
            // Vi tjekker om filnavnet (f.eks. languages.json) optræder i koden
            if (strpos($content, $actualName) !== false) {
                $dependencies[$relPath][] = $currentRel;
            }
        }
    }
    ?>

    <table id="analysisTable">
        <thead>
            <tr>
                <th onclick="sortTable(0)" style="width: 25%;">Path / Filename</th>
                <th onclick="sortTable(1, true)" style="width: 12%;">Size</th>
                <th onclick="sortTable(2)" style="width: 15%;">Changed</th>
                <th onclick="sortTable(3, true)" style="width: 10%;">Link</th>
                <th style="width: 38%;">Referenced in</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dependencies as $file => $callers): 
                $count = count(array_unique($callers));
                $badgeClass = ($count > 0) ? 'has-calls' : 'no-calls';
                $isJson = $fileInfo[$file]['is_json'];
            ?>
            <tr>
                <td class="<?php echo $isJson ? 'json-file' : ''; ?>">
                    <?php echo ($isJson ? '📦 ' : '') . $file; ?>
                </td>
                <td data-sort="<?php echo $fileInfo[$file]['size_raw']; ?>">
                    <?php echo $fileInfo[$file]['size_fmt']; ?>
                </td>
                <td data-sort="<?php echo $fileInfo[$file]['mtime']; ?>">
                    <?php echo $fileInfo[$file]['date']; ?>
                </td>
                <td data-sort="<?php echo $count; ?>">
                    <span class="count-badge <?php echo $badgeClass; ?>"><?php echo $count; ?></span>
                </td>
                <td style="font-size: 11px; color: #777;">
                    <?php echo implode(", ", array_unique($callers)); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function sortTable(n, isNumeric = false) {
    var table = document.getElementById("analysisTable");
    var rows = Array.from(table.rows).slice(1);
    var dir = table.getAttribute("data-sort-dir") === "asc" ? "desc" : "asc";
    table.setAttribute("data-sort-dir", dir);

    rows.sort(function(a, b) {
        var x = a.getElementsByTagName("TD")[n].getAttribute("data-sort") || a.getElementsByTagName("TD")[n].innerText.toLowerCase();
        var y = b.getElementsByTagName("TD")[n].getAttribute("data-sort") || b.getElementsByTagName("TD")[n].innerText.toLowerCase();
        if (isNumeric) return dir === "asc" ? parseFloat(x) - parseFloat(y) : parseFloat(y) - parseFloat(x);
        return dir === "asc" ? x.localeCompare(y) : y.localeCompare(x);
    });
    rows.forEach(row => table.tBodies[0].appendChild(row));
}
</script>
</body>
</html>