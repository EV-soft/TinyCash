<?php # /inc/htm_page.lib.php v:1.3.1 d:2026-09-15 i:evs

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
      /*  margin: 4px 20px;  */
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
    /* RETTET (bruger-rapporteret gennemgang: "flere lignende problemer" efter
       hvid-på-gul-fundet på varselsbanneret) - var(--color-info) er med vilje
       lysegrå (#c4c6c8) i dark-temaet (fin kontrast som BADGE-/tekstfarve mod
       kortenes mellemgrå baggrund dér), men de få steder, hvor color-info i
       stedet bruges som en massiv knap-/pille-BAGGRUND med hvid tekst ovenpå
       (bank_integration.php, CVR-opslagsknappen i customer_edit.php/
       supplier_edit.php, "Send faktura"-bekræftelsen i sales_hub.php), bliver
       næsten ulæselige af samme grund som varselsbanneret var. Selve
       --color-info kan ikke bare gøres mørkere generelt - det ville i stedet
       ødelægge badge-brugen. .tc-info-fill markerer kun disse fyldte-
       baggrund-steder, så KUN de får mørk tekst i dark-temaet. */
    [data-theme="dark"] .tc-info-fill { color: #1a1a1a !important; }

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

    /* RETTET (bruger-anmodet, flag-tabel-konsolidering): de fire manuelle
       fi-da/fi-sv/fi-no/fi-en-overrides herunder er fjernet. De var kun
       nødvendige fordi sprogkoder (da/sv/no/en) tidligere blev brugt DIREKTE
       som flag-klassenavne nogle steder i koden, i stedet for de rigtige
       ISO-landekoder (dk/se/no/gb) - flag-icons-bibliotekets egne CDN-klasser
       (.fi-dk, .fi-se, .fi-no, .fi-gb m.fl.) dækker allerede alle rigtige
       landekoder ude af boksen. Nu hvor inc/menu.inc.php og about.php begge
       slår det korrekte flag-felt op i languages.json i stedet for at
       gætte, emitteres kun rigtige ISO-koder - disse overrides ville aldrig
       blive ramt længere (og var i øvrigt selv en del af den bug, hvor
       svensk viste et forkert flag - se inc/menu.inc.php sin egen kommentar). */
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

    // CSRF for handlinger via et link (GET): nøglen lægges på linket i det øjeblik det klikkes - se csrf_get_actions()
    (function () {
        var reg = ' . json_encode(array_map(fn($r) => ['p' => $r['params'], 'ro' => $r['read_only'] ?? []], csrf_get_actions())) . ', tok = ' . json_encode(csrf_token()) . ';
        function sign(a) {
            if (!tok || !a.href) return;
            var u;  try { u = new URL(a.href, location.href); } catch (e) { return; }
            if (u.origin !== location.origin || u.searchParams.has("csrf_token")) return;
            var r = reg[u.pathname.split("/").pop()];
            if (!r || !r.p.some(function (p) { var v = u.searchParams.get(p); return v !== null && v !== "" && r.ro.indexOf(v) < 0; })) return;
            u.searchParams.set("csrf_token", tok);
            a.href = u.toString();
        }
        ["click", "auxclick", "contextmenu"].forEach(function (ev) {
            document.addEventListener(ev, function (e) { var a = e.target.closest ? e.target.closest("a[href]") : null;  if (a) sign(a); }, true);
        });
    })();
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

    var _sysPromptCallback = null;
    function sysPrompt(message, callback, title = "TinyCash", defaultValue = "") {     /* (message was never shown - only the title) */
        var modal = document.getElementById("custom-prompt");
        if (!modal) { callback(window.prompt(message, defaultValue)); return; }
        document.getElementById("custom-prompt-title").innerText = title;
        document.getElementById("custom-prompt-text").innerText = message || "";
        var input = document.getElementById("custom-prompt-input");
        input.value = defaultValue || "";
        _sysPromptCallback = callback;
        modal.style.display = "flex";
        setTimeout(function() { input.focus(); input.select(); }, 50);
    }

    function _sysPromptConfirm() {
        var input = document.getElementById("custom-prompt-input");
        var val = input.value;
        document.getElementById("custom-prompt").style.display = "none";
        if (_sysPromptCallback) _sysPromptCallback(val);
    }

    function _sysPromptCancel() {
        document.getElementById("custom-prompt").style.display = "none";
        if (_sysPromptCallback) _sysPromptCallback(null);
    }

    document.addEventListener("keydown", function(e) {
        var modal = document.getElementById("custom-prompt");
        if (modal && modal.style.display === "flex") {
            if (e.key === "Enter") { e.preventDefault(); _sysPromptConfirm(); }
            else if (e.key === "Escape") { e.preventDefault(); _sysPromptCancel(); }
        }
    });

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
        // RETTET (bruger-rapporteret: skillelinjen under selve titel-rækken
        // skal følge fold-tilstanden, ikke kun vises ved sidens første
        // indlæsning) - se htm_Card_()s tilsvarende PHP-rettelse i php2htm.lib.php.
        const caption = document.getElementById(id + "_caption");
        if (caption) {
            caption.style.borderBottom = opening ? "2px solid var(--color-primary)" : "none";
            // RETTET (bruger-rapporteret: asymmetrisk margin over/under
            // caption i lukket tilstand, som om der stadig var reserveret
            // plads til linjen) - de 15px/10px hørte til linjen og skal
            // følge den, ikke blive stående som tomt rum.
            caption.style.marginBottom = opening ? "15px" : "0";
            caption.style.paddingBottom = opening ? "10px" : "0";
        }
        updateToggleAllCardsButton();
    }
    // RETTET (bruger-rapporteret: "ikke der de skulle placeres, men på card
    // ved siden af fold-ikonen") - der findes ikke længere ÉN fælles knap
    // (id="fab-toggle-cards") - htm_Card_() (php2htm.lib.php) tegner nu et
    // ens dobbeltpil-ikon på HVERT foldbart kort, alle med samme
    // "tc-toggle-all-icon"-klasse og pakket i en "tc-toggle-all-wrap"-span.
    // Alle instanserne styres samlet herfra, så de altid viser præcis samme
    // retning/tooltip, uanset hvilket af dem man klikker på.
    function toggleAllCards(el) {
        const icons = document.querySelectorAll(".tc-fold-icon");
        const anyClosed = Array.from(icons).some(i => i.classList.contains("fa-chevron-right"));
        icons.forEach(icon => {
            const isClosed = icon.classList.contains("fa-chevron-right");
            if (anyClosed === isClosed) { toggleCard(icon.id.replace(/_icon$/, ""), icon); }
        });
        // toggleCard() kalder allerede updateToggleAllCardsButton() for os pr.
        // kort - ingen grund til at gøre det igen her.
    }
    function updateToggleAllCardsButton() {
        const wraps = document.querySelectorAll(".tc-toggle-all-wrap");
        if (!wraps.length) return;
        const icons = document.querySelectorAll(".tc-fold-icon");
        if (icons.length < 3) { wraps.forEach(w => w.style.display = "none"); return; }
        const anyClosed = Array.from(icons).some(i => i.classList.contains("fa-chevron-right"));
        wraps.forEach(wrap => {
            wrap.style.display = "";
            const icon = wrap.querySelector(".tc-toggle-all-icon");
            if (anyClosed) {
                icon.className = "fa-solid fa-angles-down tc-toggle-all-icon";
                wrap.setAttribute("data-hint", wrap.dataset.expandHint);
            } else {
                icon.className = "fa-solid fa-angles-up tc-toggle-all-icon";
                wrap.setAttribute("data-hint", wrap.dataset.collapseHint);
            }
        });
    }
    // Ikonerne renderes som en del af hvert korts caption-række, allerede
    // skrevet FØR dette <script>-blok (kortene ligger i selve sidens
    // brødtekst) - men vi venter alligevel til hele siden er færdigparset,
    // så det er robust uanset hvor på siden et givet kort ligger.
    document.addEventListener("DOMContentLoaded", updateToggleAllCardsButton);
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

        // Global søgning (bruger-anmodet): flyttet ud af selve menuen og
        // givet sin egen flydende rund knap i stedet, samme mønster og
        // placering som notesblok-/valuta-knapperne ovenfor (§global-search-
        // feature). Byttede plads med valuta-knappen (bruger-anmodet): søgning
        // sidder nu fast på bottom:70px (lige over notesblokken), valuta rykket
        // til bottom:120px i inc/currency_widget.php - ingen gap-udfyldnings-
        // logik nødvendig mere, da søgning altid ligger under valuta uanset om
        // valuta-modulet er aktivt. Ren navigation (ingen popup/modal som de to
        // andre), derfor et almindeligt <a>, ikke en <button> med onclick.
        echo '<a href="search.php" class="no-print" style="position: fixed; bottom: 70px; right: 3px; z-index: 10001; background: #2c3e50; color: white; border: 2px solid var(--color-primary); border-radius: 50%; width: 50px; height: 50px; font-size: 20px; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; text-decoration: none;" title="' . lang('@Search') . '">🔍</a>';

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

        // Gentagne/faste udgifter: udgiftssidens modstykke til ovenstående,
        // samme "tjek ved hver sidevisning"-mønster. Opretter altid kun
        // UBOGFØRTE udgifter, aldrig bogført direkte - se
        // inc/recurring_expenses.inc.php's egen kommentar for hvorfor det er
        // endnu strengere begrundet her end for fakturaer. Fejler tavst
        // (try/catch) hvis tabellen ikke findes endnu
        // (§migrate_recurring_expenses) - må ikke kunne vælte en helt
        // almindelig sidevisning.
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