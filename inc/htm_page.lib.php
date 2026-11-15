<?php # /inc/htm_page.lib.php v:1.3.1 d:2026-09-15 i:evs

// HEADER og FOOTER flyttet fra php2htm.lib.php

# HEADER
function htm_Header($capt = 'Tiny Cash', $mwidth = 1600, $echo = true, $force_theme = null) {
    if (!$echo) ob_start();

    // RETTET: manglede session_name før session_start. Denne fallback rammes kun, hvis INGEN session er aktiv
    // endnu (fx hvis en side nogensinde kalder htm_Header uden først at være gået gennem auth.inc.php/login.php,
    // som ellers sætter navnet korrekt) - men uden det rigtige navn ville PHP i så fald starte en helt forkert,
    // ukendt session.
    if (session_status() === PHP_SESSION_NONE) {
        session_name('TCC_V100_SESSION');
        session_start();
    }

    $html_lang = isset($_SESSION['lang']) ? strtolower($_SESSION['lang']) : 'da';

    // NYT: $force_theme (fx 'light') springer session/cookie-opslaget helt
    // over og låser siden til det angivne tema - bruges af login.php, som
    // ikke har nogen temavælger, brugeren selv kan justere fra den side.
    if ($force_theme !== null) {
        $saved_theme = $force_theme;
    } elseif (isset($_SESSION['theme'])) {
        $saved_theme = $_SESSION['theme'];
    } elseif (isset($_COOKIE['theme'])) {
        $saved_theme = $_COOKIE['theme'];
        $_SESSION['theme'] = $saved_theme;
    } else {
        $saved_theme = 'light';
    }

// RETTET: $capt blev skrevet direkte ind i <title> uden nogen escaping. htmlspecialchars er et no-op for
// almindelig statisk tekst, så alle andre kald er upåvirkede.
echo '<!DOCTYPE html><html lang="'.$html_lang.'" data-theme="'.$saved_theme.'"><head><meta charset="UTF-8">
    <title>'.htmlspecialchars(lang($capt)).'</title>';

    // Denne inline-script læser theme-cookien og overskriver data-theme med
    // det samme (for at undgå et kort "flash" af forkert tema, før PHP-
    // sessionen har indhentet cookien). Når $force_theme er sat, SKAL denne
    // IKKE køre - ellers ville den bare overskrive vores tvungne tema igen
    // med brugerens gemte cookie-værdi.
    if ($force_theme === null) {
        echo '
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
    </script>';
    }

    // <link rel="icon" type="image/x-icon" href="favicon.ico">
     
echo '
     <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

    <link rel="stylesheet" href="' . tc_asset('assets/tinycash.css') . '">
    <style>.cardW000 { max-width: '.$mwidth.'px; margin: 20px auto; background: var(--bg-card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border: 1px solid var(--border-color); }</style>
    <script src="' . tc_asset('assets/tc-head.js') . '"></script>
    <script>
    ' . htm_ActionLinkJs() . '
    </script></head><body>';
    if (!$echo) return ob_get_clean();
}

# STATISK FIL I assets/ (CSS/JS som browseren cacher)
// ?v=filens ændringstid, så en ny version hentes straks efter deploy. Sider i en undermappe (fx db-setup/) får ../ foran.
function tc_asset(string $rel): string {
    static $prefix = null;
    $root = str_replace('\\', '/', dirname(__DIR__));
    if ($prefix === null) {
        $dir = str_replace('\\', '/', dirname((string)realpath($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $prefix = str_starts_with($dir, $root . '/') ? str_repeat('../', substr_count(substr($dir, strlen($root)), '/')) : '';
    }
    return htmlspecialchars($prefix . $rel . '?v=' . (int)@filemtime($root . '/' . $rel));
}

# HANDLINGS-LINKS SOM POST (bruges af htm_Header() og af sider der bygger deres egen <head>, fx invoice_view.php)
function htm_ActionLinkJs(): string {
    return '
    // Handlinger via et link sendes som POST med CSRF-nøglen i formularen - se csrf_get_actions()/csrf_action_by_name().
    // tcPost(url) bruges også af JS der selv starter en handling (i stedet for location.href = url).
    (function () {
        var reg = ' . json_encode(array_map(fn($r) => ['p' => $r['params'], 'ro' => $r['read_only'] ?? []], csrf_get_actions())) . ', tok = ' . json_encode(csrf_token()) . ';
        var byName = /^(send_[a-z0-9_]+|[a-z0-9_]+_actions?|[a-z0-9_]+_duplicate)\.php$/;
        // Et tal fra et inputfelt - samme regler som parse_dk_number() på serveren: "1.500,00", "1500.00", "1 500,50"
        // og "1.234.567" giver alle det rigtige tal (parseFloat(v.replace(",", ".")) gav 1,5 for "1.500,00"). Ugyldigt = 0.
        // Landepakkens locale og decimaltegn (inc/country.inc.php) - toLocaleString(tcLocale, ...) og tcNum følger landet
        window.tcLocale = ' . json_encode(tc_locale()) . ';  window.tcDec = ' . json_encode(tc_num_sep()[0]) . ';
        window.tcNum = function (v) {
            var s = String(v == null ? "" : v).replace(/[\s  \x27]/g, "");
            if (s === "") return 0;
            if ((s.match(/\./g) || []).length > 1) s = s.replace(/\./g, "");
            if ((s.match(/,/g) || []).length > 1) s = s.replace(/,/g, "");
            var c = s.lastIndexOf(","), d = s.lastIndexOf(".");
            if (c >= 0 && d >= 0) s = c > d ? s.replace(/\./g, "").replace(",", ".") : s.replace(/,/g, "");
            else if (c >= 0) s = window.tcDec === "." ? s.replace(/,/g, "") : s.replace(",", ".");
            var n = parseFloat(s);
            return isFinite(n) ? n : 0;
        };
        window.tcPost = function (url, target) {
            var f = document.createElement("form");
            f.method = "post";  f.action = url;  f.style.display = "none";
            if (target) f.target = target;
            [["csrf_token", tok], ["_tc_link", "1"]].forEach(function (kv) {
                var i = document.createElement("input");  i.type = "hidden";  i.name = kv[0];  i.value = kv[1];  f.appendChild(i);
            });
            document.body.appendChild(f);  f.submit();
        };
        function isAction(href) {
            var u;  try { u = new URL(href, location.href); } catch (e) { return false; }
            if (u.origin !== location.origin) return false;
            var file = u.pathname.split("/").pop(), r = reg[file];
            var ps = r ? r.p : (byName.test(file) ? Array.from(u.searchParams.keys()) : []), ro = r ? r.ro : [];
            return ps.some(function (p) { var v = u.searchParams.get(p); return v !== null && v !== "" && ro.indexOf(v) < 0; });
        }
        // Boble-fasen: en onclick="return confirm(...)" der svarer nej, har allerede annulleret klikket
        function onLink(e) {
            if (e.defaultPrevented || (e.button !== 0 && e.button !== 1)) return;
            var a = e.target.closest ? e.target.closest("a[href]") : null;
            if (!a || a.hasAttribute("download") || !isAction(a.href)) return;
            e.preventDefault();
            tcPost(a.href, (e.button === 1 || e.ctrlKey || e.metaKey || e.shiftKey) ? "_blank" : (a.target || ""));
        }
        document.addEventListener("click", onLink);
        document.addEventListener("auxclick", onLink);
    })();
    ';
}

# FOOTER
function htm_Footer($echo = true) {
    if (!$echo) ob_start();
    flash_render(); // en side uden htm_Card_() viser stadig en ventende flash-besked

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

    <!-- RETTET (bruger-rapporteret): erstatning for browserens native prompt(),
         hvis input-felt slet ikke er styret af sidens eget CSS-tema - på
         Windows med mørkt systemtema kunne teksten man selv skrev derfor
         blive usort (heller ikke synlig ved markering, da selektionsfarven
         ramte samme problem). Samme visuelle mønster som custom-alert
         ovenfor, så feltets tekstfarve altid følger --text-main. -->
    <div id="custom-prompt" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
        <div style="background: var(--bg-card); padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 15px rgba(0,0,0,0.3); font-family:sans-serif;">
            <h3 id="custom-prompt-title" style="margin-top:0; color: var(--color-dark); border-bottom:2px solid var(--color-primary); padding-bottom:8px;">TinyCash</h3>
            <p id="custom-prompt-text" style="color: var(--text-muted); font-size:14px; line-height:1.5; margin:0;"></p>
            <input type="text" id="custom-prompt-input" style="width:100%; box-sizing:border-box; margin-top:12px; padding:8px; font-size:14px; border:1px solid var(--border-color); border-radius:4px; background: var(--bg-panel); color: var(--text-main);">
            <div style="text-align:right; margin-top:20px;">
                <button onclick="_sysPromptCancel()" style="background: var(--bg-panel); color: var(--text-main); border:1px solid var(--border-color); padding:8px 16px; border-radius:4px; cursor:pointer; margin-right:8px;">' . lang('@Cancel') . '</button>
                <button onclick="_sysPromptConfirm()" style="background: var(--color-primary); color: var(--text-light); border:none; padding:8px 16px; border-radius:4px; cursor:pointer; font-weight:bold;">OK</button>
            </div>
        </div>
    </div>

    <script src="' . tc_asset('assets/tc-foot.js') . '"></script>';

    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) { 
        if (function_exists('htm_FloatingActionBar')) { htm_FloatingActionBar(); }
        if (function_exists('htm_HelpSystem')) { htm_HelpSystem(); }
        // Valuta-omregner vises kun når valuta-modulet er aktivt (settings: module_currency = 1)
        $__cur_settings = $GLOBALS['global_settings'] ?? [];
        if (!empty($__cur_settings['module_currency']) && $__cur_settings['module_currency'] == '1') {
            include_once 'inc/currency_widget.php';
        }
        include_once 'notepad.inc.php';

        // Global søgning: flyttet ud af selve menuen og givet sin egen flydende rund knap i stedet, samme mønster
        // og placering som notesblok-/valuta-knapperne ovenfor. Byttede plads med valuta-knappen: søgning sidder
        // nu fast på bottom:70px (lige over notesblokken), valuta rykket til bottom:120px i
        // inc/currency_widget.php - ingen gap-udfyldnings-logik nødvendig mere, da søgning altid ligger under
        // valuta uanset om valuta-modulet er aktivt.
        echo '<a href="search.php" class="no-print" style="position: fixed; bottom: 68px; right: 3px; z-index: 10001; background: #2c3e50; color: white; border: 2px solid var(--color-primary); border-radius: 50%; width: 40px; height: 40px; box-sizing: border-box; font-size: 18px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; text-decoration: none;" title="' . lang('@Search') . '">🔍</a>';

        // Automatisk krypteret off-site backup: tjekkes ved hver sidevisning,
        // men udfører kun reelt arbejde hvis 21+ dage siden sidste backup OG
        // der er sket ændringer siden da (se inc/auto_backup.inc.php).
        if (isset($GLOBALS['conn']) && $GLOBALS['conn']) {
            require_once __DIR__ . '/auto_backup.inc.php';
            if (function_exists('auto_backup_check')) {
                auto_backup_check($GLOBALS['conn']);
            }
        }

        // Gentagne/faste fakturaer: samme "tjek ved hver sidevisning, udfør kun arbejde hvis forfaldent"-mønster
        // som auto-backup ovenfor (ingen rigtig cron-adgang på almindelig delt hosting). Fejler tavst (try/catch)
        // hvis tabellerne ikke findes endnu - må ikke kunne vælte en helt almindelig sidevisning.
        if (isset($GLOBALS['conn']) && $GLOBALS['conn']) {
            require_once __DIR__ . '/recurring_invoices.inc.php';
            if (function_exists('recurring_invoices_check')) {
                try { recurring_invoices_check($GLOBALS['conn']); } catch (\Throwable $e) { /* tabel mangler evt. endnu */ }
            }
        }

        // Gentagne/faste udgifter: udgiftssidens modstykke til ovenstående, samme "tjek ved hver
        // sidevisning"-mønster. Fejler tavst (try/catch) hvis tabellen ikke findes endnu - må ikke kunne vælte en
        // helt almindelig sidevisning.
        if (isset($GLOBALS['conn']) && $GLOBALS['conn']) {
            require_once __DIR__ . '/recurring_expenses.inc.php';
            if (function_exists('recurring_expenses_check')) {
                try { recurring_expenses_check($GLOBALS['conn']); } catch (\Throwable $e) { /* tabel mangler evt. endnu */ }
            }
        }
    }

    if (isset($GLOBALS['GLOBAL_DEBUG_ALERT'])) { echo $GLOBALS['GLOBAL_DEBUG_ALERT']; }
    echo '</body></html>';

    if (!$echo) return ob_get_clean();
} # htm_Footer

?>