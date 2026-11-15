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

// Notesblokken deles af alle brugere og vises på hver side: kun formatering (fed, kursiv, farve, lister...) - ingen
// scripts, hændelsesattributter eller links med javascript:. Før kunne en bruger på niveau 1 gemme et script, der kørte
// i administratorens browser (med administratorens rettigheder).
function tc_notepad_clean(string $html): string {
    if (trim($html) === '' || !class_exists('DOMDocument')) return strip_tags($html, '<b><i><u><strong><em><br><p><div><span><ul><ol><li><font>');
    $ok_tags = ['b', 'i', 'u', 'strong', 'em', 'br', 'p', 'div', 'span', 'ul', 'ol', 'li', 'font', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'strike', 's', 'sub', 'sup', 'hr', 'pre', 'code'];
    $drop_all = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'select', 'template', 'frame', 'frameset', 'noscript'];
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="tc-np-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();  libxml_use_internal_errors($prev);
    $root = $doc->getElementById('tc-np-root');
    if (!$root) return htmlspecialchars(strip_tags($html));
    $walk = function (DOMNode $node) use (&$walk, $ok_tags, $drop_all) {
        for ($c = $node->firstChild; $c !== null; $c = $next) {
            $next = $c->nextSibling;
            if ($c instanceof DOMComment) { $node->removeChild($c);  continue; }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->nodeName);
            if (in_array($tag, $drop_all, true)) { $node->removeChild($c);  continue; }
            $walk($c);
            if (!in_array($tag, $ok_tags, true)) {      // (ukendt element: behold teksten, fjern elementet)
                while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                $node->removeChild($c);
                continue;
            }
            foreach (iterator_to_array($c->attributes) as $a) {
                $an = strtolower($a->nodeName);  $av = (string)$a->nodeValue;
                $keep = ($an === 'style' && !preg_match('/url\s*\(|expression|javascript:|@import|behavior/i', $av))
                     || ($tag === 'font' && in_array($an, ['color', 'size', 'face'], true) && !preg_match('/[<>"\'`]|javascript:/i', $av));
                if (!$keep) $c->removeAttribute($a->nodeName);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

// =========================================================================
// 1. WORKER-LOGIK (Kører KUN når der gemmes data via POST)
// =========================================================================
if (isset($_GET['notepad_action']) && $_GET['notepad_action'] === 'save') {
    // RETTET: manglede session_name før session_start. Denne gren kaldes direkte via fetch til inc/notepad.inc.php
    // uden om auth.inc.php - det er derfor det FØRSTE sessionsberøring på denne forespørgsel.
    if (session_status() === PHP_SESSION_NONE) { 
        session_name('TCC_V100_SESSION');
        session_start(); 
    }
    
    // KRITISK: dette sikkerhedstjek var udkommenteret, og notepad.inc.php er selv eksplicit hvidlistet i
    // inc/.htaccess ("Require all granted") for at kunne kaldes direkte via fetch uden om hele side-loadet (og
    // dermed uden om auth.inc.php). Uden tjekket kunne ENHVER på internettet - helt uden login - POSTe vilkårligt
    // HTML/JS til dette endpoint, som blev gemt UESCAPET og derefter vist rå (via contenteditable-div'en nedenfor)
    // på HVER ENESTE sidevisning for ALLE brugere, fordi notepad.inc.php inkluderes fra htm_Footer - dvs. reelt en
    // fuldt uautentificeret, lagret XSS mod enhver indlogget bruger (inkl. admin), udløst af en helt anonym
    // angriber.
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        die('error_session');
    }

    // NYT: ALVORLIGT FUND - dette gem-endepunkt havde INTET CSRF-tjek, selvom resten af appen fik CSRF-beskyttelse
    // tidligere denne session (se csrf-protection-added.md). Det blev overset dengang, fordi det hverken er et
    // <form>-baseret POST (den programmatiske eftersøgning dengang ledte specifikt efter <form>-tags) eller går
    // gennem inc/auth.inc.php's globale CSRF-vagt (denne fil bevidst undgår hele auth.inc.php, se filens egen
    // header-kommentar).
    if (empty($_SESSION['csrf_token']) || empty($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('error_csrf');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/core_utils.lib.php';      // (filen kaldes også direkte, uden auth.inc.php)
        $notes_content = tc_notepad_clean(tc_utf8((string)file_get_contents('php://input')));      // (se tc_utf8() og tc_notepad_clean())
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
    $notepad_content = tc_notepad_clean((string)file_get_contents($file_path));      // (også noter gemt før rensningen)
} else {
    $notepad_content = "<p style='color:#7f8c8d;'>" . lang('@Write your formatted notes here...') . "</p>";
}
?>

<?php /* Position uændret (bottom: 20px, samme hjørne som altid) - kun z-index
   hævet over .floating-action-bar's 10000 (var 9999), så knappen tegnes
   FORAN bjælken i stedet for at blive dækket af den (bruger-rapporteret;
   første forsøg flyttede knappen op i stedet, hvilket ikke var ønsket). */ ?>
<?php /* RETTET (bruger-anmodet): border tilføjet, magen til .floating-action-bar's
   egen border-top: 2px solid var(--color-primary) (se inc/htm_page.lib.php) -
   samme accentfarve/-tykkelse, blot som en fuld ramme rundt om selve den
   runde knap i stedet for kun foroven på en vandret bjælke. Samme rettelse
   givet til den nye søge-knap lige nedenfor. */ ?>
<button type="button" class="no-print" onclick="toggleGlobalNotes()" style="position: fixed; bottom: 20px; right: 3px; z-index: 10001; background: #2c3e50; color: white; border: 2px solid var(--color-primary); border-radius: 50%; width: 40px; height: 40px; box-sizing: border-box; font-size: 18px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;" title="<?php echo lang('@Open Notepad (Alt + N)'); ?>">
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
// fetch() uden om et <form> skal selv sende CSRF-nøglen (se PHP-tjekket ovenfor)
const notepadCsrfToken = <?php echo json_encode(csrf_token()); ?>;
const notepadTxt = { saving: <?php echo json_encode(lang('@Saving...')); ?>, saved: <?php echo json_encode(lang('@Saved')); ?>,
                     expired: <?php echo json_encode(lang('@Session Expired')); ?>, error: <?php echo json_encode(lang('@Error')); ?> };
</script>
<script src="<?php echo tc_asset('assets/tc-notepad.js'); ?>"></script>
<?php
ob_end_flush();
?>
