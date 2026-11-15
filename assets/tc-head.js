/* /assets/tc-head.js v:1.3.1 d:2026-09-15 i:evs */
/* Fuld skærm, fold alle sektioner og temaskift - indlæses i <head> af htm_Header(). */
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
        document.documentElement.setAttribute('data-theme', themeName);
        const d = new Date();
        d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = "theme=" + themeName + ";expires=" + d.toUTCString() + ";path=/;SameSite=Strict";
    }

