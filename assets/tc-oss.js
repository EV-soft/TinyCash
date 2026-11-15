// EU-OSS/IOSS på faktura- og tilbudssiden: en privat kunde i et andet EU-land får modtagerlandets standardsats på linjer
// med den hjemlige standardsats (og tilbage igen ved skift til en anden kunde). Andre satser rettes i hånden;
// bogføringen afviser en sats landet ikke har. Siden sætter window.tcOss = {map: {kunde: [land, sats]}, home, note,
// totals: 'funktionsnavn', ioss: {kunde: [land, sats]}, iossNote} før denne fil indlæses. ioss gælder, når
// feltet inv_ioss står på 1 (fakturaen er et IOSS-salg - varer sendt fra et land uden for EU). Feltet inv_oss_off
// (i #oss-off-wrap, vist for en OSS-kunde) på 1 = en ydelse beskattet i eget land: ingen OSS-sats.
(function () {
    var cfg = window.tcOss || {map: {}, home: 25, note: '', totals: ''};
    var cur = null;
    var num = function (v) { return typeof tcNum === 'function' ? tcNum(v) : parseFloat(String(v).replace(',', '.')) || 0; };
    var field = function (n) { return document.querySelector('[name="' + n + '"]'); };
    var isIoss = function () { var f = field('inv_ioss'); return !!(f && f.value === '1' && cfg.ioss); };
    var isOff = function () { var f = field('inv_oss_off'); return !!(f && f.value === '1'); };
    window.ossRateFor = function (r) {
        return (cur && Math.abs(num(r) - cfg.home) < 0.001) ? cur[1] : null;
    };
    window.ossApply = function (custId) {
        var ioss = isIoss();
        var wrap = document.getElementById('oss-off-wrap');
        if (wrap) wrap.style.display = (!ioss && cfg.map[custId]) ? 'block' : 'none';
        var next = (ioss ? cfg.ioss[custId] : (isOff() ? null : cfg.map[custId])) || null;
        document.getElementsByName('line_vat[]').forEach(function (f) {
            var v = num(f.value);
            if (next && Math.abs(v - cfg.home) < 0.001) f.value = next[1];
            else if (!next && cur && Math.abs(v - cur[1]) < 0.001) f.value = cfg.home;
            else if (next && cur && Math.abs(v - cur[1]) < 0.001) f.value = next[1];
        });
        cur = next;
        var note = document.getElementById('oss-note');
        if (note) {
            note.style.display = next ? 'block' : 'none';
            note.textContent = next ? (ioss ? cfg.iossNote : cfg.note).replace('%s', next[0]).replace('%s', String(next[1]).replace('.', window.tcDec || ',')) : '';
        }
        if (cfg.totals && typeof window[cfg.totals] === 'function') window[cfg.totals]();
    };
    document.addEventListener('DOMContentLoaded', function () {
        var sel = field('cust_id');
        if (sel && sel.value) window.ossApply(sel.value);
        ['inv_ioss', 'inv_oss_off'].forEach(function (n) {
            var f = field(n);
            if (f) f.addEventListener('change', function () { var s = field('cust_id'); window.ossApply(s ? s.value : ''); });
        });
    });
})();
