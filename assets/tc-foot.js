/* /assets/tc-foot.js v:1.3.1 d:2026-09-15 i:evs */
/* sysAlert/sysPrompt, tooltip (data-hint), tabelsøgning og fold af kort - indlæses af htm_Footer() efter dialog-elementerne. */
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
