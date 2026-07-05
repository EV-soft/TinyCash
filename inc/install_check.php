<?php # /install_check.php v:1.0.0 d:2026-05-11 i:evs
ob_start();
session_start();

// Simpel styling til det interaktive interface
echo "<style>
    body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 40px; color: #333; }
    .container { max-width: 800px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    h1 { border-bottom: 2px solid #eee; padding-bottom: 15px; color: #2c3e50; }
    .check-item { display: flex; align-items: center; padding: 15px; border-bottom: 1px solid #f0f0f0; }
    .status { font-weight: bold; margin-right: 20px; width: 100px; text-transform: uppercase; font-size: 0.8em; }
    .ok { color: #27ae60; }
    .fail { color: #e74c3c; }
    .warn { color: #f39c12; }
    .desc { flex-grow: 1; }
    .btn { display: inline-block; padding: 12px 25px; background: #3498db; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; font-weight: bold; }
    .hint { font-size: 0.85em; color: #7f8c8d; margin-top: 5px; }
</style>";

echo "<div class='container'>";
echo "<h1>🔍 System Installation Check</h1>";

function getStatus($bool, $warn = false) {
    if ($bool) return "<span class='status ok'>✅ OK</span>";
    return $warn ? "<span class='status warn'>⚠️ OBS</span>" : "<span class='status fail'>❌ FEJL</span>";
}

function get_htaccess_status() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    // Vi tjekker db_connect.inc.php direkte - den SKAL være blokeret
    $url = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/inc/db_connect.inc.php";
    $context = stream_context_create([
        'http' => ['ignore_errors' => true, 'timeout' => 1]
    ]);
    $headers = @get_headers($url, 1, $context);
    if ($headers === false) return ['status' => 'unknown', 'msg' => 'Kunne ikke forbinde'];
    $http_code = $headers[0];
    // Hvis vi får 403 Forbidden eller 404 (hvis serveren er sat til at skjule filer), er det succes
    if (strpos($http_code, '403') !== false || strpos($http_code, '404') !== false) {
        return ['status' => 'ok', 'msg' => 'Aktiv (Adgang nægtet)'];
    } else {
        return ['status' => 'fail', 'msg' => 'FEJL: Filer i /inc/ er synlige for alle!'];
    }
}

// 1. Tjek PHP Version
$php_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
echo "<div class='check-item'>
        " . getStatus($php_ok) . "
        <div class='desc'><strong>PHP Version:</strong> " . PHP_VERSION . "
            <div class='hint'>Kræver min. 7.4 for optimal performance.</div>
        </div>
      </div>";

// 2. Tjek Database-fil (db_connect.inc.php)
$db_file = file_exists('inc/db_connect.inc.php');
echo "<div class='check-item'>
        " . getStatus($db_file) . "
        <div class='desc'><strong>Database Konfiguration:</strong> inc/db_connect.inc.php
            <div class='hint'>" . ($db_file ? "Fil fundet." : "Fil mangler! Husk at omdøbe db_connect.doc.php.") . "</div>
        </div>
      </div>";

// 3. Tjek Database Forbindelse
$db_conn = false;
$db_msg = "Kan ikke teste uden konfigurationsfil.";
if ($db_file) {
    include 'inc/db_connect.inc.php';
    if (isset($conn) && $conn) {
        $db_conn = true;
        $db_msg = "Forbindelse til MySQL er etableret.";
    } else {
        $db_msg = "Forbindelse mislykkedes. Tjek koderne i filen.";
    }
}
echo "<div class='check-item'>
        " . getStatus($db_conn) . "
        <div class='desc'><strong>MySQL Forbindelse:</strong>
            <div class='hint'>$db_msg</div>
        </div>
      </div>";

// 4. Tjek Skrivbare Mapper
$folders = ['uploads', 'backups', 'json-data'];
foreach ($folders as $folder) {
    $writable = is_dir($folder) && is_writable($folder);
    echo "<div class='check-item'>
            " . getStatus($writable) . "
            <div class='desc'><strong>Mappe-rettigheder:</strong> /$folder/
                <div class='hint'>" . ($writable ? "Mappen er skrivbar." : "Mappen mangler eller er ikke skrivbar (CHMOD 775).") . "</div>
            </div>
          </div>";
}

// 5. Tjek .htaccess (Sikkerhed)
$htaccess = file_exists('.htaccess');
echo "<div class='check-item'>
        " . getStatus($htaccess, true) . "
        <div class='desc'><strong>Brandmur (.htaccess):</strong>
            <div class='hint'>" . ($htaccess ? "Aktiv og beskytter dine filer." : "Inaktiv! Husk at omdøbe ..htaccess til .htaccess når du er klar.") . "</div>
        </div>
      </div>";

// 6. Tjek Sprogfil
$lang_file = file_exists('json-data/languages.json');
echo "<div class='check-item'>
        " . getStatus($lang_file) . "
        <div class='desc'><strong>Sprogmodul:</strong> json-data/languages.json
            <div class='hint'>" . ($lang_file ? "Sprogdata fundet." : "Systemet vil mangle tekster!") . "</div>
        </div>
      </div>";

if ($db_conn && $php_ok && $lang_file) {
    echo "<div style='text-align:center; margin-top:30px;'>
            <p style='color:#27ae60; font-weight:bold;'>Alt ser ud til at være klar!</p>
            <a href='index.php' class='btn'>Gå til Login →</a>
            <p style='font-size:0.8em; margin-top:10px; color:#e74c3c;'>Husk at slette install_check.php efter brug!</p>
          </div>";
} else {
    echo "<div style='text-align:center; margin-top:30px;'>
            <p style='color:#e74c3c;'>Løs de røde punkter ovenfor for at fortsætte.</p>
            <a href='install_check.php' class='btn' style='background:#95a5a6;'>Genindlæs tjekliste</a>
          </div>";
}

echo "</div>";
ob_end_flush();
?>
