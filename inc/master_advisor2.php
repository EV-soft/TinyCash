<?php # /inc/master_advisor2.php v:1.3.0 d:2026-08-30 i:evs
# (Skanningsområde: kun rod + 1. underniveau)
# v1.3.0: KRITISK - intet PHP-login-tjek. Dumper kildekode og sletter gamle
# eksportfiler, kun beskyttet af inc/.htaccess's "afvis alt"-standard (som
# ikke gælder på fx nginx eller lokal test uden .htaccess-understøttelse).
# Tilføjet admin-tjek som et uafhængigt, ekstra lag. Samme fund som
# Master_Advisor.php - se den fil for detaljer. Gennemgang af resterende inc-filer.
chdir(dirname(__DIR__));
require_once 'inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);


function _is_excluded($relativePath) {
    $excludes = ['PHPMailer', 'phpmailer', 'vendor', 'udgaaet', 'ai_export', 'backups', 'node_modules'];
    foreach ($excludes as $ex) {
        if (str_contains($relativePath, $ex)) return true;
    }
    return false;
}

function scanProjectFunctions($dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $foundFunctions = [];

    foreach ($files as $file) {
        if (!$file->isFile()) continue;
        if (!preg_match('/\.php$/i', $file->getFilename())) continue;
        if (basename($file->getRealPath()) === 'master_advisor.php') continue;

        $relativePath = ltrim(str_replace('\\', '/', str_replace(realpath($dir), '', $file->getRealPath())), '/');

        $parts  = explode('/', $relativePath);
        $inRoot = count($parts) === 1;
        $inInc  = count($parts) === 2 && $parts[0] === 'inc';
        if (!$inRoot && !$inInc) continue;
        if (_is_excluded($relativePath)) continue;

        $content = file_get_contents($file->getRealPath());
        $tokens  = token_get_all($content);
        $count   = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;

            $funcName = ''; $params = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $funcName = $tokens[$j][1]; break; }
            }
            $inP = false;
            for ($k = $j + 1; $k < $count; $k++) {
                if ($tokens[$k] === '(') { $inP = true; continue; }
                if ($tokens[$k] === ')') break;
                if ($inP) $params .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
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
    return $foundFunctions;
}

$sourceDir  = dirname(__DIR__);
$exportDir  = __DIR__ . '/ai_export/';

if (!file_exists($exportDir)) {
    mkdir($exportDir, 0775, true);
} else {
    foreach (glob($exportDir . 'upload_*.txt') as $f) {
        if (is_file($f)) unlink($f);
    }
}

// ── Saml filer: kun rod + første underniveau ──────────────────────────────────
$iterator       = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS));
$collectedFiles = [];

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) continue;
    if (!preg_match('/\.(php|inc)$/i', $fileInfo->getFilename())) continue;
    if ($fileInfo->getFilename() === basename(__FILE__)) continue;

    $relativeName = ltrim(str_replace('\\', '/', str_replace($sourceDir, '', $fileInfo->getPathname())), '/');

    // Kun rod-filer (ingen /) eller filer direkte i /inc/ (præcis ét niveau: inc/filnavn.php)
    $parts  = explode('/', $relativeName);
    $inRoot = count($parts) === 1;
    $inInc  = count($parts) === 2 && $parts[0] === 'inc';
    if (!$inRoot && !$inInc) continue;

    if (_is_excluded($relativeName)) continue;

    $content = file_get_contents($fileInfo->getPathname());
    $revDate = '1970-01-01';
    if (preg_match('/d:(\d{4}-\d{2}-\d{2})/', $content, $m)) {
        $revDate = $m[1];
    } else {
        $revDate = date('Y-m-d', filemtime($fileInfo->getPathname()));
    }

    $collectedFiles[] = [
        'path'     => $fileInfo->getPathname(),
        'relative' => $relativeName,
        'date'     => $revDate,
        'content'  => $content,
    ];
}

// Sorter ældste først
usort($collectedFiles, fn($a, $b) => strcmp($a['date'], $b['date']));

// Opdel i præcis 3 grupper
$totalFilesCount = count($collectedFiles);
$chunkSize       = (int)ceil($totalFilesCount / 3);
$fileGroups      = array_chunk($collectedFiles, $chunkSize ?: 1);

$groupTitles = ['aeldste', 'mellemste', 'nyeste'];
$groupLabels = ['Ældste',  'Mellemste', 'Nyeste'];

foreach ($fileGroups as $groupIndex => $group) {
    $formattedIndex = sprintf('%02d', $groupIndex + 1);
    $tag   = $groupTitles[$groupIndex] ?? 'gruppe_' . ($groupIndex + 1);
    $label = $groupLabels[$groupIndex] ?? ('Gruppe ' . ($groupIndex + 1));

    $fh = fopen($exportDir . "upload_{$formattedIndex}_{$tag}.txt", 'w');
    fwrite($fh, "### TinyCash Source Code — $label filer (rod + 1. underniveau) ###\n\n");

    foreach ($group as $fd) {
        fwrite($fh, str_repeat('#', 64) . "\n");
        fwrite($fh, "### FILE: {$fd['relative']} | REV-DATE: {$fd['date']}\n");
        fwrite($fh, str_repeat('#', 64) . "\n\n");
        fwrite($fh, $fd['content']);
        fwrite($fh, "\n\n");
    }
    fclose($fh);
}

ob_end_clean();

echo "<div style='font-family:sans-serif; padding:30px; max-width:640px; margin:0 auto;'>";
echo "<h2 style='color:#2ecc71;'>🚀 Eksport gennemført</h2>";
echo "<p>Skannet: <strong>rod</strong> og <strong>første underniveau</strong> — dybere mapper ekskluderes.</p>";
echo "<p>Fordelt på præcis <strong>3 samle-filer</strong> sorteret efter rev-dato:</p><ul>";
foreach (glob($exportDir . 'upload_*.txt') as $f) {
    $lines = count(file($f));
    $size  = round(filesize($f) / 1024, 1);
    echo "<li><strong>" . basename($f) . "</strong> — $lines linjer / {$size} KB</li>";
}
echo "</ul>";
echo "<p style='color:#7f8c8d; font-size:13px;'>Ekskluderede mapper: PHPMailer, vendor, udgaaet, ai_export, backups, node_modules</p>";
echo "</div>";