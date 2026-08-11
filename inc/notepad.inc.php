<?php # /inc/notepad.inc.php v:1.2.0 d:2026-08-11 i:evs 
# (Rettet: manglede session_name() før session_start() i gem-grenen)
ob_start();

$file_path = __DIR__ . '/../storage/global_notepad.html';

// =========================================================================
// 1. WORKER-LOGIK (Kører KUN når der gemmes data via POST)
// =========================================================================
if (isset($_GET['notepad_action']) && $_GET['notepad_action'] === 'save') {
    // RETTET: manglede session_name('TCC_V100_SESSION') før session_start().
    // Denne gren kaldes direkte via fetch() til inc/notepad.inc.php uden om
    // auth.inc.php - det er derfor det FØRSTE sessionsberøring på denne
    // forespørgsel. Uden det rigtige navn startede PHP en helt ny, ukendt
    // session i stedet for at genoptage brugerens rigtige TCC_V100_SESSION
    // (samme fejlklasse som blev fundet i logout.php, set_lang.php og
    // htm_Header()). Betyder i praksis, at et evt. fremtidigt genaktiveret
    // sikkerhedstjek på $_SESSION['user_role'] herunder ALDRIG ville virke.
    if (session_status() === PHP_SESSION_NONE) { 
        session_name('TCC_V100_SESSION');
        session_start(); 
    }
    
    /* // Sikkerhedstjek ved lagring
    if (!isset($_SESSION['user_role'])) {
        die('error_session');
    } */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $notes_content = file_get_contents('php://input');
        if (file_put_contents($file_path, $notes_content) !== false) {
            echo "saved";
        } else {
            echo "error_write";
        }
        exit;
    }
}

// Inkluder biblioteket til visning af layout og sprog
require_once __DIR__ . '/php2htm.lib.php';

// Hent indholdet direkte i PHP med det samme (Ingen AJAX 'get' forespørgsel nødvendig)
$notepad_content = '';
if (file_exists($file_path)) {
    $notepad_content = file_get_contents($file_path);
} else {
    $notepad_content = "<p style='color:#7f8c8d;'>" . lang('@Write your formatted notes here...') . "</p>";
}
?>

<button type="button" class="no-print" onclick="toggleGlobalNotes()" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; background: #2c3e50; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 20px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;" title="<?php echo lang('@Open Notepad (Alt + N)'); ?>">
    📝
</button>

<div id="globalNotesModal" style="display: none; position: fixed; z-index: 100000; top: 150px; left: 150px; width: 400px; height: 500px; min-width: 320px; min-height: 300px; background: white; border-radius: 8px; box-shadow: 0 5px 25px rgba(0,0,0,0.25); border: 1px solid #ddd; font-family: sans-serif; flex-direction: column; overflow: hidden; resize: both; box-sizing: border-box;">
    
    <div id="globalNotesHeader" style="background: #2c3e50; color: white; padding: 10px 15px; border-top-left-radius: 8px; border-top-right-radius: 8px; display: flex; justify-content: space-between; align-items: center; cursor: move; user-select: none;">
        <strong style="font-size: 14px;">📝 <?php echo lang('@Formatted Notepad'); ?></strong>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span id="notesStatus" style="font-size: 11px; opacity: 0.8;"><?php echo lang('@Ready'); ?></span>
            <button onclick="toggleGlobalNotes()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
    </div>
    
    <div style="background: #f1f2f6; padding: 5px; border-bottom: 1px solid #ddd; display: flex; gap: 4px; flex-wrap: wrap; user-select: none;">
        <button type="button" onclick="formatDoc('bold')" style="padding: 3px 8px; font-weight: bold; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Bold'); ?>">B</button>
        <button type="button" onclick="formatDoc('italic')" style="padding: 3px 8px; font-style: italic; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Italic'); ?>">I</button>
        <button type="button" onclick="formatDoc('underline')" style="padding: 3px 8px; text-decoration: underline; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Underline'); ?>">U</button>
        
        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>
        
        <select onchange="formatDoc('fontSize', this.value); this.selectedIndex=0;" style="padding: 2px; font-size: 11px; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">
            <option value="" selected disabled><?php echo lang('@Size'); ?></option>
            <option value="2"><?php echo lang('@Small'); ?></option>
            <option value="3"><?php echo lang('@Normal'); ?></option>
            <option value="4"><?php echo lang('@Medium'); ?></option>
            <option value="5"><?php echo lang('@Large'); ?></option>
        </select>

        <select onchange="formatDoc('foreColor', this.value); this.selectedIndex=0;" style="padding: 2px; font-size: 11px; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">
            <option value="" selected disabled><?php echo lang('@Color'); ?></option>
            <option value="#000000"><?php echo lang('@Black'); ?></option>
            <option value="#c53030"><?php echo lang('@Red'); ?></option>
            <option value="#2f855a"><?php echo lang('@Green'); ?></option>
            <option value="#2b6cb0"><?php echo lang('@Blue'); ?></option>
            <option value="#d69e2e"><?php echo lang('@Yellow'); ?></option>
        </select>
        
        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>

        <button type="button" onclick="formatDoc('insertUnorderedList')" style="padding: 3px 6px; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Bullet List'); ?>">• <?php echo lang('@List'); ?></button>
        <button type="button" onclick="formatDoc('removeFormat')" style="padding: 3px 6px; background: #ffeaa7; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Clear Formatting'); ?>"><?php echo lang('@Clear'); ?></button>
    </div>
    
    <div style="flex: 1; padding: 12px; display: flex; flex-direction: column; background: #fff; height: calc(100% - 110px); overflow-y: auto;">
        <div id="globalNotesArea" contenteditable="true" oninput="saveGlobalNotes()" style="width: 100%; height: 100%; min-height: 100%; font-size: 14px; line-height: 1.6; outline: none; box-sizing: border-box; color: #2c3e50; font-family: sans-serif;"><?php echo $notepad_content; ?></div>
    </div>
    
    <div style="background: #f4f6f7; padding: 6px 12px; font-size: 11px; color: #7f8c8d; text-align: right; border-top: 1px solid #edf2f7; user-select: none;">
        <?php echo lang('@All-in-one module | Saved to HTML file'); ?>
    </div>
</div>

<script>
const notepadWorkerUrl = 'inc/notepad.inc.php';

function formatDoc(cmd, value = null) {
    document.execCommand(cmd, false, value);
    document.getElementById('globalNotesArea').focus();
    saveGlobalNotes();
}

function toggleGlobalNotes() {
    var modal = document.getElementById('globalNotesModal');
    if (modal.style.display === "none" || modal.style.display === "") {
        modal.style.display = "flex";
    } else {
        modal.style.display = "none";
    }
}

var saveTimeout;
function saveGlobalNotes() {
    var htmlContent = document.getElementById('globalNotesArea').innerHTML;
    var status = document.getElementById('notesStatus');
    status.innerText = "<?php echo lang('@Saving...'); ?>";
    
    clearTimeout(saveTimeout);
    saveTimeout = setTimeout(function() {
        fetch(notepadWorkerUrl + '?notepad_action=save', {
            method: 'POST',
            body: htmlContent,
            headers: { 'Content-Type': 'text/html' }
        })
        .then(response => response.text())
        .then(res => {
            if (res === 'saved') {
                status.innerText = "<?php echo lang('@Saved'); ?>";
            } else if (res === 'error_session') {
                status.innerText = "<?php echo lang('@Session Expired'); ?>";
            } else {
                status.innerText = "<?php echo lang('@Error'); ?>";
            }
        })
        .catch(() => { status.innerText = "<?php echo lang('@Error'); ?>"; });
    }, 600);
}

window.addEventListener('keydown', function(e) {
    if (e.altKey && e.key.toLowerCase() === 'n') {
        e.preventDefault();
        toggleGlobalNotes();
    }
});

// TRÆK-OG-SLIP LOGIK (Frigjort scope)
var notepadHeader = document.getElementById("globalNotesHeader");
var notepadModal = document.getElementById("globalNotesModal");
var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;

if (notepadHeader) {
    notepadHeader.onmousedown = dragMouseDown;
}

function dragMouseDown(e) {
    // Nu kan koden se den globale isNotepadMaximized variabel uden fejl
    if (typeof isNotepadMaximized !== 'undefined' && isNotepadMaximized) return; 
    
    e = e || window.event;
    if (e.target.tagName.toLowerCase() === 'button' || e.target.tagName.toLowerCase() === 'select') return;
    e.preventDefault();
    
    pos3 = e.clientX;
    pos4 = e.clientY;
    document.onmouseup = closeDragElement;
    document.onmousemove = elementDrag;
}

function elementDrag(e) {
    e = e || window.event;
    e.preventDefault();
    
    pos1 = pos3 - e.clientX;
    pos2 = pos4 - e.clientY;
    pos3 = e.clientX;
    pos4 = e.clientY;
    
    var newTop = notepadModal.offsetTop - pos2;
    var newLeft = notepadModal.offsetLeft - pos1;
    
    if (newTop < 0) { newTop = 0; }
    if (newLeft < 0) { newLeft = 0; }
    var maxLeft = window.innerWidth - notepadModal.offsetWidth;
    if (newLeft > maxLeft) { newLeft = maxLeft; }
    var maxTop = window.innerHeight - 40; 
    if (newTop > maxTop) { newTop = maxTop; }
    
    notepadModal.style.top = newTop + "px";
    notepadModal.style.left = newLeft + "px";
}

function closeDragElement() {
    document.onmouseup = null;
    document.onmousemove = null;
    if (typeof saveNotepadGeometry === 'function') {
        saveNotepadGeometry(); 
    }
}
</script>
<?php
ob_end_flush();
?>
