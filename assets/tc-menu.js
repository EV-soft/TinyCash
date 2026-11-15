/* /assets/tc-menu.js v:1.3.1 d:2026-09-15 i:evs */
/* Topmenu/sidepanel: undermenuer, sprog, tema, zoom, layoutskift - indlæses af showMenu(). */
    function adjustZoom(d) { document.body.style.zoom = (parseFloat(document.body.style.zoom || 1) + d); }
    function toggleSub(el) {
        var sub = el.nextElementSibling;
        document.querySelectorAll(".submenu").forEach(s => { if (s !== sub) s.style.display = "none"; });
        if (sub) sub.style.display = (sub.style.display === "block") ? "none" : "block";
    }
    function toggleTestLevel(currentLevel) {
        var next = currentLevel + 1; if (next > 3) next = 1;
        // RETTET: byggede FØR URL\x27en som href.split("?")[0] + "?set_level=" - men et "#" i adressen (fx efter et
        // klik paa et href="#"-undermenu-/hjaelpe-link) blev haengende, saa "?set_level=" endte INDE i fragmentet
        // ("side#?set_level=2") og aldrig naaede serveren: niveauet skiftede ikke. URL-API\x27et fjerner fragmentet
        // og saetter parameteren korrekt, uanset hvad der ellers staar i adressen.
        try {
            var u = new URL(window.location.href);
            u.hash = "";
            u.searchParams.set("set_level", next);
            window.location.href = u.toString();
        } catch (e) {
            window.location.href = window.location.pathname + "?set_level=" + next;
        }
    }
    function toggleLangMenu(e) {
        e.stopPropagation();
        var m = document.getElementById("lang-options");
        document.querySelectorAll(".submenu").forEach(s => s.style.display = "none");
        m.style.display = (m.style.display === "block") ? "none" : "block";
    }
    function toggleTheme() {
        var themes = ["light","custom","dark"];
        var current = document.documentElement.getAttribute("data-theme") || "light";
        if (!themes.includes(current)) current = "light";
        var next = themes[(themes.indexOf(current) + 1) % themes.length];
        document.documentElement.setAttribute("data-theme", next);
        var d = new Date(); d.setTime(d.getTime() + 365*24*60*60*1000);
        document.cookie = "theme=" + next + ";expires=" + d.toUTCString() + ";path=/;SameSite=Strict";
        var icons = {light:"☀️", custom:"✨", dark:"🌙"};
        ["theme-toggle-btn","sb-theme-btn"].forEach(id => {
            var btn = document.getElementById(id);
            if (btn) btn.innerText = icons[next];
        });
        fetch(window.location.href.split("?")[0] + "?set_theme_session=" + next + "&t=" + Date.now(),
            {method:"GET", headers:{"X-Requested-With":"XMLHttpRequest"}}).catch(() => {});
    }
    function toggleFullscreen() {
        if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {});
        else document.exitFullscreen();
    }
    function sbToggleSub(el) {
        el.classList.toggle("open");
        var sub = el.nextElementSibling;
        if (sub) sub.classList.toggle("open");
    }
    function sbToggleSubSub(el) {
        var sub = el.nextElementSibling;
        if (sub) sub.classList.toggle("open");
    }
    function toggleMenuLayout() {
        var next = (localStorage.getItem("tc_menu_layout") === "sidebar") ? "top" : "sidebar";
        localStorage.setItem("tc_menu_layout", next);
        document.body.classList.toggle("sidebar-mode", next === "sidebar");
    }
    // Anvend gemt layout straks (undgår flash)
    (function() {
        if (localStorage.getItem("tc_menu_layout") === "sidebar")
            document.body.classList.add("sidebar-mode");
    })();
    window.onclick = function(e) {
        if (!e.target.closest(".dropdown") && !e.target.closest(".custom-lang-dropdown"))
            document.querySelectorAll(".submenu, #lang-options, #sb-lang-options").forEach(m => m.style.display = "none");
    };
    function toggleSbLang(e) {
        e.stopPropagation();
        var m = document.getElementById("sb-lang-options");
        if (m) m.style.display = (m.style.display === "block") ? "none" : "block";
    }
