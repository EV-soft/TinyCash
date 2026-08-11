<?php # /inc/php2htm.lib.php v:1.2.0 d:2026-08-11 i:evs 
# Inkluder de centraliserede hjælpemoduler.
# core_utils.lib.php rummer lang() og andre ikke-HTML-byggende funktioner
# (clean_address_text, get_tc_doc, resolve_doc_path). Skal inkluderes FØR
# alt herunder, da stort set alle htm_*-funktioner kalder lang().
include_once __DIR__ . '/help.lib.php';
include_once __DIR__ . '/core_utils.lib.php';
include_once __DIR__ . '/htm_page.lib.php'; # HEADER & FOOTER

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
    $is_bare = (strpos($extr, 'bare') !== false || strpos($legd, 'bare') !== false);
    if ($is_bare && $type === 'sele' && is_array($opti)) {
        $bare_style = '';
        $clean_extr = $extr;
        if (preg_match('/style=["\']([^"\']+)["\']/', $extr, $style_match)) {
            $bare_style = $style_match[1];
            $clean_extr = preg_replace('/style=["\']([^"\']+)["\']/', '', $extr);
        }
        $clean_extr = trim(str_replace('bare', '', $clean_extr));
        $h = '<select name="'.$name.'" id="'.$name.'" style="'.$bare_style.'" '.$clean_extr.'>';
        foreach ($opti as $k => $v) {
            $sel = ((string)$valu === (string)$k) ? 'selected' : '';
            $h .= '<option value="'.htmlspecialchars((string)$k).'" '.$sel.'>'.htmlspecialchars((string)$v).'</option>';
        }
        $h .= '</select>';
        if (!$echo) return $h;
        echo $h;
        return;
    }

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

    if (strpos(' '.$extr, 'dash') > 0) { $bord = 'border: 2px dashed blue;'; } 
    elseif (strpos(' '.$extr, 'required') > 0) { $bord = 'border: 2px solid orange;'; } 
    else { $bord = ''; }
    
    $h = '<div'.$outer_hint_attr.' style="display:inline-block; width:'.$wdth.'; ...">';
    $h .= '<fieldset'.$outer_hint_attr.' style="border-radius:8px; margin:2px; border:1px solid var(--border-fieldset); background-color: var(--bg-card); '.$bord.' padding:5px 10px;">';

    if ($labl) { 
        $i_h = $icon ? '<i class="fa '.$icon.'" style="margin-right:5px; color: var(--color-primary);"></i> ' : '';

        $align = 'right'; 
        if (strpos($extr, 'leg:left') !== false || strpos($legd, 'leg:left') !== false) {
            $align = 'left';
        } elseif (strpos($extr, 'leg:center') !== false || strpos($legd, 'leg:center') !== false) {
            $align = 'center';
        } elseif (strpos($extr, 'leg:right') !== false || strpos($legd, 'leg:right') !== false) {
            $align = 'right';
        } 
        else {
            if (strpos($extr, 'align-left') !== false || strpos($legd, 'align-left') !== false) $align = 'left';
            if (strpos($extr, 'align-center') !== false || strpos($legd, 'align-center') !== false) $align = 'center';
        }

        $clean_legd = str_replace(array('align-left', 'align-center'), '', $legd);

        $centering_css = ($align == 'center') ? 'margin-left:auto; margin-right:auto; float:none; display:table;' : '';
        $align_css = 'text-align: ' . $align . ';';

        $h .= '<legend'.$legend_hint_attr.' style="font-size:0.85rem; padding:0 5px; color: var(--text-muted); margin:0; '. $align_css . $centering_css . $clean_legd . '">' . $i_h . $translated_label . '</legend>';
    }

    $p_a = ($plho !== '') ? " placeholder='".htmlspecialchars(lang($plho), ENT_QUOTES)."'" : '';

    // RETTET: border:none skal have !important her, ellers overtrumfes den af
    // den globale "input, select, textarea { border: 1px solid var(--border-color)
    // !important; }"-regel i htm_page.lib.php (htm_Header()) - uden dette
    // vises der en synlig kant BÅDE på selve feltet og på fieldset'et udenom
    // (dobbelt-ramme). CSS-reglen er: en !important-stylesheet-regel vinder
    // over en inline style UDEN !important, selvom inline normalt har højere
    // specificitet.
    $s = 'width:100%; border:none !important; outline:none; background:transparent; font-family:inherit; font-size:1.1rem; margin:0; display:block; color:inherit;';

    if (preg_match('/style=["\']([^"\']+)["\']/', $extr, $style_match)) {
        $s .= ' ' . rtrim($style_match[1], ';') . ';';
        $extr = preg_replace('/style=["\']([^"\']+)["\']/', '', $extr);
    }

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
} # htm_InputGroup()


# SELECT (bare dropdown) - tynd wrapper omkring htm_InputGroup's bare-tilstand
function htm_Select($name, $options, $selected = '', $styl = '', $attrs = '', $echo = true) {
    $extr = 'bare style="'.$styl.'" '.$attrs;
    return htm_InputGroup('', '', $name, $selected, 'sele', $options, $extr, '100%', '', '', '', $echo);
}


# Indsæt denne funktion i /inc/php2htm.lib.php
# Placering: efter htm_Select() — ca. linje 48

# PROJEKT CODE FELT
# Vises kun hvis setting 'module_projects' = 1.
# Returnerer stille uden output hvis modulet er deaktiveret.
#
# Brug:   htm_ProjektCodeField($conn, $item['proj_id'] ?? null);
# Eller:  htm_ProjektCodeField($conn, $item['proj_id'] ?? null, '50%');
#
function htm_ProjektCodeField($conn, $selected_proj_id = null, $wdth = '100%'): void {
    // Tjek om modulet er aktivt
    $s = get_settings($conn);
    if (empty($s['module_projects']) || $s['module_projects'] != '1') return;

    // Byg options-array
    $opts = ['' => lang('@No project')];
    $res = DB::query($conn,
        "SELECT proj_id, proj_no, cust_id FROM projects WHERE is_active = 1 ORDER BY proj_no ASC"
    );
    if ($res) {
        while ($r = DB::fetch_assoc($res)) {
            $opts[$r['proj_id']] = htmlspecialchars($r['proj_no']);
        }
    }

    htm_InputGroup(
        icon: 'fa-folder-open',
        labl: '@Project Code',
        name: 'proj_id',
        valu: $selected_proj_id ?? '',
        type: 'sele',
        opti: $opts,
        wdth: $wdth,
        hint: '@Assign this entry to a project',
        extr: 'dash'
    );
}


function htm_Wrap($content, $tag = 'div', $style = '', $echo = true) {
    $htm = '<'. $tag. ($style !== '' ? ' style="'. $style. '"' : ''). '>'. $content. '</'. $tag. '>';
    if ($echo) echo $htm; else return $htm;
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


# php2htm.lib.php — htm_Table() v:1.3.1 d:2026-08-02 i:claude
# Nyt: $vhgh (synlig højde), skjulte kolonner (hidd-præfiks i $head), CSV-eksport ($expo)

function htm_Table($head, $data, $name='tbl', $limt=25, $html='', $echo=true, $cols=array(),
                   $vhgh='500px', $expo='') {
    /*
     * $head  — associativt array: ['Label' => ...] eller numerisk ['Label', ...]
     *          Nøgle der starter med 'hidd:' skjuler kolonnen i visningen men
     *          inkluderer data-værdien som data-attribut på <tr> (til brug i JS/links).
     *          Eks: ['hidd:id', 'Navn', 'Beløb']
     *
     * $vhgh  — max synlig højde af tabel-scrollvinduet, fx '400px' eller '80vh'
     *
     * $expo  — filnavn til CSV-download-knap, fx 'fakturaliste.csv'.
     *          Tom streng = ingen eksport-knap.
     */
    if (!$echo) ob_start();

    // Byg liste over skjulte kolonner (indeks i $head-arrayet)
    $head_keys  = array_keys($head);
    $head_clean = [];   // Label til visning
    $head_hidd  = [];   // bool: er kolonnen skjult?
    foreach ($head as $k => $labl) {
        if (strpos((string)$k, 'hidd:') === 0 || strpos($labl, 'hidd:') === 0) {
            $head_clean[] = ltrim($labl, 'hidd:');
            $head_hidd[]  = true;
        } else {
            $head_clean[] = $labl;
            $head_hidd[]  = false;
        }
    }

    echo '<style>
        #'.$name.' input { text-align:inherit !important; background:transparent; border:none; color:inherit; }
        #'.$name.' tr:nth-child(even) { background:var(--bg-table-even); }
        #'.$name.' tr:hover { background:var(--bg-table-hover); }
        .sort-ptr { cursor:pointer; }
        #'.$name.' td { padding:6px 8px; border-bottom:1px solid var(--border-subtle); color:var(--text-main); }
        #'.$name.' td.truncate { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:pointer; max-width:0; }
        #'.$name.' td.expanded { white-space:normal !important; word-break:break-word; cursor:pointer; }
        #'.$name.' th { padding:8px 10px; border-bottom:2px solid var(--border-color); text-align:left;
            background:var(--bg-panel); color:var(--text-main);
            position:sticky; top:0; z-index:10;
            box-shadow:0 2px 0 var(--border-color); }
        #'.$name.' .col-hidd { display:none; }
        #line_tbl input[readonly] { background-color:var(--bg-main) !important; cursor:not-allowed; color:var(--text-muted); }
    </style>';

    // ── Søgefelt + eksport-knap + ekstra HTML ────────────────────────────────
    echo '<div style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">';
    echo '  <div style="position:relative; width:160px;">';
    echo '    <i class="fa fa-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:var(--color-secondary); font-size:0.8rem;"></i>';
    echo '    <input type="text" id="'.$name.'_search" onkeyup="filterTable(\''.$name.'\')" placeholder="'.lang('@Search').'..."
               style="padding:6px 6px 6px 28px; border:1px solid var(--border-color); border-radius:4px; width:100%;
               font-size:0.85rem; background:var(--bg-card); color:inherit;">';
    echo '  </div>';
    echo '  <div style="display:flex; gap:8px; align-items:center;">';
    if ($expo !== '') {
        $safeExpo = htmlspecialchars($expo);
        $safeName = htmlspecialchars($name);
        echo '    <button onclick="exportCsv(\''.$safeName.'\',\''.$safeExpo.'\')"
                    style="background:var(--color-success); color:white; border:none; padding:5px 12px;
                    border-radius:4px; cursor:pointer; font-size:13px; display:flex; align-items:center; gap:6px;"
                    title="'.lang('@Download table as CSV file').'">
                    <i class="fa fa-download"></i> '.lang('@CSV').'
                  </button>';
    }
    echo '    '.$html;
    echo '  </div>';
    echo '</div>';

    // ── Scroll-wrapper: ingen border-radius her — bryder sticky ──────────────
    echo '<div style="margin-bottom:60px; max-height:'.$vhgh.'; overflow:auto;">';
    echo '<table id="'.$name.'" style="width:100%; border-collapse:separate; border-spacing:0;
        table-layout:auto; border:1px solid var(--border-subtle); border-radius:8px;">';

    // ── Colgroup ─────────────────────────────────────────────────────────────
    if (!empty($cols)) {
        echo '<colgroup>';
        foreach ($cols as $ci => $style) {
            if (!empty($head_hidd[$ci])) { echo '<col class="col-hidd">'; continue; }
            preg_match('/width:\s?([^;]+)/', $style, $matches);
            $w = isset($matches[1]) ? 'width:'.$matches[1] : (is_numeric($style) ? 'width:'.$style : '');
            echo '<col style="'.$w.'">';
        }
        echo '</colgroup>';
    }

    // ── THEAD ─────────────────────────────────────────────────────────────────
    echo '<thead><tr>';
    $i = 0;
    foreach ($head_clean as $ci => $labl) {
        $hidd = !empty($head_hidd[$ci]);

        $align    = '';
        $is_right = false;
        if (!$hidd) {
            if (isset($cols[$ci]) && strpos($cols[$ci], 'text-align') !== false) {
                $align    = preg_replace('/width:[^;]+;?/', '', $cols[$ci]);
                if (strpos($cols[$ci], 'right') !== false) $is_right = true;
            }
        }

        $is_action     = (!$hidd && ($labl === '' || $ci === 'actions'
                          || strpos(strtolower($labl), 'action') !== false
                          || strpos($labl, '<') !== false));
        $display_label = (!$hidd && strpos($labl, '<') !== false) ? $labl : lang($labl);

        if (!$hidd && $is_action && (trim(strip_tags($display_label)) === ''
                           || strtolower(trim($display_label)) === 'action'
                           || strtolower(trim($display_label)) === '@action')) {
            $display_label = lang('@Actions');
        }

        if ($hidd) {
            echo '<th class="col-hidd"></th>';
        } elseif ($is_action) {
            echo '<th style="'.$align.'">'.$display_label.'</th>';
        } else {
            $flex = $is_right
                ? 'display:inline-flex; flex-direction:row; align-items:center; gap:5px; justify-content:flex-end; width:100%;'
                : 'display:inline-flex; align-items:center; gap:5px;';
            echo '<th class="sort-ptr" onclick="sortTable(\''.$name.'\', '.$i.')" style="'.$align.'">';
            echo '<span style="'.$flex.'">'.$display_label
                .' <i class="fa fa-sort" style="font-size:0.7rem; color:var(--color-secondary); flex-shrink:0;"></i></span>';
            echo '</th>';
            $i++;
        }
    }
    echo '</tr></thead>';

    // ── TBODY ─────────────────────────────────────────────────────────────────
    echo '<tbody>';
    foreach ($data as $rowv) {
        // Saml skjulte kolonner som data-attributter på <tr>
        $data_attrs = '';
        foreach ($head_hidd as $ci => $hidd) {
            if ($hidd) {
                $key = ltrim(array_keys($head)[$ci] ?? '', 'hidd:');
                $val = htmlspecialchars($rowv[$ci] ?? '');
                $data_attrs .= ' data-'.$key.'="'.$val.'"';
            }
        }
        echo '<tr'.$data_attrs.'>';
        foreach ($rowv as $ci => $valu) {
            $hidd  = !empty($head_hidd[$ci]);
            $style = isset($cols[$ci]) ? $cols[$ci] : '';
            if ($hidd) {
                echo '<td class="col-hidd"></td>';
            } else {
                echo '<td style="'.$style.'">'.$valu.'</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    ?>
    <script>
    function sortTable(tableId, n) {
        var table = document.getElementById(tableId); if (!table) return;
        // Find den n'te synlige th (spring skjulte over)
        var ths = Array.from(table.getElementsByTagName("th"))
                       .filter(function(t) { return !t.classList.contains("col-hidd"); });
        var th = ths[n];
        if (!th || !th.classList.contains("sort-ptr")) return;

        // Find kolonneindeks i tabellen (inkl. skjulte)
        var allThs = Array.from(table.getElementsByTagName("th"));
        var colIdx = allThs.indexOf(th);

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
                var p = text.split(/[\s.:]/);
                return new Date(+p[2], +p[1]-1, +p[0], +p[3], +p[4]).getTime();
            }
            if (/^-?[\d.]+(,\d{2})?$/.test(text)) {
                var cleanNum = parseFloat(text.replace(/\./g, "").replace(",", "."));
                if (!isNaN(cleanNum)) return cleanNum;
            }
            return lower;
        }

        while (switching) {
            switching = false; rows = table.rows;
            for (i = 1; i < rows.length - 1; i++) {
                shouldSwitch = false;
                x = rows[i].getElementsByTagName("TD")[colIdx];
                y = rows[i+1].getElementsByTagName("TD")[colIdx];
                if (!x || !y) continue;
                var xVal = getCleanValue(x), yVal = getCleanValue(y);
                if (dir === "asc"  && xVal > yVal) { shouldSwitch = true; break; }
                if (dir === "desc" && xVal < yVal) { shouldSwitch = true; break; }
            }
            if (shouldSwitch) {
                rows[i].parentNode.insertBefore(rows[i+1], rows[i]);
                switching = true; switchcount++;
            } else if (switchcount === 0 && dir === "asc") {
                dir = "desc"; switching = true;
            }
        }
    }

    function toggleCell(cell) {
        if (cell.classList.contains("expanded")) {
            cell.classList.remove("expanded");
            cell.classList.add("truncate");
        } else {
            cell.classList.remove("truncate");
            cell.classList.add("expanded");
        }
    }

    function exportCsv(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var rows = [];

        // Headers (kun synlige)
        var hdrs = Array.from(table.querySelectorAll("thead th"))
                        .filter(function(t) { return !t.classList.contains("col-hidd"); })
                        .map(function(t) { return '"' + t.innerText.trim().replace(/"/g, '""') + '"'; });
        rows.push(hdrs.join(";"));

        // Data-rækker (kun synlige kolonner og rækker)
        Array.from(table.querySelectorAll("tbody tr")).forEach(function(tr) {
            if (tr.style.display === "none") return;
            var cells = Array.from(tr.querySelectorAll("td"))
                             .filter(function(td) { return !td.classList.contains("col-hidd"); })
                             .map(function(td) { return '"' + td.innerText.trim().replace(/"/g, '""') + '"'; });
            rows.push(cells.join(";"));
        });

        var bom    = "\uFEFF"; // BOM så Excel åbner UTF-8 korrekt
        var blob   = new Blob([bom + rows.join("\n")], { type: "text/csv;charset=utf-8;" });
        var url    = URL.createObjectURL(blob);
        var a      = document.createElement("a");
        a.href     = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }
    </script>
    <?php
    if (!$echo) return ob_get_clean();
}




# SECTION START
function htm_SectionStart($icon, $labl, $open=false, $colr='var(--color-primary)', $echo=true, $tool='', $show_toggle=false) {
    if (!$echo) ob_start();
    $s = $open ? 'open' : '';
    $indicator = $open ? 'fa-chevron-down' : 'fa-chevron-right';
    // Hvis show_toggle er aktiveret, indsættes knapperne automatisk i tool-delen
    if ($show_toggle) {
        $toggle_btns = '<button type="button" onclick="toggleAllSections(true)" title="' . lang('@Expand All') . '" style="background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1em; padding:0;"><i class="fa fa-angle-double-down"></i></button>'
                     . '<button type="button" onclick="toggleAllSections(false)" title="' . lang('@Collapse All') . '" style="background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1em; padding:0;"><i class="fa fa-angle-double-up"></i></button>';
        $tool = $tool !== '' ? $tool . ' ' . $toggle_btns : $toggle_btns;
    }
    echo '<details '.$s.' ontoggle="this.querySelector(\'.section-arrow\').classList.toggle(\'fa-chevron-right\'); this.querySelector(\'.section-arrow\').classList.toggle(\'fa-chevron-down\');" style="margin-bottom:20px; border:2px solid var(--border-color); border-radius:8px; background: var(--bg-card);">';
    echo '<summary style="padding:14px 20px; background: var(--bg-panel); color: var(--text-main); cursor:pointer; font-weight:bold; display:flex; align-items:center; list-style:none;">';
    echo '<i class="fa-solid '.$icon.'" style="font-size:1.4em; width:35px; color:'.$colr.';"></i>';
    echo '<span style="flex-grow:1;">'.lang($labl).'</span>';
    if ($tool !== '') {
        echo '<div style="margin-left:auto; margin-right:10px; display:flex; gap:8px; align-items:center;" onclick="event.stopPropagation();">'.$tool.'</div>';
    }
    echo '<i class="fa-solid '.$indicator.' section-arrow" style="font-size:0.9em; color:var(--text-muted); transition:transform 0.2s;"></i>';
    echo '</summary><div style="padding:20px;">';
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
    $bg = ($type == 'error') ? "var(--bg-alert-error, #f8d7da)" : "var(--bg-alert-success, #d4edda)"; 
    $c  = ($type == 'error') ? "var(--text-alert-error, #721c24)" : "var(--text-alert-success, #155724)";
    $htm = '<div style="background:'.$bg.'; color:'.$c.'; padding:15px; margin:10px auto; max-width:'.$width.'px; border-radius:4px; border:1px solid '.$c.'50;">' . lang($text) . '</div>';
    if ($echo) { echo $htm; } else { return $htm; }
}

# BADGE
function htm_Badge($text, $type = 'secondary', $echo = true) {
    $light_bg_variants = ['warning'];
    $text_color = in_array($type, $light_bg_variants) ? 'var(--color-dark)' : 'var(--text-light)';

    $htm = '<span style="display:inline-block; padding:3px 10px; border-radius:12px; '
       . 'font-size:11px; font-weight:bold; text-align:center; line-height:1.4; '
       . 'color:'.$text_color.'; background:var(--color-'.$type.', var(--color-secondary));">'
       . lang($text) . '</span>';
    if (!$echo) return $htm;
    echo $htm;
}

# CONFIRM LINK/BUTTON
function htm_ConfirmLink($icon = '', $labl = '', $link = '', $mess = '@Are you sure?', 
                         $type = 'danger', $styl = '', $attr = '', $echo = true) {
    $s = "display:inline-block; text-align:center; background-color: var(--color-$type); "
       . "color: var(--text-light); padding:8px 16px; border-radius:4px; text-decoration:none; "
       . "border:none; cursor:pointer; font-size:14px; font-weight:600;";
    $l   = ($labl != '') ? ' ' . lang($labl) : '';
    $i_h = ($icon != '') ? "<i class='fa-solid $icon'></i>" : '';
    $msg_js = htmlspecialchars(lang($mess), ENT_QUOTES);
    $htm = "<a href='" . htmlspecialchars($link) . "' style='$s$styl' "
       . "onclick=\"return confirm('" . addslashes($msg_js) . "')\" $attr>$i_h$l</a>";
    if (!$echo) return $htm;
    echo $htm;
}

# ACTION BUTTONS
function htm_ActionButtons($acti, $echo = true) {
    $htm = '<div style="display:flex; gap:4px; justify-content:flex-end; align-items:center;">';
    foreach ($acti as $a) {
        $type    = $a['type']    ?? 'secondary';
        $icon    = $a['icon']    ?? '';
        $hint    = $a['hint']    ?? '';
        $label   = $a['label']   ?? '';
        $link    = $a['link']    ?? '';
        $onclick = $a['onclick'] ?? '';
        $confirm = $a['confirm'] ?? '';
        if ($confirm !== '') {
            $msg_js  = addslashes(htmlspecialchars(lang($confirm), ENT_QUOTES));
            $onclick = "return confirm('$msg_js')" . ($onclick !== '' ? ";$onclick" : '');
        }
        $has_label = ($label !== '');
        if ($has_label) {
            $style = "display:inline-flex; align-items:center; justify-content:center; gap:6px; "
                   . "height:28px; padding:0 12px; white-space:nowrap; "
                   . "border-radius:4px; background:var(--color-$type); color:var(--text-light); "
                   . "text-decoration:none; border:none; cursor:pointer; font-size:12px; font-weight:600;";
        } else {
            $style = "display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; "
                   . "border-radius:4px; background:var(--color-$type); color:var(--text-light); "
                   . "text-decoration:none; border:none; cursor:pointer; font-size:12px;";
        }
        $hint_attr = ($hint !== '') ? ' data-hint="' . htmlspecialchars(lang($hint)) . '"' : '';
        $icon_html = $icon !== '' ? "<i class='fa-solid $icon'></i>" : '';
        $label_html = $has_label ? ' ' . lang($label) : '';
        $onclick_attr = $onclick !== '' ? ' onclick="' . $onclick . '"' : '';
        if ($link !== '') {
            $htm .= "<a href='" . htmlspecialchars($link) . "' style='$style'{$onclick_attr}{$hint_attr}>$icon_html$label_html</a>";
        } else {
            $htm .= "<button type='button' style='$style'{$onclick_attr}{$hint_attr}>$icon_html$label_html</button>";
        }
    }
    $htm .= '</div>';
    if (!$echo) return $htm;
    echo $htm;
}

# UTILS (HTML-byggende)
function htm_nl($rept=1) {
    echo str_repeat('<br />', $rept);
}

function htm_GetDocIcon($filename, $type = 'other') {
    // Kalder resolve_doc_path() (tidligere htm_DocPath()) fra core_utils.lib.php
    $path = resolve_doc_path($filename); if (!$path) return '---';
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

function print_arr($arrVar,$titl='',$attr='',$wdth='fit-content',$rtrn=false)
{
    if (is_string($arrVar)) {
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