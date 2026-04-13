<?php # /inc/php2htm.lib.php v:0.8.4 d:2026-04-13 i:Gemini m:1

function htm_Header($title = 'Tiny Cash', $mwidth = 1600) {
    echo '<!DOCTYPE html><html lang="da">
        <head><meta charset="UTF-8">
          <title>'.$title.'</title>
          <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
          <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.2.3/css/flag-icons.min.css" />
          <style>
    body { font-family: "Inter", sans-serif; background: #f4f7f6; margin: 4px 20px; }
    .cardW000 { max-width: <?php echo $mwidth; ?>px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    
    /* Navigation Base */
    nav { position: relative; z-index: 9000 !important; display: flex; flex-wrap: wrap; align-items: center; background: #2c3e50; padding: 5px 20px; min-height: 70px; }
    
    /* Hovedmenu Links */
.nav-main-link {
        display: flex !important;
        flex-direction: column !important; /* Ikon over tekst */
        align-items: center !important;
        justify-content: center !important;
        text-decoration: none !important;
        color: #ffffff !important; /* Gennemtving hvid tekst */
        padding: 5px 10px !important;
        min-width: 85px;
        transition: background 0.2s;
    }
    
    .nav-main-link span {
        color: #ffffff !important; /* Sikrer at tekst i spans også er hvid */
        display: block !important;
        text-align: center;
    }

/* Gør ikonet (første span) tydeligt større */
.nav-main-link span.menu-icon { 
    font-size: 1.5em; 
    line-height: 1;
    margin-bottom: 3px;
}

/* Gør teksten (andet span) mindre og centreret */
.nav-main-link span.menu-text { 
    font-size: 0.95em; 
    font-weight: 600;
}
    .nav-main-link:hover { background: rgba(255,255,255,0.1); border-radius: 4px; }
    .nav-main-link span:first-child { font-size: 1.4em; transition: transform 0.2s; }
    .nav-main-link:hover span:first-child { transform: scale(1.1); }

    /* Undermenu Container */
    .submenu {
        display: none;
        position: absolute;
        background: #34495e !important;
        min-width: 240px !important;
        z-index: 9999 !important;
        box-shadow: 0 8px 25px rgba(0,0,0,0.6);
        border-radius: 4px;
        border: 1px solid #455a64;
        margin-top: 5px;
        padding: 5px 0 !important;
    }

    /* Punkter i undermenu */
    .dropdown-item {
        display: flex !important;
        align-items: center !important;
        gap: 10px;
        padding: 12px 15px !important;
        color: white !important;
        text-decoration: none !important;
        cursor: pointer !important;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .dropdown-item:hover { background: #3498db !important; }
    
    /* Hint & Form elementer beholdes som de er... */
    #tc-hint { position: fixed; display: none; background: #2c3e50; color: white; padding: 8px 15px; z-index: 10000; pointer-events: none; }
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