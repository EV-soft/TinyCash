<?php # /storage_browser.php v:1.2.0 d:2026-07-07 i:claude (Opdateret til at bruge htm_ActionButtons)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$allowed_folders = [
    'invoices'   => 'storage/invoices/',
    'einvoices'  => 'storage/einvoices/',
    'saf-t'      => 'storage/saf-t/',
    'doc'        => 'doc/',
    'images'     => 'images/',
    'uploads'    => 'uploads/',
    'backups'    => 'backups/',
    'json-data'  => 'json-data/'
];

$current_key = $_GET['folder'] ?? 'invoices';
if (!array_key_exists($current_key, $allowed_folders)) {
    $current_key = 'invoices';
}
$storage_dir = $allowed_folders[$current_key];
$msg_html = '';

if (!is_dir($storage_dir)) {
    mkdir($storage_dir, 0755, true);
}

function get_folder_stats($path) {
    $stats = ['count' => 0, 'size' => 0, 'formatted_size' => '0 KB'];
    if (!is_dir($path)) return $stats;
    $files = array_diff(scandir($path), ['.', '..', '.htaccess']);
    foreach ($files as $file) {
        $file_path = $path . $file;
        if (is_file($file_path)) {
            $stats['count']++;
            $stats['size'] += filesize($file_path);
        }
    }
    if ($stats['size'] >= 1048576) {
        $stats['formatted_size'] = number_format($stats['size'] / 1048576, 2, ',', '.') . ' MB';
    } elseif ($stats['size'] > 0) {
        $stats['formatted_size'] = number_format($stats['size'] / 1024, 2, ',', '.') . ' KB';
    }
    return $stats;
}

function is_text_file($path) {
    if (!file_exists($path) || is_dir($path)) return false;
    $fp = @fopen($path, 'rb');
    if (!$fp) return false;
    $bytes = fread($fp, 512);
    fclose($fp);
    if ($bytes === false || strlen($bytes) === 0) return true; 
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $bytes)) {
        return false; 
    }
    return true; 
}

$image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];

if (isset($_POST['action']) && $_POST['action'] === 'view_file' && !empty($_POST['file'])) {
    ob_clean();
    $filename = basename($_POST['file']);
    $target_file = $storage_dir . $filename;
    if (file_exists($target_file)) {
        $ext = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        
        if ($ext === 'pdf') {
            echo "PDF:" . $target_file;
        } elseif (in_array($ext, $image_extensions)) {
            echo "IMG:" . $target_file;
        } elseif (is_text_file($target_file)) {
            echo "TXT:" . htmlspecialchars(file_get_contents($target_file), ENT_QUOTES, 'UTF-8');
        } else {
            echo "ERR:" . lang('@This file type cannot be displayed in the browser.');
        }
    } else {
        echo "ERR:" . lang('@File not found.');
    }
    exit;
}

if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $filename = basename($_GET['delete']);
    $target_file = $storage_dir . $filename;
    if (file_exists($target_file)) {
        if (unlink($target_file)) {
            $msg_html = htm_Alert(text: lang('@File deleted successfully!'), type: 'success');
        } else {
            $msg_html = htm_Alert(text: lang('@Error: Could not delete file.'), type: 'danger');
        }
    } else {
        $msg_html = htm_Alert(text: lang('@Error: File not found.'), type: 'danger');
    }
}

if (isset($_GET['download']) && !empty($_GET['download'])) {
    $filename = basename($_GET['download']);
    $target_file = $storage_dir . $filename;
    if (file_exists($target_file)) {
        ob_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($target_file));
        readfile($target_file);
        exit;
    }
}

htm_Header('@Storage Browser');
showMenu();
echo $msg_html;

htm_Card_(capt: '@System Storage Browser', wdth: ''); 
?>
<style>
/* SPLIT PANEL LAYOUT VIA CSS GRID */
.browser-split-container {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 15px;
    align-items: start;
    width: 100%;
}

/* VENSTRE PANEL (MAPPETRÆ) - nu tema-bevidst i stedet for hårdkodede
   lys-tema-farver, som gjorde panelet til en lys boks i dark-tema. */
.tree-panel {
    background: var(--bg-panel);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 12px;
    box-sizing: border-box;
}
.tree-root {
    font-weight: bold;
    color: var(--text-main);
    margin-bottom: 12px;
    font-size: 14px;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 6px;
}
.tree-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.tree-item {
    margin: 4px 0;
}
.tree-link {
    text-decoration: none;
    color: var(--text-muted);
    font-size: 13px;
    padding: 6px 10px;
    border-radius: 4px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    transition: all 0.15s ease;
}
.tree-link:hover {
    background: var(--bg-table-hover);
    color: var(--text-main);
}
.tree-link.active {
    background: var(--color-primary);
    color: var(--text-light);
    font-weight: bold;
}
.tree-badge {
    font-size: 11px;
    opacity: 0.75;
    font-weight: normal;
}

/* HØJRE PANEL (FILPANEL) */
.files-panel {
    min-width: 0; 
}
.files-panel .tbl, 
.files-panel .tbl th {
    font-size: 11px !important;
}
.files-panel .tbl td {
    font-size: 11px !important;
    padding-top: 3px !important;
    padding-bottom: 3px !important;
}

/* Skjul sorteringspile på den sidste kolonne via system-standard */
.tbl th:last-child .sort-icon, .tbl th:last-child i, .tbl th:last-child .fa { display: none !important; }
.tbl th:last-child a::after { display: none !important; }

/* MODAL & VIEWER STYLES */
.tc-modal { display: none; position: fixed; z-index: 10500; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); font-family: sans-serif; }
.tc-modal-content { 
    background-color: var(--bg-card); margin: 2% auto; padding: 20px; border-radius: 8px; width: 85%; max-width: 1100px; 
    box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: flex; flex-direction: column; max-height: 95vh; 
}
.tc-modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--color-primary); padding-bottom: 10px; margin-bottom: 15px; }
/* .code-container er BEVIDST altid mørk (kode-fremviser-æstetik), uanset
   sidens tema - rørt ikke ved, det er ikke en tema-fejl men et design-valg. */
.code-container { 
    display: flex; flex-direction: column; background: #2c3e50; border-radius: 4px; overflow: auto; max-height: 85vh; 
    font-family: monospace; font-size: 12px; line-height: 1.5; position: relative; padding: 10px 0;
}
.code-row { display: flex; align-items: stretch; width: 100%; }
.line-number { 
    padding: 0 10px 0 15px; background: #23313f; color: #7f8c8d; text-align: right; user-select: none; -webkit-user-select: none;
    border-right: 1px solid #34495e; min-width: 45px; box-sizing: border-box; flex-shrink: 0;
}
.code-text { flex: 1; padding: 0 15px; color: #ecf0f1; margin: 0; box-sizing: border-box; user-select: text; -webkit-user-select: text; }
.code-container.no-wrap .code-text { white-space: pre; word-break: normal; }
.code-container.wrap .code-text { white-space: pre-wrap; word-break: break-all; }
.image-container { display: none; justify-content: center; align-items: center; background: var(--bg-panel); border-radius: 4px; padding: 20px; max-height: 85vh; overflow: auto; }
.image-container img { max-width: 100%; max-height: 80vh; object-fit: contain; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.15); background: white; }
.tc-modal-close { font-size: 24px; font-weight: bold; color: var(--text-muted); cursor: pointer; border: none; background: none; }
.tc-modal-close:hover { color: var(--color-danger); }
.wrap-container { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-main); font-weight: 600; background: var(--bg-panel); padding: 4px 10px; border-radius: 4px; border: 1px solid var(--border-color); cursor: pointer; }
</style>

<div class="browser-split-container">
    
    <div class="tree-panel">
        <div class="tree-root">📁 storage/</div>
        <ul class="tree-list">
            <?php
            foreach ($allowed_folders as $key => $path) {
                $is_active = ($current_key === $key);
                $active_class = $is_active ? 'active' : '';
                $icon = $is_active ? '📂' : '📁';
                
                $folder_stats = get_folder_stats($path);
                $display_name = ucfirst($key);
                
                echo "<li class='tree-item'>";
                echo "<a href='storage_browser.php?folder={$key}' class='tree-link {$active_class}'>";
                echo "<span>{$icon} {$display_name}</span>";
                echo "<span class='tree-badge'>{$folder_stats['count']} " . lang('@files') . " • {$folder_stats['formatted_size']}</span>";
                echo "</a>";
                echo "</li>";
            }
            ?>
        </ul>
    </div>

    <div class="files-panel">
        <?php
        $files = is_dir($storage_dir) ? array_diff(scandir($storage_dir), ['.', '..', '.htaccess']) : [];
        $table_data = [];

        foreach ($files as $file) {
            $path = $storage_dir . $file;
            if (is_dir($path)) continue; 
            
            $size = lang('@Unknown');
            if (file_exists($path)) {
                $size_bytes = @filesize($path);
                if ($size_bytes !== false) {
                    $size = ($size_bytes >= 1048576) ? number_format($size_bytes / 1048576, 2, ',', '.') . ' MB' : number_format($size_bytes / 1024, 2, ',', '.') . ' KB';
                }
            }
            
            $date = lang('@Unknown');
            if (file_exists($path)) {
                $mtime = @filemtime($path);
                if ($mtime !== false) {
                    $date = date(CONF_DATE_FORMAT.' H:i', $mtime);
                }
            }
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $can_view = (is_text_file($path) || in_array($ext, $image_extensions) || $ext === 'pdf');
            
            // Byg handlings-array via htm_ActionButtons. 'label' bevarer den
            // synlige tekst ved siden af ikonet, ligesom originalens
            // .action-btn-knapper havde (kræver den udvidede htm_ActionButtons
            // med label-understøttelse - se htm_ActionButtons_v2.php).
            $file_actions = [];
            if ($can_view) {
                $file_actions[] = ['icon' => 'fa-eye', 'label' => '@View', 'onclick' => "openFileViewer('" . htmlspecialchars($file, ENT_QUOTES) . "')", 'type' => 'primary'];
            }
            $file_actions[] = ['icon' => 'fa-download', 'label' => '@Download', 'link' => 'storage_browser.php?folder=' . $current_key . '&download=' . urlencode($file), 'type' => 'success'];
            $file_actions[] = ['icon' => 'fa-trash', 'label' => '@Delete', 'link' => 'storage_browser.php?folder=' . $current_key . '&delete=' . urlencode($file), 'confirm' => '@Are you sure you want to delete this file?', 'type' => 'danger'];

            $actions = htm_ActionButtons($file_actions, false);
            
            # Sorteringstildelingen mapper nu korrekt mod nøglerne defineret i $headers
            $table_data[] = [
                'name'     => htmlspecialchars($file),
                'date'     => $date,
                'size'     => $size,
                '@Actions' => $actions
            ];
        }

        # Nøglerne her og i $column_styles skal matche hinanden 100% sprogligt
        $headers = [ 
            'name'     => lang('@File Name'), 
            'date'     => lang('@Modified Date'), 
            'size'     => lang('@Size'), 
            '@Actions' => lang('@Actions') 
        ];

        # REGEL: Nøglen rettet fra 'actions' til '@Actions' så htm_Table() synkroniserer både th og td.
        $column_styles = [
            'name'     => '',
            'date'     => '',
            'size'     => 'text-align: right !important; padding-right: 25px !important;',
            '@Actions' => 'text-align: right !important;'
        ];

        if (empty($table_data)) {
            echo '<div style="text-align:center; padding:40px; color:var(--text-muted); background:var(--bg-panel); border:1px solid var(--border-color); border-radius:6px; font-size:11px;"><i class="fa fa-folder-open" style="font-size:36px; margin-bottom:10px; display:block;"></i>' . lang('@No files found in this directory.') . '</div>';
        } else {
            htm_Table($headers, $table_data, 'tbl', 25, '', true, $column_styles);
        }
        ?>
    </div>
</div>

<?php htm_Card_end(); ?>

<div id="viewerModal" class="tc-modal">
    <div class="tc-modal-content">
        <div class="tc-modal-header">
            <h3 id="viewerTitle" style="margin:0; color:var(--text-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:50%; font-size:16px;"><?php echo lang('@File Content'); ?></h3>
            <div style="display:flex; align-items:center; gap:15px;">
                <label id="wrapToggleContainer" class="wrap-container"><input type="checkbox" id="wrapToggle" onchange="toggleWordWrap(this.checked)"><span><?php echo lang('@Ombryd linjer'); ?></span></label>
                <button type="button" class="tc-modal-close" onclick="closeFileViewer()">&times;</button>
            </div>
        </div>
        <div id="codeContainer" class="code-container no-wrap"></div>
        <div id="imageContainer" class="image-container"><img id="viewerImage" src="" alt="Billedvisning"></div>
        <div id="pdfContainer" style="display:none; width:100%;"><iframe id="viewerPdf" src="" style="width:100%; height:85vh; border:none; border-radius:4px;"></iframe></div>
    </div>
</div>

<script>
function openFileViewer(filename) {
    document.getElementById('viewerTitle').innerText = filename;
    document.getElementById('codeContainer').style.top = "0";
    document.getElementById('codeContainer').style.display = "none";
    document.getElementById('imageContainer').style.display = "none";
    document.getElementById('pdfContainer').style.display = "none";
    document.getElementById('wrapToggleContainer').style.display = "none";
    document.getElementById('codeContainer').innerHTML = "Indlæser...";
    document.getElementById('viewerImage').src = "";
    document.getElementById('viewerPdf').src = "";
    document.getElementById('viewerModal').style.display = "block";
    document.getElementById('wrapToggle').checked = false;
    toggleWordWrap(false);

    var formData = new FormData();
    formData.append('action', 'view_file');
    formData.append('file', filename);

    var currentFolder = new URLSearchParams(window.location.search).get('folder') || 'invoices';

    fetch('storage_browser.php?folder=' + currentFolder, { method: 'POST', body: formData })
    .then(response => response.text())
    .then(responseText => {
        if (responseText.startsWith("PDF:")) {
            document.getElementById('viewerPdf').src = responseText.substring(4);
            document.getElementById('pdfContainer').style.display = "flex";
        } else if (responseText.startsWith("IMG:")) {
            document.getElementById('viewerImage').src = responseText.substring(4) + "?t=" + new Date().getTime();
            document.getElementById('imageContainer').style.display = "flex";
        } else if (responseText.startsWith("TXT:")) {
            var rawText = responseText.substring(4);
            var container = document.getElementById('codeContainer');
            container.innerHTML = ""; 
            
            var parser = new DOMParser();
            var decodedDocument = parser.parseFromString(rawText, 'text/html');
            var decodedText = decodedDocument.body.textContent || "";

            var lines = decodedText.split(/\r?\n/);
            if (lines.length > 1 && lines[lines.length - 1] === "") { lines.pop(); }

            lines.forEach((lineText, index) => {
                var row = document.createElement('div');
                row.className = 'code-row';
                
                var numDiv = document.createElement('div');
                numDiv.className = 'line-number';
                numDiv.innerText = (index + 1);
                
                var textPre = document.createElement('pre');
                textPre.className = 'code-text';
                textPre.innerText = lineText === "" ? " " : lineText; 
                
                row.appendChild(numDiv);
                row.appendChild(textPre);
                container.appendChild(row);
            });

            container.style.display = "flex";
            document.getElementById('wrapToggleContainer').style.display = "flex";
        } else {
            document.getElementById('codeContainer').innerHTML = '<div style="padding:15px; color:#e74c3c;">' + (responseText.startsWith("ERR:") ? responseText.substring(4) : responseText) + '</div>';
            document.getElementById('codeContainer').style.display = "flex";
        }
    })
    .catch(error => {
        document.getElementById('codeContainer').innerHTML = '<div style="padding:15px; color:#e74c3c;"><?php echo lang('@Error loading file.'); ?></div>';
        document.getElementById('codeContainer').style.display = "flex";
    });
}

function closeFileViewer() {
    document.getElementById('viewerModal').style.display = "none";
    document.getElementById('viewerImage').src = "";
    document.getElementById('viewerPdf').src = "";
}

function toggleWordWrap(isTrue) {
    var container = document.getElementById('codeContainer');
    if (isTrue) {
        container.classList.remove('no-wrap');
        container.classList.add('wrap');
    } else {
        container.classList.remove('wrap');
        container.classList.add('no-wrap');
    }
}

window.addEventListener('click', function(event) {
    if (event.target == document.getElementById('viewerModal')) {
        closeFileViewer();
    }
});
</script>
<?php
htm_Footer();
ob_end_flush();
?>
