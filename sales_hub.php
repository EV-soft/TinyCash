<?php # sales_hub.php v:1.2.0 d:2026-08-11 i:evs 
# (sysConfirm erstatter native confirm())
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$current_status = isset($_GET['status']) ? $_GET['status'] : 'all';

htm_Header("@Sales & Customers", 1600);
showMenu();

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
function postInv(id) {
    sysConfirm(
        "' . lang('@Are you sure you want to post this invoice? This will assign an official invoice number and lock the draft.') . '",
        function() {
            fetch("invoice_post_action.php?id=" + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success) { sysAlert("' . lang('@Invoice successfully posted with number: ') . '" + data.invoice_no); location.reload(); }
                    else sysAlert("' . lang('@Posting failed: ') . '" + data.error);
                })
                .catch(() => sysAlert("' . lang('@System error: Could not connect to server.') . '"));
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
    htm_Card_('@Customers', "100%", "", "c_card", true, $c_tools);

    $data_c = [];
    $resc = DB::query($conn, "SELECT cust_id, cust_name, cust_email FROM customers ORDER BY cust_name LIMIT 100");
    if ($resc) {
        while ($rowc = DB::fetch_assoc($resc)) {
            $btn = htm_ActionButtons([
                ['icon' => 'fa-pencil', 'link' => 'customer_edit.php?id='.$rowc['cust_id'], 'hint' => '@Edit', 'type' => 'primary'],
            ], false);
            $data_c[] = [$rowc['cust_id'], $rowc['cust_name'], $rowc['cust_email'], $btn];
        }
    }
    htm_Table(['@ID', '@Name', '@Email', '@Action'], $data_c, 'hub_cust', 20, '', true,
        ['width:50px;', 'width:140px;', 'width:180px;', 'width:100px; text-align:center;']);
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
        $l        = ($f === 'all') ? lang('@All') : lang('@'.$f);
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
    $query  = "SELECT i.inv_id, i.invoice_no, i.inv_date, c.cust_name, i.inv_status,
                      SUM(l.quantity * l.price_each) as total_amount
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
            $row_actions = [
                ['icon' => 'fa-eye', 'link' => 'invoice_view.php?id='.$rowi['inv_id'], 'hint' => lang('@Show and process'), 'type' => 'info'],
            ];
            if (strtolower($rowi['inv_status']) === 'draft') {
                $row_actions[] = ['icon' => 'fa-pencil', 'link'    => 'invoice_edit.php?id='.$rowi['inv_id'], 'hint' => '@Edit',                        'type' => 'primary'];
                $row_actions[] = ['icon' => 'fa-gavel',  'onclick' => 'postInv('.$rowi['inv_id'].')',          'hint' => '@Post Invoice (Assign No.)',    'type' => 'success'];
            } else {
                $row_actions[] = ['icon' => 'fa-undo',   'link'    => 'invoice_edit.php?id=0&credit_ref='.$rowi['inv_id'], 'hint' => '@Credit this invoice (Auto-create)', 'type' => 'warning'];
            }
            $row_actions[] = ['icon' => 'fa-trash', 'onclick' => 'delInv('.$rowi['inv_id'].')', 'hint' => '@Delete', 'type' => 'danger'];

            $invoice_display_no = ($rowi['inv_status'] === 'draft') ? lang('@Draft') : ($rowi['invoice_no'] ?: '---');
            $data_i[] = [
                $invoice_display_no,
                $rowi['inv_date'],
                $rowi['cust_name'] ?: '---',
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