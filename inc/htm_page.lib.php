<?php # /inc/htm_page.lib.php v:1.3.0 d:2026-08-30 i:evs

// HEADER og FOOTER flyttet fra php2htm.lib.php

# HEADER
function htm_Header($capt = 'Tiny Cash', $mwidth = 1600, $echo = true, $force_theme = null) {
    if (!$echo) ob_start();

    // RETTET: manglede session_name('TCC_V100_SESSION') før session_start().
    // Denne fallback rammes kun, hvis INGEN session er aktiv endnu (fx hvis
    // en side nogensinde kalder htm_Header() uden først at være gået gennem
    // auth.inc.php/login.php, som ellers sætter navnet korrekt) - men uden
    // det rigtige navn ville PHP i så fald starte en helt forkert, ukendt
    // session (samme fejlklasse som blev fundet i logout.php og set_lang.php).
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

// RETTET (§bugs-batch-13-review): $capt blev skrevet direkte ind i <title>
// uden nogen escaping. De fleste kald sender en statisk '@Nøgle'-tekst (helt
// ufarligt), men flere sider (customer_edit.php, customer_statement.php)
// bygger $capt dynamisk ved at sammensætte det med rå, brugerkontrolleret
// data (fx et kundenavn) - en kunde med navnet '</title><script>...</script>'
// kunne dermed bryde ud af <title> og få reel, kørende JavaScript ind i
// <head> for enhver der åbnede den kundes side. htmlspecialchars() er et
// no-op for almindelig statisk tekst, så alle andre kald er upåvirkede.
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

    <style>
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
        /* RETTET (§bugs-batch-22-review-del-b): disse fire (og de nye
           warning/info-par) lå FØR helt uden for enhver CSS-selector (mellem
           denne bloks lukning og body{}-reglen) - ugyldig CSS, som browseren
           stille dropper. htm_Alert() brugte derfor ALTID sin hardkodede
           fallback-værdi (var(--x, #fallback)), aldrig den faktiske tema-
           farve, uanset hvilket tema der var valgt. Flyttet ind i hver af de
           tre temablokke, med selvstændige mørke/lyse værdier hvor relevant.
           Nye --bg-alert-warning/--text-alert-warning og -info/-info tilføjet
           samtidig, til htm_Alert()s nye warning/info-typer. */
        --bg-alert-success: #d4edda;   --text-alert-success: #155724;
        --bg-alert-error:   #f8d7da;   --text-alert-error:   #721c24;
        --bg-alert-warning: #fff3cd;   --text-alert-warning: #856404;
        --bg-alert-info:    #d1ecf1;   --text-alert-info:    #0c5460;
    }

    [data-theme="custom"] {
        --bg-main: #3498db; --bg-card: #ffffff; --bg-nav: #2c3e50;
        --bg-nav-hover: rgba(255,255,255,0.15); --bg-submenu: #243342;
        --bg-panel: #f4f6f8; --bg-table-even: #f8faf9; --bg-table-hover: #eef9f3;
        --border-color: #cbd5e1; --border-subtle: #e2e8f0; --border-fieldset: #475569;
        --text-main: #1e293b; --text-muted: #64748b; --text-light: #ffffff;
        --color-primary: #2ecc71; --color-dark: #1a252f; --color-warning: #f39c12;
        --bg-alert-success: #d4edda;   --text-alert-success: #155724;
        --bg-alert-error:   #f8d7da;   --text-alert-error:   #721c24;
        --bg-alert-warning: #fff3cd;   --text-alert-warning: #856404;
        --bg-alert-info:    #d1ecf1;   --text-alert-info:    #0c5460;
    }

    [data-theme="dark"] {
        --bg-main: #121212; --bg-card: #7d7373; --bg-nav: #1a1a1a;
        --bg-nav-hover: rgba(255,255,255,0.08); --bg-submenu: #2d2d2d;
        /* RETTET: --bg-panel (bruges bl.a. til tabel-headers) var meget tæt
           på sort (#262626). Lysnet en anelse til #333333 for et blødere
           udtryk - hvid header-tekst har fortsat glimrende kontrast mod
           denne værdi. --bg-table-even/--bg-table-hover ligger tæt på
           --bg-cards egen lyshed for en diskret zebra-effekt. */
        --bg-panel: #333333; --bg-table-even: #706767; --bg-table-hover: #8c8282;
        /* --border-color/--border-subtle skal være LYSERE end baggrunden i
           dark-tema (modsat light-tema-logikken) for at være synlige mod de
           næsten-sorte flader. --border-fieldset er lysnet yderligere, da
           den primært bruges mod --bg-card (den mellemgrå kort-baggrund). */
        --border-color: #707070; --border-subtle: #555555; --border-fieldset: #a8a8a8;
        --text-main: #ffffff; --text-muted: #dcdcdc; --text-light: #ffffff;
        --color-primary: #2980b9; --color-success: #27ae60; --color-danger: #c0392b;
        --color-warning: #f39c12; --color-secondary: #7f8c8d; --color-dark: #0f172a;
        --color-purple: #7d3c98; --color-info: #c4c6c8;
        /* Mørkere/mættede baggrunde + lyse tekstfarver, tilpasset det
           mellemgrå --bg-card (#7d7373) i stedet for lyse light-tema-farver,
           som ville have dårlig kontrast/virke malplacerede på en mørk side. */
        --bg-alert-success: #1e4620;   --text-alert-success: #8fd19e;
        --bg-alert-error:   #4a1e1e;   --text-alert-error:   #f5a3a3;
        --bg-alert-warning: #4a3c0a;   --text-alert-warning: #f5d576;
        --bg-alert-info:    #1a3a4a;   --text-alert-info:    #8ecae6;
    }

    body { 
        font-family: "Inter", sans-serif; 
        background: var(--bg-main); 
        margin: 4px 20px; 
        color: var(--text-main); 
        padding-bottom: 100px;
        position: relative;
    }
    
    body.sidebar-mode {
        margin-left: 214px !important;
        margin-right: 20px !important;
        margin-top: 4px !important;
        padding-bottom: 100px !important;
    }

    body::before {
        content: "";
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        z-index: -1;
        transition: background 0.3s ease;
    }

    [data-theme="light"] body::before {
        background-image: url("_background.png");
    }

    [data-theme="custom"] body::before {
        background-image: none;
        background-color: var(--bg-main);
    }

    [data-theme="dark"] body::before {
        background-image: none;
        background-color: var(--bg-main);
    }

    .cardW000 { max-width: '.$mwidth.'px; margin: 20px auto; background: var(--bg-card); padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border: 1px solid var(--border-color); }
    nav { position: relative; z-index: 9000 !important; display: flex; flex-wrap: wrap; align-items: center; background: var(--bg-nav); padding: 5px 20px; min-height: 70px; }
    .nav-main-link { display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; text-decoration: none !important; color: var(--text-light) !important; padding: 5px 10px !important; min-width: 85px; transition: background 0.2s; }
    .nav-main-link span { color: var(--text-light) !important; display: block !important; text-align: center; }
    .nav-main-link span.menu-icon { font-size: 1.5em; line-height: 1; margin-bottom: 3px; }
    .nav-main-link span.menu-text { font-size: 0.95em; font-weight: 600; }
    .nav-main-link:hover { background: var(--bg-nav-hover); border-radius: 4px; }

    .submenu { display: none; position: az-indbsolute; background: var(--bg-submenu) !important; min-width: 240px !important; ex: 9999 !important; box-shadow: 0 8px 25px rgba(0,0,0,0.6); border-radius: 4px; border: 1px solid var(--border-color); margin-top: 5px; padding: 5px 0 !important; }

    .dropdown-item { display: flex !important; align-items: center !important; gap: 10px; padding: 12px 15px !important; color: var(--text-light) !important; text-decoration: none !important; cursor: pointer !important; border-bottom: 1px solid var(--border-subtle); }
    .dropdown-item:hover { background: var(--color-primary) !important; }

    input, select, textarea {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border: 1px solid var(--border-color) !important;
    }
    input::placeholder,
    textarea::placeholder {
        font-style: italic;
        font-size: smaller;
        opacity: 0.8; /* Valgfrit: Gør placeholderen en smule lysere */
    }

    input:-webkit-autofill,
    input:-webkit-autofill:hover, 
    input:-webkit-autofill:focus {
        -webkit-text-fill-color: var(--text-main) !important;
        -webkit-box-shadow: 0 0 0px 1000px var(--bg-card) inset !important;
        transition: background-color 5000s ease-in-out 0s;
    }
    .alert-box {
        background-color: var(--bg-panel) !important;
        border-left: 4px solid var(--color-warning) !important;
        color: var(--text-main) !important;
    }

    .quick-actions { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column-reverse; gap: 10px; z-index: 9999; }
    .qa-btn { background-color: var(--color-dark); color: var(--text-light) !important; padding: 12px 20px; border-radius: 50px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 10px; transition: all 0.3s ease; border: none; cursor: pointer; }
    .qa-btn:hover { background-color: var(--color-primary); transform: scale(1.05); }
    .qa-btn i { font-size: 1.2em; }
    .qa-invoice { background-color: #27ae60; } 
    .qa-expense { background-color: var(--color-danger); } 
    .qa-account { background-color: var(--color-purple); } 

    .floating-action-bar { position: fixed; bottom: 0; left: 0; right: 0; width: 100%; background: rgba(44, 62, 80, 0.97); border-top: 2px solid var(--color-primary); display: flex; justify-content: center; gap: 20px; padding: 6px 0; z-index: 10000; box-shadow: 0 -2px 10px rgba(0,0,0,0.2); box-sizing: border-box; }
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
    /* body padding-bottom og font-family er defineret ovenfor — duplikat fjernet */

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

    function toggleAllSections(open) {
        document.querySelectorAll("details").forEach(function(el) {
            if (open) {
                el.setAttribute("open", "");
            } else {
                el.removeAttribute("open");
            }
        });
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
    // Fold/luk-ikon for htm_Card_(fold: true) - se inc/php2htm.lib.php.
    function toggleCard(id, btn) {
        const el = document.getElementById(id);
        if (!el) return;
        const opening = el.style.display === "none";
        el.style.display = opening ? "" : "none";
        if (btn) { btn.classList.toggle("fa-chevron-down", opening); btn.classList.toggle("fa-chevron-right", !opening); }
    }
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
        // Valuta-omregner vises kun når valuta-modulet er aktivt (settings: module_currency = 1)
        $__cur_settings = $GLOBALS['global_settings'] ?? [];
        if (!empty($__cur_settings['module_currency']) && $__cur_settings['module_currency'] == '1') {
            include_once 'inc/currency_widget.php';
        }
        include_once 'notepad.inc.php';

        // Automatisk krypteret off-site backup: tjekkes ved hver sidevisning,
        // men udfører kun reelt arbejde hvis 21+ dage siden sidste backup OG
        // der er sket ændringer siden da (se inc/auto_backup.inc.php).
        if (isset($GLOBALS['conn']) && $GLOBALS['conn']) {
            require_once __DIR__ . '/auto_backup.inc.php';
            if (function_exists('auto_backup_check')) {
                auto_backup_check($GLOBALS['conn']);
            }
        }

        // Gentagne/faste fakturaer: samme "tjek ved hver sidevisning, udfør
        // kun arbejde hvis forfaldent"-mønster som auto-backup ovenfor (ingen
        // rigtig cron-adgang på almindelig delt hosting). Opretter altid kun
        // KLADDER, aldrig bogførte fakturaer direkte. Fejler tavst (try/catch)
        // hvis tabellerne ikke findes endnu (§migrate_recurring_invoices) - må
        // ikke kunne vælte en helt almindelig sidevisning.
        if (isset($GLOBALS['conn']) && $GLOBALS['conn']) {
            require_once __DIR__ . '/recurring_invoices.inc.php';
            if (function_exists('recurring_invoices_check')) {
                try { recurring_invoices_check($GLOBALS['conn']); } catch (\Throwable $e) { /* tabel mangler evt. endnu */ }
            }
        }
    }

    if (isset($GLOBALS['GLOBAL_DEBUG_ALERT'])) { echo $GLOBALS['GLOBAL_DEBUG_ALERT']; }
    echo '</body></html>';

    if (!$echo) return ob_get_clean();
} # htm_Footer

?>