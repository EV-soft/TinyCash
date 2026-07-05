<?php # /invoice_view.php v:1.1.0 d:2026-07-05 i:evs
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

$coName = $settings['company_name'] ?? 'Company Name ApS';
$coAddr = $settings['company_address'] ?? 'Street Address 1';
$coCity = $settings['company_city'] ?? '8000 Aarhus';
$coCVR  = $settings['company_cvr'] ?? '12345678';
$cur = $inv['currency'] ?? 'DKK';

function getStyle($id, $layouts) {
    $layout = $layouts[$id] ?? ['pos_x' => 0, 'pos_y' => 0, 'width_mm' => 0];
    $x = $layout['pos_x'];
    $y = $layout['pos_y'];
    $w = ($layout['width_mm'] > 0) ? $layout['width_mm'] . "mm" : "max-content; min-width: 40mm";
    return "left:{$x}mm; top:{$y}mm; width:{$w}; box-sizing: border-box;";
}

echo '<!DOCTYPE html><html><head>
<script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
    /* MÅLSÆTNING: Centrale farvevariabler til lyst/mørkt tema */
    :root {
        --bg-main: #f0f0f0;
        --bg-paper: #ffffff;
        --bg-panel: #f8f9fa;
        --border-color: #eee;
        --border-dark: #333;
        --text-main: #333;
        --text-muted: #666;
        --text-light: #fff;
        
        /* Status-stempler */
        --status-paid: #2ecc71;
        --status-sent: #3498db;
        --status-draft: #95a5a6;
        --status-void: #e74c3c;
        
        /* UI elementer */
        --btn-scroll: #34495e;
        --btn-scroll-hover: #2c3e50;
        --btn-help: #e67e22;
        --btn-disabled: #7f8c8d;
    }

    body { background: var(--bg-main); margin: 0; padding: 0 20px; font-family: "Segoe UI", Arial, sans-serif; padding-bottom: 100px; color: var(--text-main); }
    .html2pdf__container .paper { box-shadow: none !important; margin: 0 !important; padding: 0 !important; }
    .paper { background: var(--bg-paper); width: 210mm; min-height: 297mm; padding: 15mm; margin: 0 auto; position: relative; box-shadow: 0 0 10px rgba(0,0,0,0.1); box-sizing: border-box; }
    .design-mode.paper { background-image: radial-gradient(#d1d1d1 1px, transparent 1px); background-size: 10mm 10mm; }
    .design-block { position: absolute; padding: 5px; box-sizing: border-box; }
    .design-mode .design-block { border: 1px dashed var(--status-sent); cursor: move; background: rgba(52,152,219,0.02); }
    
    /* Centraliseret farvestyring af stempler */
    .status-stamp { border: 4px solid; padding: 5px 15px; font-weight: bold; font-size: 20px; text-transform: uppercase; transform: rotate(-15deg); opacity: 0.15; position: static; display: inline-block; }
    .status-paid { color: var(--status-paid); border-color: var(--status-paid); }
    .status-sent { color: var(--status-sent); border-color: var(--status-sent); }
    .status-draft { color: var(--status-draft); border-color: var(--status-draft); }
    .status-void { color: var(--status-void); border-color: var(--status-void); }

    .line-table { width: 100%; border-collapse: collapse; }
    .line-table th { background: var(--bg-panel); border-bottom: 2px solid var(--border-dark); padding: 8px; text-align: left; font-size: 13px; }
    .line-table td { padding: 10px 8px; border-bottom: 1px solid var(--border-color); font-size: 15px; }
    .no-print { width: 210mm; margin: 10px auto; text-align: right; }
    
    /* Centraliseret succes-banner overskrevet fra inline farver */
    .alert-sent-success { width: 210mm; margin: 0 auto 15px auto; background: var(--status-paid); color: var(--text-light); padding: 15px; border-radius: 4px; font-weight: bold; text-align: center; box-sizing: border-box; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

    @media print { 
        .no-print, .floating-action-bar, #floating-menu-id, .fab-container, #tc-hint, #help-system-popup, #fab-scroll-top { display: none !important; } 
        button, .btn, i, .fa { display: none !important; } 
        body { background: var(--bg-paper); margin: 0; padding: 0; } 
        .paper { box-shadow: none; margin: 0; padding: 0; border: none; } 
    }
    
    #floating-menu-id, .floating-action-bar:not(.custom-bottom-right) { display: none !important; }

    .floating-action-bar.custom-bottom-right { position: fixed !important; bottom: 20px !important; right: 80px !important; left: auto !important; width: auto !important; display: block !important; z-index: 999991 !important; }
    .floating-action-bar.custom-bottom-right .fab-item { margin: 0 !important; padding: 10px 15px !important; border-radius: 4px !important; box-shadow: 0 4px 6px rgba(0,0,0,0.15) !important; text-decoration: none !important; color: var(--text-light) !important; font-family: sans-serif !important; font-size: 13px !important; display: flex !important; align-items: center !important; justify-content: center !important; min-width: 90px !important; box-sizing: border-box !important; }

    #fab-scroll-top { margin: 0 0 10px 0 !important; padding: 10px 15px !important; border-radius: 4px !important; box-shadow: 0 4px 6px rgba(0,0,0,0.15) !important; text-decoration: none !important; color: var(--text-light) !important; font-family: sans-serif !important; font-size: 13px !important; display: none; align-items: center !important; justify-content: center !important; min-width: 90px !important; box-sizing: border-box !important; background: var(--btn-scroll) !important; cursor: pointer !important; position: fixed !important; bottom: 65px !important; right: 20px !important; z-index: 999990 !important; }
    #fab-scroll-top:hover { background: var(--btn-scroll-hover) !important; }
    
    .design-mode .design-block::after { content: ""; position: absolute; right: 0; bottom: 0; width: 10px; height: 10px; background: var(--status-sent); cursor: nwse-resize; }
    
    #help-system-popup { position: fixed; top: 100px; right: 40px; width: 300px; background: var(--bg-paper); border: 1px solid var(--btn-disabled); box-shadow: 0 5px 15px rgba(0,0,0,0.2); z-index: 999995; border-radius: 5px; overflow: hidden; display: none; }
    #help-system-popup h3 { margin: 0; padding: 10px; background: var(--btn-help); color: var(--text-light); font-size: 14px; cursor: move !important; }
    #help-system-content { padding: 15px; font-size: 13px; color: var(--text-main); line-height: 1.4; max-height: 400px; overflow-y: auto; }
</style></head><body>';

if ($is_sent_view) {
    echo '<div class="alert-sent-success">';
    echo '✓ ' . lang("@Invoice was generated successfully and has been sent to") . ' ' . htmlspecialchars($inv['cust_email']);
    echo '</div>';
}

echo '<div class="no-print" style="width: 210mm; margin: 10px auto; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px;">';
echo '<div style="display: flex; align-items: flex-end;"></div>';
echo '<div style="display: flex; align-items: flex-end; gap: 15px;">';

if (!$is_sent_view) {
    $standard_hilsen = !empty($settings['default_mail_body']) ? $settings['default_mail_body'] : "Hi " . $inv['cust_name'] . ",\n\nPlease find your invoice attached.\n\nBest regards,";
    htm_InputGroup(icon: 'fa-envelope-open-text', labl: '@Email Message', name: 'mail_body', valu: $standard_hilsen, type: 'textarea', wdth: '320px', hint: '@Write a personal message to the customer here...', extr: 'rows="2" style="font-size: 12px; line-height: 1.3;"');
}

echo '<div style="display: flex; gap: 10px; align-items: flex-end;">';
if (!$is_sent_view) {
    htm_Button(icon: 'fa-pencil-ruler', labl: '@Design Mode', type: 'secondary', attr: 'onclick="toggleFineDesign()" id="designBtn"');
    htm_Button(icon: 'fa-envelope', labl: '@Send via Mail', type: 'info', attr: 'onclick="generateAndSendInvoice(' . $inv_id . ')" id="mailBtn"');
    htm_Button(icon: 'fa-file-code', labl: '@OIOUBL XML', type: 'warning', attr: 'onclick="window.location.href=\'export_oioubl.php?id=' . $inv_id . '\'"');
}
htm_Button(icon: 'fa-print', labl: '@Print', type: 'primary', attr: 'onclick="window.print()"');
htm_Button(icon: 'fa-door-open', labl: '@Leave', type: 'danger', attr: 'onclick="window.location.href=\'sales_hub.php\'"');
echo '</div></div></div>';

echo '<div class="paper" id="invoice-page">';

// Tildeler CSS status klasse i stedet for rå inline-styles farvekoder
$status_class = 'status-' . ($inv['inv_status'] ?? 'draft');

echo '<div class="design-block" id="block-stamp" style="'.getStyle('block-stamp', $layouts).'; width: auto; z-index: 10;">';
echo '<div class="status-stamp '.$status_class.'">' . lang('@'.$inv['inv_status']) . '</div></div>';

echo '<div class="design-block" id="block-logo" style="'.getStyle('block-logo', $layouts).'; width: auto;">';
echo file_exists('images/logo.png') ? '<img src="images/logo.png" style="max-height:60px; display:block;">' : '<div style="padding:10px; border:1px solid var(--btn-disabled); font-size:10px;">'.lang('@Logo missing').'</div>';
echo '</div>';

echo '<div class="design-block" id="block-sender" style="'.getStyle('block-sender', $layouts).'; ">';
echo '<div style="font-size:14px; color: var(--text-main);" contenteditable="'.($is_sent_view ? 'false' : 'true').'"><strong>'.htmlspecialchars($coName).'</strong><br>'.htmlspecialchars($coAddr).'<br>'.htmlspecialchars($coCity).'<br>'.lang('@CVR').': '.htmlspecialchars($coCVR).'</div></div>';

echo '<div class="design-block" id="block-recipient" style="'.getStyle('block-recipient', $layouts).'">';
echo '<div style="width: 90mm; font-size: 14px;"><small style="color: var(--text-muted);">'.lang('@Recipient').':</small><br><strong>'.$inv['cust_name'].'</strong><br>'.nl2br(htmlspecialchars($inv['cust_address'] ?? '')).'</div></div>';

echo '<div class="design-block" id="block-inv-no" style="'.getStyle('block-inv-no', $layouts).';">';
$f_no = $inv['invoice_no'] ? '#'.str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT) : lang('@DRAFT');
echo '<strong>'.lang('@Invoice No').':</strong><br><span style="font-size:16px;">'.$f_no.'</span></div>';

echo '<div class="design-block" id="block-inv-date" style="'.getStyle('block-inv-date', $layouts).';"><strong>'.lang('@Date').':</strong><br>'.date('d.m.Y', strtotime($inv['inv_date'])).'</div>';
echo '<div class="design-block" id="block-inv-due" style="'.getStyle('block-inv-due', $layouts).';"><strong>'.lang('@Due Date').':</strong><br>'.date('d.m.Y', strtotime($inv['inv_due_date'])).'</div>';

echo '<div class="design-block" id="block-lines" style="'.getStyle('block-lines', $layouts).'">';
echo '<table class="line-table"><thead><tr><th>'.lang('@Description').'</th><th style="text-align:right;">'.lang('@Qty').'</th><th style="text-align:right;">'.lang('@Price').'</th><th style="text-align:right;">'.lang('@Total').'</th></tr></thead><tbody>';

$res_l = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC"); 
$sub = 0; $vat = 0;
while ($l = DB::fetch_assoc($res_l)) { 
    $lt = $l['quantity'] * $l['price_each']; 
    $sub += $lt; $vat += ($lt * ($l['line_vat_rate'] / 100));
    echo '<tr><td>'.htmlspecialchars($l['line_text']).'</td><td align="right">'.number_format($l['quantity'],2,',','.').'</td><td align="right">'.number_format($l['price_each'],2,',','.').'</td><td align="right" style="font-weight:bold;">'.number_format($lt,2,',','.').'</td></tr>';
} 
echo '</tbody></table></div>';

echo '<div class="design-block" id="block-totals" style="'.getStyle('block-totals', $layouts).'">';
echo '<table style="width:45%; margin-left:55%; border-collapse:collapse;">';
echo '<tr><td>'.lang('@Subtotal').':</td><td align="right">'.number_format($sub,2,',','.').'</td></tr>';
echo '<tr><td>'.lang('@VAT').':</td><td align="right">'.number_format($vat,2,',','.').'</td></tr>';
echo '<tr style="font-size:1.2em;font-weight:bold;border-top:2px solid var(--border-dark);"><td>'.lang('@Total').':</td><td align="right">'.number_format($sub+$vat,2,',','.').' '.$cur.'</td></tr></table>';
if($inv['inv_note']) echo '<div style="margin-top:10mm;border-top:1px solid var(--border-color);padding-top:5px;"><small>'.lang('@Notes').':</small><br>'.nl2br(htmlspecialchars($inv['inv_note'])).'</div>';
echo '</div>';

echo '<div class="design-block" id="block-foot-pay" style="'.getStyle('block-foot-pay', $layouts).'; font-size:10px; color: var(--text-muted);"><strong>'.lang('@Payment').'</strong><br>' . ($settings['bank_name'] ?? 'Nordea') . '<br>' . lang('@Reg') . ': ' . ($settings['bank_reg'] ?? '0000') . ' ' . lang('@Acc') . ': ' . ($settings['bank_acc'] ?? '00000000') . '</div>';
echo '<div class="design-block" id="block-foot-contact" style="'.getStyle('block-foot-contact', $layouts).'; font-size:10px; color: var(--text-muted);"><strong>'.lang('@Contact').'</strong><br>' . ($settings['company_phone'] ?? '') . '<br>' . ($settings['company_email'] ?? '') . '</div>';
echo '<div class="design-block" id="block-foot-online" style="'.getStyle('block-foot-online', $layouts).'; font-size:10px; color: var(--text-muted);"><strong>'.lang('@Online').'</strong><br>' . lang('@MobilePay') . ': ' . ($settings['company_mobilepay'] ?? '00000') . '<br>' . ($settings['company_web'] ?? 'www.company.com') . '</div>';
echo '<div class="design-block" id="block-foot-legal" style="'.getStyle('block-foot-legal', $layouts).'; font-size:10px; color: var(--text-muted); text-align:right;"><strong>' . ($settings['company_name'] ?? 'Company Name') . '</strong><br>' . lang('@CVR') . ': ' . ($settings['company_cvr'] ?? '00000000') . '<br><em>'.lang('@Thank you for your business!').'</em></div>';
echo '</div>';

echo '<div id="help-system-popup">
        <h3><i class="fa-solid fa-circle-question"></i> ' . lang('@Hjælp & Vejledning') . '</h3>
        <div id="help-system-content">' . lang('@Henter hjælp...') . '</div>
      </div>';
?>
<script>
let designActive = false;

function toggleFineDesign() {
    designActive = !designActive;
    const page = document.getElementById('invoice-page');
    const btn = document.getElementById('designBtn');
    const mailBtn = document.getElementById('mailBtn');
    const printBtn = document.querySelector('.fa-print').parentElement;
    if(designActive) {
        page.classList.add('design-mode');
        btn.style.background = "var(--status-paid)"; 
        btn.innerText = "<?php echo lang('@Save & Exit Design'); ?>";
        if(printBtn) printBtn.style.display = 'none';
        if(mailBtn) mailBtn.style.display = 'none';
        initInteract();
    } else { 
        location.reload(); 
    }
}

function initInteract() {
    interact('#help-system-popup').draggable({
        allowFrom: 'h3',
        listeners: {
            move(event) {
                const target = event.target;
                const x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx;
                const y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;
                target.style.transform = `translate(${x}px, ${y}px)`;
                target.setAttribute('data-x', x);
                target.setAttribute('data-y', y);
            }
        }
    });

    interact('.design-block').draggable({
        listeners: {
            move(event) {
                const target = event.target;
                const x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx;
                const y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;
                target.style.transform = `translate(${x}px, ${y}px)`;
                target.setAttribute('data-x', x);
                target.setAttribute('data-y', y);
            },
            end(event) { saveToDB(event.target); }
        }
    }).resizable({
        edges: { left: false, right: true, bottom: false, top: false },
        modifiers: [ 
            interact.modifiers.snapSize({ targets: [ interact.createSnapGrid({ x: 5, y: 5 }) ] }), 
            interact.modifiers.restrictSize({ min: { width: 50 } }) 
        ],
        listeners: {
            move (event) {
                var target = event.target;
                target.style.width = event.rect.width + 'px';
            },
            end (event) { saveToDB(event.target); }
        }
    });
}

function openHelpSystem() {
    const popup = document.getElementById('help-system-popup');
    const content = document.getElementById('help-system-content');
    if(popup) {
        popup.style.display = 'block';
        fetch('json-data/help_system.json')
        .then(res => res.json())
        .then(data => {
            const page = '<?php echo basename($_SERVER['SCRIPT_NAME']); ?>';
            if(data[page]) {
                content.innerHTML = data[page];
            } else {
                content.innerHTML = "<?php echo lang('@Ingen hjælpetekst tilgængelig for denne side.'); ?>";
            }
        }).catch(() => {
            content.innerHTML = "<?php echo lang('@Fejl under indlæsning af hjælp.'); ?>";
        });
    }
}

function saveToDB(target) {
    const paper = document.getElementById('invoice-page').getBoundingClientRect();
    const rect = target.getBoundingClientRect();
    const pxToMm = 3.7795;
    const posX_mm = (rect.left - paper.left) / pxToMm;
    const posY_mm = (rect.top - paper.top) / pxToMm;
    const width_mm = target.offsetWidth / pxToMm;
    let fd = new FormData();
    fd.append('id', target.id);
    fd.append('x', posX_mm.toFixed(2));
    fd.append('y', posY_mm.toFixed(2));
    fd.append('w', width_mm.toFixed(2));
    fetch('save_layout.php', { method: 'POST', body: fd })
    .then(response => response.text())
    .then(data => { console.log("<?php echo lang('@Saved:'); ?>", target.id, 'X:', posX_mm.toFixed(2), 'W:', width_mm.toFixed(2)); });
}

function generateAndSendInvoice(invoiceId) {
    const element = document.getElementById('invoice-page');
    const mailBtn = document.getElementById('mailBtn');
    if (!element) {
        alert("<?php echo lang('@Could not find invoice element.'); ?>");
        return;
    }
    if(mailBtn) {
        mailBtn.disabled = true;
        mailBtn.innerText = "<?php echo lang('@Sending...'); ?>";
    }
    const opt = {
        margin:       0,
        filename:     'invoice_' + invoiceId + '.pdf',
        image:        { type: 'jpeg', quality: 0.95 },
        html2canvas:  { scale: 2, useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).outputPdf('blob').then(function(pdfBlob) {
        const formData = new FormData();
        formData.append('pdf_file', pdfBlob, 'invoice_' + invoiceId + '.pdf');
        const customBody = document.getElementsByName('mail_body')[0];
        if (customBody) formData.append('custom_body', customBody.value);
        fetch('send_invoice_action.php?id=' + invoiceId, { method: 'POST', body: formData })
        .then(response => {
            if (!response.ok) throw new Error("<?php echo lang('@Server responded with status'); ?> " + response.status);
            return response.json();
        })
        .then(data => {
            if (data.success) {
                window.location.href = 'invoice_view.php?id=' + invoiceId + '&status=sent';
            } else {
                alert("<?php echo lang('@Error during dispatch:'); ?> " + data.error);
                if(mailBtn) {
                    mailBtn.disabled = false;
                    mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>";
                }
            }
        })
        .catch(error => {
            console.error('Network details:', error);
            alert("<?php echo lang('@Network error: Server rejected data. Check console (F12) for details.'); ?>");
            if(mailBtn) {
                mailBtn.disabled = false;
                mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>";
            }
        });
    });
}

window.onscroll = function() {
    const btn = document.getElementById("fab-scroll-top");
    if (btn) {
        if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) { 
            btn.style.display = "flex"; 
        } else { 
            btn.style.display = "none"; 
        }
    }
};
</script>

<div id="fab-scroll-top" onclick="window.scrollTo({top: 0, behavior: 'smooth'});" data-hint="<?php echo lang('@Go to top'); ?>">
    <i class="fa fa-arrow-up"></i>&nbsp;<span><?php echo lang('@Top'); ?></span>
</div>

<div class="floating-action-bar custom-bottom-right">
    <?php
    $current_page = basename($_SERVER['SCRIPT_NAME']);
    $help_file = __DIR__ . '/json-data/help_system.json';
    $has_help = false;
    if (file_exists($help_file)) {
        $help_data = json_decode(file_get_contents($help_file), true);
        if ($help_data && isset($help_data[$current_page]) && !empty($help_data[$current_page])) {
            $has_help = true;
        }
    }
    if ($has_help) {
        $btn_style = 'background: var(--btn-help); opacity: 1; cursor: pointer;';
        $btn_onclick = 'openHelpSystem(); return false;';
        $btn_hint = '@Hjælp tilgængelig';
    } else {
        $btn_style = 'background: var(--btn-disabled); opacity: 0.4; cursor: not-allowed;';
        $btn_onclick = 'return false;';
        $btn_hint = '@Ingen hjælpetekst til denne side';
    }
    ?>
    <a href="#" class="fab-item" onclick="<?php echo $btn_onclick; ?>" style="<?php echo $btn_style; ?>" data-hint="<?php echo lang($btn_hint); ?>">
        <i class="fa-solid fa-circle-question"></i>&nbsp;<?php echo lang('@Hjælp'); ?>
    </a>
</div>

<?php
$GLOBALS['no_floating_menu'] = true; 
htm_Footer(); 
?>