<?php # /inc/php2htm.lib.php v:0.9.1 d:2026-05-09 i:evs

# HEADER: Opsætter HTML, CSS og Meta-tags
function htm_Header($capt = 'Tiny Cash', $mwidth = 1600, $echo = true) {
    if (!$echo) ob_start();
    echo '<!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><title>'.lang($capt).'</title>
    <link rel="manifest" href="manifest.json"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.2.3/css/flag-icons.min.css" />
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
    [data-hint]::after, [data-hint]::before { content: none !important; display: none !important; }
    [data-hint] { cursor: help !important; }
    </style>
    <script>
    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                console.log("Error: " + err.message);
            });
        } else {
            if (document.exitFullscreen) document.exitFullscreen();
        }
    }
    </script></head><body>';
    if (!$echo) return ob_get_clean();
}

# FOOTER: Lukker dokumentet og kører JavaScript
function htm_Footer($echo = true) {
    if (!$echo) ob_start();
    echo '<div id="tc-hint" style="position:fixed; display:none; background:#2c3e50; color:white; 
          padding:8px 15px; border-radius:4px; border-left:4px solid #f1c40f; z-index:2147483647; 
          pointer-events:none; font-size:13px; max-width:300px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
          line-height:1.4; white-space:pre-wrap; font-family:sans-serif;"></div>
    <script>
    (function() {
        const hb = document.getElementById("tc-hint");
        document.addEventListener("mouseover", function(e) {
            const t = e.target.closest("[data-hint]");
            if (t && hb) {
                hb.innerHTML = t.getAttribute("data-hint");
                hb.style.display = "block";
            }
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
    function clearSearch(id) { const i = document.getElementById(id + "_search"); if(i){
        i.value = ""; filterTable(id); i.focus(); } 
    }
    </script></body></html>';
    if (!$echo) return ob_get_clean();
}

# V2 NAVIGATION
function htm_V2_Nav($inv_id=0) {
    echo '
    <div style="position:fixed; bottom:0; left:0; right:0; background:#2c3e50; 
        padding:10px; display:flex; justify-content:center; gap:20px; z-index:9999; 
        border-top:3px solid #3498db;">
        <span style="color:#fff; align-self:center; font-weight:bold; border-right:1px solid #555; 
            padding-right:15px; font-family:sans-serif;">V2 COCKPIT</span>
        <a href="invoice_list.php" class="nav-main-link" style="min-width:auto; 
            padding:5px 15px; background:#34495e; border-radius:4px; color:white; 
            text-decoration:none; font-family:sans-serif; font-size:14px;">'.
            lang('@Oversigt').'</a>';
    if ($inv_id > 0) {
        echo '<a href="invoice_edit.php?id='.$inv_id.'" class="nav-main-link" style="min-width:auto; 
              padding:5px 15px; color:white; text-decoration:none; font-family:sans-serif; font-size:14px;">'. 
              lang('@Rediger').'</a>';
        echo '<a href="invoice_view.php?id='.$inv_id.'" class="nav-main-link" style="min-width:auto; 
              padding:5px 15px; color:white; text-decoration:none; font-family:sans-serif; font-size:14px;">'. 
              lang('@Vis/Print').'</a>';
    }
    echo '<a href="customer_list.php" class="nav-main-link" style="min-width:auto; background:#27ae60; 
          padding:5px 15px; border-radius:4px; color:white; text-decoration:none; font-family:sans-serif; font-size:14px;">'. 
          lang('@Ny Faktura').'</a>
    </div>
    <div style="height:70px;"></div>';
}

# INPUT GROUP
function htm_InputGroup($icon, $labl, $name, $valu='', $type='text', $opti=null, $extr='', $wdth='100%', $hint='', $plho='', $echo=true) {
    if (!$echo) ob_start();
    $clean_hint = ($hint > '') ? str_replace(['<br>', '<br />', '<br/>'], "\n", lang($hint)) : '';
    $h_attr = ($clean_hint > '') ? ' data-hint="'.htmlspecialchars($clean_hint).'"' : '';
    $h = '<div'.$h_attr.' style="display:inline-block;width:'.$wdth.';vertical-align:top;padding:0;margin:0;box-sizing:border-box;">';
    $h .= '<fieldset style="border-radius:8px;margin:2px;border:1px solid #ddd;padding:5px 10px;">';
    if ($labl) { 
        $i_h = $icon ? '<i class="fa '.$icon.'" style="margin-right:5px;color:#3498db;"></i> ' : '';
        $a = (strpos($extr, 'align-left') !== false) ? 'left' : 'right'; 
        $c_e = str_replace('align-left', '', $extr);
        $h .= '<legend align="'.$a.'" style="font-size:0.85rem;padding:0 5px;color:#666;margin:0;">'.$i_h.lang($labl).'</legend>';
    } else { $c_e = $extr; }
    $p_a = ($plho !== '') ? " placeholder='".htmlspecialchars(lang($plho), ENT_QUOTES)."'" : '';
    $s = 'width:100%;border:none;outline:none;background:transparent;font-family:inherit;font-size:1.1rem;margin:0;display:block;';
    if ($type == 'view' || $type == 'raw') { $h .= '<div style="'.$s.'font-weight:bold;min-height:1.2em;color:#333;">'.($valu ?: '&nbsp;').'</div>'; 
    } elseif ($type == 'sele' && is_array($opti)) {
        $h .= '<select name="'.$name.'" id="'.$name.'" style="'.$s.'" '.$c_e.'>';
        foreach ($opti as $k => $v) $h .= '<option value="'.$k.'" '.($valu == $k ? 'selected' : '').'>'.$v.'</option>';
        $h .= '</select>';
    } elseif ($type == 'textarea') { $h .= '<textarea name="'.$name.'" id="'.$name.'" '.$p_a.' rows="2" 
                style="'.$s.'line-height:1.4;resize:vertical;" '.$c_e.'>'.htmlspecialchars((string)$valu).'</textarea>'; 
    } else { $h .= '<input type="'.$type.'" name="'.$name.'" id="'.$name.'" '.$p_a.' style="'.$s.'" value="'.htmlspecialchars((string)$valu).'" '.$c_e.'>'; }
    $h .= '</fieldset></div>';
    if ($echo) { echo $h; } else { return $h; }
}

# SHELL START: Åbner en container (div eller span)
function htm_Shell_($styl='margin:0 auto; padding:10px;', $type='div', $echo = true) {
    static $stack = [];     // Statisk variabel holder styr på rækkefølgen af åbnede tags
    // Hvis vi bare kalder funktionen for at få fat i stacken (bruges af htm_Shell_end)
    if ($styl === 'GET_STACK') return $stack;
    if ($styl === 'POP_STACK') { array_pop($stack); return; }
    $stack[] = $type;       // Gem det aktuelle tag-type i hukommelsen
    $htm = '<' . $type . ' style="' . $styl . '">';
    if ($echo) { echo $htm; } else { return $htm; }
}

# SHELL END: Detekterer selv hvilket tag der skal lukkes
function htm_Shell_end($echo = true) {
    $stack = htm_Shell_('GET_STACK');   // Hent stacken fra den første funktion
    if (empty($stack)) return '';       // Intet at lukke
    $last_tag = end($stack);            // Tag det sidste element (tag type) og fjern det fra stacken
    htm_Shell_('POP_STACK');
    $htm = '</' . $last_tag . '>';
    if ($echo) { echo $htm; } else { return $htm; }
}

# CARD: Container-boks
function htm_Card_($capt, $wdth='600', $info='', $form=false, $echo = true, $tool = '') {
    static $form_is_open = false; if ($form === 'CHECK_STATE') return $form_is_open;
    if ($form === 'RESET_STATE') { $form_is_open = false; return; }
    if (!$echo) ob_start();
    $w = is_numeric($wdth) ? $wdth.'px' : $wdth;
    if ($form) { $form_is_open = true; $n = is_string($form) ? " name='$form' id='$form'" : ""; echo "<form method='post' $n style='margin:0;'>"; }
    echo '<div style="max-width:'.$w.'; margin: 20px auto; padding: 0 5px;">
            <div style="background:#fff; padding:25px; border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #3498db; margin-bottom:15px; padding-bottom:10px;">
            <h2 style="margin:0; color:#2c3e50;">'.lang($capt).'</h2>
            <div>'.$tool.'</div>
          </div>';
    if($info) echo '<div style="margin-bottom:15px; padding:10px; background:#f8f9fa; border-radius:4px; 
                    font-size:0.9em; color:#555;">'.$info.'</div>';
    if (!$echo) return ob_get_clean();
}

function htm_Card_end($echo = true) {
    if (!$echo) ob_start();
    if (htm_Card_('', '', '', 'CHECK_STATE')) { echo '</div></div></form>'; htm_Card_('', '', '', 'RESET_STATE'); } else { echo '</div></div>'; }
    if (!$echo) return ob_get_clean();
}

# BUTTON
function htm_Button($icon='', $labl='', $type='primary', $link='', $styl='', $attr='', $cont='', $echo=true) {
    $cl = ['primary'=>'#3498db', 'success'=>'#2ecc71', 'danger'=>'#e74c3c', 'secondary'=>'#95a5a6', 'info'=>'#34495e'];
    $bg = $cl[$type] ?? $cl['primary']; 
    $s = "display:inline-block; background-color:$bg; color:#fff; padding:8px 16px; border-radius:4px; text-decoration:none; border:none; cursor:pointer; font-size:14px; font-weight:600;";
    $l = ($labl != '') ? " ".lang($labl) : ''; 
    $i_h = ($icon != '') ? "<i class='fa-solid $icon'></i>" : "";
    $b = ($link != '') ? "<a href='$link' style='$s$styl' $attr>$i_h$l</a>" : "<button type='submit' style='$s$styl' $attr>$i_h$l</button>";
    if (!$echo) return $b; echo $b;
}

# TABLE
function htm_Table($head, $data, $name='tbl', $limt=25, $html='', $echo=true) {
    if (!$echo) ob_start();
    echo '<style>#'.$name.' tr:nth-child(even){background:#f9f9f9;} #'.$name.' tr:hover{background:#f1f7fd;} .sort-ptr { cursor:pointer; }</style>';
    echo '<div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:nowrap;">';
    echo '<div style="display:flex; gap:6px; align-items:center;">';
    echo '<div style="position:relative; width:160px;">';
    echo '<i class="fa fa-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#95a5a6; font-size:0.8rem;"></i>';
    echo '<input type="text" id="'.$name.'_search" onkeyup="filterTable(\''.$name.'\')" placeholder="'.lang('@Search').'..." data-hint="'.lang('@Search in all columns').'" style="padding:6px 6px 6px 28px; border:1px solid #ddd; border-radius:4px; width:100%; font-size:0.85rem;">';
    echo '</div>';
    echo '</div>';
    echo '<div style="display:flex; gap:5px; align-items:center;">'.$html.'</div>';
    echo '</div>';
    echo '<div style="max-height:600px; overflow-y:auto; border:1px solid #eee; border-radius:8px;">';
    echo '<table id="'.$name.'" style="width:100%; border-collapse:separate;">';
    echo '<thead style="position:sticky; top:0; background:#f8f9fa; z-index:5;"><tr>';
    $colIdx = 0;
    foreach ($head as $labl) {
        echo '<th class="sort-ptr" onclick="sortTable(\''.$name.'\', '.$colIdx.')" style="padding:12px 10px; border-bottom:2px solid #dee2e6; text-align:left;">'.lang($labl).' <i class="fa fa-sort" style="font-size:0.7rem; color:#ccc;"></i></th>';
        $colIdx++;
    } 
    echo '</tr></thead><tbody>';
    foreach ($data as $rowv) {
        echo '<tr>';
        foreach ($rowv as $valu) echo '<td style="padding:12px 10px; border-bottom:1px solid #eee;">'.$valu.'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<script>';
    echo 'function sortTable(tableId, n) {
    var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
    table = document.getElementById(tableId);
    switching = true;
    dir = "asc"; 
    while (switching) {
        switching = false;
        rows = table.rows;
        for (i = 1; i < (rows.length - 1); i++) {
            shouldSwitch = false;
            x = rows[i].getElementsByTagName("TD")[n];
            y = rows[i + 1].getElementsByTagName("TD")[n];
            // Håndtering af både tal og tekst
            var xVal = x.innerText.toLowerCase();
            var yVal = y.innerText.toLowerCase();
            if (dir == "asc") {
                if (xVal > yVal) { shouldSwitch = true; break; }
            } else if (dir == "desc") {
                if (xVal < yVal) { shouldSwitch = true; break; }
            }
        }
        if (shouldSwitch) {
            rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
            switching = true;
            switchcount ++;      
        } else {
            if (switchcount == 0 && dir == "asc") {
                dir = "desc";
                switching = true;
            }
        }
    }
}';
    echo '</script>';
    
    if (!$echo) return ob_get_clean();
}



# SECTION / ALERTS / LANG
function htm_SectionStart($icon, $labl, $open=false, $colr='#0d6efd', $echo=true) {
    if (!$echo) ob_start();
    $s = $open ? 'open' : '';
    echo '<details '.$s.' style="margin-bottom:20px; border:1px solid #ddd; border-radius:8px;">';
    echo '<summary style="padding:14px 20px; background:#f8f9fa; cursor:pointer; font-weight:bold; display:flex; 
    align-items:center;"><i class="fa-solid '.$icon.'" style="font-size:1.4em; width:35px; color:'.$colr.';"></i>'.
    lang($labl).'</summary><div style="padding:20px;">';
    if (!$echo) return ob_get_clean();
}
function htm_SectionEnd($echo=true) { if ($echo) echo '</div></details>'; return '</div></details>'; }

function htm_Alert($text, $type='success', $width=700, $echo=true) {
    if (empty($text)) return ""; 
    if (!$echo) ob_start();
    $bg = ($type == 'error') ? "#f8d7da" : "#d4edda";
    $c = ($type == 'error') ? "#721c24" : "#155724";
    echo '<div style="background:'.$bg.'; color:'.$c.'; padding:15px; margin:10px auto; 
          max-width:{'.$width.'}px; border-radius:4px; border:1px solid;">'.lang($text).'</div>';
    if (!$echo) return ob_get_clean();
}

if (!function_exists('lang')) {
    function lang($key) {
        $cl = $_SESSION['lang'] ?? 'da'; static $tr = null;
        if ($tr === null) {
            $f = dirname(__DIR__) . '/json-data/languages.json';
            if (file_exists($f)) {
                $d = json_decode(file_get_contents($f), true);
                if ($d && isset($d['language'])) { foreach ($d['language'] as $l) { if ($l['code'] === $cl) { $tr = $l['translation']; break; } } }
            }
        }
        return (!isset($tr[$key]) || $tr[$key] === "") ? ltrim($key, '@') : $tr[$key];
    }
}
function htm_nl($rept=1) {
    $html= str_repeat('<br />',$rept);
    echo $html;
    }
?>