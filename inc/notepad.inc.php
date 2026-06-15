<?php # /inc/notepad.inc.php v:1.2.0 d:2026-05-23 i:evs ok
// =========================================================================
// 1. WORKER-LOGIK (Kører kun når JS anmoder om at 'get' eller 'save')
// =========================================================================
if (isset($_GET['notepad_action'])) {
    // Sikkerhedstjek: Kræver aktiv session (auth.inc.php er indlæst af modersiden)
    if (!isset($_SESSION['user_role'])) {
        die('Adgang nægtet');
    }

    $file_path = __DIR__ . '/../json-data/global_notepad.html';
    $action = $_GET['notepad_action'];

    // HENT DATA FRA FIL
    if ($action === 'get') {
        if (file_exists($file_path)) {
            echo file_get_contents($file_path);
        } else {
            echo "<p style='color:#7f8c8d;'>Skriv dine formaterede noter her...</p>";
        }
        exit; // Stop, så vi ikke spytter HTML-brugerfladen ud i AJAX-svaret
    }

    // GEM DATA I FIL
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $notes_content = file_get_contents('php://input');
        if (file_put_contents($file_path, $notes_content) !== false) {
            echo "saved";
        } else {
            echo "error";
        }
        exit; // Stop her
    }
}

// =========================================================================
// 2. VISUEL BRUGERFLADE (HTML, CSS & JS) - Inkluderes i din footer
// =========================================================================
?>

<button type="button" onclick="toggleGlobalNotes()" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; background: #2c3e50; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 20px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;" title="Åbn Notesblok (Alt + N)">
    📝
</button>

<div id="globalNotesModal" style="display: none; position: fixed; z-index: 10000; right: 20px; bottom: 80px; width: 400px; height: 500px; min-width: 320px; min-height: 300px; background: white; border-radius: 8px; box-shadow: 0 5px 25px rgba(0,0,0,0.25); border: 1px solid #ddd; font-family: sans-serif; flex-direction: column; overflow: hidden; resize: both;">
    
    <div id="globalNotesHeader" style="background: #2c3e50; color: white; padding: 10px 15px; border-top-left-radius: 8px; border-top-right-radius: 8px; display: flex; justify-content: space-between; align-items: center; cursor: move; user-select: none;">
        <strong style="font-size: 14px;">📝 Formaterbar Notesblok</strong>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span id="notesStatus" style="font-size: 11px; opacity: 0.8;">Klar</span>
            <button onclick="toggleGlobalNotes()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
    </div>
    
    <div style="background: #f1f2f6; padding: 5px; border-bottom: 1px solid #ddd; display: flex; gap: 4px; flex-wrap: wrap; user-select: none;">
        <button type="button" onclick="formatDoc('bold')" style="padding: 3px 8px; font-weight: bold; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="Fed">B</button>
        <button type="button" onclick="formatDoc('italic')" style="padding: 3px 8px; font-style: italic; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="Kursiv">I</button>
        <button type="button" onclick="formatDoc('underline')" style="padding: 3px 8px; text-decoration: underline; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="Understreget">U</button>
        
        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>
        
        <select onchange="formatDoc('fontSize', this.value); this.selectedIndex=0;" style="padding: 2px; font-size: 11px; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">
            <option value="" selected disabled>Størrelse</option>
            <option value="2">Lille</option>
            <option value="3">Normal</option>
            <option value="4">Mellem</option>
            <option value="5">Stor</option>
        </select>

        <select onchange="formatDoc('foreColor', this.value); this.selectedIndex=0;" style="padding: 2px; font-size: 11px; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">
            <option value="" selected disabled>Farve</option>
            <option value="#000000">Sort</option>
            <option value="#c53030">Rød</option>
            <option value="#2f855a">Grøn</option>
            <option value="#2b6cb0">Blå</option>
            <option value="#d69e2e">Gul</option>
        </select>
        
        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>

        <button type="button" onclick="formatDoc('insertUnorderedList')" style="padding: 3px 6px; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="Punktopstilling">• Liste</button>
        <button type="button" onclick="formatDoc('removeFormat')" style="padding: 3px 6px; background: #ffeaa7; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="Rens formatering">Rens</button>
    </div>
    
    <div style="flex: 1; padding: 12px; display: flex; flex-direction: column; background: #fff; height: calc(100% - 110px); overflow-y: auto;">
        <div id="globalNotesArea" contenteditable="true" oninput="saveGlobalNotes()" style="width: 100%; height: 100%; min-height: 100%; font-size: 14px; line-height: 1.6; outline: none; box-sizing: border-box; color: #2c3e50; font-family: sans-serif;"></div>
    </div>
    
    <div style="background: #f4f6f7; padding: 6px 12px; font-size: 11px; color: #7f8c8d; text-align: right; border-top: 1px solid #edf2f7; user-select: none;">
        Alt-i-én modul | Gemmes i HTML-fil
    </div>
</div>

<script>
// Find stien til denne fil dynamisk (kalder sig selv med URL-parametre)
const notepadWorkerUrl = 'inc/notepad.inc.php';

function formatDoc(cmd, value = null) {
    document.execCommand(cmd, false, value);
    document.getElementById('globalNotesArea').focus();
    saveGlobalNotes();
}

function toggleGlobalNotes() {
    var modal = document.getElementById('globalNotesModal');
    var status = document.getElementById('notesStatus');
    
    if (modal.style.display === "none" || modal.style.display === "") {
        modal.style.display = "flex";
        status.innerText = "Henter...";
        
        fetch(notepadWorkerUrl + '?notepad_action=get')
            .then(response => response.text())
            .then(data => {
                document.getElementById('globalNotesArea').innerHTML = data;
                status.innerText = "Klar";
            })
            .catch(() => { status.innerText = "Fejl"; });
    } else {
        modal.style.display = "none";
    }
}

var saveTimeout;
function saveGlobalNotes() {
    var htmlContent = document.getElementById('globalNotesArea').innerHTML;
    var status = document.getElementById('notesStatus');
    status.innerText = "Gemmer...";
    
    clearTimeout(saveTimeout);
    saveTimeout = setTimeout(function() {
        fetch(notepadWorkerUrl + '?notepad_action=save', {
            method: 'POST',
            body: htmlContent,
            headers: { 'Content-Type': 'text/html' }
        })
        .then(response => response.text())
        .then(res => {
            if(res === 'saved') status.innerText = "Gemt";
        })
        .catch(() => { status.innerText = "Fejl"; });
    }, 600);
}

window.addEventListener('keydown', function(e) {
    if (e.altKey && e.key.toLowerCase() === 'n') {
        e.preventDefault();
        toggleGlobalNotes();
    }
});

// Flytbar funktion (Drag-and-drop)
(function() {
    var modal = document.getElementById("globalNotesModal");
    var header = document.getElementById("globalNotesHeader");
    var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
    header.onmousedown = dragMouseDown;
    function dragMouseDown(e) {
        e = e || window.event;
        if (e.target.tagName.toLowerCase() === 'button' || e.target.tagName.toLowerCase() === 'select') return;
        e.preventDefault();
        pos3 = e.clientX; pos4 = e.clientY;
        document.onmouseup = closeDragElement;
        document.onmousemove = elementDrag;
    }
    function elementDrag(e) {
        e = e || window.event;
        e.preventDefault();
        pos1 = pos3 - e.clientX; pos2 = pos4 - e.clientY;
        pos3 = e.clientX; pos4 = e.clientY;
        modal.style.bottom = "auto"; modal.style.right = "auto";
        modal.style.top = (modal.offsetTop - pos2) + "px";
        modal.style.left = (modal.offsetLeft - pos1) + "px";
    }
    function closeDragElement() { document.onmouseup = null; document.onmousemove = null; }
})();
</script>