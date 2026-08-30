<?php # /sales_hub.php v:1.3.0 d:2026-08-30 i:evs
# Salg & kunder: samlet oversigt med to lister side om side.
# Kundeliste: navn/email, med knapper til Redigér og Kontoudtog (customer_statement.php).
# Fakturaliste: nr./dato/kunde/beløb (inkl. moms, viser restbeløb ved delvis
# betaling)/status, filtrerbar på status (alle/kladde/sendt/betalt). Handlinger
# afhænger af status: kladder kan redigeres/bogføres/slettes; bogførte
# fakturaer (der ikke allerede er krediteret eller selv er en kreditnota) kan
# krediteres. Bogføring og mailafsendelse sker async via fetch() mod
# invoice_post_action.php/send_invoice_action.php, med en fælles styled
# sysConfirm()-bekræftelsesdialog i stedet for native confirm().
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$current_status = isset($_GET['status']) ? $_GET['status'] : 'all';

htm_Header("@Sales & Customers", 1600);
showMenu();

// RETTET (se [[bugs-batch-10-review]]): invoice_credit.php's err=-omdirigeringer
// hertil har ALDRIG været vist - landede altid tavst på denne side uden
// nogen synlig fejlbesked. Samme "stille fejl"-mønster som er fundet og
// rettet flere gange denne session.
if (isset($_GET['err'])) {
    $err_map = [
        'invalid_credit_ref'          => '@Invoice or customer not found',
        'not_found'                   => '@Invoice or customer not found',
        'already_credited'            => '@This invoice has already been credited.',
        'date_locked'                 => '@Credit date is in a locked accounting period.',
        'cannot_credit_a_credit_note' => '@A credit note cannot itself be credited.',
    ];
    if (isset($err_map[$_GET['err']])) {
        htm_Alert(lang($err_map[$_GET['err']]), 'error');
    }
}

echo '<style>
    .hub-total { font-family: "Courier New", monospace; font-size: 1.1rem; font-weight: 600; text-align: right; }
    #hub_cust th, #hub_inv th, th.sortable { color: #333333 !important; font-weight: bold !important; }
    th.sortable { cursor: pointer; position: relative; }
    th.sortable:after { content: "\f0dc"; font-family: FontAwesome; padding-left: 5px; color: #ccc; }
    #hub_cust td, #hub_inv td { color: var(--theme-text, #333); }
    .filter-label { font-size:0.8rem; font-weight:bold; margin-right:5px; font-family:sans-serif; color: var(--theme-text-muted, #555); }
    .filter-btn { padding:5px 12px; border:1px solid var(--theme-border, #ddd); border-radius:15px; cursor:pointer; font-size:0.75rem; }

    [data-theme="dark"] .filter-label { color: #a4b0be !important; }
    [data-theme="dark"] .btn-info, [data-theme="dark"] [class*="info"], [data-theme="dark"] .bg-info, [data-theme="dark"] a[class*="info"], [data-theme="dark"] a[href*="type="] { background-color: #00a8ff !important; border-color: #00a8ff !important; color: #ffffff !important; }
    [data-theme="dark"] .btn-success, [data-theme="dark"] [class*="success"], [data-theme="dark"] .bg-success { background-color: #10ac84 !important; border-color: #10ac84 !important; color: #ffffff !important; }
    [data-theme="dark"] .btn-info a, [data-theme="dark"] .btn-info i, [data-theme="dark"] .btn-success a, [data-theme="dark"] .btn-success i, [data-theme="dark"] [id$="_card"] a, [data-theme="dark"] [id$="_card"] .btn i { color: #ffffff !important; text-decoration: none !important; }
    [data-theme="dark"] #hub_cust th, [data-theme="dark"] #hub_inv th, [data-theme="dark"] th.sortable { color: #ffffff !important; }
    [data-theme="dark"] #hub_cust td, [data-theme="dark"] #hub_inv td { color: #dcdde1 !important; }
    [data-theme="dark"] th.sortable:after { color: #57606f; }
    [data-theme="dark"] #c_card, [data-theme="dark"] #i_card, [data-theme="dark"] [class*="card"], [data-theme="dark"] [class*="panel"] { color: #ffffff !important; }
    [data-theme="dark"] #c_card h1, [data-theme="dark"] #c_card h2, [data-theme="dark"] #c_card h3, [data-theme="dark"] #c_card h4,
    [data-theme="dark"] #i_card h1, [data-theme="dark"] #i_card h2, [data-theme="dark"] #i_card h3, [data-theme="dark"] #i_card h4 { color: #ffffff !important; }

    /* ── sysConfirm modal ── */
    #sys-confirm-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.45); z-index: 999998;
        align-items: center; justify-content: center;
    }
    #sys-confirm-overlay.open { display: flex; }
    #sys-confirm-box {
        background: var(--bg-card); border-radius: 10px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        max-width: 420px; width: 90%; font-family: sans-serif;
        overflow: hidden; animation: scaleIn 0.15s ease;
    }
    @keyframes scaleIn { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    #sys-confirm-icon { font-size: 2.2em; margin-bottom: 8px; }
    #sys-confirm-title { margin: 0 0 6px; color: var(--color-dark); font-size: 1.05em; font-weight: 700; }
    #sys-confirm-text { color: var(--text-muted); font-size: 0.92em; line-height: 1.5; margin: 0; }
    #sys-confirm-buttons { display: flex; gap: 10px; padding: 14px 20px; justify-content: flex-end; background: var(--bg-panel); border-top: 1px solid var(--border-color); }
    #sys-confirm-buttons button { padding: 8px 20px; border: none; border-radius: 5px; font-size: 0.9em; font-weight: 600; cursor: pointer; transition: opacity 0.15s; }
    #sys-confirm-buttons button:hover { opacity: 0.85; }
    #sys-confirm-cancel { background: var(--bg-card); color: var(--text-muted); border: 1px solid var(--border-color) !important; }
    #sys-confirm-ok { color: white; }
</style>';

echo '<script>
// ── sysConfirm: styled confirm-dialog ────────────────────────────────────────
(function() {
    var _cb = null;
    window.sysConfirm = function(msg, callback, opts) {
        opts = opts || {};
        document.getElementById("sys-confirm-icon").textContent    = opts.icon    || "⚠️";
        document.getElementById("sys-confirm-title").textContent   = opts.title   || msg;
        document.getElementById("sys-confirm-text").textContent    = msg;
        document.getElementById("sys-confirm-ok").textContent      = opts.okLabel || "' . lang('@Confirm') . '";
        document.getElementById("sys-confirm-ok").style.background = opts.okColor || "var(--color-danger)";
        document.getElementById("sys-confirm-cancel").textContent  = opts.cancelLabel || "' . lang('@Cancel') . '";
        _cb = callback;
        document.getElementById("sys-confirm-overlay").classList.add("open");
    };
    window._sysConfirmOk = function() {
        document.getElementById("sys-confirm-overlay").classList.remove("open");
        if (typeof _cb === "function") _cb();
        _cb = null;
    };
    window._sysConfirmCancel = function() {
        document.getElementById("sys-confirm-overlay").classList.remove("open");
        _cb = null;
    };
})();

// ── Faktura-handlinger ────────────────────────────────────────────────────────
// RETTET (§bugs-batch-19-review): intet forhindrede et utålmodigt gentaget
// klik på "Bogfør" (eller en langsom forbindelse + et andet klik i mellem-
// tiden) i at affyre flere samtidige bogføringsforsøg for samme faktura, før
// siden nåede at genindlæse og vise den nye "sendt"-status. Server-siden
// (invoice_post_action.php) har nu sit eget, uafhængige kapløbsværn - denne
// simple in-flight-spærring her er blot et ekstra, hurtigere lag, der
// forhindrer selve dobbeltklikket i at sende det andet kald overhovedet.
var _postInvInFlight = {};
function postInv(id) {
    if (_postInvInFlight[id]) return;
    sysConfirm(
        "' . lang('@Are you sure you want to post this invoice? This will assign an official invoice number and lock the draft.') . '",
        function() {
            if (_postInvInFlight[id]) return;
            _postInvInFlight[id] = true;
            fetch("invoice_post_action.php?id=" + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success) { sysAlert("' . lang('@Invoice successfully posted with number: ') . '" + data.invoice_no); location.reload(); }
                    else { sysAlert("' . lang('@Posting failed: ') . '" + data.error); _postInvInFlight[id] = false; }
                })
                .catch(() => { sysAlert("' . lang('@System error: Could not connect to server.') . '"); _postInvInFlight[id] = false; });
        },
        { title: "' . lang('@Post Invoice') . '", icon: "📋", okLabel: "' . lang('@Post Invoice') . '", okColor: "var(--color-success)" }
    );
}

function delInv(id) {
    sysConfirm(
        "' . lang('@Delete Invoice?') . '",
        function() { window.location = "invoice_edit.php?id=" + id + "&del=1"; },
        { title: "' . lang('@Delete Invoice') . '", icon: "🗑️", okLabel: "' . lang('@Delete') . '", okColor: "var(--color-danger)" }
    );
}

function setFilter(status) {
    window.location.href = "sales_hub.php?status=" + status;
}

function sendMail(id) {
    sysConfirm(
        "' . lang('@Send Invoice via Email?') . '",
        function() {
            const btn = document.getElementById("mail_btn_" + id);
            const originalHTML = btn ? btn.innerHTML : "";
            if (btn) { btn.disabled = true; btn.innerHTML = \'<i class="fa fa-spinner fa-spin"></i>\'; btn.style.opacity = "0.6"; }
            fetch("send_invoice_action.php?id=" + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success) { sysAlert("' . lang('@Email sent successfully!') . '"); location.reload(); }
                    else {
                        sysAlert("' . lang('@Error: ') . '" + data.error);
                        if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; btn.style.opacity = "1"; }
                    }
                })
                .catch(() => {
                    sysAlert("' . lang('@System error: Could not connect to mail system.') . '");
                    if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; btn.style.opacity = "1"; }
                });
        },
        { title: "' . lang('@Send Invoice') . '", icon: "📧", okLabel: "' . lang('@Send') . '", okColor: "var(--color-info)" }
    );
}
</script>';

// ── sysConfirm HTML ───────────────────────────────────────────────────────────
echo '<div id="sys-confirm-overlay">
    <div id="sys-confirm-box">
        <div style="padding:24px 24px 16px; text-align:center;">
            <div id="sys-confirm-icon">⚠️</div>
            <h3 id="sys-confirm-title"></h3>
            <p id="sys-confirm-text"></p>
        </div>
        <div id="sys-confirm-buttons">
            <button id="sys-confirm-cancel" onclick="_sysConfirmCancel()">'.lang('@Cancel').'</button>
            <button id="sys-confirm-ok"     onclick="_sysConfirmOk()">'.lang('@Confirm').'</button>
        </div>
    </div>
</div>';

echo '<div style="display:flex; gap:10px; align-items:flex-start; padding:10px;">';

# KOLONNE 1: KUNDER
echo '<div style="flex:2; min-width:400px;">';
    $c_tools  = htm_Button('fa-file-csv',  '', 'info',    'export.php?type=customers',  'padding:4px 8px; margin-right:5px;', 'target="_blank" data-hint="'.lang('@Export All Data').'"', '', false);
    $c_tools .= htm_Button('fa-user-plus', '', 'success', 'customer_edit.php?id=0',     'padding:4px 8px;',                   'data-hint="'.lang('@Create New').'"',                    '', false);
    htm_Card_('@Customers', "100%", "", "c_card", true, $c_tools, fold: true); // TEST: fold-toggle

    $data_c = [];
    $resc = DB::query($conn, "SELECT cust_id, cust_name, cust_email FROM customers ORDER BY cust_name LIMIT 100");
    if ($resc) {
        while ($rowc = DB::fetch_assoc($resc)) {
            $btn = htm_ActionButtons([
                ['icon' => 'fa-pencil',              'link' => 'customer_edit.php?id='.$rowc['cust_id'],      'hint' => '@Edit', 'type' => 'primary'],
                ['icon' => 'fa-file-invoice-dollar',  'link' => 'customer_statement.php?id='.$rowc['cust_id'], 'hint' => '@Account Statement', 'type' => 'info'],
            ], false);
            // RETTET (§bugs-batch-13-review): cust_name/cust_email skrevet
            // direkte ind i tabellen uden escaping - htm_Table() antager at
            // hver celle allerede er den HTML der skal vises (mange kald
            // sender bevidst <a>/<span>), så uescaped fritekst som et
            // kundenavn er en lagret XSS (fx et kundenavn "<script>...").
            $data_c[] = [$rowc['cust_id'], htmlspecialchars($rowc['cust_name']), htmlspecialchars($rowc['cust_email']), $btn];
        }
    }
    htm_Table(['@ID', '@Name', '@Email', '@Action'], $data_c, 'hub_cust', 20, '', true,
        ['width:50px;', 'width:120px;', 'width:150px;', 'width:130px; text-align:center;']);
    htm_Card_end();
echo '</div>';

# KOLONNE 2: FAKTURAER
echo '<div style="flex:3;">';
    $i_tools  = htm_Button('fa-file-csv', '', 'info',    'export.php?type=invoices',  'padding:4px 8px; margin-right:5px;', 'target="_blank" data-hint="'.lang('@Export All Data').'"', '', false);
    $i_tools .= htm_Button('fa-plus',     '', 'success', 'invoice_edit.php?id=0',      'padding:4px 8px;',                   'data-hint="'.lang('@Create New').'"',                    '', false);
    htm_Card_("@Invoice List", "100%", "", "i_card", true, $i_tools);

    $i_filter  = '<div style="display:flex; gap:3px; margin-bottom:10px; align-items:center;" data-hint="'.lang('@Filter: Hide all data rows that do not meet criteria').'">';
    $i_filter .= '<span class="filter-label">' . lang('@Filter:') . '</span>';
    foreach (['all', 'draft', 'sent', 'paid'] as $f) {
        // RETTET: $f er altid småt ('draft'/'sent'/'paid'), men de faktiske
        // oversættelsesnøgler er med stort forbogstav (@Draft/@Sent/@Paid) -
        // lang('@draft') matchede derfor aldrig noget, og filterknapperne
        // viste altid det engelske ord uanset sprogvalg. Samme fejlklasse
        // fundet flere steder i denne runde (quote_view.php, year_end_close.php,
        // invoice_view.php, settings_fees.php).
        // NB: lang('@'.ucfirst($f)) kan frase-skanneren ikke selv opdage - de
        // faktiske nøgler nævnes derfor bevidst som strengliteraler herunder:
        // '@Draft', '@Sent', '@Paid'
        $l        = ($f === 'all') ? lang('@All') : lang('@'.ucfirst($f));
        $isActive = (strtolower($current_status) === $f);
        $bg       = $isActive ? 'var(--theme-primary, #3498db)' : 'var(--theme-field-bg, #fff)';
        $color    = $isActive ? '#fff' : 'var(--theme-text, #333)';
        $i_filter .= '<button type="button" onclick="setFilter(\''.$f.'\')" class="filter-btn" style="background:'.$bg.'; color:'.$color.'; font-weight:'.($isActive?'bold':'normal').';">'.$l.'</button>';
    }
    $i_filter .= '</div>';

    $where_clause = "";
    if ($current_status !== 'all') {
        $safe_status  = DB::real_escape_string($conn, strtolower($current_status));
        $where_clause = "WHERE LOWER(i.inv_status) = '$safe_status'";
    }

    $data_i = [];
    // RETTET 2026-08-20: ALVORLIGT FUND - "SUM(l.quantity * l.price_each)"
    // manglede momsen HELT (intet moms-udtryk overhovedet) - "Beløb"-kolonnen
    // viste nettobeløbet, ikke det kunden reelt skylder, i modsætning til
    // hver anden fakturasum-visning i systemet (invoice_view.php,
    // reminders.php osv.). "(100+rate)/100" i stedet for "(1+rate/100)", som
    // ville have udregnet momsen til 0 på SQLite (se
    // [[reminders-feature-and-vat-truncation-bug]]).
    // RETTET (§reel-multi-valuta-bogforing, §bugs-batch-32-review): samme
    // fejlklasse som lige fundet i reconcile_action.php/invoice_view.php -
    // total_amount blev regnet i fakturaens EGEN valuta (fx EUR), men
    // sammenlignet direkte mod amount_paid, som ALTID er i DKK (rigtige
    // bankbeløb). "Restbeløb" for en delvist betalt udenlandsk faktura viste
    // derfor et meningsløst tal (EUR-total minus DKK-indbetaling). Ganger nu
    // med invoices.exch_rate (1 hvis ikke sat, dvs. en helt almindelig DKK-
    // faktura er uændret) - samme konvertering som invoice_dkk_totals() i
    // inc/db_connect.inc.php bruger, blot udført direkte i SQL'en her for at
    // undgå 100 ekstra enkelt-forespørgsler i denne oversigt. Kan i sjældne
    // tilfælde afvige med en brøkdel af en øre fra det faktisk bogførte
    // beløb pga. afrundingsrækkefølgen (invoice_dkk_totals() runder netto og
    // moms hver for sig FØR kursomregning) - uden betydning for en oversigts-
    // kolonne, i modsætning til den hidtidige fejl på op til hele kursfaktoren.
    $query  = "SELECT i.inv_id, i.invoice_no, i.inv_date, c.cust_name, i.inv_status,
                      SUM(l.quantity * l.price_each * (100 + l.line_vat_rate) / 100.0 * COALESCE(NULLIF(i.exch_rate, 0), 1)) as total_amount,
                      COALESCE((SELECT SUM(amount) FROM invoice_payments WHERE inv_id = i.inv_id), 0) as amount_paid
               FROM invoices i
               LEFT JOIN customers c ON i.cust_id = c.cust_id
               LEFT JOIN invoice_lines l ON i.inv_id = l.inv_id
               $where_clause
               GROUP BY i.inv_id
               ORDER BY i.inv_id DESC LIMIT 100";
    $resi = DB::query($conn, $query);
    if ($resi) {
        while ($rowi = DB::fetch_assoc($resi)) {
            $amou = number_format($rowi['total_amount'] ?? 0, 2, ',', '.');
            // Delvis betaling (2026-08-20): en faktura der er 'sent' men har
            // en eller flere indbetalinger registreret uden at dække det
            // fulde beløb, viser nu restbeløbet i stedet for at se ud som
            // helt ubetalt.
            $paid_so_far = (float)($rowi['amount_paid'] ?? 0);
            if (strtolower($rowi['inv_status']) === 'sent' && $paid_so_far > 0.01) {
                $remaining = max(0, (float)($rowi['total_amount'] ?? 0) - $paid_so_far);
                $amou .= '<br><small style="color:var(--color-warning); font-weight:normal;">' . lang('@Remaining:') . ' ' . number_format($remaining, 2, ',', '.') . '</small>';
            }
            $row_actions = [
                ['icon' => 'fa-eye', 'link' => 'invoice_view.php?id='.$rowi['inv_id'], 'hint' => lang('@Show and process'), 'type' => 'info'],
            ];
            // Slet vises nu KUN for kladder - en bogført faktura afvises alligevel
            // server-side (invoice_edit.php, §bogforingslov-compliance), så vis
            // aldrig en knap der bare ville blive nægtet. Rettelse af en bogført
            // faktura sker via kreditnota (samme knap som allerede fandtes).
            if (strtolower($rowi['inv_status']) === 'draft') {
                $row_actions[] = ['icon' => 'fa-pencil', 'link'    => 'invoice_edit.php?id='.$rowi['inv_id'], 'hint' => '@Edit',                        'type' => 'primary'];
                $row_actions[] = ['icon' => 'fa-gavel',  'onclick' => 'postInv('.$rowi['inv_id'].')',          'hint' => '@Post Invoice (Assign No.)',    'type' => 'success'];
                $row_actions[] = ['icon' => 'fa-trash',  'onclick' => 'delInv('.$rowi['inv_id'].')',           'hint' => '@Delete',                       'type' => 'danger'];
            } elseif (!in_array(strtolower($rowi['inv_status']), ['credited', 'credit'], true)) {
                // RETTET (se [[bugs-batch-10-review]]): knappen blev vist for
                // ALLE ikke-kladde-fakturaer, inkl. allerede krediterede
                // fakturaer og kreditnota-rækkerne selv - samme "vis aldrig en
                // knap der bare bliver afvist"-princip som Slet-knappen
                // ovenfor allerede følger, nu udvidet til denne.
                $row_actions[] = ['icon' => 'fa-undo',   'link'    => 'invoice_edit.php?id=0&credit_ref='.$rowi['inv_id'], 'hint' => '@Credit this invoice (Auto-create)', 'type' => 'warning'];
            }

            $invoice_display_no = (strtolower($rowi['inv_status']) === 'draft') ? lang('@Draft') : ($rowi['invoice_no'] ?: '---');
            // RETTET (§bugs-batch-13-review): samme uescapede cust_name som
            // kundelisten ovenfor - lagret XSS via et fritekst-kundenavn.
            $data_i[] = [
                $invoice_display_no,
                $rowi['inv_date'],
                htmlspecialchars($rowi['cust_name'] ?: '---'),
                $amou,
                lang('@'.strtoupper($rowi['inv_status'])),
                htm_ActionButtons($row_actions, false)
            ];
        }
    }
    htm_Table(['@Inv. No', '@Date', '@Customer', '@Amount', '@Status', '@Action'], $data_i, 'hub_inv', 20, $i_filter, true,
        ['width:90px;', 'width:120px;', 'width:200px;', 'width:100px; text-align:right;', 'width:90px; text-align:center;', 'width:140px; text-align:left;']);
    htm_Card_end();
echo '</div>';
echo '</div>';

echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    try {
        const custSearch = document.querySelector("#hub_cust_search");
        if (custSearch) custSearch.setAttribute("data-hint", "' . lang('@Search for customer name, ID or email...') . '");
        const invSearch = document.querySelector("#hub_inv_search");
        if (invSearch) invSearch.setAttribute("data-hint", "' . lang('@Search for invoice number, customer or date...') . '");
    } catch(e) {}
});
</script>';

htm_Footer();
?>