<?php # /tools/file_dependency_analyzer.php v:1.3.0 d:2026-08-30 i:evs
# Rapporterer også ubenyttede funktioner
$realBase = realpath(dirname(__DIR__)); 
if (!$realBase) {
    die("Error: Could not find root directory.");
}
$baseDir = $realBase . DIRECTORY_SEPARATOR;
$incDir  = $baseDir . 'inc' . DIRECTORY_SEPARATOR;
$dataDir = $baseDir . 'json-data' . DIRECTORY_SEPARATOR;

$scanDirs = array_filter([$baseDir, $incDir, $dataDir], 'is_dir');

$phpFiles = [];
foreach ($scanDirs as $dir) {
    $files = glob($dir . "*.{php,inc,inc.php,page.php,json}", GLOB_BRACE);
    if ($files) $phpFiles = array_merge($phpFiles, $files);
}
$phpFiles = array_unique($phpFiles);

$existingFiles = [];
foreach ($phpFiles as $fullPath) {
    $relPath = str_replace($baseDir, '', $fullPath);
    $existingFiles[$relPath] = true;
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>System-, Link- & Funktionsanalyse v1.2.0</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .container { max-width: 1400px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        h2 { color: #1a73e8; margin: 0 0 20px 0; display: flex; justify-content: space-between; align-items: center; }
        .stats { font-size: 0.5em; background: #e8f0fe; padding: 5px 15px; border-radius: 20px; color: #1967d2; }
        
        .section-title { margin-top: 40px; color: #3c4043; border-bottom: 2px solid #eee; padding-bottom: 8px; }
        
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 30px; }
        th { background: #f8f9fa; color: #5f6368; cursor: pointer; padding: 15px; text-align: left; border-bottom: 2px solid #eee; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f1f1; font-size: 13px; word-wrap: break-word; vertical-align: top; }
        tr:hover { background: #fcfcfc; }
        
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-weight: bold; font-size: 11px; text-align: center; color: white; }
        .badge-danger { background: #ea4335; }
        .badge-warning { background: #f29900; }
        .badge-success { background: #34a853; }
        .badge-info { background: #1a73e8; }
    </style>
</head>
<body>

<div class="container">
    <h2>
        📊 System-, Link- & Funktionsanalyse
        <span class="stats">Scanned: <?php echo count($phpFiles); ?> filer</span>
    </h2>

    <?php
    $deadLinks = [];
    $dependencies = [];
    $fileInfo = [];
    
    // Funktions-tracking arrays
    $definedFunctions = []; // ['funktionsnavn' => 'fil_hvor_den_er_defineret.php']
    $functionCalls = [];    // ['funktionsnavn' => ['fil1.php', 'fil2.php']]

    // 1. Gennemgang: Saml fil-info og find ALLE definerede funktioner
    foreach ($phpFiles as $fullPath) {
        $relPath = str_replace($baseDir, '', $fullPath);
        $dependencies[$relPath] = [];
        $rawSize = filesize($fullPath);
        
        $fileInfo[$relPath] = [
            'size_fmt' => $rawSize >= 1024 ? number_format($rawSize / 1024, 1, ',', '.') . ' KB' : $rawSize . ' B',
            'date' => date("Y-m-d H:i", filemtime($fullPath)),
            'is_json' => (pathinfo($fullPath, PATHINFO_EXTENSION) === 'json')
        ];

        if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'json') continue;

        $content = file_get_contents($fullPath);
        
        // Matcher: function funktionsNavn(...)
        if (preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/i', $content, $funcMatches)) {
            foreach ($funcMatches[1] as $funcName) {
                $definedFunctions[$funcName] = $relPath;
                $functionCalls[$funcName] = []; // Klargør array til kald
            }
        }
    }

    // 2. Gennemgang: Find referencer (Links, Includes og Funktionskald)
    foreach ($phpFiles as $fullPath) {
        if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'json') continue;
        
        $content = file_get_contents($fullPath);
        $currentRel = str_replace($baseDir, '', $fullPath);
        $currentDir = dirname($currentRel) === '.' ? '' : dirname($currentRel) . '/';

        // Tjek for PHP Includes
        if (preg_match_all('/(?:include|require)(?:_once)?\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $target) {
                $checkPath = $target;
                if (!file_exists($baseDir . $checkPath) && file_exists($baseDir . $currentDir . $target)) {
                    $checkPath = $currentDir . $target;
                }
                if (!file_exists($baseDir . $checkPath)) {
                    $deadLinks[] = ['source' => $currentRel, 'type' => 'PHP Include', 'target' => $target, 'status' => 'Filen mangler'];
                } else {
                    $dependencies[$checkPath][] = $currentRel;
                }
            }
        }

        // Tjek for HTML / JS Links
        if (preg_match_all('/(?:href|src)\s*=\s*[\'"]([^\'"]+\.(?:php|html|js|json|xml))[\'"]|fetch\(\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
            $targets = array_filter(array_merge($matches[1], $matches[2]));
            foreach ($targets as $target) {
                if (preg_match('/^(?:https?:)?\/\//i', $target) || strpos($target, '#') === 0 || strpos($target, 'mailto:') === 0) continue;
                $cleanTarget = explode('?', $target)[0];
                $checkPath = $cleanTarget;
                if (!file_exists($baseDir . $checkPath) && file_exists($baseDir . $currentDir . $cleanTarget)) {
                    $checkPath = $currentDir . $cleanTarget;
                }
                if (!file_exists($baseDir . $checkPath)) {
                    $deadLinks[] = ['source' => $currentRel, 'type' => 'HTML / JS Link', 'target' => $target, 'status' => 'Dødt link / 404'];
                } else {
                    $dependencies[$checkPath][] = $currentRel;
                }
            }
        }

        // Tjek for brug af de fundne funktioner
        foreach ($definedFunctions as $funcName => $definedInFile) {
            // Vi leder efter funktionsnavnet efterfulgt af en start-parentes, f.eks. minFunktion(
            // Men vi sikrer os med negative lookbehinds (?<!...), at det ikke er selve definitionen 'function minFunktion(' vi matcher
            if (preg_match_all('/(?<!function\s)' . preg_quote($funcName, '/') . '\s*\(/i', $content)) {
                $functionCalls[$funcName][] = $currentRel;
            }
        }
    }
    ?>

    <h3 class="section-title" style="color: #ea4335;">⚠️ Døde links & Manglende inkluderinger</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 30%;">Fundet i fil (Source)</th>
                <th style="width: 15%;">Type</th>
                <th style="width: 35%;">Henviser til (Dødt link)</th>
                <th style="width: 20%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deadLinks)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: #34a853; font-weight: bold; padding: 20px;">
                        🎉 Perfekt! Ingen døde links eller manglende filer fundet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($deadLinks as $link): ?>
                    <tr>
                        <td><strong><?php echo $link['source']; ?></strong></td>
                        <td>
                            <span class="badge <?php echo $link['type'] === 'PHP Include' ? 'badge-danger' : 'badge-warning'; ?>">
                                <?php echo $link['type']; ?>
                            </span>
                        </td>
                        <td style="color: #ea4335; font-family: monospace;"><?php echo htmlspecialchars($link['target']); ?></td>
                        <td><span class="badge badge-danger"><?php echo $link['status']; ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>


    <h3 class="section-title" style="color: #e67e22;">⚙️ Funktions-analyse (Ubenyttet kode / Dead Code)</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 25%;">Funktionsnavn</th>
                <th style="width: 25%;">Defineret i fil</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 35%;">Kaldt fra (Referencer)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $hasOrphanFunctions = false;
            foreach ($functionCalls as $funcName => $callers): 
                $uniqueCallers = array_unique($callers);
                $count = count($uniqueCallers);
                if ($count === 0) $hasOrphanFunctions = true;
            ?>
            <tr>
                <td style="font-family: monospace; font-weight: bold; <?php echo $count === 0 ? 'color: #ea4335;' : ''; ?>">
                    <?php echo $funcName; ?>();
                </td>
                <td><?php echo $definedFunctions[$funcName]; ?></td>
                <td>
                    <span class="badge <?php echo $count > 0 ? 'badge-success' : 'badge-danger'; ?>">
                        <?php echo $count > 0 ? $count . ' aktive kald' : 'Ubenyttet'; ?>
                    </span>
                </td>
                <td style="font-size: 11px; color: #666;">
                    <?php echo $count > 0 ? implode(", ", $uniqueCallers) : '<em style="color:#ea4335; font-weight:bold;">Kan sandsynligvis slettes</em>'; ?>
                </td>
            </tr>
            <?php endforeach; if (!$hasOrphanFunctions && !empty($definedFunctions)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: #34a853; font-weight: bold; padding: 20px;">
                        🎉 Flot! Alle dine brugerdefinerede funktioner er i brug i systemet.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>


    <h3 class="section-title">📂 Eksisterende filer & Forældreløse filer (Orphans)</h3>
    <table id="analysisTable">
        <thead>
            <tr>
                <th onclick="sortTable(0)" style="width: 30%;">Sti / Filnavn</th>
                <th onclick="sortTable(1, true)" style="width: 10%;">Størrelse</th>
                <th onclick="sortTable(2)" style="width: 15%;">Sidst ændret</th>
                <th onclick="sortTable(3, true)" style="width: 15%;">Antal referencer</th>
                <th style="width: 30%;">Bliver brugt af (Referenced in)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dependencies as $file => $callers): 
                $uniqueCallers = array_unique($callers);
                $count = count($uniqueCallers);
                $isJson = $fileInfo[$file]['is_json'];
                
                $badgeClass = ($count > 0) ? 'badge-success' : 'badge-danger';
                $badgeText = $count > 0 ? $count . ' referencer' : '0 (Forældreløs)';
            ?>
            <tr>
                <td style="<?php echo $isJson ? 'color: #f29900; font-weight: bold;' : ''; ?>">
                    <?php echo ($isJson ? '📦 ' : '📄 ') . $file; ?>
                </td>
                <td><?php echo $fileInfo[$file]['size_fmt']; ?></td>
                <td><?php echo $fileInfo[$file]['date']; ?></td>
                <td>
                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                </td>
                <td style="font-size: 11px; color: #666;">
                    <?php echo $count > 0 ? implode(", ", $uniqueCallers) : '<em style="color:#b5b5b5;">Tilsyneladende Ubrugt fil (Kan måske slettes)</em>'; ?>
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
        var x = a.getElementsByTagName("TD")[n].innerText.toLowerCase();
        var y = b.getElementsByTagName("TD")[n].innerText.toLowerCase();
        
        if (isNumeric) {
            x = parseFloat(x.replace(/[^0-9.-]/g, '')) || 0;
            y = parseFloat(y.replace(/[^0-9.-]/g, '')) || 0;
            return dir === "asc" ? x - y : y - x;
        }
        return dir === "asc" ? x.localeCompare(y) : y.localeCompare(x);
    });
    rows.forEach(row => table.tBodies[0].appendChild(row));
}
</script>
</body>
</html>