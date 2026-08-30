<?php # /inc/currency_widget.php v:1.3.0 d:2026-08-30 i:evs
# Valutaomregner-popup — inkluderes i htm_Footer() eller på den ønskede side
# Bruger frankfurter.app (ECB-data, ingen API-nøgle)
?>
<style>
#currency-btn {
    position: fixed; bottom: 70px; right: 20px; z-index: 9998;
    background: var(--color-dark); color: white;
    border: none; border-radius: 50%; width: 50px; height: 50px;
    font-size: 20px; cursor: pointer;
    box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    display: flex; align-items: center; justify-content: center;
    transition: background 0.2s;
}
#currency-btn:hover { background: var(--color-primary); }

#currency-modal {
    display: none; position: fixed;
    bottom: 130px; right: 20px; z-index: 9997;
    width: 300px; background: var(--bg-card);
    border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,0.25);
    border: 1px solid var(--border-color);
    font-family: sans-serif; overflow: hidden;
    animation: slideUp 0.2s ease;
}
@keyframes slideUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

#currency-modal .cm-header {
    background: var(--color-dark); color: white;
    padding: 12px 15px; display: flex; justify-content: space-between; align-items: center;
}
#currency-modal .cm-header span { font-weight: bold; font-size: 14px; }
#currency-modal .cm-header button { background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 0; line-height: 1; }

#currency-modal .cm-body { padding: 16px; display: flex; flex-direction: column; gap: 10px; }

#currency-modal input[type=number] {
    width: 100%; padding: 8px 10px; font-size: 1.1em;
    border: 1px solid var(--border-color) !important;
    border-radius: 6px; box-sizing: border-box;
    background: var(--bg-card) !important; color: var(--text-main) !important;
}
#currency-modal select {
    padding: 7px 10px; border-radius: 6px; font-size: 14px;
    border: 1px solid var(--border-color) !important;
    background: var(--bg-card) !important; color: var(--text-main) !important;
    flex: 1;
}
#currency-modal .cm-row { display: flex; gap: 8px; align-items: center; }
#currency-modal .cm-arrow { font-size: 20px; color: var(--text-muted); flex-shrink: 0; }
#currency-modal .cm-result {
    background: var(--bg-panel); border-radius: 6px;
    padding: 12px; text-align: center;
}
#currency-modal .cm-result .cm-amount {
    font-size: 1.6em; font-weight: bold; color: var(--color-primary);
    font-family: "Courier New", monospace;
}
#currency-modal .cm-result .cm-rate {
    font-size: 11px; color: var(--text-muted); margin-top: 4px;
}
#currency-modal .cm-footer {
    font-size: 10px; color: var(--text-muted);
    text-align: center; padding: 8px; border-top: 1px solid var(--border-subtle);
}
#currency-modal .cm-swap {
    background: none; border: 1px solid var(--border-color); border-radius: 50%;
    width: 28px; height: 28px; cursor: pointer; font-size: 14px;
    color: var(--text-muted); display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
#currency-modal .cm-swap:hover { background: var(--bg-panel); color: var(--color-primary); }
</style>

<button id="currency-btn" onclick="toggleCurrencyModal()" title="<?php echo lang('@Currency Converter'); ?>" data-hint="<?php echo lang('@Currency Converter'); ?>">
    💱
</button>

<div id="currency-modal">
    <div class="cm-header">
        <span>💱 <?php echo lang('@Currency Converter'); ?></span>
        <button onclick="toggleCurrencyModal()">×</button>
    </div>
    <div class="cm-body">
        <input type="number" id="cm-input" value="1" min="0" step="any"
               oninput="convertCurrency()" placeholder="<?php echo lang('@Amount'); ?>">

        <div class="cm-row">
            <select id="cm-from" onchange="convertCurrency()"></select>
            <button class="cm-swap" onclick="swapCurrencies()" title="<?php echo lang('@Swap currencies'); ?>">⇄</button>
            <select id="cm-to" onchange="convertCurrency()"></select>
        </div>

        <div class="cm-result">
            <div class="cm-amount" id="cm-result">—</div>
            <div class="cm-rate"   id="cm-rate"></div>
        </div>
    </div>
    <div class="cm-footer">
        <?php echo lang('@Rates from European Central Bank via frankfurter.app'); ?>
        — <span id="cm-date"></span>
    </div>
</div>

<script>
(function() {
    var _rates = {};
    var _base  = 'EUR';
    var _date  = '';

    // Populære valutaer øverst, resten alfabetisk
    var _preferred = ['DKK','EUR','USD','GBP','SEK','NOK','CHF','JPY','CAD','AUD'];

    function buildOptions(selectEl, selected) {
        var all = Object.keys(_rates).concat([_base]);
        all = all.filter(function(v, i, a) { return a.indexOf(v) === i; }).sort();

        // Preferred først, derefter resten
        var ordered = _preferred.filter(function(v) { return all.indexOf(v) !== -1; });
        all.forEach(function(v) { if (ordered.indexOf(v) === -1) ordered.push(v); });

        selectEl.innerHTML = '';
        ordered.forEach(function(code) {
            var opt = document.createElement('option');
            opt.value = code;
            opt.textContent = code;
            if (code === selected) opt.selected = true;
            selectEl.appendChild(opt);
        });
    }

    function loadRates() {
        document.getElementById('cm-result').textContent = '…';
        fetch('<?php echo (isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '') . '/currency_proxy.php'; ?>')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                _rates = data.rates;
                _base  = data.base;
                _date  = data.date;
                document.getElementById('cm-date').textContent = _date;

                var fromSel = document.getElementById('cm-from');
                var toSel   = document.getElementById('cm-to');
                buildOptions(fromSel, 'EUR');
                buildOptions(toSel,   'DKK');
                convertCurrency();
            })
            .catch(function() {
                document.getElementById('cm-result').textContent = '<?php echo lang('@Could not load rates'); ?>';
            });
    }

    function toEUR(amount, from) {
        if (from === _base) return amount;
        return amount / _rates[from];
    }

    function fromEUR(amount, to) {
        if (to === _base) return amount;
        return amount * _rates[to];
    }

    window.convertCurrency = function() {
        var amount = parseFloat(document.getElementById('cm-input').value);
        var from   = document.getElementById('cm-from').value;
        var to     = document.getElementById('cm-to').value;
        if (isNaN(amount) || !_rates[from] && from !== _base || !_rates[to] && to !== _base) return;

        var result = fromEUR(toEUR(amount, from), to);
        var rate   = fromEUR(toEUR(1, from), to);

        document.getElementById('cm-result').textContent =
            result.toLocaleString('da-DK', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) + ' ' + to;
        document.getElementById('cm-rate').textContent =
            '1 ' + from + ' = ' + rate.toLocaleString('da-DK', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) + ' ' + to;
    };

    window.swapCurrencies = function() {
        var fromSel = document.getElementById('cm-from');
        var toSel   = document.getElementById('cm-to');
        var tmp = fromSel.value;
        fromSel.value = toSel.value;
        toSel.value   = tmp;
        convertCurrency();
    };

    var _loaded = false;
    window.toggleCurrencyModal = function() {
        var modal = document.getElementById('currency-modal');
        if (modal.style.display === 'block') {
            modal.style.display = 'none';
        } else {
            modal.style.display = 'block';
            if (!_loaded) { _loaded = true; loadRates(); }
        }
    };
})();
</script>
