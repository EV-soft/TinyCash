<?php # /inc/php2htm.lib.php v:1.1.0 d:2026-07-02 i:evs

# Inkluder det centraliserede hjælpemodul
include_once __DIR__ . '/help.lib.php';
# -------------------------------------------------------------------------
# SPROGFUNKTION (Sikret mod dobbeltindlæsning, men dynamisk ved sprogskift)
# -------------------------------------------------------------------------
if (!function_exists('lang')) {
    function lang($key) {
        $current_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da'; 
        
        static $tr = null;
        static $loaded_lang = null; // Holder øje med, hvilket sprog der ligger i cachen
        
        // Hvis vi ikke har indlæst noget endnu, ELLER hvis sproget har ændret sig undervejs:
        if ($tr === null || $loaded_lang !== $current_lang) {
            $tr = null; // Nulstil det gamle sprog-array
            $loaded_lang = $current_lang; // Opdater det tracked sprog
            
            $f = dirname(__DIR__) . '/json-data/languages.json';
            if (file_exists($f)) {
                $d = json_decode(file_get_contents($f), true);
                if ($d && isset($d['language'])) { 
                    foreach ($d['language'] as $l) { 
                        if ($l['code'] === $current_lang) { 
                            $tr = $l['translation']; 
                            break; 
                        } 
                    } 
                }
            }
        }
        
        if ($tr === null || !isset($tr[$key]) || $tr[$key] === "") {
            return ltrim($key, '@');
        }
        return $tr[$key];
    }
}
# HEADER
function htm_Header($capt = 'Tiny Cash', $mwidth = 1600, $echo = true) {
    if (!$echo) ob_start();
    
    // Sikr at sessionen er startet for at kunne læse/skrive sessions-temaer
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $html_lang = isset($_SESSION['lang']) ? strtolower($_SESSION['lang']) : 'da';
    
    // Prioriter sessionen først, derefter cookien for at undgå unødig cookie-load på serveren
    if (isset($_SESSION['theme'])) {
        $saved_theme = $_SESSION['theme'];
    } elseif (isset($_COOKIE['theme'])) {
        $saved_theme = $_COOKIE['theme'];
        $_SESSION['theme'] = $saved_theme;
    } else {
        $saved_theme = 'light';
    }

echo '<!DOCTYPE html><html lang="'.$html_lang.'" data-theme="'.$saved_theme.'"><head><meta charset="UTF-8">
    <title>'.lang($capt).'</title>
    <script>
        (function() {
            const getCookie = (name) => {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(";").shift();
            };
            const savedTheme = getCookie("theme") || "light";
            document.documentElement.setAttribute("data-theme", savedTheme);
        })();
    </script>
     <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css" />

    <style>
   /* =========================================================================
       TEMA-STYRING (CENTRALISERET VIA CSS-VARIABLER)
       ========================================================================= */

    :root, 
    [data-theme="light"] {
        --bg-main: #f4f7f6; --bg-card: #ffffff; --bg-nav: #4c4e4f;
        --bg-nav-hover: rgba(255,255,255,0.1); --bg-submenu: #34495e;
        --bg-panel: #f8f9fa; --bg-table-even: #f9f9f9; --bg-table-hover: #f1f7fd;
        --border-color: #dee2e6; --border-subtle: #eeeeee; --border-fieldset: #787878;
        --text-main: #333333; --text-muted: #666666; --text-light: #ffffff;
        --color-primary: #3498db; --color-success: #2ecc71; --color-danger: #e74c3c;
        --color-warning: #f1c40f; --color-secondary: #95a5a6; --color-dark: #2c3e50;
        --color-purple: #8e44ad; --color-info: #34495e;
    }

    [data-theme="custom"] {
        --bg-main: #3498db; --bg-card: #ffffff; --bg-nav: #2c3e50;
        --bg-nav-hover: rgba(255,255,255,0.15); --bg-submenu: #243342;
        --bg-panel: #f4f6f8; --bg-table-even: #f8faf9; --bg-table-hover: #eef9f3;
        --border-color: #cbd5e1; --border-subtle: #e2e8f0; --border-fieldset: #475569;
        --text-main: #1e293b; --text-muted: #64748b; --text-light: #ffffff;
        --color-primary: #2ecc71; --color-dark: #1a252f; --color-warning: #f39c12;
    }

    [data-theme="dark"] {
        --bg-main: #121212; --bg-card: #1e1e1e; --bg-nav: #1a1a1a;
        --bg-nav-hover: rgba(255,255,255,0.08); --bg-submenu: #2d2d2d;
        --bg-panel: #262626; --bg-table-even: #242424; --bg-table-hover: #2c2c2c;
        --border-color: #404040; --border-subtle: #2d2d2d; --border-fieldset: #888888;
        --text-main: #e0e0e0; --text-muted: #a0a0a0; --text-light: #ffffff;
        --color-primary: #2980b9; --color-success: #27ae60; --color-danger: #c0392b;
        --color-warning: #f39c12; --color-secondary: #7f8c8d; --color-dark: #0f172a;
        --color-purple: #7d3c98; --color-info: #1c2833;
    }

    /* KERNEN I LØSNINGEN: Baggrunds-overlay */
    body { 
        font-family: "Inter", sans-serif; 
        background: var(--bg-main); 
        margin: 4px 20px; 
        color: var(--text-main); 
        padding-bottom: 60px;
        position: relative;
    }

    body::before {
        content: "";
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        z-index: -1;
        transition: background 0.3s ease;
    }

    /* LIGHT: Billede aktivt */
    [data-theme="light"] body::before {
        background-image: url("_background.png");
    }

    /* CUSTOM: Ingen billede, kun blå farve (som var før) */
    [data-theme="custom"] body::before {
        background-image: none;
        background-color: var(--bg-main); /* Bruger den blå farve fra din custom-def */
    }

    /* DARK: Ingen billede, kun mørk farve (som var før) */
    [data-theme="dark"] body::before {
        background-image: none;
        background-color: var(--bg-main); /* Bruger den mørke farve fra din dark-def */
    }

    .cardW000 { max-width: '.$mwidth.'px; margin: 20px auto; background: var(--bg-card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border: 1px solid var(--border-color); }
    nav { position: relative; z-index: 9000 !important; display: flex; flex-wrap: wrap; align-items: center; background: var(--bg-nav); padding: 5px 20px; min-height: 70px; }
    .nav-main-link { display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; text-decoration: none !important; color: var(--text-light) !important; padding: 5px 10px !important; min-width: 85px; transition: background 0.2s; }
    .nav-main-link span { color: var(--text-light) !important; display: block !important; text-align: center; }
    .nav-main-link span.menu-icon { font-size: 1.5em; line-height: 1; margin-bottom: 3px; }
    .nav-main-link span.menu-text { font-size: 0.95em; font-weight: 600; }
    .nav-main-link:hover { background: var(--bg-nav-hover); border-radius: 4px; }
    
    .submenu { display: none; position: absolute; background: var(--bg-submenu) !important; min-width: 240px !important; z-index: 9999 !important; box-shadow: 0 8px 25px rgba(0,0,0,0.6); border-radius: 4px; border: 1px solid var(--border-color); margin-top: 5px; padding: 5px 0 !important; }
    
    .dropdown-item { display: flex !important; align-items: center !important; gap: 10px; padding: 12px 15px !important; color: var(--text-light) !important; text-decoration: none !important; cursor: pointer !important; border-bottom: 1px solid var(--border-subtle); }
    .dropdown-item:hover { background: var(--color-primary) !important; }
    
    .quick-actions { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column-reverse; gap: 10px; z-index: 9999; }
    .qa-btn { background-color: var(--color-dark); color: var(--text-light) !important; padding: 12px 20px; border-radius: 50px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 10px; transition: all 0.3s ease; border: none; cursor: pointer; }
    .qa-btn:hover { background-color: var(--color-primary); transform: scale(1.05); }
    .qa-btn i { font-size: 1.2em; }
    .qa-invoice { background-color: #27ae60; } 
    .qa-expense { background-color: var(--color-danger); } 
    .qa-account { background-color: var(--color-purple); } 
    
    .floating-action-bar { position: fixed; bottom: 0; width: 96%; background: rgba(44, 62, 80, 0.95); border-top: 2px solid var(--color-primary); display: flex; justify-content: center; gap: 20px; padding: 2px 0; z-index: 10000; box-shadow: 0 -2px 10px rgba(0,0,0,0.2); }
    .fab-item { color: var(--text-light) !important; text-decoration: none; font-size: 0.9rem; font-weight: bold; display: flex; align-items: center; gap: 8px; padding: 5px 15px; border-radius: 4px; transition: background 0.2s; }
    .fab-item:hover { background: var(--bg-nav-hover); }
    .fab-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; }
    .dot-invoice { background-color: #2ecc71; } 
    .dot-expense { background-color: var(--color-danger); } 
    .dot-account { background-color: var(--color-warning); } 
    
    .flag-icon { display: inline-block; background-size: contain; background-position: 50%; background-repeat: no-repeat; position: relative; width: 1.33333333em; line-height: 1em; }
    .flag-icon::before { content: "\00a0"; }

    .flag-icon-da, .fi-da, .flag-icon-dk, .fi-dk { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/dk.svg) !important; }
    .flag-icon-sv, .fi-sv, .flag-icon-se, .fi-se { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/se.svg) !important; }
    .flag-icon-no, .fi-no, .flag-icon-nb, .fi-nb { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/no.svg) !important; }
    .flag-icon-en, .fi-en, .flag-icon-gb, .fi-gb { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/gb.svg) !important; }
    body { padding-bottom: 60px; }
    
    .fab-top { position: absolute; right: 20px; background: rgba(255, 255, 255, 0.6); border-left: 1px solid rgba(255, 255, 255, 0.2); padding: 0 15px; height: 100%; display: flex; align-items: center; cursor: pointer; transition: all 0.3s; }
    .fab-top:hover { background: var(--color-primary); color: var(--text-light); }
    @media (max-width: 600px) { .fab-top span { display: none; } }

    [data-hint]::after, [data-hint]::before { content: none !important; display: none !important; }
    [data-hint] { cursor: help !important; }
    </style>
    
    <script>
    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => { console.log("Error: " + err.message); });
        } else {
            if (document.exitFullscreen) document.exitFullscreen();
        }
    }

    function setTheme(themeName) {
        document.documentElement.setAttribute(\'data-theme\', themeName);
        const d = new Date();
        d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = "theme=" + themeName + ";expires=" + d.toUTCString() + ";path=/;SameSite=Strict";
    }
    </script></head><body>';
    if (!$echo) return ob_get_clean();
}

# FOOTER
function htm_Footer($echo = true) {
    if (!$echo) ob_start();
    
    echo '<div id="tc-hint" style="position:fixed; display:none; background: var(--color-dark); color: var(--text-light); 
          padding:8px 15px; border-radius:4px; border-left:4px solid var(--color-warning); z-index:2147483647; 
          pointer-events:none; font-size:13px; max-width:300px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
          line-height:1.4; white-space:pre-wrap; font-family:sans-serif;"></div>
          
    <div id="custom-alert" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
        <div style="background: var(--bg-card); padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 15px rgba(0,0,0,0.3); font-family:sans-serif;">
            <h3 id="custom-alert-title" style="margin-top:0; color: var(--color-dark); border-bottom:2px solid var(--color-primary); padding-bottom:8px;">TinyCash</h3>
            <p id="custom-alert-text" style="color: var(--text-muted); font-size:14px; line-height:1.5;"></p>
            <div style="text-align:right; margin-top:20px;">
                <button onclick="closeAlert()" style="background: var(--color-primary); color: var(--text-light); border:none; padding:8px 16px; border-radius:4px; cursor:pointer; font-weight:bold;">OK</button>
            </div>
        </div>
    </div>
    
    <script>
    function sysAlert(message, title = "TinyCash") {
        var modal = document.getElementById("custom-alert");
        if (modal) {
            document.getElementById("custom-alert-title").innerText = title;
            document.getElementById("custom-alert-text").innerText = message;
            modal.style.display = "flex";
        }
    }

    function closeAlert() {
        var modal = document.getElementById("custom-alert");
        if (modal) modal.style.display = "none";
    }
    
    (function() {
        const hb = document.getElementById("tc-hint");
        document.addEventListener("mouseover", function(e) {
            const t = e.target.closest("[data-hint]");
            if (t && hb) { hb.innerHTML = t.getAttribute("data-hint"); hb.style.display = "block"; }
        });
        document.addEventListener("mousemove", function(e) {
            if (hb && hb.style.display === "block") {
                let x = e.clientX + 20, y = e.clientY + 20;
                if (x + hb.offsetWidth > window.innerWidth) x = e.clientX - hb.offsetWidth - 20;
                if (y + hb.offsetHeight > window.innerHeight) y = e.clientY - hb.offsetHeight - 20;
                hb.style.left = x + "px"; hb.style.top = y + "px";
            }
        });
        document.addEventListener("mouseout", function(e) { if (hb) hb.style.display = "none"; });
    })();
    function filterTable(id) {
        const input = document.getElementById(id + "_search"); if(!input) return;
        const filter = input.value.toUpperCase();
        const table = document.getElementById(id); if(!table) return;
        const tr = table.getElementsByTagName("tr");
        for (let i = 1; i < tr.length; i++) {
            let found = false, td = tr[i].getElementsByTagName("td");
            for (let j = 0; j < td.length; j++) {
                if (td[j] && (td[j].textContent || td[j].innerText).toUpperCase().indexOf(filter) > -1) { found = true; break; }
            }
            tr[i].style.display = found ? "" : "none";
        }
    }
    function clearSearch(id) { const i = document.getElementById(id + "_search"); if(i){ i.value = ""; filterTable(id); i.focus(); } }
    window.onscroll = function() {
        const btn = document.getElementById("fab-scroll-top");
        if (btn) {
            if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) { btn.style.display = "flex"; } else { btn.style.display = "none"; }
        }
    };
    </script>';

    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) { 
        if (function_exists('htm_FloatingActionBar')) { htm_FloatingActionBar(); }
        if (function_exists('htm_HelpSystem')) { htm_HelpSystem(); }
        include_once 'notepad.inc.php';
    }
    
    if (isset($GLOBALS['GLOBAL_DEBUG_ALERT'])) { echo $GLOBALS['GLOBAL_DEBUG_ALERT']; }
    echo '</body></html>';
    
    if (!$echo) return ob_get_clean();
}

# QUICK MENU
function htm_QuickMenu() {
    ?>
    <div class="quick-actions">
        <a href="account_edit.php?id=0" class="qa-btn qa-account"><i class="fa fa-plus-circle"></i> <span><?php echo lang('@Account'); ?></span></a>
        <a href="expense_edit.php?id=0" class="qa-btn qa-expense"><i class="fa fa-minus-circle"></i> <span><?php echo lang('@Expense'); ?></span></a>
        <a href="invoice_edit.php?id=0" class="qa-btn qa-invoice"><i class="fa fa-file-invoice-dollar"></i> <span><?php echo lang('@Invoice'); ?></span></a>
    </div>
    <?php
}

# INPUT GROUP
function htm_InputGroup($icon, $labl, $name, $valu='', $type='text', $opti=null, $extr='', $wdth='100%', $hint='', $plho='', $legd='', $echo=true) {
    if (!$echo) ob_start();
    static $id_counter = 0;
    $id_counter++;
    $unique_id = str_replace(array('[]', '[', ']'), '_', $name) . $id_counter;

    $translated_label = lang($labl);
    $english_reference = ltrim($labl, '@'); 

    $outer_hint_attr = '';
    $legend_hint_attr = '';

    $current_lang = strtolower(isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da');
    $show_english_fallback = ($current_lang !== 'en' && strtolower($translated_label) !== strtolower($english_reference));

    if ($hint > '') {
        $clean_hint = str_replace(array('<br>', '<br />', '<br/>'), "\n", lang($hint));
        if ($show_english_fallback) {
            $clean_hint .= "\n\n(English: " . $english_reference . ")";
        }
        $outer_hint_attr = ' data-hint="'.htmlspecialchars($clean_hint, ENT_QUOTES).'"';
    } 
    elseif ($show_english_fallback) {
        $legend_hint_attr = ' data-hint="'.htmlspecialchars("English: " . $english_reference, ENT_QUOTES).'"';
    }

    if (empty($translated_label)) {
        $translated_label = $english_reference;
    }

    if (strpos(' '.$extr,'required')>0) $bord= 'border: 2px solid orange;'; else $bord='';
    $h = '<div'.$outer_hint_attr.' style="display:inline-block; width:'.$wdth.'; vertical-align:bottom; padding:0; margin:0; box-sizing:border-box;"><fieldset'.$outer_hint_attr.' style="border-radius:8px; margin:2px; border:1px solid var(--border-fieldset);'.$bord.' padding:5px 10px;">';
    
    if ($labl) { 
        $i_h = $icon ? '<i class="fa '.$icon.'" style="margin-right:5px; color: var(--color-primary);"></i> ' : '';
        
        // 1. Tjek det NYE prefix-format først
        $align = 'right'; 
        if (strpos($extr, 'leg:left') !== false || strpos($legd, 'leg:left') !== false) {
            $align = 'left';
        } elseif (strpos($extr, 'leg:center') !== false || strpos($legd, 'leg:center') !== false) {
            $align = 'center';
        } elseif (strpos($extr, 'leg:right') !== false || strpos($legd, 'leg:right') !== false) {
            $align = 'right';
        } 
        // 2. BAGUDKOMPATIBILITET: Hvis nyt prefix ikke findes, brug gamle regler
        else {
            if (strpos($extr, 'align-left') !== false || strpos($legd, 'align-left') !== false) $align = 'left';
            if (strpos($extr, 'align-center') !== false || strpos($legd, 'align-center') !== false) $align = 'center';
        }

        // Rens $legd for de gamle align-strenge
        $clean_legd = str_replace(array('align-left', 'align-center'), '', $legd);
        
        $centering_css = ($align == 'center') ? 'margin-left:auto; margin-right:auto; float:none; display:table;' : '';
        $align_css = 'text-align: ' . $align . ';';
        
        $h .= '<legend'.$legend_hint_attr.' style="font-size:0.85rem; padding:0 5px; color: var(--text-muted); margin:0; '. $align_css . $centering_css . $clean_legd . '">' . $i_h . $translated_label . '</legend>';
    }
    
    $p_a = ($plho !== '') ? " placeholder='".htmlspecialchars(lang($plho), ENT_QUOTES)."'" : '';
    
    // Standard-styles til input/select/textarea
    $s = 'width:100%; border:none; outline:none; background:transparent; font-family:inherit; font-size:1.1rem; margin:0; display:block; color:inherit;';
    
    // HVIS der sendes en style med i $extr, trækker vi indholdet ud og fletter det ind i $s,
    // så vi undgår dobbelte style="" attributter i HTML-tagget.
    if (preg_match('/style=["\']([^"\']+)["\']/', $extr, $style_match)) {
        $s .= ' ' . rtrim($style_match[1], ';') . ';';
        $extr = preg_replace('/style=["\']([^"\']+)["\']/', '', $extr);
    }
    
    // 3. RENS INPUT-FORMAT: Fjern styringsklasser fra $extr, så input forbliver fejlfrit
    $clean_extr = str_replace(array('leg:left', 'leg:center', 'leg:right', 'align-left', 'align-center'), '', $extr);

    if ($type == 'view' || $type == 'raw') { 
        $h .= '<div style="'.$s.' font-weight:bold; min-height:1.2em; color: var(--text-main); box-sizing: border-box;">'.($valu ? $valu : '&nbsp;').'</div>'; 
    } elseif ($type == 'sele' && is_array($opti)) {
        $h .= '<select name="'.$name.'" id="'.$unique_id.'" style="'.$s.'" '.$clean_extr.'>';
        foreach ($opti as $k => $v) {
            $h .= '<option value="'.$k.'" '.($valu == $k ? 'selected' : '').'>'.$v.'</option>';
        }
        $h .= '</select>';
    } elseif ($type == 'textarea') { 
        $h .= '<textarea name="'.$name.'" id="'.$unique_id.'" '.$p_a.' style="'.$s.' min-height:3em; resize:vertical; line-height:1.5;" '.$clean_extr.'>'; 
        $h .= htmlspecialchars((string)$valu).'</textarea>'; 
    } else { 
        $h .= '<input type="'.$type.'" name="'.$name.'" id="'.$unique_id.'" '.$p_a.' style="'.$s.'" value="'.htmlspecialchars((string)$valu).'" '.$clean_extr.'>'; 
    }
    $h .= '</fieldset></div>';
    if ($echo) { echo $h; } else { return $h; }
}

# SHELL START
function htm_Shell_($styl='margin:0 auto; padding:10px;', $type='div', $echo = true) {
    static $stack = array();
    if ($styl === 'GET_STACK') return $stack;
    if ($styl === 'POP_STACK') { array_pop($stack); return; }
    $stack[] = $type;
    $htm = '<' . $type . ' style="' . $styl . '">';
    if ($echo) { echo $htm; } else { return $htm; }
}

# SHELL END
function htm_Shell_end($echo = true) {
    $stack = htm_Shell_('GET_STACK');
    if (empty($stack)) return '';
    $last_tag = end($stack);
    htm_Shell_('POP_STACK');
    $htm = '</' . $last_tag . '>';
    if ($echo) { echo $htm; } else { return $htm; }
}

# CARD START
function htm_Card_($capt, $wdth='600', $info='', $form=false, $echo = true, $tool = '') {
    static $form_is_open = false; 
    if ($form === 'CHECK_STATE') return $form_is_open;
    if ($form === 'RESET_STATE') { $form_is_open = false; return; }
    
    if (!$echo) ob_start();
    $w = is_numeric($wdth) ? $wdth.'px' : $wdth;
    
    if ($form) { 
        $form_is_open = true; 
        $n = is_string($form) ? " name='$form' id='$form'" : ""; 
        echo "<form method='post' $n style='margin:0;'>"; 
    }
    
    echo '<div style="max-width:'.$w.'; margin: 20px auto; padding: 0 5px;"><div style="background: var(--bg-card); padding:25px; border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid var(--color-primary); margin-bottom:15px; padding-bottom:10px;"><h2 style="margin:0; color: var(--text-main, #2c3e50);">'.lang($capt).'</h2><div>'.$tool.'</div></div>';
    if($info) echo '<div style="margin-bottom:15px; padding:10px; background: var(--bg-panel); border-radius:4px; font-size:0.9em; color: var(--text-muted);">'.$info.'</div>';
    
    if (!$echo) return ob_get_clean();
}

# CARD END
function htm_Card_end($echo = true) {
    if (!$echo) ob_start();
    if (htm_Card_('', '', '', 'CHECK_STATE')) { 
        echo '</div></div></form>'; 
        htm_Card_('', '', '', 'RESET_STATE'); 
    } else { 
        echo '</div></div>'; 
    }
    if (!$echo) return ob_get_clean();
}

# BUTTON 
function htm_Button($icon='', $labl='', $type='primary', $link='', $styl='', $attr='', $cont='', $echo=true) {
    $s = "display:inline-block; text-align:center; background-color: var(--color-$type); color: var(--text-light); padding:8px 16px; border-radius:4px; text-decoration:none; border:none; cursor:pointer; font-size:14px; font-weight:600;";
    
    $l = ($labl != '') ? " ".lang($labl) : ''; 
    $i_h = ($icon != '') ? "<i class='fa-solid $icon'></i>" : "";
    $b = ($link != '') ? "<a href='$link' style='$s$styl' $attr>$i_h$l</a>" : "<button type='submit' style='$s$styl' $attr>$i_h$l</button>";
    if (!$echo) return $b; echo $b;
}

# TABLE
function htm_Table($head, $data, $name='tbl', $limt=25, $html='', $echo=true, $cols=array()) {
    if (!$echo) ob_start();
    echo '<style>
        #'.$name.' input { text-align: inherit !important; background: transparent; border: none; color: inherit; }
        #'.$name.' tr:nth-child(even){background: var(--bg-table-even);} 
        #'.$name.' tr:hover{background: var(--bg-table-hover);} 
        .sort-ptr { cursor:pointer; }
        #'.$name.' td { padding: 6px 8px; border-bottom: 1px solid var(--border-subtle); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #'.$name.' th { padding: 8px 10px; border-bottom: 2px solid var(--border-color); text-align: left; background: var(--bg-panel); position: sticky; top: 0; z-index: 5; color: var(--text-main); }
        #line_tbl input[readonly] { background-color: var(--bg-main) !important; cursor: not-allowed; color: var(--text-muted); }
    </style>';

    echo '<div style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">';
    echo '  <div style="position:relative; width:160px;">';
    echo '    <i class="fa fa-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color: var(--color-secondary); font-size:0.8rem;"></i>';
    echo '    <input type="text" id="'.$name.'_search" onkeyup="filterTable(\''.$name.'\')" placeholder="'.lang('@Search').'..." style="padding:6px 6px 6px 28px; border:1px solid var(--border-color); border-radius:4px; width:100%; font-size:0.85rem; background: var(--bg-card); color: inherit;">';
    echo '  </div>';
    echo '  <div style="display:flex; gap:5px; align-items:center;">'.$html.'</div>';
    echo '</div>';

    echo '<div style="max-height:600px; overflow-y:auto; overflow-x:auto; -webkit-overflow-scrolling:touch; margin-bottom: 60px; border:1px solid var(--border-subtle); border-radius:8px;">';
    echo '<table id="'.$name.'" style="width:100%; border-collapse:separate; table-layout:auto;">';
    
    if (!empty($cols)) {
        echo '<colgroup>';
        foreach ($cols as $style) {
            preg_match('/width:\s?([^;]+)/', $style, $matches);
            $w = isset($matches[1]) ? 'width:'.$matches[1] : (is_numeric($style) ? 'width:'.$style : '');
            echo '<col style="'.$w.'">';
        }
        echo '</colgroup>';
    }

    echo '<thead><tr>';
        $i = 0;
        foreach ($head as $idx => $labl) {
            $align = '';
            $is_right = false;
            
            if (isset($cols[$idx]) && strpos($cols[$idx], 'text-align') !== false) { 
                $align = preg_replace('/width:[^;]+;?/', '', $cols[$idx]); 
                if (strpos($cols[$idx], 'right') !== false) $is_right = true;
            } elseif (isset($cols[$i]) && strpos($cols[$i], 'text-align') !== false) { 
                $align = preg_replace('/width:[^;]+;?/', '', $cols[$i]); 
                if (strpos($cols[$i], 'right') !== false) $is_right = true;
            }
            
            $is_action = ($labl === '' || $idx === 'actions' || strpos(strtolower($labl), 'action') !== false || strpos($labl, '<') !== false);
            $display_label = (strpos($labl, '<') !== false) ? $labl : lang($labl);
            
            if ($is_action && (trim($display_label) === '' || strtolower(trim($display_label)) === 'action' || strtolower(trim($display_label)) === '@action')) {
                $display_label = lang('@Actions');
            }
            if ($is_action && (trim($display_label) === '' || strtolower(trim($display_label)) === 'action' || strtolower(trim($display_label)) === '@action')) {
                $display_label = 'Handling';
            }
            if ($is_action) {
                echo '<th style="'.$align.'">'.$display_label.'</th>';
            } else {
                $click_attr = 'onclick="sortTable(\''.$name.'\', '.$i.')"';
                $flex_style = $is_right ? 'display: inline-flex; flex-direction: row; align-items: center; gap: 5px; justify-content: flex-end; width: 100%;' : 'display: inline-flex; align-items: center; gap: 5px;';
                
                echo '<th class="sort-ptr" '.$click_attr.' style="'.$align.'">';
                echo '<span style="'.$flex_style.'">'.$display_label.' <i class="fa fa-sort" style="font-size:0.7rem; color: var(--color-secondary); flex-shrink: 0;"></i></span>';
                echo '</th>';
            }
            $i++;
        } 
    echo '</tr></thead><tbody>';
    
    foreach ($data as $rowv) {
        echo '<tr>';
        foreach ($rowv as $idx => $valu) { 
            $style = isset($cols[$idx]) ? $cols[$idx] : ''; 
            echo '<td style="'.$style.'">'.$valu.'</td>'; 
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    ?>
    <script>
    function sortTable(tableId, n) {
        var table = document.getElementById(tableId); if (!table) return;
        var th = table.getElementsByTagName("th")[n];
        if (!th || !th.classList.contains("sort-ptr")) return;

        var rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
        switching = true; dir = "asc"; 
        
        function getCleanValue(cell) {
            if (!cell) return "";
            var text = cell.innerText.trim(); var lower = text.toLowerCase();
            
            if (lower.endsWith(" mb") || lower.endsWith(" kb")) {
                var num = parseFloat(text.replace(/[^\d,.-]/g, "").replace(",", "."));
                if (isNaN(num)) return 0;
                return lower.endsWith(" mb") ? num * 1024 * 1024 : num * 1024;
            }
            
            if (/^\d{2}\.\d{2}\.\d{4}\s\d{2}:\d{2}$/.test(text)) {
                var parts = text.split(/[\s.:]/);
                return new Date(parseInt(parts[2], 10), parseInt(parts[1], 10) - 1, parseInt(parts[0], 10), parseInt(parts[3], 10), parseInt(parts[4], 10)).getTime();
            }
            
            if (/^-?[\d.]+(,\d{2})?$/.test(text)) {
                var cleanNum = parseFloat(text.replace(/\./g, "").replace(",", "."));
                if (!isNaN(cleanNum)) return cleanNum;
            }
            
            return lower;
        }
        
        while (switching) {
            switching = false; rows = table.rows;
            for (i = 1; i < (rows.length - 1); i++) {
                shouldSwitch = false; 
                x = rows[i].getElementsByTagName("TD")[n]; y = rows[i + 1].getElementsByTagName("TD")[n];
                if (!x || !y) continue;
                var xVal = getCleanValue(x); var yVal = getCleanValue(y);
                if (dir == "asc") { if (xVal > yVal) { shouldSwitch = true; break; } } 
                else if (dir == "desc") { if (xVal < yVal) { shouldSwitch = true; break; } }
            }
            if (shouldSwitch) { rows[i].parentNode.insertBefore(rows[i + 1], rows[i]); switching = true; switchcount++; } 
            else { if (switchcount == 0 && dir == "asc") { dir = "desc"; switching = true; } }
        }
    }
    </script>
    <?php
    if (!$echo) return ob_get_clean();
}

# SECTION START
function htm_SectionStart($icon, $labl, $open=false, $colr='var(--color-primary)', $echo=true) {
    if (!$echo) ob_start();
    $s = $open ? 'open' : '';
    echo '<details '.$s.' style="margin-bottom:20px; border:1px solid var(--border-color); border-radius:8px; background: var(--bg-card);">';
    echo '<summary style="padding:14px 20px; background: var(--bg-panel); cursor:pointer; font-weight:bold; display:flex; align-items:center;"><i class="fa-solid '.$icon.'" style="font-size:1.4em; width:35px; color:'.$colr.';"></i>'.lang($labl).'</summary><div style="padding:20px;">';
    if (!$echo) return ob_get_clean();
}

# SECTION END
function htm_SectionEnd($echo=true) { 
    if ($echo) { echo '</div></details>'; } else { return '</div></details>'; }
}

# ALERT
function htm_Alert($text, $type='success', $width=700, $echo=true) {
    if (empty($text)) return ""; 
    if (!$echo) ob_start();
    $bg = ($type == 'error') ? "var(--bg-alert-error)" : "var(--bg-alert-success)"; 
    $c  = ($type == 'error') ? "var(--text-alert-error)" : "var(--text-alert-success)";
    
    $h = '<div style="background:'.$bg.'; color:'.$c.'; padding:15px; margin:10px auto; max-width:'.$width.'px; border-radius:4px;">' . lang($text) . '</div>';
    
    if ($echo) { echo $h; } else { return $h; }
}

# UTILS
function htm_nl($rept=1) {
    echo str_repeat('<br />', $rept);
}

function get_tc_doc($filename, $type = 'DOC') {
    if (empty($filename)) return false;
    $base_dir = 'uploads/'; $clean_name = basename($filename); $path = $base_dir . $clean_name;
    if (file_exists(__DIR__ . '/../' . $path) || file_exists($path)) { return $path; }
    return false;
}

function htm_DocPath($filename) {
    if (!$filename) return false;
    $clean_name = basename($filename); $paths = array('uploads/', 'expenses/', 'bilag/');
    foreach ($paths as $p) { if (file_exists($p . $clean_name)) { return $p . $clean_name; } }
    return false;
}

function htm_GetDocIcon($filename, $type = 'other') {
    $path = htm_DocPath($filename); if (!$path) return '---';
    $icons = array(
        'expense' => array('icon' => 'fa-file-invoice-dollar', 'color' => 'var(--color-danger)'),
        'revenue' => array('icon' => 'fa-file-invoice',        'color' => '#27ae60'),
        'bank'    => array('icon' => 'fa-university',          'color' => 'var(--color-primary)'),
        'other'   => array('icon' => 'fa-paperclip',           'color' => 'var(--color-secondary)')
    );
    if (strpos($filename, 'EXP_') === 0) $type = 'expense';
    $cfg = isset($icons[$type]) ? $icons[$type] : $icons['other'];
    return '<a href="'.$path.'" target="_blank" style="color:'.$cfg['color'].'; font-size:1.1em;" data-hint="'.htmlspecialchars($filename).'"><i class="fa-solid '.$cfg['icon'].'"></i></a>';
}

function print_arr($arrVar,$titl='',$attr='',$wdth='fit-content',$rtrn=false)  ## Pretty output of any variable
{   // Hvis der er sendt en streng (f.eks. '_POST' eller 'minVariabel')
    if (is_string($arrVar)) { // Sætter titlen til f.eks. "$_POST"
        if (empty($titl)) { $titl = '$' . $arrVar; }
        if (isset($GLOBALS[$arrVar])) { $arrVar = $GLOBALS[$arrVar]; }
        elseif (isset($_REQUEST[$arrVar])) { $arrVar = $_REQUEST[$arrVar]; }
    }
    
    $result = '<div style="background:var(--bg-panel); color:var(--text-main); border:1px solid var(--border-color); padding:10px; margin:10px 0; border-radius:5px; width:'.$wdth.'; font-family:monospace; font-size:12px; overflow:auto; '.$attr.'">';
    if (!empty($titl)) { $result .= '<b style="color:var(--color-primary); display:block; margin-bottom:5px;">'.htmlspecialchars($titl).'</b>'; }
    $result .= '<pre style="margin:0; white-space:pre-wrap;">' . htmlspecialchars(print_r($arrVar, true)) . '</pre></div>';
    
    if ($rtrn) { return $result; }
    echo $result;
}