<?php # /notepad.inc.php v:1.0.0 d:2026-06-15 i:evs
// =========================================================================
// 1. WORKER-LOGIK (Kører kun når JS anmoder om at 'get' eller 'save')
// =========================================================================
if (isset($_GET['notepad_action'])) {
    if (session_status() === PHP_SESSION_NONE) { 
        session_start(); 
    }
    
    // SIKKERHEDSTJEK: Matchet 100% med TinyCash login.php sessioner
    if (!isset($_SESSION['user_id'])) {
        header('HTTP/1.0 403 Forbidden');
        die('Adgang naegtet - Ingen aktiv session');
    }

    $dir_path = __DIR__ . '/storage';
    $file_path = $dir_path . '/global_notepad.html';
    $action = $_GET['notepad_action'];

    // HENT DATA FRA FIL
    if ($action === 'get') {
        if (file_exists($file_path)) {
            echo file_get_contents($file_path);
        } else {
            echo "<p style='color:#7f8c8d;'>" . lang('@Write your formatted notes here...') . "</p>";
        }
        exit;
    }

    // GEM DATA I FIL
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $notes_content = file_get_contents('php://input');
        
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0755, true);
        }
        
        if (file_put_contents($file_path, $notes_content) !== false) {
            echo "saved";
        } else {
            header('HTTP/1.0 500 Internal Server Error');
            echo "Kunne ikke skrive til fil.";
        }
        exit;
    }
}

// =========================================================================
// 2. VISUEL BRUGERFLADE (HTML, CSS & JS) - Inkluderes i din footer
// =========================================================================
?>
<button type="button" onclick="toggleGlobalNotes()" 
       style="position: fixed; bottom: -5px; right: 0; z-index: 99999; background: #888888; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 20px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;" 
       data-hint="<?php echo lang('@Open system Notepad (Alt + N)'); ?>">
    📝
</button>

<div id="globalNotesModal" style="display: none; position: fixed; z-index: 100000; width: 400px; height: 500px; min-width: 320px; min-height: 300px; background: white; border-radius: 8px; box-shadow: 0 5px 25px rgba(0,0,0,0.25); border: 1px solid #ddd; font-family: sans-serif; flex-direction: column; overflow: hidden; resize: both; box-sizing: border-box;">
    
    <div id="globalNotesHeader" style="background: #2c3e50; color: white; padding: 10px 15px; border-top-left-radius: 8px; border-top-right-radius: 8px; display: flex; justify-content: space-between; align-items: center; cursor: move; user-select: none; box-sizing: border-box;">
        <strong style="font-size: 14px;">📝 <?php echo lang('@Notepad'); ?></strong>
        <div style="display: flex; gap: 12px; align-items: center;">
            <span id="notesStatus" style="font-size: 11px; opacity: 0.8;"><?php echo lang('@Ready'); ?></span>
            <button id="notepadMaximizeBtn" onclick="toggleMaximizeNotepad()" style="background: none; border: none; color: white; font-size: 14px; cursor: pointer; padding: 0; line-height: 1; display: flex; align-items: center;" title="<?php echo lang('@Fullscreen / Normal size'); ?>">🔳</button>
            <button onclick="toggleGlobalNotes()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
    </div>
    
    <div class="editor-toolbar" style="background: #f1f2f6; padding: 5px; border-bottom: 1px solid #ddd; display: flex; gap: 4px; flex-wrap: wrap; user-select: none; box-sizing: border-box;">
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
        <button type="button" onclick="stripHtmlFromSelection()" style="padding: 3px 6px; background: #ffeaa7; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Removes formatting and cleans selection from codes'); ?>"><?php echo lang('@Clean'); ?></button>

        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>

        <button type="button" onclick="insertCustomCode()" style="padding: 3px 6px; background: #e8f4fd; border: 1px solid #b4d5fe; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Insert raw HTML or icons'); ?>"><i class="fa-solid fa-code"></i> + <?php echo lang('@Code'); ?></button>
        <button type="button" id="notepadSourceBtn" onclick="toggleSourceView()" style="padding: 3px 6px; background: #f1f2f6; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Toggle between source code and visual rendering'); ?>"><i class="fa-solid fa-terminal"></i> <?php echo lang('@Source Code'); ?></button>

        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>

        <button type="button" onclick="copyAllNotes()" style="padding: 3px 6px; background: #e3ecef; border: 1px solid #b2c2c7; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@Copy all plain text to clipboard'); ?>">📋 <?php echo lang('@Copy All'); ?></button>
    </div>
    
    <div style="flex: 1; padding: 12px; display: flex; flex-direction: column; background: #fff; height: calc(100% - 110px); overflow-y: auto; box-sizing: border-box;">
        <style>
            #globalNotesArea p:first-child { margin-top: 0 !important; padding-top: 0 !important; }
            #globalNotesArea p { margin-top: 4px; margin-bottom: 4px; }
        </style>
        <div id="globalNotesArea" contenteditable="true" oninput="saveGlobalNotes()" style="width: 100%; height: 100%; min-height: 100%; font-size: 14px; line-height: 1.6; outline: none; box-sizing: border-box; color: #2c3e50; font-family: sans-serif;"></div>
    </div>
    
    <div style="background: #f4f6f7; padding: 6px 12px; font-size: 11px; color: #7f8c8d; text-align: right; border-top: 1px solid #edf2f7; user-select: none; box-sizing: border-box;">
        <?php echo lang('@Memory & Focus active | Saved in /storage/'); ?>
    </div>
</div>

<script>
const notepadWorkerUrl = 'notepad.inc.php';
let isNotepadMaximized = false;
let isSourceView = false;

// JS-oversættelsesstrenge indsprøjtet fra PHP lang()
const langStrings = {
    fetching: "<?php echo lang('@Fetching...'); ?>",
    ready: "<?php echo lang('@Ready'); ?>",
    saving: "<?php echo lang('@Saving...'); ?>",
    saved: "<?php echo lang('@Saved'); ?>",
    error: "<?php echo lang('@Error'); ?>",
    errorSave: "<?php echo lang('@Error saving'); ?>",
    copied: "<?php echo lang('@Copied!'); ?>",
    errorCopy: "<?php echo lang('@Error on copy'); ?>",
    promptCode: "<?php echo lang('@Enter formatting code (e.g. <i class=\"fa fa-star\"></i> or <b>Text</b>):'); ?>",
    visual: "<?php echo lang('@Visual'); ?>",
    sourceCode: "<?php echo lang('@Source Code'); ?>"
};

function saveNotepadGeometry() {
    if (isNotepadMaximized) return; 
    const modal = document.getElementById('globalNotesModal');
    const geometry = {
        top: modal.style.top,
        left: modal.style.left,
        width: modal.style.width,
        height: modal.style.height
    };
    localStorage.setItem('tinycash_notepad_geom', JSON.stringify(geometry));
}

function formatDoc(cmd, value = null) {
    if (isSourceView) return; 
    document.execCommand(cmd, false, value);
    document.getElementById('globalNotesArea').focus();
    saveGlobalNotes();
}

function insertCustomCode() {
    if (isSourceView) return;
    const code = prompt(langStrings.promptCode);
    if (code && code.trim() !== "") {
        const editor = document.getElementById('globalNotesArea');
        editor.focus();
        
        const sel = window.getSelection();
        if (sel.getRangeAt && sel.rangeCount) {
            let range = sel.getRangeAt(0);
            range.deleteContents();
            
            const el = document.createElement("div");
            el.innerHTML = code;
            
            const frag = document.createDocumentFragment();
            let node, lastNode;
            while ((node = el.firstChild)) {
                lastNode = frag.appendChild(node);
            }
            range.insertNode(frag);
            
            if (lastNode) {
                range = range.cloneRange();
                range.setStartAfter(lastNode);
                range.collapse(true);
                sel.removeAllRanges();
                sel.addRange(range);
            }
        }
        saveGlobalNotes();
    }
}

function toggleSourceView() {
    const editor = document.getElementById('globalNotesArea');
    const btn = document.getElementById('notepadSourceBtn');
    const toolbarButtons = document.querySelectorAll('.editor-toolbar button, .editor-toolbar select');
    
    if (!isSourceView) {
        const html = editor.innerHTML;
        editor.innerText = html;
        editor.style.fontFamily = "monospace";
        editor.style.whiteSpace = "pre-wrap";
        editor.style.background = "#2d3436";
        editor.style.color = "#dfe6e9";
        
        btn.style.background = "#ff7675";
        btn.style.color = "white";
        btn.innerHTML = "<i class='fa-solid fa-eye'></i> " + langStrings.visual;
        
        toolbarButtons.forEach(b => { if(b !== btn) b.style.opacity = "0.4"; });
        isSourceView = true;
    } else {
        const rawText = editor.innerText;
        editor.innerHTML = rawText;
        editor.style.fontFamily = "sans-serif";
        editor.style.whiteSpace = "normal";
        editor.style.background = "#fff";
        editor.style.color = "#2c3e50";
        
        btn.style.background = "#f1f2f6";
        btn.style.color = "initial";
        btn.innerHTML = "<i class='fa-solid fa-code'></i> " + langStrings.sourceCode;
        
        toolbarButtons.forEach(b => b.style.opacity = "1");
        isSourceView = false;
    }
    editor.focus();
}

function stripHtmlFromSelection() {
    if (isSourceView) return;
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    
    const range = sel.getRangeAt(0);
    const plainText = range.toString();
    
    if (plainText !== "") {
        range.deleteContents();
        const textNode = document.createTextNode(plainText);
        range.insertNode(textNode);
        
        range.setStartAfter(textNode);
        range.setEndAfter(textNode);
        sel.removeAllRanges();
        sel.addRange(range);
    } else {
        document.execCommand('removeFormat', false, null);
    }
    saveGlobalNotes();
}

function copyAllNotes() {
    const editor = document.getElementById('globalNotesArea');
    const status = document.getElementById('notesStatus');
    const plainText = editor.innerText || editor.textContent;
    
    navigator.clipboard.writeText(plainText).then(() => {
        const oldStatus = status.innerText;
        status.innerText = langStrings.copied;
        setTimeout(() => { status.innerText = oldStatus; }, 1500);
    }).catch(() => {
        status.innerText = langStrings.errorCopy;
    });
}

function toggleGlobalNotes() {
    var modal = document.getElementById('globalNotesModal');
    var status = document.getElementById('notesStatus');
    var editor = document.getElementById('globalNotesArea');
    
    if (modal.style.display === "none" || modal.style.display === "") {
        modal.style.display = "flex";
        status.innerText = langStrings.fetching;
        
        const savedGeom = localStorage.getItem('tinycash_notepad_geom');
        if (savedGeom) {
            const geom = JSON.parse(savedGeom);
            modal.style.top = geom.top;
            modal.style.left = geom.left;
            modal.style.width = geom.width;
            modal.style.height = geom.height;
        } else {
            modal.style.top = (window.innerHeight - 590) + "px"; 
            modal.style.left = (window.innerWidth - 430) + "px";
            modal.style.width = "400px";
            modal.style.height = "500px";
        }
        
        fetch(notepadWorkerUrl + '?notepad_action=get')
            .then(response => response.text())
            .then(data => {
                if (isSourceView) toggleSourceView();
                editor.innerHTML = data;
                status.innerText = langStrings.ready;
                focusAtEnd(editor);
            })
            .catch(() => { status.innerText = langStrings.error; });
    } else {
        modal.style.display = "none";
    }
}

function focusAtEnd(el) {
    el.focus();
    if (typeof window.getSelection != "undefined" && typeof document.createRange != "undefined") {
        var range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

function toggleMaximizeNotepad() {
    const modal = document.getElementById('globalNotesModal');
    const btn = document.getElementById('notepadMaximizeBtn');
    
    if (!isNotepadMaximized) {
        saveNotepadGeometry();
        modal.style.top = "15px";
        modal.style.left = "15px";
        modal.style.width = "calc(100vw - 30px)";
        modal.style.height = "calc(100vh - 30px)";
        modal.style.resize = "none"; 
        
        btn.innerText = "🔲";
        isNotepadMaximized = true;
    } else {
        modal.style.resize = "both";
        isNotepadMaximized = false;
        
        const savedGeom = localStorage.getItem('tinycash_notepad_geom');
        if (savedGeom) {
            const geom = JSON.parse(savedGeom);
            modal.style.top = geom.top;
            modal.style.left = geom.left;
            modal.style.width = geom.width;
            modal.style.height = geom.height;
        } else {
            modal.style.width = "400px";
            modal.style.height = "500px";
        }
        
        btn.innerText = "🔳";
    }
    document.getElementById('globalNotesArea').focus();
}

document.getElementById('globalNotesModal').addEventListener('mouseup', function() {
    saveNotepadGeometry();
});

document.getElementById('globalNotesArea').addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        var sel = window.getSelection();
        var range = sel.getRangeAt(0);
        var tabNode = document.createTextNode("\u00a0\u00a0\u00a0\u00a0");
        range.insertNode(tabNode);
        range.setStartAfter(tabNode);
        range.setEndAfter(tabNode);
        sel.removeAllRanges();
        sel.addRange(range);
        saveGlobalNotes();
    }
});

var saveTimeout;
function saveGlobalNotes() {
    const editor = document.getElementById('globalNotesArea');
    const status = document.getElementById('notesStatus');
    
    const htmlContent = isSourceView ? editor.innerText : editor.innerHTML;
    status.innerText = langStrings.saving;
    
    clearTimeout(saveTimeout);
    saveTimeout = setTimeout(function() {
        fetch(notepadWorkerUrl + '?notepad_action=save', {
            method: 'POST',
            body: htmlContent,
            headers: { 'Content-Type': 'text/html' }
        })
        .then(response => {
            if (!response.ok) { throw new Error('Serverfejl'); }
            return response.text();
        })
        .then(res => {
            if(res === 'saved') status.innerText = langStrings.saved;
        })
        .catch(() => { status.innerText = langStrings.errorSave; });
    }, 600);
}

window.addEventListener('keydown', function(e) {
    if (e.altKey && e.key.toLowerCase() === 'n') {
        e.preventDefault();
        toggleGlobalNotes();
    }
});

(function() {
    var modal = document.getElementById("globalNotesModal");
    var header = document.getElementById("globalNotesHeader");
    var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
    
    header.onmousedown = dragMouseDown;
    
    function dragMouseDown(e) {
        if (isNotepadMaximized) return; 
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
        
        var newTop = modal.offsetTop - pos2;
        var newLeft = modal.offsetLeft - pos1;
        
        if (newTop < 0) { newTop = 0; }
        if (newLeft < 0) { newLeft = 0; }
        var maxLeft = window.innerWidth - modal.offsetWidth;
        if (newLeft > maxLeft) { newLeft = maxLeft; }
        var maxTop = window.innerHeight - 40; 
        if (newTop > maxTop) { newTop = maxTop; }
        
        modal.style.top = newTop + "px";
        modal.style.left = newLeft + "px";
    }
    
    function closeDragElement() {
        document.onmouseup = null;
        document.onmousemove = null;
        saveNotepadGeometry(); 
    }
})();
</script>