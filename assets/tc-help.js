/* /assets/tc-help.js v:1.3.1 d:2026-09-15 i:evs */
/* Hjælpevinduet: åbn/luk og træk (interact.js hentes ved behov). */
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
