<?php # /inc/depot_worker.inc.php v:1.0.0 d:2026-05-24 i:evs ok

/**
 * Scanner Bilagsdepotet og sikrer, at kildemapperne eksisterer.
 * @return array Struktur over fundne filer opdelt på kilder.
 */
function getDepotFiles() {
    // Definer hovedmappen til depotet i din storage-struktur
    $base_depot = __DIR__ . '/../storage/bilagsdepot/';
    
    // De 4 ønskede kilde-mapper
    $sources = ['Foto', 'Mail', 'Skanner', 'Download'];
    $depot_structure = [];

    foreach ($sources as $source) {
        $dir_path = $base_depot . $source;
        
        // Opret mappen automatisk, hvis den mangler
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0755, true);
        }

        $depot_structure[$source] = [];

        // Scan mappen for filer (og sorter dem, så de nyeste ligger øverst)
        $files = scandir($dir_path, SCANDIR_SORT_DESCENDING);
        
        foreach ($files as $file) {
            // Spring over system-filer (. og ..)
            if ($file === '.' || $file === '..') continue;
            
            $full_path = $dir_path . '/' . $file;
            
            if (is_file($full_path)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                // Tillad kun gængse bilagsformater
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $depot_structure[$source][] = [
                        'filename' => $file,
                        'source'   => $source,
                        'size'     => filesize($full_path),
                        'date'     => filemtime($full_path),
                        'rel_path' => 'storage/bilagsdepot/' . $source . '/' . $file
                    ];
                }
            }
        }
    }

    return $depot_structure;
}