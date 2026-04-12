<?php # /inc/php2htm.lib.php v:0.8.0 d:2026-04-12 i:Gemini m:1

function htm_Header($title = 'Tiny Cash', $mwidth = 1600) {
    echo '<!DOCTYPE html><html lang="da">
        <head><meta charset="UTF-8">
          <title>'.$title.'</title>
          <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
          <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.2.3/css/flag-icons.min.css" />
          <style>
            body{font-family:sans-serif;background:#f4f7f6;margin:4px 20px;}
            .cardW000{max-width:'.$mwidth.'px;margin:20px auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
            .cardW500{max-width:500px;margin:20px auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
            .cardW800{max-width:800px;margin:20px auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
            
            /* Knapper */
            button, .btn-primary, .btn-success, .btn-danger { cursor:pointer; border:none; font-weight:bold; padding:10px; border-radius:4px; width:100%; box-sizing:border-box; margin:8px 0; }
            .btn-primary{background:#3498db;color:white;}
            .btn-success{background:#27ae60;color:white;}
            .btn-danger{background:#e74c3c;color:white;}
            hr{border:0;border-top:1px solid #eee;margin:20px 0;}
            
            /* Hint system */
            #tc-hint {
                position: fixed;
                display: none;
                background: #2c3e50;
                color: white;
                padding: 8px 15px;
                border-radius: 4px;
                border-left: 3px solid #f1c40f;
                font-size: 12px;
                line-height: 1.4;
                z-index: 10000;
                max-width: 280px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.5);
                pointer-events: none;
            }

            /* Field-group system */
            .field-group { 
                border: 1px solid #ccc !important; 
                border-radius: 4px; 
                margin-bottom: 15px; 
                padding: 0 10px 5px 10px; 
                background-color: #fff; 
                display: block; 
            }
            .field-group legend { 
                font-size: 0.75rem; 
                font-weight: 700; 
                color: #7f8c8d; 
                padding: 0 8px; 
                text-transform: uppercase; 
                margin-left: auto; 
                margin-right: 0; 
                width: auto; 
                border: none !important;
            }
            .field-group input, .field-group select, .field-group textarea { 
                width: 100% !important; 
                border: none !important; 
                outline: none !important; 
                box-shadow: none !important; 
                background: transparent !important; 
                padding: 8px 0 !important; 
                margin: 0 !important; 
                font-size: 1rem; 
                display: block; 
            }

/* Containeren skal være mørk */
.submenu {
    display: none;
    position: absolute;
    background: #2c3e50 !important;
    min-width: 220px !important;
    width: max-content !important;
    max-width: 450px !important; /* God plads til grønlandsk */
    border: 1px solid #455a64 !important;
    box-shadow: 0 8px 16px rgba(0,0,0,0.5);
    z-index: 1000;
    padding: 5px 0 !important;
    border-radius: 4px;
}

/* Selve linket - vi bruger Flexbox for at holde tingene på række */
.submenu .dropdown-item {
    display: flex !important;
    align-items: flex-start !important;
    gap: 12px !important;            /* Fast afstand mellem ikon og tekst */
    padding: 10px 15px !important;   /* Normal padding */
    color: #ffffff !important;       /* TVING HVID TEKST */
    text-decoration: none !important;
    font-size: 14px !important;
    line-height: 1.4 !important;
    white-space: normal !important;  /* Tillad linjeskift */
    word-break: break-word !important;
    border-bottom: 1px solid rgba(255,255,255,0.1) !important;
}

/* Hover effekt */
.submenu .dropdown-item:hover {
    background: #3498db !important;
    color: #ffffff !important;
}

/* Hvis der er en HR (linje) i menuen */
.submenu hr {
    border: 0 !important;
    border-top: 1px solid #455a64 !important;
    margin: 5px 0 !important;
}
            .dropdown-item:hover { background: #f8f9fa !important; color: #000 !important; }
           </style>
        </head>
    <body>'; 
}

function htm_Footer() { 
    echo '
    <div id="tc-hint" style="position:fixed; display:none; background:#2c3e50; color:white; padding:8px 15px; border-radius:4px; border-left:3px solid #f1c40f; z-index:10000; pointer-events:none; font-size:12px; max-width:280px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); line-height:1.4;"></div>
    <script>
    (function() {
        const box = document.getElementById("tc-hint");
        document.addEventListener("mouseover", function(e) {
            const target = e.target.closest("[data-hint]");
            if (target && box) {
                box.innerHTML = target.getAttribute("data-hint");
                box.style.display = "block";
            }
        });
        document.addEventListener("mousemove", function(e) {
            if (box && box.style.display === "block") {
                let x = e.clientX + 15;
                let y = e.clientY + 15;
                const boxWidth = box.offsetWidth;
                const boxHeight = box.offsetHeight;
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;
                // Tjek højre kant - hvis boksen stikker ud, flyt den til venstre for musen
                if (x + boxWidth > windowWidth) {
                    x = e.clientX - boxWidth - 15;
                }
                // Tjek bund - hvis boksen stikker ud, flyt den op over musen
                if (y + boxHeight > windowHeight) {
                    y = e.clientY - boxHeight - 15;
                }
                box.style.left = x + "px";
                box.style.top = y + "px";
            }
        });
        document.addEventListener("mouseout", function(e) {
            const target = e.target.closest("[data-hint]");
            if (target && box) {
                box.style.display = "none";
            }
        });
    })();
    </script>
    </body></html>'; 
}

function htm_Card_($capt, $width = '600') {
    echo '<div class="card-container" style="max-width: '.$width.'px; margin: 20px auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">';
    echo '<h2 style="margin-top:0; color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:10px;">'.$capt.'</h2>';
}

function htm_Card_end() { 
    echo '</div>'; 
}


function htm_InputGroup($icon, $label, $name, $val = '', $type = 'text', $opt = null, $extra = '') {
    // Vi skifter fra 'return' til 'echo', så kaldet i siderne kan nøjes med: htm_InputGroup(...);
    echo "<fieldset class='field-group'>";
    
    if ($label) {
        // Vi tilføjer ikonet foran label/legend hvis det findes
        $icon_html = $icon ? "<i class='fa $icon' style='margin-right:5px; color:#3498db;'></i> " : "";
        echo "<legend>" . $icon_html . lang($label) . "</legend>";
    }
    
    if ($type == 'select' && is_array($opt)) {
        echo "<select name='$name' id='$name' $extra>";
        foreach ($opt as $k => $v) {
            $sel = ($val == $k) ? "selected" : "";
            echo "<option value='$k' $sel>$v</option>";
        }
        echo "</select>";
    } elseif ($type == 'textarea') {
        echo "<textarea name='$name' id='$name' rows='3' $extra>" . htmlspecialchars((string)$val) . "</textarea>";
    } elseif ($type == 'file') {
        // Sikrer at fil-felter har auto-højde
        echo "<input type='file' name='$name' id='$name' $extra style='height:auto; padding:5px 0;'>";
    } else {
        echo "<input type='$type' name='$name' id='$name' value='" . htmlspecialchars((string)$val) . "' $extra>";
    }
    
    echo "</fieldset>";
}



if (!function_exists('lang')) {
function lang($key) {
    $current_lang = $_SESSION['lang'] ?? 'da'; 
    static $translations = null;
    if ($translations === null) {
        $file = dirname(__DIR__) . '/json-data/languages.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['language'])) {
                foreach ($data['language'] as $l) {
                    if ($l['code'] === $current_lang) {
                        $translations = $l['translation'];
                        break;
                    }
                }
            }
        }
    }
    if (!isset($translations[$key]) || $translations[$key] === "") {
        return ltrim($key, '@');
    }
    return $translations[$key];
}
}

/**
 * Viser en standardiseret beskedboks (success, error, info)
 * @param string $text Selve beskedteksten
 * @param string $type 'success', 'error' (default) eller 'info'
 * @param int $width Maksimal bredde i px
 */
function htm_Alert($text, $type = 'success', $width = 700) {
    if (empty($text)) return "";
    
    $bg = "#d4edda"; $color = "#155724"; $border = "#c3e6cb"; $icon = "✅";
    
    if ($type == 'error') {
        $bg = "#f8d7da"; $color = "#721c24"; $border = "#f5c6cb"; $icon = "⚠️";
    } elseif ($type == 'info') {
        $bg = "#d1ecf1"; $color = "#0c5460"; $border = "#bee5eb"; $icon = "ℹ️";
    }

    echo "<div style='background:$bg; color:$color; padding:15px; margin:10px auto; max-width:{$width}px; border-radius:4px; border:1px solid $border; font-family:sans-serif;'>";
    echo "$icon $text";
    echo "</div>";
}

function get_settings($conn) {
    $res = mysqli_query($conn, "SELECT * FROM settings");
    $settings = [];
    if($res) {
        while($row = mysqli_fetch_assoc($res)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings ?: ['company_name' => 'Mit Firma', 'currency' => 'DKK'];
}

function getVatOptionsFromDB($conn) {
    $options = ['' => '-- ' . lang('@Select Account') . ' --'];
    // Vi henter konti (typisk 1000-1999 er salg/indtægter)
    $sql = "SELECT acc_id, acc_name, vat_rate FROM accounts ORDER BY acc_id ASC";
    $res = mysqli_query($conn, $sql);
    
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            // Vi bruger acc_id som nøgle (ID), så det passer til tabellen products
            $options[$row['acc_id']] = $row['acc_id'] . " - " . $row['acc_name'] . " (" . (int)$row['vat_rate'] . "%)";
        }
    }
    return $options;
}?>