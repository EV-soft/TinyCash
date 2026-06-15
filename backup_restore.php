<?php # backup_restore.php v:1.0.0 d:2026-06-15 i:evs
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php'; 
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; 

// Kun admins
if ($_SESSION['user_role'] !== 'admin') { die(lang('@Access denied')); }

htm_Header('@Restore System');
showMenu();

echo "<div style='max-width: 600px; margin: 0 auto; font-family: sans-serif;'>";
htm_Card_('@Restore from Backup');

// Vis fejl eller succes beskeder
if (isset($_GET['msg'])) {
    $color = ($_GET['msg'] == 'success') ? '#27ae60' : '#e74c3c';
    $text = ($_GET['msg'] == 'success') ? lang('@Recovery completed!') 
                                        : lang('@Error during recovery.');
    echo "<div style='background:$color; color:white; padding:10px; border-radius:4px; 
          margin-bottom:20px; text-align:center;'>$text</div>";
}

echo '<p style="color:#e67e22; font-weight:bold;">⚠️ '.lang('@Warning:').'</p>';
echo '<p style="font-size:0.9em; color:#666;">'.
      lang('@By uploading a backup file, you will overwrite the current data in the system. This cannot be undone.'). '</p>';
echo "<hr style='border:0; border-top:1px solid #eee; margin:20px 0;'>";

// Formular til upload
echo "<form action='backup_restore_worker.php' method='post' enctype='multipart/form-data'>";
echo "  <div style='margin-bottom:20px;'>";
echo "      <label style='display:block; margin-bottom:10px; font-weight:bold;'>" . 
            lang('@Select Backup File (.zip)') . ":</label>";
echo "      <input type='file' name='backup_file' accept='.zip' required style='width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;'>";
echo "  </div>";
echo "  <button type='submit' style='background:#e67e22; color:white; border:0; padding:12px 25px; border-radius:4px; 
            cursor:pointer; font-weight:bold; width:100%;'>" . lang('@Start Restore Now') . 
        "</button>";
echo "</form>";
htm_Card_end();

echo "</div>";

htm_Footer();
?>