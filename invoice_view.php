<?php # /invoice_view.php v:1.2.0 d:2026-08-11 i:evs 
# (Print: fab og scroll-knap skjules via onbeforeprint)
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/auth.inc.php'; 
require_once 'inc/php2htm.lib.php';
 
$inv_id = (int)$_GET['id'];
$is_sent_view = isset($_GET['status']) && $_GET['status'] === 'sent';
$layouts = [];
$l_res = DB::query($conn, "SELECT * FROM layout_settings");
while($l = DB::fetch_assoc($l_res)) { $layouts[$l['element_id']] = $l; }

$sql = "SELECT i.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id";
$res = DB::query($conn, $sql);
$inv = DB::fetch_assoc($res); 
if (!$inv) die(lang("@Invoice not found.")); 

$settings = []; 
$set_res = DB::query($conn, "SELECT setting_key, setting_value FROM settings");
while ($s = DB::fetch_assoc($set_res)) { $settings[$s['setting_key']] = $s['setting_value']; }

function getSet($key) { global $settings; return $settings[$key] ?? ''; }

function echoBlock($id, $content, $extra_class = '') {
    $cls = 'design-block' . ($extra_class ? ' ' . $extra_class : '');
    echo '<div class="'.$cls.'" id="'.$id.'" style="'.getStyle($id, $GLOBALS['layouts']).'">'.$content.'</div>';
}

function echoFooterBlock($id, $title, $content) {
    echo '<div class="design-block" id="block-'.$id.'" style="'.getStyle('block-'.$id, $GLOBALS['layouts']).'; font-size:10px; color: var(--text-muted);">';
    echo '<strong>'.lang($title).'</strong><br>'.$content.'</div>';
}

$df  = $settings['date_format'] ?? 'd.m.Y';
$coName = $settings['company_name']    ?? 'Company Name ApS';
$coAddr = $settings['company_address'] ?? 'Street Address 1';
$coCity = $settings['company_city']    ?? '8000 Aarhus';
$coCVR  = $settings['company_cvr']    ?? '12345678';
$cur    = $inv['currency'] ?? 'DKK';

function getStyle($id, $layouts) {
    static $defaults = [
        'block-logo'         => ['pos_x' => 0,   'pos_y' => 0,   'width_mm' => 60],
        'block-stamp'        => ['pos_x' => 140, 'pos_y' => 0,   'width_mm' => 0],
        'block-sender'       => ['pos_x' => 0,   'pos_y' => 22,  'width_mm' => 90],
        'block-recipient'    => ['pos_x' => 0,   'pos_y' => 50,  'width_mm' => 90],
        'block-cust-ref'     => ['pos_x' => 0,   'pos_y' => 76,  'width_mm' => 90],
        'block-inv-no'       => ['pos_x' => 120, 'pos_y' => 50,  'width_mm' => 60],
        'block-inv-date'     => ['pos_x' => 120, 'pos_y' => 62,  'width_mm' => 60],
        'block-inv-due'      => ['pos_x' => 120, 'pos_y' => 74,  'width_mm' => 60],
        'block-lines'        => ['pos_x' => 0,   'pos_y' => 90,  'width_mm' => 180],
        'block-totals'       => ['pos_x' => 0,   'pos_y' => 210, 'width_mm' => 180],
        'block-notes'        => ['pos_x' => 0,   'pos_y' => 228, 'width_mm' => 120],
        'block-foot-pay'     => ['pos_x' => 0,   'pos_y' => 250, 'width_mm' => 42],
        'block-foot-contact' => ['pos_x' => 46,  'pos_y' => 250, 'width_mm' => 42],
        'block-foot-online'  => ['pos_x' => 92,  'pos_y' => 250, 'width_mm' => 42],
        'block-foot-legal'   => ['pos_x' => 134, 'pos_y' => 250, 'width_mm' => 46],
    ];
    $fallback = $defaults[$id] ?? ['pos_x' => 0, 'pos_y' => 0, 'width_mm' => 0];
    $layout   = $layouts[$id] ?? $fallback;
    $x = $layout['pos_x'];
    $y = $layout['pos_y'];
    $w = ($layout['width_mm'] > 0) ? $layout['width_mm'] . "mm" : "max-content; min-width: 40mm";
    return "left:{$x}mm; top:{$y}mm; width:{$w}; box-sizing: border-box;";
}

echo '<!DOCTYPE html><html><head>
<script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
    :root {
        --bg-main: #f0f0f0; --bg-paper: #ffffff; --bg-panel: #f8f9fa;
        --border-color: #eee; --border-dark: #333;
        --text-main: #333; --text-muted: #666; --text-light: #fff;
        --bg-card: #ffffff; --border-fieldset: #cccccc;
        --status-paid: #2ecc71; --status-sent: #3498db;
        --status-draft: #95a5a6; --status-void: #e74c3c;
        --btn-scroll: #34495e; --btn-scroll-hover: #2c3e50;
        --btn-help: #e67e22; --btn-disabled: #7f8c8d;
        --color-primary: #3498db; --color-secondary: #95a5a6;
        --color-success: #2ecc71; --color-danger: #e74c3c;
        --color-warning: #f1c40f; --color-info: #34495e;
        --color-purple: #8e44ad; --color-dark: #2c3e50;
    }
    body { background: var(--bg-main); margin: 0; padding: 0 20px 100px; font-family: "Segoe UI", Arial, sans-serif; color: var(--text-main); }
    .html2pdf__container .paper { box-shadow: none !important; margin: 0 !important; padding: 0 !important; }
    .paper { background: var(--bg-paper); width: 210mm; min-height: 297mm; padding: 15mm; margin: 0 auto; position: relative; box-shadow: 0 0 10px rgba(0,0,0,0.1); box-sizing: border-box; }
    .design-mode.paper { background-image: linear-gradient(to right, #e0e0e0 1px, transparent 1px), linear-gradient(to bottom, #e0e0e0 1px, transparent 1px); background-size: 37.8px 37.8px; background-color: #fcfcfc; }

    /* Zone-overlay */
    .design-zone { display:none; position:absolute; left:0; right:0; pointer-events:none; z-index:0; font-size:9px; font-weight:bold; letter-spacing:0.05em; text-transform:uppercase; padding:3px 6px; box-sizing:border-box; }
    .design-mode .design-zone { display:block; }
    .zone-header { background:rgba(52,152,219,0.07);  color:rgba(52,152,219,0.6);  border-bottom:1px dashed rgba(52,152,219,0.3); }
    .zone-lines  { background:rgba(46,204,113,0.06);  color:rgba(46,204,113,0.6);  border-bottom:1px dashed rgba(46,204,113,0.3); }
    .zone-totals { background:rgba(155,89,182,0.07);  color:rgba(155,89,182,0.6);  border-bottom:1px dashed rgba(155,89,182,0.3); }
    .zone-footer { background:rgba(230,126,34,0.07);  color:rgba(230,126,34,0.6);  }

    /* Kuvertvindue DL */
    .design-envelope { display:none; position:absolute; pointer-events:none; z-index:1; left:20mm; top:47mm; width:90mm; height:45mm; border:1.5px dashed rgba(180,120,0,0.5); background:rgba(255,200,0,0.04); box-sizing:border-box; }
    .design-envelope::after { content:"Kuvertvindue (DL 90x45mm)"; position:absolute; bottom:3px; right:5px; font-size:8px; color:rgba(180,120,0,0.6); font-weight:bold; letter-spacing:0.04em; text-transform:uppercase; }
    .design-mode .design-envelope { display:block; }
    .design-mode.paper::before { content:""; position:absolute; inset:0; pointer-events:none; border:56.7px solid rgba(255,100,100,0.06); outline:1px dashed rgba(255,80,80,0.35); outline-offset:-56.7px; z-index:0; }

    /* Design blocks */
    .design-block { position:absolute; padding:5px; box-sizing:border-box; -webkit-user-select:none; -moz-user-select:none; user-select:none; touch-action:none; }
    .design-mode .design-block { border:1px dashed var(--status-sent); cursor:move; background:rgba(52,152,219,0.02); }
    .design-mode .design-block::after { content:""; position:absolute; right:0; bottom:0; width:10px; height:10px; background:var(--status-sent); cursor:nwse-resize; }
    .design-block, .design-block * { -webkit-user-select:none !important; -moz-user-select:none !important; user-select:none !important; pointer-events:auto; }

    /* Tom blok */
    .block-empty { display:none; }
    .design-mode .block-empty { display:block; }
    .design-placeholder { font-style:italic; color:#aaa; font-size:11px; }

    /* Status-stempel */
    .status-stamp { border:4px solid; padding:5px 15px; font-weight:bold; font-size:20px; text-transform:uppercase; transform:rotate(-15deg); opacity:0.15; position:static; display:inline-block; }
    .status-paid  { color:var(--status-paid);  border-color:var(--status-paid);  }
    .status-sent  { color:var(--status-sent);  border-color:var(--status-sent);  }
    .status-draft { color:var(--status-draft); border-color:var(--status-draft); }
    .status-void  { color:var(--status-void);  border-color:var(--status-void);  }

    /* Faktura-tabel */
    .line-table { width:100%; border-collapse:collapse; }
    .line-table th { background:var(--bg-panel); border-bottom:2px solid var(--border-dark); padding:8px; text-align:left; font-size:13px; }
    .line-table td { padding:10px 8px; border-bottom:1px solid var(--border-color); font-size:15px; }

    /* UI-elementer */
    .no-print { width:210mm; margin:10px auto; text-align:right; }
    .alert-sent-success { width:210mm; margin:0 auto 15px; background:var(--status-paid); color:var(--text-light); padding:15px; border-radius:4px; font-weight:bold; text-align:center; box-sizing:border-box; box-shadow:0 2px 4px rgba(0,0,0,0.05); }

    /* Floating knapper */
    #floating-menu-id, .floating-action-bar:not(.custom-bottom-right) { display:none !important; }
    .floating-action-bar.custom-bottom-right { position:fixed !important; bottom:20px !important; right:80px !important; left:auto !important; width:auto !important; display:block !important; z-index:999991 !important; }
    .floating-action-bar.custom-bottom-right .fab-item { margin:0 !important; padding:10px 15px !important; border-radius:4px !important; box-shadow:0 4px 6px rgba(0,0,0,0.15) !important; text-decoration:none !important; color:var(--text-light) !important; font-family:sans-serif !important; font-size:13px !important; display:flex !important; align-items:center !important; justify-content:center !important; min-width:90px !important; box-sizing:border-box !important; }
    #fab-scroll-top { margin:0 0 10px !important; padding:10px 15px !important; border-radius:4px !important; box-shadow:0 4px 6px rgba(0,0,0,0.15) !important; text-decoration:none !important; color:var(--text-light) !important; font-family:sans-serif !important; font-size:13px !important; display:none; align-items:center !important; justify-content:center !important; min-width:90px !important; box-sizing:border-box !important; background:var(--btn-scroll) !important; cursor:pointer !important; position:fixed !important; bottom:65px !important; right:20px !important; z-index:999990 !important; }
    #fab-scroll-top:hover { background:var(--btn-scroll-hover) !important; }

    /* Hjælpe-popup */
    #help-system-popup { position:fixed; top:100px; right:40px; width:300px; background:var(--bg-paper); border:1px solid var(--btn-disabled); box-shadow:0 5px 15px rgba(0,0,0,0.2); z-index:999995; border-radius:5px; overflow:hidden; display:none; }
    #help-system-popup h3 { margin:0; padding:10px; background:var(--btn-help); color:var(--text-light); font-size:14px; cursor:move !important; }
    #help-system-content { padding:15px; font-size:13px; color:var(--text-main); line-height:1.4; max-height:400px; overflow-y:auto; }

    /* Koordinat-label */
    #coordinate-label { position:fixed !important; display:none; background:#333; color:#fff; padding:4px 8px; border-radius:4px; pointer-events:none; z-index:999999; font-size:12px; white-space:nowrap; }

    /* ── PRINT ──────────────────────────────────────────────────────────────
       Floating-knapper skjules via onbeforeprint (JS) fordi inline-style
       display:block !important ikke kan overskrives af @media print.
       Her skjules kun elementer uden inline-style konflikter.
    ──────────────────────────────────────────────────────────────────────── */
    @media print {
        .no-print,
        .floating-action-bar,
        #floating-menu-id,
        .fab-container,
        #tc-hint,
        #help-system-popup,
        #coordinate-label { display:none !important; }
        .block-empty       { display:none !important; }
        body  { background:var(--bg-paper); margin:0; padding:0; }
        .paper { box-shadow:none; margin:0; padding:0; border:none; }
    }
</style></head><body>';

// ── Sendt-bekræftelse ─────────────────────────────────────────────────────────
if ($is_sent_view) {
    echo '<div class="alert-sent-success">';
    echo '✓ ' . lang("@Invoice was generated successfully and has been sent to") . ' ' . htmlspecialchars($inv['cust_email']);
    echo '</div>';
}

// ── Topbar med knapper ────────────────────────────────────────────────────────
echo '<div class="no-print" style="width:210mm; margin:5px auto; display:flex; justify-content:space-between; align-items:flex-end;">';
echo '<div style="display:flex; align-items:flex-end;"></div>';
echo '<div style="display:flex; align-items:flex-end; gap:15px;">';
if (!$is_sent_view) {
    $standard_hilsen = !empty($settings['default_mail_body'])
        ? $settings['default_mail_body']
        : "Hi " . $inv['cust_name'] . ",\n\nPlease find your invoice attached.\n\nBest regards,";
    htm_InputGroup(icon:'fa-envelope-open-text', labl:'@Email Message', name:'mail_body', valu:$standard_hilsen, type:'textarea', wdth:'320px', hint:'@Write a personal message to the customer here...', extr:'rows="3" style="font-size:12px; line-height:1.3;"');
}
echo '<div style="display:flex; gap:10px; align-items:flex-end;">';
if (!$is_sent_view) {
    htm_Button(icon:'fa-pencil-ruler', labl:'@Design Mode',  type:'secondary', attr:'onclick="toggleFineDesign()" id="designBtn"');
    htm_Button(icon:'fa-envelope',     labl:'@Send via Mail', type:'info',      attr:'onclick="generateAndSendInvoice('.$inv_id.')" id="mailBtn"');
    htm_Button(icon:'fa-file-code',    labl:'@OIOUBL XML',   type:'warning',   attr:'onclick="window.location.href=\'export_oioubl.php?id='.$inv_id.'\'"');
}
htm_Button(icon:'fa-print',     labl:'@Print Invoice',  type:'primary', attr:'onclick="window.print()"');
htm_Button(icon:'fa-door-open', labl:'@Leave the page', type:'danger',  attr:'onclick="window.location.href=\'sales_hub.php\'"');
echo '</div></div></div>';

// ── Faktura-papir ─────────────────────────────────────────────────────────────
echo '<div class="paper" id="invoice-page">';
echo '<div class="design-zone zone-header" style="top:0; height:56.7px;">Header</div>';
echo '<div class="design-zone zone-lines"  style="top:calc(100mm - 15mm); height:120mm;">Varelinjer</div>';
echo '<div class="design-zone zone-totals" style="top:calc(230mm - 15mm); height:35mm;">Totaler &amp; noter</div>';
echo '<div class="design-zone zone-footer" style="top:calc(270mm - 15mm); height:22mm;">Footer</div>';
echo '<div class="design-envelope"></div>';

// Stempel
$status_class = 'status-' . ($inv['inv_status'] ?? 'draft');
echoBlock('block-stamp', '<div class="status-stamp '.$status_class.'">' . lang('@'.$inv['inv_status']) . '</div>');

// Logo
$logo_html = file_exists('images/logo.png')
    ? '<img src="images/logo.png" style="max-height:60px; display:block;">'
    : '<div style="padding:10px; border:1px solid var(--btn-disabled); font-size:10px;">'.lang('@Logo missing').'</div>';
echoBlock('block-logo', $logo_html);

// Afsender
$sender_html = '<div style="font-size:14px; color:var(--text-main);" contenteditable="'.($is_sent_view ? 'false' : 'true').'"><strong>'.htmlspecialchars($coName).'</strong><br>'.htmlspecialchars($coAddr).'<br>'.htmlspecialchars($coCity).'<br>'.lang('@CVR').': '.htmlspecialchars($coCVR).'</div>';
echoBlock('block-sender', $sender_html);

// Modtager
$recip_html = '<div style="width:90mm; font-size:14px;"><small style="color:var(--text-muted);">'.lang('@Recipient').':</small><br><strong>'.$inv['cust_name'].'</strong><br>'.nl2br(htmlspecialchars($inv['cust_address'] ?? '')).'</div>';
echoBlock('block-recipient', $recip_html);

// Kundens reference (altid i DOM)
$custref_val     = trim($inv['cust_reference'] ?? '');
$custref_content = !empty($custref_val)
    ? htmlspecialchars($custref_val)
    : '<span class="design-placeholder">(' . lang('@Your Reference') . ')</span>';
$custref_empty   = empty($custref_val) ? 'block-empty' : '';
echoBlock('block-cust-ref',
    '<div style="width:95%; font-size:13px;"><small style="color:var(--text-muted);">'.lang('@Your Reference').':</small> '.$custref_content.'</div>',
    $custref_empty
);

// Faktura-nr, dato, forfald
$f_no = $inv['invoice_no'] ? '#'.str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT) : lang('@DRAFT');
echoBlock('block-inv-no',   '<strong>'.lang('@Invoice No').':</strong><br><span style="font-size:16px;">'.$f_no.'</span>');
echoBlock('block-inv-date', '<strong>'.lang('@Date').':</strong><br>'.date('d.m.Y', strtotime($inv['inv_date'])));
echoBlock('block-inv-due',  '<strong>'.lang('@Due Date').':</strong><br>'.date('d.m.Y', strtotime($inv['inv_due_date'])));

// Linjer
$lines_html = '<table class="line-table"><thead><tr><th>'.lang('@Description').'</th><th style="text-align:right;">'.lang('@Qty').'</th><th style="text-align:right;">'.lang('@Price').'</th><th style="text-align:right;">'.lang('@Line total').'</th></tr></thead><tbody>';
$res_l = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC");
$sub = 0; $vat = 0;
while ($l = DB::fetch_assoc($res_l)) {
    $lt    = $l['quantity'] * $l['price_each'];
    $sub  += $lt;
    $vat  += ($lt * ($l['line_vat_rate'] / 100));
    $lines_html .= '<tr><td>'.htmlspecialchars($l['line_text']).'</td>'
        . '<td align="right">'.number_format($l['quantity'],  2, ',', '.').'</td>'
        . '<td align="right">'.number_format($l['price_each'],2, ',', '.').'</td>'
        . '<td align="right" style="font-weight:bold;">'.number_format($lt, 2, ',', '.').'</td></tr>';
}
$lines_html .= '</tbody></table>';
echoBlock('block-lines', $lines_html);

// Totaler
$total_html  = '<table style="width:98%; margin-left:5%; border-collapse:collapse;">';
$total_html .= '<tr><td>'.lang('@Subtotal').':</td><td align="right">'.number_format($sub, 2, ',', '.').'</td></tr>';
$total_html .= '<tr><td>'.lang('@VAT').':</td><td align="right">'.number_format($vat, 2, ',', '.').'</td></tr>';
$total_html .= '<tr style="font-size:1.2em;font-weight:bold;border-top:2px solid var(--border-dark);"><td>'.lang('@Total').':</td><td align="right">'.number_format($sub+$vat, 2, ',', '.').' '.$cur.'</td></tr></table>';
echoBlock('block-totals', $total_html);

// Noter (altid i DOM)
$note_val     = trim($inv['inv_note'] ?? '');
$note_content = !empty($note_val)
    ? nl2br(htmlspecialchars($note_val))
    : '<span class="design-placeholder">(' . lang('@Notes') . ')</span>';
$note_empty   = empty($note_val) ? 'block-empty' : '';
echoBlock('block-notes',
    '<div style="border-top:1px solid var(--border-color); padding-top:5px;"><small>'.lang('@Notes').':</small><br>'.$note_content.'</div>',
    $note_empty
);

// Footer-blokke
echoFooterBlock('foot-pay',     '@Payment', ($settings['bank_name'] ?? 'Nordea').'<br>'.lang('@Reg').': '.($settings['bank_reg'] ?? '0000').' '.lang('@Acc').': '.($settings['bank_acc'] ?? '00000000'));
echoFooterBlock('foot-contact', '@Contact', ($settings['company_phone'] ?? '').'<br>'.($settings['company_email'] ?? ''));
echoFooterBlock('foot-online',  '@Online',  lang('@MobilePay').': '.($settings['company_mobilepay'] ?? '00000').'<br>'.($settings['company_web'] ?? 'www.company.com'));
echoFooterBlock('foot-legal',   '@Legal',   '<strong>'.($settings['company_name'] ?? 'Company Name').'</strong><br>'.lang('@CVR').': '.($settings['company_cvr'] ?? '00000000'));

echo '</div>'; // .paper

// Hjælpe-popup
echo '<div id="help-system-popup">
    <h3><i class="fa-solid fa-circle-question"></i> ' . lang('@Help & Guide') . '</h3>
    <div id="help-system-content">' . lang('@Loading help...') . '</div>
</div>';
?>
<script>
let designActive = false;

function toggleFineDesign() {
    designActive = !designActive;
    const page    = document.getElementById('invoice-page');
    const btn     = document.getElementById('designBtn');
    const mailBtn = document.getElementById('mailBtn');
    const printBtn = document.querySelector('.fa-print') ? document.querySelector('.fa-print').parentElement : null;
    if (designActive) {
        page.classList.add('design-mode');
        if (btn)      { btn.style.background = "var(--status-paid)"; btn.innerText = "<?php echo lang('@Save & Exit Design'); ?>"; }
        if (printBtn) printBtn.style.display = 'none';
        if (mailBtn)  mailBtn.style.display = 'none';
        initInteract();
    } else {
        location.reload();
    }
}

function initInteract() {
    const paper  = document.getElementById('invoice-page');
    const pxToMm = paper.offsetWidth / 210;
    const label  = document.getElementById('coordinate-label');

    document.querySelectorAll('.design-block').forEach(el => {
        const x = el.offsetLeft, y = el.offsetTop;
        el.setAttribute('data-x', x);
        el.setAttribute('data-y', y);
        el.style.left      = '0';
        el.style.top       = '0';
        el.style.transform = `translate(${x}px, ${y}px)`;
    });

    interact('#help-system-popup').draggable({
        allowFrom: 'h3',
        listeners: {
            move(event) {
                const t = event.target;
                let x = (parseFloat(t.getAttribute('data-x')) || 0) + event.dx;
                let y = (parseFloat(t.getAttribute('data-y')) || 0) + event.dy;
                label.style.display = 'block';
                label.textContent   = `X: ${Math.round(x)}, Y: ${Math.round(y)}`;
                label.style.left    = (event.clientX + 15) + 'px';
                label.style.top     = (event.clientY + 15) + 'px';
                t.style.transform   = `translate(${x}px, ${y}px)`;
                t.setAttribute('data-x', x); t.setAttribute('data-y', y);
            }
        }
    });

    interact('.design-block')
        .draggable({
            modifiers: [interact.modifiers.restrictRect({ restriction: 'parent', endOnly: true })],
            listeners: {
                start(event) { label.style.display = 'block'; },
                move(event) {
                    const t = event.target;
                    let x = (parseFloat(t.getAttribute('data-x')) || 0) + event.dx;
                    let y = (parseFloat(t.getAttribute('data-y')) || 0) + event.dy;
                    if (event.shiftKey) { const g = 18.9; x = Math.round(x/g)*g; y = Math.round(y/g)*g; }
                    t.style.transform   = `translate(${x}px, ${y}px)`;
                    t.setAttribute('data-x', x); t.setAttribute('data-y', y);
                    label.textContent = `X: ${Math.round(x/pxToMm)}mm  Y: ${Math.round(y/pxToMm)}mm`;
                    label.style.left  = (event.clientX + 15) + 'px';
                    label.style.top   = (event.clientY + 15) + 'px';
                },
                end(event) { label.style.display = 'none'; saveToDB(event.target); }
            }
        })
        .resizable({
            edges: { left: false, right: true, bottom: false, top: false },
            modifiers: [
                interact.modifiers.snapSize({ targets: [interact.createSnapGrid({ x: 37.8, y: 37.8 })] }),
                interact.modifiers.restrictSize({ min: { width: 50 } })
            ],
            listeners: {
                move(event) { event.target.style.width = event.rect.width + 'px'; },
                end(event)  { saveToDB(event.target); }
            }
        });
}

function openHelpSystem() {
    const popup   = document.getElementById('help-system-popup');
    const content = document.getElementById('help-system-content');
    if (!popup) return;
    popup.style.display = 'block';
    fetch('json-data/help_system.json')
        .then(r => r.json())
        .then(data => {
            const page = '<?php echo basename($_SERVER['SCRIPT_NAME']); ?>';
            content.innerHTML = data[page] ?? "<?php echo lang('@No help text available for this page.'); ?>";
        })
        .catch(() => { content.innerHTML = "<?php echo lang('@Error loading help.'); ?>"; });
}

function saveToDB(target) {
    const paper   = document.getElementById('invoice-page');
    const pxToMm  = paper.offsetWidth / 210;
    const posX_mm = (parseFloat(target.getAttribute('data-x')) || 0) / pxToMm;
    const posY_mm = (parseFloat(target.getAttribute('data-y')) || 0) / pxToMm;
    const width_mm = target.offsetWidth / pxToMm;
    let fd = new FormData();
    fd.append('id', target.id);
    fd.append('x', posX_mm.toFixed(2));
    fd.append('y', posY_mm.toFixed(2));
    fd.append('w', width_mm.toFixed(2));
    fetch('save_layout.php', { method: 'POST', body: fd })
        .then(r => { if (!r.ok) throw new Error("status " + r.status); return r.json(); })
        .then(data => {
            if (data.success) {
                console.log("<?php echo lang('@Saved:'); ?>", target.id, 'X:', posX_mm.toFixed(2), 'W:', width_mm.toFixed(2));
            } else {
                console.error("<?php echo lang('@Layout save failed:'); ?>", data.error);
                sysAlert("<?php echo lang('@Could not save the new position. See console (F12) for details.'); ?>");
            }
        })
        .catch(err => {
            console.error("<?php echo lang('@Layout save error:'); ?>", err);
            sysAlert("<?php echo lang('@Could not save the new position (network or server error). See console (F12) for details.'); ?>");
        });
}

function generateAndSendInvoice(invoiceId) {
    const element = document.getElementById('invoice-page');
    const mailBtn = document.getElementById('mailBtn');
    if (!element) { alert("<?php echo lang('@Could not find invoice element.'); ?>"); return; }
    if (mailBtn)  { mailBtn.disabled = true; mailBtn.innerText = "<?php echo lang('@Sending...'); ?>"; }
    const opt = {
        margin: 0,
        filename: 'invoice_' + invoiceId + '.pdf',
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).outputPdf('blob').then(function(pdfBlob) {
        const formData = new FormData();
        formData.append('pdf_file', pdfBlob, 'invoice_' + invoiceId + '.pdf');
        const customBody = document.getElementsByName('mail_body')[0];
        if (customBody) formData.append('custom_body', customBody.value);
        fetch('send_invoice_action.php?id=' + invoiceId, { method: 'POST', body: formData })
            .then(r => { if (!r.ok) throw new Error("status " + r.status); return r.json(); })
            .then(data => {
                if (data.success) {
                    window.location.href = 'invoice_view.php?id=' + invoiceId + '&status=sent';
                } else {
                    alert("<?php echo lang('@Error during dispatch:'); ?> " + data.error);
                    if (mailBtn) { mailBtn.disabled = false; mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>"; }
                }
            })
            .catch(err => {
                console.error('Network details:', err);
                alert("<?php echo lang('@Network error: Server rejected data. Check console (F12) for details.'); ?>");
                if (mailBtn) { mailBtn.disabled = false; mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>"; }
            });
    });
}

// Scroll-knap synlighed
window.onscroll = function() {
    const btn = document.getElementById("fab-scroll-top");
    if (btn) btn.style.display = (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) ? "flex" : "none";
};

// ── Print: skjul floating-knapper via JS (CSS kan ikke overtrumfe inline display:block !important)
const _printHideIds = ['fab-scroll-top', 'tc-hint', 'custom-alert', 'help-system-popup', 'coordinate-label'];
const _printHideSelectors = ['.floating-action-bar', '.floating-action-bar.custom-bottom-right', '#notepad-toggle', '#notepad-panel', '.notepad-container', '[id*="notepad"]', '[class*="notepad"]'];
let _printHidden = [];

window.onbeforeprint = function() {
    _printHidden = [];
    // Skjul via id-liste
    _printHideIds.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) { _printHidden.push({el, display: el.style.display}); el.style.setProperty('display', 'none', 'important'); }
    });
    // Skjul via selektor-liste
    _printHideSelectors.forEach(function(sel) {
        document.querySelectorAll(sel).forEach(function(el) {
            if (!_printHidden.find(function(e) { return e.el === el; })) {
                _printHidden.push({el, display: el.style.display});
                el.style.setProperty('display', 'none', 'important');
            }
        });
    });
};
window.onafterprint = function() {
    _printHidden.forEach(function(item) {
        item.el.style.removeProperty('display');
        if (item.display) item.el.style.display = item.display;
    });
    _printHidden = [];
};
</script>

<div id="fab-scroll-top" onclick="window.scrollTo({top:0, behavior:'smooth'});" data-hint="<?php echo lang('@Go to top'); ?>">
    <i class="fa fa-arrow-up"></i>&nbsp;<span><?php echo lang('@Top'); ?></span>
</div>

<div class="floating-action-bar custom-bottom-right">
<?php
$current_page = basename($_SERVER['SCRIPT_NAME']);
$help_file    = __DIR__ . '/json-data/help_system.json';
$has_help     = false;
if (file_exists($help_file)) {
    $help_data = json_decode(file_get_contents($help_file), true);
    if ($help_data && isset($help_data[$current_page]) && !empty($help_data[$current_page])) {
        $has_help = true;
    }
}
if ($has_help) {
    $btn_style   = 'background:var(--btn-help); opacity:1; cursor:pointer;';
    $btn_onclick = 'openHelpSystem(); return false;';
    $btn_hint    = '@Help available';
} else {
    $btn_style   = 'background:var(--btn-disabled); opacity:0.4; cursor:not-allowed;';
    $btn_onclick = 'return false;';
    $btn_hint    = '@No help text for this page';
}
?>
    <a href="#" class="fab-item" onclick="<?php echo $btn_onclick; ?>" style="<?php echo $btn_style; ?>" data-hint="<?php echo lang($btn_hint); ?>">
        <i class="fa-solid fa-circle-question"></i>&nbsp;<?php echo lang('@Help'); ?>
    </a>
</div>

<div id="coordinate-label"></div>
</body>
<?php
$GLOBALS['no_floating_menu'] = true;
htm_Footer();
?>
