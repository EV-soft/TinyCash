<?php # /inc/notepad.inc.php v:1.3.0 d:2026-08-30 i:evs
# Global, delt WYSIWYG-notesblok (indhold gemt fladt i
# inc/data/global_notepad.html, vist rå/uescapet i en contenteditable-div -
# med vilje, da det er et rigt HTML-format brugerne selv formaterer).
# Inkluderes fra htm_Footer() og vises derfor på HVER sidevisning i hele
# appen. Er selv hvidlistet i inc/.htaccess ("Require all granted"), da
# gem-knappen kalder den direkte via fetch() uden om det normale side-load
# (og dermed uden om auth.inc.php) - kræver derfor sin EGEN login-kontrol her
# i selve PHP-koden (se ?notepad_action=save nedenfor), som var udkommenteret
# indtil §bugs-batch-15-review fandt det.
# RETTET (bruger-anmodet konsolidering af installationsspecifikke data):
# flyttet fra storage/ til inc/data/ (hvor tinycash.sqlite/env.ini også nu
# ligger) - inc/data/.htaccess afviser allerede al direkte HTTP-adgang.
# Selv-helbredende: opretter inc/data/ ved første gem, hvis mappen mangler.
# </>-knappen skifter til rå HTML-kildevisning/-redigering.
ob_start();

$file_path = __DIR__ . '/data/global_notepad.html';

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
    
    // KRITISK (§bugs-batch-15-review): dette sikkerhedstjek var udkommenteret,
    // og notepad.inc.php er selv eksplicit hvidlistet i inc/.htaccess
    // ("Require all granted") for at kunne kaldes direkte via fetch() uden om
    // hele side-loadet (og dermed uden om auth.inc.php). Uden tjekket kunne
    // ENHVER på internettet - helt uden login - POSTe vilkårligt HTML/JS til
    // dette endpoint, som blev gemt UESCAPET og derefter vist rå (via
    // contenteditable-div'en nedenfor) på HVER ENESTE sidevisning for ALLE
    // brugere, fordi notepad.inc.php inkluderes fra htm_Footer() - dvs. reelt
    // en fuldt uautentificeret, lagret XSS mod enhver indlogget bruger
    // (inkl. admin), udløst af en helt anonym angriber. Fundet ved en
    // gennemgang af inc/.htaccess's hvidlistede undtagelser.
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        die('error_session');
    }

    // NYT (§bugs-batch-27-review): ALVORLIGT FUND - dette gem-endepunkt havde
    // INTET CSRF-tjek, selvom resten af appen fik CSRF-beskyttelse tidligere
    // denne session (se csrf-protection-added.md). Det blev overset dengang,
    // fordi det hverken er et <form>-baseret POST (den programmatiske
    // eftersøgning dengang ledte specifikt efter <form>-tags) eller går
    // gennem inc/auth.inc.php's globale CSRF-vagt (denne fil bevidst
    // undgår hele auth.inc.php, se filens egen header-kommentar). Da
    // notesblokken er GLOBAL og DELT på tværs af ALLE brugere, og vises
    // uescaped via contenteditable/innerHTML på HVER sidevisning i hele
    // appen, ville en ekstern, uautentificeret angriber kunne narre en
    // hvilken som helst logget-ind bruger til (via et auto-indsendt
    // cross-site <form enctype="text/plain">, samme trusselsmodel som
    // resten af CSRF-arbejdet allerede er baseret på - SameSite=Lax
    // beskytter ikke mod dette, se [[csrf-protection-added]]) at overskrive
    // notesblokken med vilkårlig HTML/JS, som derefter ville køre i
    // browseren hos ENHVER bruger der efterfølgende besøger en hvilken som
    // helst side - en CSRF-til-lagret-XSS-kæde der rammer hele
    // brugerbasen, ikke kun offeret for selve CSRF-forsøget. Tokenet kan
    // ikke sendes som et almindeligt $_POST-felt her (selve request-
    // kroppen ER notesindholdet, ikke et form-kodet felt - $_POST er
    // derfor altid tomt for dette kald), så det bæres i stedet i
    // forespørgselsstrengen (se scriptet nedenfor, samme sted tokenet
    // allerede indlejres for save_layout.php/storage_browser.php).
    if (empty($_SESSION['csrf_token']) || empty($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('error_csrf');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $notes_content = file_get_contents('php://input');
        // Selv-helbredende: en frisk installation har ofte ikke inc/data/-
        // mappen endnu. Opret den før skrivning, så notesblokken virker uden
        // manuelt setup. (Deny-from-all .htaccess i inc/data/ rammer kun
        // HTTP, ikke PHP.)
        $dir = dirname($file_path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (@file_put_contents($file_path, $notes_content) !== false) {
            echo "saved";
        } else {
            // Kommer man hertil trods mappen findes, er det næsten altid
            // fil-rettigheder: web-brugeren må ikke skrive i inc/data/.
            error_log('Notepad: kunne ikke skrive til ' . $file_path . ' - tjek at inc/data/ er skrivbar for web-brugeren (chmod/ejer).');
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

<?php /* Position uændret (bottom: 20px, samme hjørne som altid) - kun z-index
   hævet over .floating-action-bar's 10000 (var 9999), så knappen tegnes
   FORAN bjælken i stedet for at blive dækket af den (bruger-rapporteret;
   første forsøg flyttede knappen op i stedet, hvilket ikke var ønsket). */ ?>
<button type="button" class="no-print" onclick="toggleGlobalNotes()" style="position: fixed; bottom: 20px; right: 20px; z-index: 10001; background: #2c3e50; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 20px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;" title="<?php echo lang('@Open Notepad (Alt + N)'); ?>">
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

        <span style="border-left: 1px solid #ccc; margin: 0 4px;"></span>

        <button type="button" id="notepadCodeBtn" onclick="toggleNotepadSource()" style="padding: 3px 8px; font-family: monospace; background: white; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;" title="<?php echo lang('@View/edit HTML source'); ?>">&lt;/&gt;</button>
    </div>
    
    <div style="flex: 1; padding: 12px; display: flex; flex-direction: column; background: #fff; height: calc(100% - 110px); overflow-y: auto;">
        <div id="globalNotesArea" contenteditable="true" oninput="saveGlobalNotes()" style="width: 100%; height: 100%; min-height: 100%; font-size: 14px; line-height: 1.6; outline: none; box-sizing: border-box; color: #2c3e50; font-family: sans-serif;"><?php echo $notepad_content; ?></div>
        <textarea id="globalNotesSource" oninput="saveGlobalNotes()" spellcheck="false" style="display:none; width:100%; height:100%; min-height:100%; box-sizing:border-box; border:none; outline:none; resize:none; font-family:monospace; font-size:12px; line-height:1.5; color:#2c3e50; background:#fff;"></textarea>
    </div>
    
    <div style="background: #f4f6f7; padding: 6px 12px; font-size: 11px; color: #7f8c8d; text-align: right; border-top: 1px solid #edf2f7; user-select: none;">
        <?php echo lang('@All-in-one module | Saved to HTML file'); ?>
    </div>
</div>

<script>
const notepadWorkerUrl = 'inc/notepad.inc.php';
// NYT (§bugs-batch-27-review): se PHP-tjekket ovenfor for baggrunden - samme
// mønster som invoice_view.php/storage_browser.php allerede bruger til deres
// egne fetch()-baserede AJAX-kald uden om et rigtigt <form>.
const notepadCsrfToken = <?php echo json_encode(csrf_token()); ?>;

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
    status.innerText = "<?php echo lang('@Saving...'); ?>";
    
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
