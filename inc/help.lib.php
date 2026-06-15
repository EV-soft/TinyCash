<?php # /inc/help.lib.php - Centraliseret hjælpesystem (Logik og UI)

# -------------------------------------------------------------------------
# INTERN DATA-LOGIK (Rettet til dynamisk sprogvalg)
# -------------------------------------------------------------------------

function _help_get_content($current_page, $target_lang) {
    // 1. Tving sprogkoden til små bogstaver (så 'DA' bliver til 'da')
    $lang = strtolower(trim($target_lang));
    
    // 2. Definer stien til den specifikke sprogfil
    $lang_file = dirname(__DIR__) . "/json-data/languages/help_system_" . $lang . ".json";
    $master_file = dirname(__DIR__) . '/json-data/help_system.json';
    
    // 3. Vælg sprogfilen hvis den findes, ellers snup master-filen (en)
    $file_to_load = (file_exists($lang_file)) ? $lang_file : $master_file;

    if (!file_exists($file_to_load)) return false;
    
    $data = json_decode(file_get_contents($file_to_load), true);
    if (!$data || !isset($data[$current_page])) {
        // Hvis siden mangler i sprogfilen, så prøv at snappe den fra master-filen i stedet
        if ($file_to_load !== $master_file && file_exists($master_file)) {
            $data = json_decode(file_get_contents($master_file), true);
            if (!$data || !isset($data[$current_page])) return false;
        } else {
            return false;
        }
    }

    $help_lines = $data[$current_page];
    return implode("\n", $help_lines);
}

function _help_has_text($current_page, $target_lang) {
    $content = _help_get_content($current_page, $target_lang);
    return ($content !== false && !empty($content));
}

# -------------------------------------------------------------------------
# BRUGERFLADE-FUNKTIONER (HTM_*)
# -------------------------------------------------------------------------

# SYSTEM POPUP HJÆLP 
function htm_HelpSystem() {
    $current_page = basename($_SERVER['SCRIPT_NAME']);
    $target_lang  = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
    $master_file = dirname(__DIR__) . '/json-data/help_system.json';

 /*    // DEBUG INFO DIREKTE PÅ SKÆRMEN:
    echo "<div style='background:#fff; border:3px solid red; padding:15px; margin:20px; color:#000; font-family:monospace; z-index:999999; position:relative;'>";
    echo "<h3>🔍 Debug Hjælpesystem:</h3>";
    echo "Aktuel side: <b>" . $current_page . "</b><br>";
    echo "Søger efter fil på sti: <b>" . $master_file . "</b><br>";
 */    
/*     if (!file_exists($master_file)) {
        echo "<span style='color:red;'>❌ FEJL: help_system.json findes IKKE på denne sti!</span><br>";
    } else {
        echo "<span style='color:green;'>✔️ OK: Filen eksisterer.</span><br>";
        $raw = file_get_contents($master_file);
        $master_data = json_decode($raw, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "<span style='color:red;'>❌ FEJL I JSON-SYNTAKS: " . json_last_error_msg() . "</span><br>";
        } else {
            echo "<span style='color:green;'>✔️ OK: JSON er gyldig.</span><br>";
            if (!isset($master_data[$current_page])) {
                echo "<span style='color:red;'>❌ FEJL: Nøglen '" . $current_page . "' findes IKKE inde i din JSON-fil!</span><br>";
                echo "Tilgængelige nøgler i filen: " . implode(", ", array_keys($master_data)) . "<br>";
            } else {
                echo "<span style='color:green;'>✔️ OK: Nøglen '" . $current_page . "' blev fundet!</span><br>";
            }
        }
    }
    echo "</div>";

 */    // Den oprindelige kode fortsætter herunder...
    $help_text = _help_get_content($current_page, $target_lang);
    if (!$help_text) return;
    
    echo '
    <div id="help-modal-container" style="display:none; position:fixed; top:80px; right:30px; width:450px; background:white; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.25); border:1px solid #bdc3c7; z-index:99999; font-family:sans-serif; touch-action:none;">
        <div id="help-modal-header" style="background:#2c3e50; color:white; padding:12px 15px; border-radius:7px 7px 0 0; cursor:move; display:flex; justify-content:space-between; align-items:center; user-select:none;">
            <span style="font-weight:bold; font-size:14px;"><i class="fa-solid fa-circle-question" style="color:#e67e22; margin-right:6px;"></i>' . lang('@Hjælpesystem') . '</span>
            <button onclick="closeHelpSystem()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#bdc3c7; line-height:1; margin-left:auto; padding:0 5px;">&times;</button>
        </div>
        <div style="padding:20px;">
            <div id="help-modal-content" style="color:#2c3e50; line-height:1.6; font-size:14px; max-height:350px; overflow-y:auto;">
                ' . $help_text . '
            </div>
            <div style="margin-top:15px; padding-top:10px; border-top:1px solid #eee; text-align:right; font-size:11px; color:#95a5a6;">
                <i class="fa-solid fa-language"></i> Sprog: ' . strtoupper($target_lang) . '
            </div>
        </div>
    </div>';
    ?>
    <script>
    if (typeof interact === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js';
        document.head.appendChild(script);
    }
    function initHelpDrag() {
        if (typeof interact === 'undefined') { setTimeout(initHelpDrag, 100); return; }
        if (window.helpDragInitialized) return;
        window.helpDragInitialized = true;
        interact('#help-modal-container').draggable({
            allowFrom: '#help-modal-header',
            listeners: {
                move(event) {
                    var target = event.target, x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx, y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;
                    target.style.transform = 'translate(' + x + 'px, ' + y + 'px)'; target.setAttribute('data-x', x); target.setAttribute('data-y', y);
                }
            }
        });
    }
    function openHelpSystem() { var c = document.getElementById('help-modal-container'); if (c) { c.style.display = 'block'; initHelpDrag(); } }
    function closeHelpSystem() { var c = document.getElementById('help-modal-container'); if (c) c.style.display = 'none'; }
    </script>
    <?php
}
# FLOATING ACTION BAR
function htm_FloatingActionBar() {
    $current_page = basename($_SERVER['SCRIPT_NAME']);
    $lang         = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';

    if (_help_has_text($current_page, $lang)) {
        $btn_style = 'background: #e67e22; border-radius: 4px; opacity: 1; cursor: pointer;';
        $btn_onclick = 'openHelpSystem(); return false;';
        $btn_hint = '@Help available';
    } else {
        $btn_style = 'background: #7f8c8d; border-radius: 4px; opacity: 0.4; cursor: not-allowed;';
        $btn_onclick = 'return false;';
        $btn_hint = '@No help text for this page';
    }
    ?>
    <div class="floating-action-bar">
        <a href="invoice_edit.php?id=0" class="fab-item"><span class="fab-dot dot-invoice"></span><i class="fa fa-file-invoice"></i> <?php echo lang('@New Invoice'); ?></a>
        <a href="expense_edit.php?id=0" class="fab-item"><span class="fab-dot dot-receipt"></span><i class="fa fa-receipt"></i> <?php echo lang('@New Expense'); ?></a>
        <a href="product_edit.php?id=0" class="fab-item"><span class="fab-dot dot-account"></span><i class="fa fa-box-open"></i> <?php echo lang('@New Product'); ?></a>
        <a href="account_edit.php?id=0" class="fab-item"><span class="fab-dot dot-account"></span><i class="fa fa-plus-square"></i> <?php echo lang('@New Account'); ?></a>
        <a href="customer_edit.php?id=0" class="fab-item"><i class="fa fa-user-plus"></i> <?php echo lang('@New Customer'); ?></a>
        <div id="fab-scroll-top" class="fab-top" style="display:none;" onclick="window.scrollTo({top: 0, behavior: 'smooth'});" data-hint="<?php echo lang('@Go to top'); ?>"><i class="fa fa-arrow-up"></i>&nbsp;<span><?php echo lang('@Top'); ?></span></div>
        <a href="#" class="fab-item" onclick="<?php echo $btn_onclick; ?>" style="<?php echo $btn_style; ?>" data-hint="<?php echo lang($btn_hint); ?>">
            <i class="fa-solid fa-circle-question"></i> <?php echo lang('@Help'); ?>
        </a>
    </div>
    <?php
}
?>