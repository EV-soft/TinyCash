/* /assets/tc-menuctx.js v:1.3.1 d:2026-09-15 i:evs */
/* Admins højreklik-menu. Tekster og CSRF-nøgle kommer fra window.tcMenuCtx (sat inline af showMenu()). */
        (function() {
            // NYT (bruger-anmodet: "Det skal være valgbart L1 / L2 alene
            // eller begge"): L1 og L2 kan nu skjules/vises helt uafhængigt
            // af hinanden via to separate afkrydsningsfelter, i stedet for
            // den tidligere ene "skjul begge på én gang"-knap. Nuværende
            // tilstand kommer fra data-hidden-l1/data-hidden-l2 (udskrevet
            // server-side ud fra det allerede indlæste $overrides, se
            // $ctxAttrs() ovenfor i denne fil) - intet AJAX-kald nødvendigt
            // bare for at ÅBNE menuen og se hvad der allerede er skjult.
            var ctxKey = null, ctxHasChildren = false, ctxL1Initial = false, ctxL2Initial = false, ctxTarget = 'menu';
            var menu = document.getElementById('tc-menu-ctx');
            var labelEl = menu.querySelector('.tc-menu-ctx-label');
            var l1Box = document.getElementById('tc-menu-ctx-l1');
            var l2Box = document.getElementById('tc-menu-ctx-l2');
            var l1Txt = document.getElementById('tc-menu-ctx-l1-txt');
            var l2Txt = document.getElementById('tc-menu-ctx-l2-txt');
            var saveBtn = document.getElementById('tc-menu-ctx-save');
            var restoreBtn = document.getElementById('tc-menu-ctx-restore');
            var TXT = window.tcMenuCtx.txt;
            l1Txt.textContent = TXT.hideL1;
            l2Txt.textContent = TXT.hideL2;
            saveBtn.textContent = TXT.save;
            restoreBtn.textContent = TXT.restore;
            document.addEventListener('contextmenu', function(e) {
                // NYT (bruger-anmodet): samme kontekstmenu genbruges nu til to
                // FORSKELLIGE ting - et menupunkt ([data-menukey], mod
                // menu_hide_action.php) eller et enkelt, markeret formularfelt
                // ([data-fieldkey], mod field_hide_action.php, se inc/field_
                // visibility.inc.php). closest() prøver menupunktet FØRST, da
                // et felt aldrig kan ligge inden i et menupunkt, men et
                // menupunkt (i sjældne tilfælde) teoretisk kunne indeholde et
                // markeret felt - menupunktet skal derfor vinde ved en reel
                // konflikt, ikke omvendt.
                var el = e.target.closest('[data-menukey]');
                var isField = false;
                if (!el) {
                    el = e.target.closest('[data-fieldkey]');
                    isField = !!el;
                }
                if (!el) return;
                e.preventDefault();
                ctxTarget = isField ? 'field' : 'menu';
                ctxKey = el.getAttribute(isField ? 'data-fieldkey' : 'data-menukey');
                // Felter har ingen undermenupunkter - kaskade-advarslen er
                // udelukkende relevant for menupunkter.
                ctxHasChildren = !isField && el.getAttribute('data-haschildren') === '1';
                labelEl.textContent = ctxKey;
                l1Box.checked = el.getAttribute('data-hidden-l1') === '1';
                l2Box.checked = el.getAttribute('data-hidden-l2') === '1';
                ctxL1Initial = l1Box.checked;
                ctxL2Initial = l2Box.checked;
                menu.style.display = 'block';
                var x = e.clientX, y = e.clientY;
                var mw = 280, mh = 160;
                if (x + mw > window.innerWidth) x = window.innerWidth - mw - 10;
                if (y + mh > window.innerHeight) y = window.innerHeight - mh - 10;
                menu.style.left = x + 'px';
                menu.style.top = y + 'px';
            });
            document.addEventListener('click', function(e) {
                if (!menu.contains(e.target)) menu.style.display = 'none';
            });
            function callAction(action, extra) {
                var fd = new FormData();
                fd.append('key', ctxKey);
                fd.append('action', action);
                if (extra) { for (var k in extra) fd.append(k, extra[k]); }
                fd.append('csrf_token', window.tcMenuCtx.tok);
                var endpoint = (ctxTarget === 'field') ? 'field_hide_action.php' : 'menu_hide_action.php';
                fetch(endpoint, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        menu.style.display = 'none';
                        if (data.success) {
                            alert(action === 'set_levels' ? (ctxTarget === 'field' ? TXT.doneField : TXT.doneMenu) : TXT.restored);
                            window.location.reload();
                        } else {
                            alert(TXT.error + (data.error ? ' (' + data.error + ')' : ''));
                        }
                    })
                    .catch(function() { menu.style.display = 'none'; alert(TXT.error); });
            }
            saveBtn.addEventListener('click', function() {
                // RETTET (selv-fanget, "find fem fejl"): sendte FØR altid
                // BEGGE niveauers tilstand, uanset om brugeren reelt havde
                // rørt ved dem - baseret på et snapshot taget da menuen blev
                // åbnet. To administratorer der samtidig højreklikkede samme
                // punkt og hver især kun ændrede ÉT niveau kunne dermed
                // tabe hinandens ændring (den anden admins snapshot af det
                // niveau VEDKOMMENDE ikke rørte var forældet og overskrev
                // stille den første admins reelle ændring). Sender nu KUN
                // det/de niveauer der faktisk blev ændret siden menuen blev
                // åbnet - serveren beholder den aktuelle, friskt-læste værdi
                // for et niveau der slet ikke indgår i requesten (se
                // menu_hide_action.php's tilsvarende rettelse).
                var l1Changed = l1Box.checked !== ctxL1Initial;
                var l2Changed = l2Box.checked !== ctxL2Initial;
                if (!l1Changed && !l2Changed) { menu.style.display = 'none'; return; }
                // Advar kun om kaskaden når et niveau reelt SKIFTER fra synlig
                // til skjult her og nu - ikke blot fordi det (evt. allerede
                // fra før) står som skjult i det snapshot menuen blev åbnet med.
                var newlyHidingChildren = ctxHasChildren &&
                    ((l1Changed && l1Box.checked) || (l2Changed && l2Box.checked));
                if (newlyHidingChildren && !confirm(TXT.warnChildren)) { menu.style.display = 'none'; return; }
                var extra = {};
                if (l1Changed) extra.hide_l1 = l1Box.checked ? '1' : '0';
                if (l2Changed) extra.hide_l2 = l2Box.checked ? '1' : '0';
                callAction('set_levels', extra);
            });
            restoreBtn.addEventListener('click', function() { callAction('restore'); });
        })();
