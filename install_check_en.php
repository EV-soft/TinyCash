<?php # /install_check_en.php v:1.3.0 d:2026-08-30 i:evs
# self-lock added when accounts table has data
ob_start();
session_start();

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
    return $warn ? "<span class='status warn'>⚠️ NOTE</span>" : "<span class='status fail'>❌ FAIL</span>";
}

// 1. Check PHP Version (Minimum 8.0 due to named arguments)
$php_ok = version_compare(PHP_VERSION, '8.0.0', '>=');
echo "<div class='check-item'>
        " . getStatus($php_ok) . "
        <div class='desc'><strong>PHP Version:</strong> " . PHP_VERSION . "
            <div class='hint'>Min. version 8.0 required for named arguments support.</div>
        </div>
      </div>";

// 2. Check Database Configuration File
$db_file = file_exists('inc/db_connect.inc.php');
echo "<div class='check-item'>
        " . getStatus($db_file) . "
        <div class='desc'><strong>Database Config:</strong> inc/db_connect.inc.php
            <div class='hint'>" . ($db_file ? "File found." : "File missing! Remember to edit and rename db_connect.doc.php.") . "</div>
        </div>
      </div>";

// 3. Check Active Database Connection
$db_conn = false;
$db_msg = "Cannot test without a valid config file.";
if ($db_file) {
    include 'inc/db_connect.inc.php';
    if (isset($conn) && $conn) {
        $db_conn = true;
        $db_msg = "Connection to MySQL established successfully.";

        // SELF-LOCK (2026-08-19): this page is deliberately login-free (it
        // must run BEFORE the admin account exists), which made it a
        // permanent, unprotected diagnostic/fingerprinting endpoint if
        // forgotten after installation. Same pattern as setup_chart.php's
        // own lock: if the accounts table already has data, installation is
        // clearly complete, so the page refuses to show anything further.
        $res = DB::query($conn, "SELECT COUNT(*) FROM accounts");
        if ($res) {
            $row = DB::fetch_row($res);
            if ((int)($row[0] ?? 0) > 0) {
                ob_clean();
                echo "<div style='font-family:sans-serif;max-width:600px;margin:60px auto;text-align:center;padding:30px;background:#fff3cd;border:1px solid #ffeeba;border-radius:8px;color:#856404;'>
                        <h2>⚠️ Installation already complete</h2>
                        <p>The system already contains data. This diagnostic page has been disabled for security reasons.</p>
                        <p><strong>Please delete this file (" . htmlspecialchars(basename(__FILE__)) . ") from the server.</strong></p>
                      </div>";
                ob_end_flush();
                exit;
            }
        }
    } else {
        $db_msg = "Connection failed. Verify your credentials in the config file.";
    }
}
echo "<div class='check-item'>
        " . getStatus($db_conn) . "
        <div class='desc'><strong>MySQL Connection:</strong>
            <div class='hint'>$db_msg</div>
        </div>
      </div>";

// 4. Check Writable Directories
$folders = ['uploads', 'backups', 'json-data', 'temp_restore'];
foreach ($folders as $folder) {
    $writable = is_dir($folder) && is_writable($folder);
    echo "<div class='check-item'>
            " . getStatus($writable) . "
            <div class='desc'><strong>Directory Permissions:</strong> /$folder/
                <div class='hint'>" . ($writable ? "Directory is writable." : "Missing or not writable (requires CHMOD 775).") . "</div>
            </div>
          </div>";
}

// 5. Check Security (.htaccess)
$htaccess = file_exists('inc/.htaccess');
echo "<div class='check-item'>
        " . getStatus($htaccess, true) . "
        <div class='desc'><strong>Security Firewall (.htaccess):</strong>
            <div class='hint'>" . ($htaccess ? "Active in the /inc/ directory." : "Not found! Remember to rename inc/..htaccess to inc/.htaccess.") . "</div>
        </div>
      </div>";

if ($db_conn && $php_ok) {
    echo "<div style='text-align:center; margin-top:30px;'>
            <p style='color:#27ae60; font-weight:bold;'>System is ready for use!</p>
            <a href='index.php' class='btn'>Go to Login →</a>
            <p style='font-size:0.8em; margin-top:10px; color:#e74c3c;'>Important: Delete install_check_en.php after successful setup!</p>
          </div>";
} else {
    echo "<div style='text-align:center; margin-top:30px;'>
            <p style='color:#e74c3c;'>Please resolve the red items above to proceed.</p>
            <a href='install_check_en.php' class='btn' style='background:#95a5a6;'>Refresh Checklist</a>
          </div>";
}

echo "</div>";
ob_end_flush();
?>
