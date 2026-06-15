<?php # /inc/php2htm.lib.php v:0.9.9 d:2026-06-13 i:gemini ok (Fixed TD Clipping & Smart Action Column Detection)

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
    $html_lang = isset($_SESSION['lang']) ? strtolower($_SESSION['lang']) : 'da';
echo '<!DOCTYPE html><html lang="'.$html_lang.'"><head><meta charset="UTF-8"><title>'.lang($capt).'</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css" />
    <style>
    body { font-family: "Inter", sans-serif; background: #f4f7f6; margin: 4px 20px; }
    .cardW000 { max-width: '.$mwidth.'px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    nav { position: relative; z-index: 9000 !important; display: flex; flex-wrap: wrap; align-items: center; background: #4c4e4f; padding: 5px 20px; min-height: 70px; }
    .nav-main-link { display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; text-decoration: none !important; color: #fff !important; padding: 5px 10px !important; min-width: 85px; transition: background 0.2s; }
    .nav-main-link span { color: #fff !important; display: block !important; text-align: center; }
    .nav-main-link span.menu-icon { font-size: 1.5em; line-height: 1; margin-bottom: 3px; }
    .nav-main-link span.menu-text { font-size: 0.95em; font-weight: 600; }
    .nav-main-link:hover { background: rgba(255,255,255,0.1); border-radius: 4px; }
    .submenu { display: none; position: absolute; background: #34495e !important; min-width: 240px !important; z-index: 9999 !important; box-shadow: 0 8px 25px rgba(0,0,0,0.6); border-radius: 4px; border: 1px solid #455a64; margin-top: 5px; padding: 5px 0 !important; }
    .dropdown-item { display: flex !important; align-items: center !important; gap: 10px; padding: 12px 15px !important; color: white !important; text-decoration: none !important; cursor: pointer !important; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .dropdown-item:hover { background: #3498db !important; }
    
    .quick-actions { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column-reverse; gap: 10px; z-index: 9999; }
    .qa-btn { background-color: #2c3e50; color: white !important; padding: 12px 20px; border-radius: 50px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 10px; transition: all 0.3s ease; border: none; cursor: pointer; }
    .qa-btn:hover { background-color: #3498db; transform: scale(1.05); }
    .qa-btn i { font-size: 1.2em; }
    .qa-invoice { background-color: #27ae60; } 
    .qa-expense { background-color: #e74c3c; } 
    .qa-account { background-color: #8e44ad; } 
    
    .floating-action-bar { position: fixed; bottom: 0; width: 96%; background: rgba(44, 62, 80, 0.95); border-top: 2px solid #3498db; display: flex; justify-content: center; gap: 20px; padding: 2px 0; z-index: 10000; box-shadow: 0 -2px 10px rgba(0,0,0,0.2); }
    .fab-item { color: white !important; text-decoration: none; font-size: 0.9rem; font-weight: bold; display: flex; align-items: center; gap: 8px; padding: 5px 15px; border-radius: 4px; transition: background 0.2s; }
    .fab-item:hover { background: rgba(255, 255, 255, 0.1); }
    .fab-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; }
    .dot-invoice { background-color: #2ecc71; } 
    .dot-expense { background-color: #e74c3c; } 
    .dot-account { background-color: #f1c40f; } 
    
    /* Sørg for at gamle klasser (flag-icon) arver fra det nye biblioteks struktur */
    .flag-icon { display: inline-block; background-size: contain; background-position: 50%; background-repeat: no-repeat; position: relative; width: 1.33333333em; line-height: 1em; }
    .flag-icon::before { content: "\00a0"; }

    /* Map de mest almindelige sprogkoder over til de korrekte landeflag, hvis der linkes forkert */
    .flag-icon-da, .fi-da { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/dk.svg) !important; }
    .flag-icon-en, .fi-en { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/gb.svg) !important; }
    .flag-icon-de, .fi-de { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/de.svg) !important; }

    /* Sørg for at gamle klasser (flag-icon) arver fra det nye biblioteks struktur */
    .flag-icon { display: inline-block; background-size: contain; background-position: 50%; background-repeat: no-repeat; position: relative; width: 1.33333333em; line-height: 1em; }
    .flag-icon::before { content: "\00a0"; }

    /* Sprog- og landekode mapping for Skandinavien + UK */
    .flag-icon-da, .fi-da, .flag-icon-dk, .fi-dk { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/dk.svg) !important; }
    .flag-icon-sv, .fi-sv, .flag-icon-se, .fi-se { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/se.svg) !important; }
    .flag-icon-no, .fi-no, .flag-icon-nb, .fi-nb { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/no.svg) !important; }
    .flag-icon-en, .fi-en, .flag-icon-gb, .fi-gb { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/gb.svg) !important; }

    /* Generel fallback hvis databasen/menuen stadig bruger "flag-icon-dk" */
    .flag-icon-dk { background-image: url(https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/flags/4x3/dk.svg) !important; }
    body { padding-bottom: 60px; }
    
    .fab-top { position: absolute; right: 20px; background: rgba(255, 255, 255, 0.6); border-left: 1px solid rgba(255, 255, 255, 0.2); padding: 0 15px; height: 100%; display: flex; align-items: center; cursor: pointer; transition: all 0.3s; }
    .fab-top:hover { background: #3498db; color: white; }
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
    </script></head><body>';
    if (!$echo) return ob_get_clean();
}

# FOOTER
function htm_Footer($echo = true) {
    if (!$echo) ob_start();
    
    echo '<div id="tc-hint" style="position:fixed; display:none; background:#2c3e50; color:white; 
          padding:8px 15px; border-radius:4px; border-left:4px solid #f1c40f; z-index:2147483647; 
          pointer-events:none; font-size:13px; max-width:300px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
          line-height:1.4; white-space:pre-wrap; font-family:sans-serif;"></div>
          
    <div id="custom-alert" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:white; padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 15px rgba(0,0,0,0.3); font-family:sans-serif;">
            <h3 id="custom-alert-title" style="margin-top:0; color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:8px;">TinyCash</h3>
            <p id="custom-alert-text" style="color:#555; font-size:14px; line-height:1.5;"></p>
            <div style="text-align:right; margin-top:20px;">
                <button onclick="closeAlert()" style="background:#3498db; color:white; border:none; padding:8px 16px; border-radius:4px; cursor:pointer; font-weight:bold;">OK</button>
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

    // 1. Hent den aktuelle oversættelse direkte fra din sprogfunktion
    $translated_label = lang($labl);
    
    // 2. Den rå engelske reference er altid teksten uden '@'
    $english_reference = ltrim($labl, '@'); 

    $outer_hint_attr = '';
    $legend_hint_attr = '';

    // 3. Tjek det aktuelle sprog dynamisk fra sessionen HVER gang funktionen kaldes
    $current_lang = strtolower(isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da');
    
    // Vi viser KUN den engelske reference, hvis sproget aktivt er sat til dansk ('da')
    // OG hvis den oversatte tekst faktisk er forskellig fra den engelske reference
    $show_english_fallback = ($current_lang !== 'en' && strtolower($translated_label) !== strtolower($english_reference));

    if ($hint > '') {
        $clean_hint = str_replace(array('<br>', '<br />', '<br/>'), "\n", lang($hint));
        
        if ($show_english_fallback) {
            $clean_hint .= "\n\n(English: " . $english_reference . ")";
        }
        
        $outer_hint_attr = ' data-hint="'.htmlspecialchars($clean_hint, ENT_QUOTES).'"';
    } 
    elseif ($show_english_fallback) {
        // Læg KUN hintet på legend-elementet (label-teksten)
        $legend_hint_attr = ' data-hint="'.htmlspecialchars("English: " . $english_reference, ENT_QUOTES).'"';
    }

    if (empty($translated_label)) {
        $translated_label = $english_reference;
    }

    $h = '<div'.$outer_hint_attr.' style="display:inline-block; width:'.$wdth.'; vertical-align:bottom; padding:0; margin:0; box-sizing:border-box;"><fieldset'.$outer_hint_attr.' style="border-radius:8px; margin:2px; border:1px solid #787878; padding:5px 10px;">';
    
    if ($labl) { 
        $i_h = $icon ? '<i class="fa '.$icon.'" style="margin-right:5px; color:#3498db;"></i> ' : '';
        $align = 'right'; 
        if (strpos($extr, 'align-left') !== false || strpos($legd, 'align-left') !== false) $align = 'left';
        if (strpos($extr, 'align-center') !== false || strpos($legd, 'align-center') !== false) $align = 'center';
        $clean_legd = str_replace(array('align-left', 'align-center'), '', $legd);
        $centering_css = ($align == 'center') ? 'margin-left:auto; margin-right:auto; float:none; display:table;' : '';
        $align_css = 'text-align: ' . $align . ';';
        if ($align == 'center') { $centering_css = 'margin-left:auto; margin-right:auto; float:none; display:table;'; } else { $centering_css = ''; }
        
        $h .= '<legend'.$legend_hint_attr.' style="font-size:0.85rem; padding:0 5px; color:#666; margin:0; ' . $align_css . $centering_css . $clean_legd . '">' . $i_h . $translated_label . '</legend>';
    }
    
    $p_a = ($plho !== '') ? " placeholder='".htmlspecialchars(lang($plho), ENT_QUOTES)."'" : '';
    $s = 'width:100%; border:none; outline:none; background:transparent; font-family:inherit; font-size:1.1rem; margin:0; display:block;';
    if ($type == 'view' || $type == 'raw') { 
        $h .= '<div style="'.$s.' font-weight:bold; min-height:1.2em; color:#333;">'.($valu ? $valu : '&nbsp;').'</div>'; 
    } elseif ($type == 'sele' && is_array($opti)) {
        $h .= '<select name="'.$name.'" id="'.$unique_id.'" style="'.$s.'" '.str_replace(array('align-left', 'align-center'), '', $extr).'>';
        foreach ($opti as $k => $v) {
            $h .= '<option value="'.$k.'" '.($valu == $k ? 'selected' : '').'>'.$v.'</option>';
        }
        $h .= '</select>';
    } elseif ($type == 'textarea') { 
        $h .= '<textarea name="'.$name.'" id="'.$unique_id.'" '.$p_a.' style="'.$s.' min-height:3em; resize:vertical; line-height:1.5;" '.str_replace(array('align-left', 'align-center'), '', $extr).'>'; 
        $h .= htmlspecialchars((string)$valu).'</textarea>'; 
    } else { 
        $h .= '<input type="'.$type.'" name="'.$name.'" id="'.$unique_id.'" '.$p_a.' style="'.$s.'" value="'.htmlspecialchars((string)$valu).'" '.str_replace(array('align-left', 'align-center'), '', $extr).'>'; 
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
    
    echo '<div style="max-width:'.$w.'; margin: 20px auto; padding: 0 5px;"><div style="background:#fff; padding:25px; border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #3498db; margin-bottom:15px; padding-bottom:10px;"><h2 style="margin:0; color:#2c3e50;">'.lang($capt).'</h2><div>'.$tool.'</div></div>';
    if($info) echo '<div style="margin-bottom:15px; padding:10px; background:#f8f9fa; border-radius:4px; font-size:0.9em; color:#555;">'.$info.'</div>';
    
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
    $button_colors = array('primary'=>'#3498db', 'success'=>'#2ecc71', 'danger'=>'#e74c3c', 'secondary'=>'#95a5a6', 'info'=>'#34495e');
    $bg = isset($button_colors[$type]) ? $button_colors[$type] : $button_colors['primary']; 
    
    $s = "display:inline-block; text-align:center; background-color:$bg; color:#fff; padding:8px 16px; border-radius:4px; text-decoration:none; border:none; cursor:pointer; font-size:14px; font-weight:600;";
    
    $l = ($labl != '') ? " ".lang($labl) : ''; 
    $i_h = ($icon != '') ? "<i class='fa-solid $icon'></i>" : "";
    $b = ($link != '') ? "<a href='$link' style='$s$styl' $attr>$i_h$l</a>" : "<button type='submit' style='$s$styl' $attr>$i_h$l</button>";
    if (!$echo) return $b; echo $b;
}

# TABLE
function htm_Table($head, $data, $name='tbl', $limt=25, $html='', $echo=true, $cols=array()) {
    if (!$echo) ob_start();
    echo '<style>
        #'.$name.' input { text-align: inherit !important; background: transparent; border: none; }
        #'.$name.' tr:nth-child(even){background:#f9f9f9;} 
        #'.$name.' tr:hover{background:#f1f7fd;} 
        .sort-ptr { cursor:pointer; }
        #'.$name.' td { padding: 6px 8px; border-bottom: 1px solid #eee; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #'.$name.' th { padding: 8px 10px; border-bottom: 2px solid #dee2e6; text-align: left; background: #f8f9fa; position: sticky; top: 0; z-index: 5; }
        #line_tbl input[readonly] { background-color: #f0f0f0 !important; cursor: not-allowed; color: #666; }
    </style>';

    echo '<div style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">';
    echo '  <div style="position:relative; width:160px;">';
    echo '    <i class="fa fa-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#95a5a6; font-size:0.8rem;"></i>';
    echo '    <input type="text" id="'.$name.'_search" onkeyup="filterTable(\''.$name.'\')" placeholder="'.lang('@Search').'..." style="padding:6px 6px 6px 28px; border:1px solid #ddd; border-radius:4px; width:100%; font-size:0.85rem;">';
    echo '  </div>';
    echo '  <div style="display:flex; gap:5px; align-items:center;">'.$html.'</div>';
    echo '</div>';

    echo '<div style="max-height:600px; overflow-y:auto; overflow-x:auto; -webkit-overflow-scrolling:touch; margin-bottom: 60px; border:1px solid #eee; border-radius:8px;">';
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
            
            // Genkend udelukkende handlingskolonnen ud fra de engelske termer eller HTML
            $is_action = ($labl === '' || $idx === 'actions' || strpos(strtolower($labl), 'action') !== false || strpos($labl, '<') !== false);
            
            // Oversæt via det eksisterende sprogmodul
            $display_label = (strpos($labl, '<') !== false) ? $labl : lang($labl);
            
            // Hvis det er en handlingskolonne, og den er tom eller blot returnerer den engelske frase uændret
            if ($is_action && (trim($display_label) === '' || strtolower(trim($display_label)) === 'action' || strtolower(trim($display_label)) === '@action')) {
                // Sender den engelske frase til lang(), så systemet selv oversætter til "Handlinger" på dansk
                $display_label = lang('@Actions');
            }
            
            // Hvis det er en handlingskolonne, og den er tom eller blot returnerer den engelske frase uændret
            if ($is_action && (trim($display_label) === '' || strtolower(trim($display_label)) === 'action' || strtolower(trim($display_label)) === '@action')) {
                $display_label = 'Handling';
            }
            if ($is_action) {
                echo '<th style="'.$align.'">'.$display_label.'</th>';
            } else {
                $click_attr = 'onclick="sortTable(\''.$name.'\', '.$i.')"';
                $flex_style = $is_right ? 'display: inline-flex; flex-direction: row; align-items: center; gap: 5px; justify-content: flex-end; width: 100%;' : 'display: inline-flex; align-items: center; gap: 5px;';
                
                echo '<th class="sort-ptr" '.$click_attr.' style="'.$align.'">';
                echo '<span style="'.$flex_style.'">'.$display_label.' <i class="fa fa-sort" style="font-size:0.7rem; color:#ccc; flex-shrink: 0;"></i></span>';
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
function htm_SectionStart($icon, $labl, $open=false, $colr='#0d6efd', $echo=true) {
    if (!$echo) ob_start();
    $s = $open ? 'open' : '';
    echo '<details '.$s.' style="margin-bottom:20px; border:1px solid #ddd; border-radius:8px;">';
    echo '<summary style="padding:14px 20px; background:#f8f9fa; cursor:pointer; font-weight:bold; display:flex; align-items:center;"><i class="fa-solid '.$icon.'" style="font-size:1.4em; width:35px; color:'.$colr.';"></i>'.lang($labl).'</summary><div style="padding:20px;">';
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
    $bg = ($type == 'error') ? "#f8d7da" : "#d4edda"; $c = ($type == 'error') ? "#721c24" : "#155724";
    echo '<div style="background:'.$bg.'; color:'.$c.'; padding:15px; margin:10px auto; max-width:'.$width.'px; border-radius:4px; border:1px solid;">'.lang($text).'</div>';
    if (!$echo) return ob_get_clean();
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

function htm_GetDocPath($filename) {
    if (!$filename) return false;
    $clean_name = basename($filename); $paths = array('uploads/', 'expenses/', 'bilag/');
    foreach ($paths as $p) { if (file_exists($p . $clean_name)) { return $p . $clean_name; } }
    return false;
}

function htm_GetDocIcon($filename, $type = 'other') {
    $path = htm_GetDocPath($filename); if (!$path) return '---';
    $icons = array(
        'expense' => array('icon' => 'fa-file-invoice-dollar', 'color' => '#e74c3c'),
        'revenue' => array('icon' => 'fa-file-invoice',        'color' => '#27ae60'),
        'bank'    => array('icon' => 'fa-university',          'color' => '#3498db'),
        'other'   => array('icon' => 'fa-paperclip',           'color' => '#95a5a6')
    );
    if (strpos($filename, 'EXP_') === 0) $type = 'expense';
    $cfg = isset($icons[$type]) ? $icons[$type] : $icons['other'];
    return '<a href="'.$path.'" target="_blank" style="color:'.$cfg['color'].'; font-size:1.1em;" data-hint="'.htmlspecialchars($filename).'"><i class="fa-solid '.$cfg['icon'].'"></i></a>';
}
?>