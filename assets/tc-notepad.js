/* /assets/tc-notepad.js v:1.3.1 d:2026-09-15 i:evs */
/* Notesblokken: formatering, kildevisning, gem og træk. Kræver notepadWorkerUrl, notepadCsrfToken og notepadTxt (inline i notepad.inc.php). */

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

// Skift mellem normal (WYSIWYG) visning og redigerbar HTML-kilde.
var notepadSourceMode = false;
function getNotepadContent() {
    return notepadSourceMode
        ? document.getElementById('globalNotesSource').value
        : document.getElementById('globalNotesArea').innerHTML;
}
function toggleNotepadSource() {
    var area = document.getElementById('globalNotesArea');
    var src  = document.getElementById('globalNotesSource');
    var btn  = document.getElementById('notepadCodeBtn');
    if (!notepadSourceMode) {
        // Til kilde-visning: vis den rå HTML som redigerbar tekst.
        src.value = area.innerHTML;
        area.style.display = 'none';
        src.style.display  = 'block';
        btn.style.background = '#2c3e50';
        btn.style.color = '#fff';
        notepadSourceMode = true;
        src.focus();
    } else {
        // Tilbage til normal visning: fortolk den redigerede HTML og gem.
        area.innerHTML = src.value;
        src.style.display  = 'none';
        area.style.display = 'block';
        btn.style.background = 'white';
        btn.style.color = '';
        notepadSourceMode = false;
        saveGlobalNotes();
    }
}

var saveTimeout;
function saveGlobalNotes() {
    var htmlContent = getNotepadContent();
    var status = document.getElementById('notesStatus');
    status.innerText = notepadTxt.saving;
    
    clearTimeout(saveTimeout);
    saveTimeout = setTimeout(function() {
        fetch(notepadWorkerUrl + '?notepad_action=save&csrf_token=' + encodeURIComponent(notepadCsrfToken), {
            method: 'POST',
            body: htmlContent,
            headers: { 'Content-Type': 'text/html' }
        })
        .then(response => response.text())
        .then(res => {
            if (res === 'saved') {
                status.innerText = notepadTxt.saved;
            } else if (res === 'error_session') {
                status.innerText = notepadTxt.expired;
            } else {
                status.innerText = notepadTxt.error;
            }
        })
        .catch(() => { status.innerText = notepadTxt.error; });
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
