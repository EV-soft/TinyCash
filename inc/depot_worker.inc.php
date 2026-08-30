<?php # /inc/depot_worker.inc.php v:1.3.0 d:2026-08-30 i:evs

/**
 * Scanner Bilagsdepotet og sikrer, at kildemapperne eksisterer.
 * @return array Struktur over fundne filer opdelt på kilder.
 */
function getDepotFiles() {
    // Definer hovedmappen til depotet i din storage-struktur (små bogstaver, engelsk)
    $base_depot = __DIR__ . '/../storage/voucher_depot/';
    
    // De 4 ønskede kilde-mapper i lowercase
    $sources = ['mail', 'scanner', 'photo', 'download'];
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
                        'rel_path' => 'storage/voucher_depot/' . $source . '/' . $file
                    ];
                }
            }
        }
    }

    return $depot_structure;
}