// Nemhandel-status på fakturaen og kundekortet: et element med data-nh-cust="<kunde-id>" får teksten fra et opslag
// (nemhandel_lookup.php). Teksterne (oversatte) ligger i elementets data-nh-yes/-no/-noinv/-nokey/-err.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-nh-cust]').forEach(function (el) {
        var url = (el.getAttribute('data-nh-url') || 'nemhandel_lookup.php') + '?cust_id=' + encodeURIComponent(el.getAttribute('data-nh-cust'));
        var show = function (key, color, extra) {
            el.textContent = (el.getAttribute('data-nh-' + key) || '') + (extra || '');
            el.style.color = color;
            el.style.display = '';
        };
        fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.success) return show('err', 'var(--text-muted)');
            if (d.foreign) return;
            if (d.nokey) return show('nokey', 'var(--text-muted)');
            if (!d.found) return show('no', 'var(--text-muted)');
            if (!d.invoice) return show('noinv', 'var(--color-warning)', d.key ? ' (' + d.key + ')' : '');
            show('yes', 'var(--color-success)', d.key ? ' (' + d.key + ')' : '');
        }).catch(function () { show('err', 'var(--text-muted)'); });
    });
});
