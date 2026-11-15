/* /assets/tc-table.js v:1.3.1 d:2026-09-15 i:evs */
/* htm_Table(): sortering, fold af celler og CSV-eksport. */
    function sortTable(tableId, n) {
        var table = document.getElementById(tableId); if (!table) return;
        // Find den n'te synlige th (spring skjulte over)
        var ths = Array.from(table.getElementsByTagName("th"))
                       .filter(function(t) { return !t.classList.contains("col-hidd"); });
        var th = ths[n];
        if (!th || !th.classList.contains("sort-ptr")) return;

        // Find kolonneindeks i tabellen (inkl. skjulte)
        var allThs = Array.from(table.getElementsByTagName("th"));
        var colIdx = allThs.indexOf(th);

        // RETTET: (1) boblesortering der flyttede én DOM-række ad gangen og startede forfra efter hver flytning - O(n^2)
        // med DOM-opslag, så en tabel med et par hundrede rækker frøs browseren i sekunder; (2) beløb med valuta eller
        // enhed ("-1.250,00 DKK", "1.250,00 kr.", "25 %") og tal med én decimal ("12,5") blev sorteret som TEKST.
        // Nu: hver celles sorteringsværdi beregnes én gang, rækkerne sorteres som et array (stabilt) og sættes ind igen.
        function getCleanValue(cell) {
            if (!cell) return "";
            var text = cell.innerText.trim(); var lower = text.toLowerCase();
            if (lower.endsWith(" mb") || lower.endsWith(" kb")) {
                var num = parseFloat(text.replace(/[^\d,.-]/g, "").replace(",", "."));
                if (isNaN(num)) return 0;
                return lower.endsWith(" mb") ? num * 1024 * 1024 : num * 1024;
            }
            var dm = text.match(/^(\d{2})[.\/-](\d{2})[.\/-](\d{4})(?:\s(\d{2}):(\d{2}))?$/);
            if (dm) return new Date(+dm[3], +dm[2]-1, +dm[1], +(dm[4] || 0), +(dm[5] || 0)).getTime();
            // (tusindtalsseparatoren er "." i DK/IS, men et hårdt mellemrum i SE/NO/FI: "1 250,00" sorterede som tekst)
            // (enhver ISO-valutakode - kun 7 var med, så "1.250,00 CHF"/"PLN"/"CZK" sorterede som tekst: 100 før 20)
            var nm = text.replace(/\s*([a-z]{3}|kr\.?|%)$/i, "").replace(/−/g, "-");
            if (/^-?\d{1,3}([.   ]\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/.test(nm)) {
                var cleanNum = parseFloat(nm.replace(/[.   ]/g, "").replace(",", "."));
                if (!isNaN(cleanNum)) return cleanNum;
            }
            return lower;
        }
        var body = table.tBodies[0] || table;
        var rows = Array.from(body.rows).filter(function(r) { return r.getElementsByTagName("TD").length > 0; });
        var dir = (table.dataset.sortCol == String(colIdx) && table.dataset.sortDir === "asc") ? "desc" : "asc";
        table.dataset.sortCol = String(colIdx);  table.dataset.sortDir = dir;
        var keyed = rows.map(function(r, i) { return { r: r, i: i, v: getCleanValue(r.getElementsByTagName("TD")[colIdx]) }; });
        keyed.sort(function(a, b) {
            var x = a.v, y = b.v, c;
            if (typeof x === typeof y) c = (x < y) ? -1 : (x > y ? 1 : 0);
            else c = (typeof x === "number") ? -1 : 1;      // tal før tekst
            if (dir === "desc") c = -c;
            return c || (a.i - b.i);
        });
        keyed.forEach(function(k) { body.appendChild(k.r); });
    }

    function toggleCell(cell) {
        if (cell.classList.contains("expanded")) {
            cell.classList.remove("expanded");
            cell.classList.add("truncate");
        } else {
            cell.classList.remove("truncate");
            cell.classList.add("expanded");
        }
    }

    // RETTET (§bugs-batch-13-review): CSV/Excel-formel-injektion. En celle
    // der starter med =, +, - eller @ kan af Excel/LibreOffice blive
    // fortolket som en formel i stedet for tekst, hvis brugeren senere åbner
    // den eksporterede CSV-fil - samme sårbarhedsklasse som allerede var
    // rettet i export.php/inventory_export.php (server-side CSV-eksport),
    // men ALDRIG i denne fælles klient-side eksportknap, som en lang række
    // andre sider (audit_log.php, customer_statement.php, inventory_status.php
    // m.fl.) genbruger via htm_Table(...,'fil.csv'). Data i cellerne kan
    // stamme fra frit tekstfelter (kundenavn, banktekst osv.), som en bruger
    // reelt kontrollerer.
    // RETTET: alt der begyndte med "-" fik en apostrof foran (formel-værn) - også negative beløb ("-1.250,00 DKK"),
    // som så blev tekst med en synlig ' i Excel og ikke kunne regnes med. Et tal (evt. med valuta/enhed) er ufarligt.
    // (enhver ISO-valutakode, som sorteringen - "-1.250,00 CHF" fik en apostrof og blev tekst i Excel)
    function csvSafe(v) {
        if (/^[-−]?\d{1,3}([.   ]\d{3})*(,\d+)?(\s*([a-z]{3}|kr\.?|%))?$|^[-−]?\d+(,\d+)?(\s*([a-z]{3}|kr\.?|%))?$/i.test(v)) return v;
        return (v.length > 0 && "=+-@".indexOf(v[0]) !== -1) ? "'" + v : v;
    }

    function exportCsv(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var rows = [];

        // Headers (kun synlige)
        var hdrs = Array.from(table.querySelectorAll("thead th"))
                        .filter(function(t) { return !t.classList.contains("col-hidd"); })
                        .map(function(t) { return '"' + csvSafe(t.innerText.trim()).replace(/"/g, '""') + '"'; });
        rows.push(hdrs.join(";"));

        // Data-rækker (kun synlige kolonner og rækker)
        Array.from(table.querySelectorAll("tbody tr")).forEach(function(tr) {
            if (tr.style.display === "none") return;
            var cells = Array.from(tr.querySelectorAll("td"))
                             .filter(function(td) { return !td.classList.contains("col-hidd"); })
                             .map(function(td) { return '"' + csvSafe(td.innerText.trim()).replace(/"/g, '""') + '"'; });
            rows.push(cells.join(";"));
        });

        var bom    = "\uFEFF"; // BOM så Excel åbner UTF-8 korrekt
        var blob   = new Blob([bom + rows.join("\n")], { type: "text/csv;charset=utf-8;" });
        var url    = URL.createObjectURL(blob);
        var a      = document.createElement("a");
        a.href     = url;
        a.download = filename;
        a.click();
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);      // (straks efter klik kunne nogle browsere afbryde hentningen)
    }
