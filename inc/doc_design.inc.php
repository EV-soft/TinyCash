<?php # /inc/doc_design.inc.php v:1.0.0 d:2026-11-04 i:evs
// Designtilstand for kundevendte blanketter (faktura, tilbud, rykker, kontoudtog): blokke der placeres frit på et A4-papir, gemt
// pr. layout i invoice_layout_blocks (layouttypen i invoice_layouts.doc_type, se inc/invoice_layouts.inc.php).
// Gem-endpoint save_layout.php, layout-handlinger invoice_layout_action.php. invoice_view.php bruger kun JS'en og
// doc_block_style() - fakturaen har sin egen <head> og CSS (konvolutzoner m.m.).
require_once __DIR__ . '/invoice_layouts.inc.php';

// Standardplacering (mm fra papirets kant) for en blok uden gemt række - samme som invoice_view.php's getStyle()
function doc_block_style(string $id, array $layouts): string {
    static $defaults = [
        'block-logo'         => ['pos_x' => 0,   'pos_y' => 0,   'width_mm' => 60],
        'block-stamp'        => ['pos_x' => 140, 'pos_y' => 0,   'width_mm' => 0],
        'block-sender'       => ['pos_x' => 0,   'pos_y' => 22,  'width_mm' => 90],
        'block-recipient'    => ['pos_x' => 0,   'pos_y' => 50,  'width_mm' => 90],
        'block-cust-ref'     => ['pos_x' => 0,   'pos_y' => 76,  'width_mm' => 90],
        'block-delivery'     => ['pos_x' => 0,   'pos_y' => 84,  'width_mm' => 90],
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
        'block-foot-message' => ['pos_x' => 0,   'pos_y' => 265, 'width_mm' => 180],
    ];
    $l = $layouts[$id] ?? ($defaults[$id] ?? ['pos_x' => 0, 'pos_y' => 0, 'width_mm' => 0]);
    $w = ((float)$l['width_mm'] > 0) ? (float)$l['width_mm'] . 'mm' : 'max-content; min-width: 40mm';
    return 'left:' . (float)$l['pos_x'] . 'mm; top:' . (float)$l['pos_y'] . 'mm; width:' . $w . '; box-sizing: border-box;';
}

// En blok på papiret. $extra_class 'block-empty': kun synlig (som pladsholder) i designtilstand
function doc_block(string $id, string $content, array $layouts, string $extra_class = '', string $extra_style = ''): void {
    $opt = in_array($id, IL_OPTIONAL_BLOCKS, true);
    $cls = 'design-block' . ($extra_class !== '' ? ' ' . $extra_class : '') . ($opt && il_block_hidden($layouts, $id) ? ' block-off' : '');
    echo '<div class="' . $cls . '"' . ($opt ? ' data-optional="1"' : '') . ' id="' . $id . '" style="' . doc_block_style($id, $layouts) . $extra_style . '">' . $content . '</div>';
}

// Layoutet der vises: ?layout= (af samme type) eller typens standard. Ikke migreret: on=false og standardplaceringer
function doc_layout_setup($conn, string $doc): array {
    if (!il_doc_ready($conn)) return ['on' => false, 'id' => 0, 'list' => [], 'blocks' => []];
    $default = il_default_id($conn, $doc);      // (det første besøg laver "Standard" - en kopi af fakturaens)
    $id = il_valid_id($conn, $_GET['layout'] ?? 0, $doc) ?: $default;
    return ['on' => true, 'id' => $id, 'list' => il_list($conn, $doc), 'blocks' => il_blocks($conn, $id)];
}

// Advarsel over papiret, når firmaprofilen mangler felter (papiret viser pladsholdere)
function doc_profile_banner(array $s): void {
    if (!doc_company($s)['incomplete']) return;
    echo '<div class="no-print" style="width:210mm; margin:10px auto;">';
    htm_Banner('<i class="fa-solid fa-triangle-exclamation"></i> ' . sprintf(lang('@Your company profile is incomplete (name, %s, address or city is missing). This invoice will show placeholder text where data is missing until you fill it in under Company Settings.'), tc_company_id_short())
        . ' <a href="company_settings.php" style="font-weight:bold;">' . lang('@Go to Company Settings') . '</a>', 'warning');
    echo '</div>';
}

// Knaprækken over papiret (ombrydes - en lang række må ikke klippes i venstre side); lukkes med echo '</div>'
function doc_toolbar_(): void {
    echo '<div class="no-print" style="width:210mm; max-width:100%; margin:10px auto; display:flex; justify-content:flex-end; flex-wrap:wrap; gap:10px;">';
}

// Logo, afsender og modtager. $co fra doc_company() kaldt på kundens sprog; $extra står under modtagerens adresse
function doc_head_blocks(array $layouts, array $co, string $cust_name, string $cust_address, string $extra = ''): void {
    $logo = tc_logo_path();
    doc_block('block-logo', $logo ? '<img src="' . htmlspecialchars($logo) . '" style="max-height:60px; display:block;">'
        : '<span class="design-placeholder">(' . lang('@Logo missing') . ')</span>', $layouts, $logo ? '' : 'block-empty');
    doc_block('block-sender', '<div style="font-size:14px;"><strong>' . htmlspecialchars($co['name']) . '</strong><br>' . htmlspecialchars($co['addr']) . '<br>'
        . htmlspecialchars($co['city']) . '<br>' . tc_company_id_short() . ': ' . htmlspecialchars($co['cvr']) . '</div>', $layouts);
    doc_block('block-recipient', '<div style="font-size:14px;"><small style="color:#888;">' . lang('@Recipient') . ':</small><br><strong>'
        . htmlspecialchars($cust_name) . '</strong><br>' . nl2br(htmlspecialchars($cust_address)) . $extra . '</div>', $layouts);
}

// Betalingsoplysningerne i foden: bank, reg./konto, IBAN/BIC (når udfyldt) og fakturaens betalingsreference
// ($ref fra tc_payment_ref(), inc/payment_ref.inc.php). Fælles for faktura, rykker og kontoudtog.
function doc_pay_html(array $s, ?array $ref = null): string {
    $h = htmlspecialchars(($s['bank_name'] ?? '') ?: lang('@[Bank name missing]'));
    // Reg./kontonr. er dansk (og nordisk) - med kun en IBAN (resten af EU) stod der "[Reg.nr. mangler]" på kundens faktura
    $has_local = trim((string)($s['bank_reg'] ?? '')) !== '' || trim((string)($s['bank_acc'] ?? '')) !== '';
    if ($has_local || empty($s['bank_iban']))
        $h .= '<br>' . lang('@Reg') . ': ' . htmlspecialchars(($s['bank_reg'] ?? '') ?: lang('@[Reg. no. missing]'))
            . ' ' . lang('@Acc') . ': ' . htmlspecialchars(($s['bank_acc'] ?? '') ?: lang('@[Account no. missing]'));
    if (!empty($s['bank_iban'])) $h .= '<br>IBAN: ' . htmlspecialchars(trim(chunk_split($s['bank_iban'], 4, ' ')))
        . (!empty($s['bank_bic']) ? ' &nbsp;BIC: ' . htmlspecialchars($s['bank_bic']) : '');
    if ($ref) $h .= '<br><strong>' . ($ref['scheme'] === 'fik71' ? 'FIK' : lang('@Payment reference')) . ': ' . htmlspecialchars($ref['display']) . '</strong>';
    return $h;
}

// Foden: betaling (ikke på et tilbud - der er intet at betale endnu), kontakt, online, firma
function doc_foot_blocks(array $s, array $layouts, array $co, bool $pay = true, ?array $ref = null): void {
    $style = ' font-size:10px; color:#666;';
    $foot  = fn(string $t, string $h) => '<strong>' . lang($t) . '</strong><br>' . $h;
    if ($pay) doc_block('block-foot-pay', $foot('@Payment', doc_pay_html($s, $ref)), $layouts, '', $style);
    $online = [];
    if (!empty($s['company_mobilepay'])) $online[] = lang('@MobilePay') . ': ' . htmlspecialchars($s['company_mobilepay']);
    if (!empty($s['company_web']))       $online[] = htmlspecialchars($s['company_web']);
    doc_block('block-foot-contact', $foot('@Contact', htmlspecialchars($s['company_phone'] ?? '') . '<br>' . htmlspecialchars($s['company_email'] ?? '')), $layouts, '', $style);
    doc_block('block-foot-online', $foot('@Online', implode('<br>', $online)), $layouts, $online ? '' : 'block-empty', $style);
    // Momsnummeret skal stå på fakturaen (momsdirektivet art. 226) - kun firma-id'et stod der: Hollands btw-id, Litauens
    // PVM-kode og Tysklands USt-IdNr manglede helt, og fx Belgien og Norge kræver selve momsnummeret (BE..., ...MVA)
    $vat_no = function_exists('tc_company_vat_id') ? tc_company_vat_id() : '';
    $vat_same = $vat_no === '' || (function_exists('tc_country') && tc_country() === 'dk');      // (i Danmark er CVR-nummeret nok)
    doc_block('block-foot-legal', $foot('@Legal', '<strong>' . htmlspecialchars($co['name']) . '</strong><br>' . tc_company_id_short() . ': ' . htmlspecialchars($co['cvr'])
        . (!$vat_same ? '<br>' . lang('@VAT number') . ': ' . htmlspecialchars($vat_no) : '')
        . (tc_register_text() !== '' ? '<br>' . htmlspecialchars(tc_register_text()) : '')), $layouts, '', $style);
}

// JS: html2pdf med blanketternes faste A4-opsætning. docPdf(el, filnavn) giver en html2pdf-kæde (.save() /
// .outputPdf("blob")); docPdfSave(papir-id, filnavn) gemmer filen
function doc_pdf_js(): string {
    return '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function docPdf(el, filename) {
    return html2pdf().set({ margin: 0, filename: filename, image: { type: "jpeg", quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF: { unit: "mm", format: "a4", orientation: "portrait" } }).from(el);
}
function docPdfSave(id, filename) {
    const el = document.getElementById(id);
    if (!el || typeof html2pdf === "undefined") { sysAlert(' . json_encode(lang('@PDF library not loaded.')) . ');  return; }
    docPdf(el, filename).save();
}
// Sender blanketten som PDF: danner PDF-filen af papiret, uploader den sammen med handlingen (ajax=1), som vedhæfter
// den mailen og svarer med JSON {success, error, redirect}. question: et ja/nej først (tom = intet spørgsmål).
function docPdfSend(id, url, filename, question, btn) {
    if (question && !confirm(question)) return;
    const el = document.getElementById(id);
    if (!el || typeof html2pdf === "undefined") { sysAlert(' . json_encode(lang('@PDF library not loaded.')) . ');  return; }
    const label = btn ? btn.innerHTML : "";
    const reset = () => { if (btn) { btn.disabled = false;  btn.innerHTML = label; } };
    if (btn) { btn.disabled = true;  btn.textContent = ' . json_encode(lang('@Sending...')) . '; }
    docPdf(el, filename).outputPdf("blob").then(blob => {
        const fd = new FormData();
        fd.append("pdf_file", blob, filename);  fd.append("csrf_token", ' . json_encode(csrf_token()) . ');  fd.append("ajax", "1");
        return fetch(url, { method: "POST", body: fd }).then(r => r.json().catch(() => ({ success: false, error: "status " + r.status })));
    }).then(d => {
        if (d.success) { location.href = d.redirect || location.href; return; }
        sysAlert(d.error || "?");  reset();
    }).catch(e => { sysAlert(String(e));  reset(); });
}
</script>';
}

// Grafikkens udseende (samme regler som docShapeStyle() i doc_shapes_js()): linjer er en fyldt strimmel i stregfarven,
// ramme = kant + valgfrit fyld, flade = fyld. Farverne skal med i udskrift/PDF (print-color-adjust).
function doc_shape_style(array $s): string {
    $sw = max(0.2, (float)$s['stroke_mm']);
    $sc = $s['stroke_color'] !== '' ? $s['stroke_color'] : 'transparent';
    $fc = $s['fill_color'] !== '' ? $s['fill_color'] : 'transparent';
    $css = 'position:absolute; left:' . (float)$s['pos_x'] . 'mm; top:' . (float)$s['pos_y'] . 'mm; box-sizing:border-box; '
         . '-webkit-print-color-adjust:exact; print-color-adjust:exact; ';
    switch ($s['shape_type']) {
        case 'hline': return $css . 'width:' . (float)$s['width_mm'] . 'mm; height:' . $sw . 'mm; background:' . $sc . ';';
        case 'vline': return $css . 'width:' . $sw . 'mm; height:' . (float)$s['height_mm'] . 'mm; background:' . $sc . ';';
        case 'fill':  return $css . 'width:' . (float)$s['width_mm'] . 'mm; height:' . (float)$s['height_mm'] . 'mm; background:' . $fc . '; border-radius:' . (float)$s['radius_mm'] . 'mm;';
        default:      return $css . 'width:' . (float)$s['width_mm'] . 'mm; height:' . (float)$s['height_mm'] . 'mm; background:' . $fc . '; border:'
                           . ((float)$s['stroke_mm'] > 0 ? (float)$s['stroke_mm'] : 0) . 'mm solid ' . $sc . '; border-radius:' . (float)$s['radius_mm'] . 'mm;';
    }
}
// Layoutets grafik som HTML - skrives FØRST i papiret, så teksten ligger ovenpå
function doc_shapes_html(array $shapes): string {
    $h = '';
    foreach ($shapes as $s) {
        $data = ['id' => (int)$s['shape_id'], 'type' => $s['shape_type'], 'stroke' => $s['stroke_color'], 'fill' => $s['fill_color'],
                 'stroke_mm' => (float)$s['stroke_mm'], 'radius_mm' => (float)$s['radius_mm']];
        $h .= '<div class="design-shape" data-shape="' . htmlspecialchars(json_encode($data), ENT_QUOTES) . '" style="' . doc_shape_style($s) . ' pointer-events:none;"></div>';
    }
    return $h;
}

// CSS: papiret (altid hvidt - også i mørkt tema, det er kundens papir), blokkene og designtilstanden
function doc_design_css(): string {
    return '<style>
    .doc-paper { background:#fff; color:#222; width:210mm; min-height:297mm; padding:15mm; margin:20px auto; position:relative; box-shadow:0 0 10px rgba(0,0,0,0.1); box-sizing:border-box; }
    .html2pdf__container .doc-paper { margin:0; box-shadow:none; min-height:296mm; }      /* (297mm gav en tom side 2 i PDF-filen) */
    .doc-paper.design-mode { background-image:linear-gradient(to right, #e0e0e0 1px, transparent 1px), linear-gradient(to bottom, #e0e0e0 1px, transparent 1px); background-size:37.8px 37.8px; background-color:#fcfcfc; }
    .doc-paper .design-block { position:absolute; padding:5px; box-sizing:border-box; user-select:none; touch-action:none; }
    .doc-paper.design-mode .design-block { border:1px dashed #3498db; cursor:move; background:rgba(52,152,219,0.02); }
    .doc-paper.design-mode .design-block::after { content:""; position:absolute; right:0; bottom:0; width:10px; height:10px; background:#3498db; cursor:nwse-resize; }
    .doc-paper .block-empty { display:none; }
    .doc-paper.design-mode .block-empty { display:block; }
    .doc-paper .block-off { display:none !important; }
    .doc-paper.design-mode .block-off { display:block !important; opacity:.3; outline:1px dashed #e74c3c; }
    .doc-paper .il-eye { display:none; position:absolute; top:-10px; right:-10px; width:22px; height:22px; border-radius:50%; border:1px solid #ccc; background:#fff; color:#333; font-size:11px; cursor:pointer; align-items:center; justify-content:center; z-index:5; padding:0; }
    .doc-paper.design-mode .il-eye { display:flex; }
    .doc-paper.design-mode .block-off .il-eye { opacity:1; color:#e74c3c; }
    .doc-paper .design-placeholder { font-style:italic; color:#aaa; font-size:11px; }
    .doc-paper .line-table { width:100%; border-collapse:collapse; }
    .doc-paper .line-table th { background:#f8f9fa; border-bottom:2px solid #333; padding:8px; text-align:left; font-size:13px; color:#222; }
    .doc-paper .line-table td { padding:10px 8px; border-bottom:1px solid #eee; font-size:15px; color:#222; }
    .doc-paper .doc-stamp { display:inline-block; border:3px solid; padding:4px 14px; font-weight:bold; font-size:16px; text-transform:uppercase; transform:rotate(-8deg); border-radius:4px; }
    .il-bar { width:210mm; margin:0 auto 8px; display:flex; flex-wrap:wrap; align-items:center; gap:8px; font-size:13px; }
    .il-bar select { padding:4px 6px; font-size:13px; max-width:260px; }
    .il-tools { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
    .il-bar button[disabled] { opacity:.45; cursor:default !important; }
    #doc-coord { position:fixed; display:none; background:#333; color:#fff; padding:4px 8px; border-radius:4px; pointer-events:none; z-index:999999; font-size:12px; white-space:nowrap; }
    @media print {
        .no-print, .il-bar { display:none !important; }
        body { background:#fff; margin:0; padding:0; }
        .doc-paper { box-shadow:none; margin:0; }
    }
    </style>';
}

// JS: en blok med variabel længde (posteringer/varelinjer) skubber blokkene under sig ned i stedet for at skrive hen
// over dem, og papiret bliver længere (PDF/udskrift deler det i sider). Blokkenes gemte plads røres ikke. Kaldes lige
// efter papiret (så en PDF dannet ved DOMContentLoaded er skubbet) og igen ved load (skrifttyper og logo på plads).
function doc_flow_js(string $paper, string $grow): string {
    return '<script>
(function () {
    function flow() {
        const p = document.getElementById(' . json_encode($paper) . '), g = document.getElementById(' . json_encode($grow) . ');
        if (!p || !g || p.classList.contains("design-mode")) return;
        p.querySelectorAll("[data-flow-top]").forEach(el => { el.style.top = el.dataset.flowTop;  el.removeAttribute("data-flow-top"); });
        p.style.minHeight = "";
        if (g.offsetParent === null) return;
        const k = p.offsetWidth / 210, gTop = g.offsetTop;
        const below = Array.from(p.querySelectorAll(".design-block, .design-shape")).filter(el => el !== g && el.offsetParent !== null && el.offsetTop > gTop);
        if (!below.length) return;
        const push = gTop + g.offsetHeight + 3 * k - Math.min.apply(null, below.map(el => el.offsetTop));
        if (push <= 0) return;
        let bottom = 0;
        below.forEach(el => { el.dataset.flowTop = el.style.top;  el.style.top = (el.offsetTop + push) + "px";  bottom = Math.max(bottom, el.offsetTop + el.offsetHeight); });
        p.style.minHeight = Math.max(p.offsetHeight, bottom + 15 * k) + "px";
    }
    flow();
    window.addEventListener("load", flow);
})();
</script>';
}

// Layoutlinjen over papiret: vælg, opret (kopi), omdøb, standard, slet
function doc_layout_bar(array $list, int $shown_id, string $hint): void {
    echo '<div class="no-print il-bar">';
    echo '<label for="ilSel"><i class="fa-solid fa-table-cells-large"></i> ' . lang('@Layout') . ':</label> ';
    echo '<select id="ilSel" onchange="docLayoutShow(this.value)" data-hint="' . htmlspecialchars($hint) . '">';
    foreach ($list as $lid => $l) {
        echo '<option value="' . (int)$lid . '"' . ($lid === $shown_id ? ' selected' : '') . '>' . htmlspecialchars($l['name'] . ((int)$l['is_default'] === 1 ? ' ★' : '')) . '</option>';
    }
    echo '</select> <span class="il-tools">';
    $is_default = (int)($list[$shown_id]['is_default'] ?? 0) === 1;
    htm_Button(icon: 'fa-plus', labl: '@New layout', styl: 'padding:4px 10px; font-size:12px;', type: 'secondary', attr: 'onclick="docLayoutNew()" data-hint="' . lang('@A new layout - a copy of the layout shown, then move the blocks in Design Mode') . '"');
    htm_Button(icon: 'fa-i-cursor', labl: '@Rename', styl: 'padding:4px 10px; font-size:12px;', type: 'secondary', attr: 'onclick="docLayoutRename()"');
    htm_Button(icon: 'fa-star', labl: '@Make default', styl: 'padding:4px 10px; font-size:12px;', type: 'secondary', attr: ($is_default ? 'disabled ' : '') . 'onclick="docLayoutDefault()"');
    htm_Button(icon: 'fa-trash', labl: '@Delete', styl: 'padding:4px 10px; font-size:12px;', type: 'danger', attr: ($is_default ? 'disabled ' : '') . 'onclick="docLayoutDelete()"');
    echo '</span></div>';
}

// JS: designtilstand (træk, bredde, skjul/vis) og layout-handlinger. $cfg: paper (element-id), layout (vist layout,
// 0 = ikke migreret: kun placering, ingen layouts), doc ('invoice'|'quote'|'reminder'), url (siden uden
// layout-parameter, fx "quote_view.php?id=16"), names, btn (id på designknappen). Valgfrit: own (dokumentets eget
// layout - vises uden ?layout=), confirm (spørgsmål før designtilstand), hide (CSS-selektorer der skjules i
// designtilstand), t (tekster der erstatter standardteksterne)
function doc_design_js(array $cfg): string {
    $cfg['t'] = ($cfg['t'] ?? []) + [
        'save' => lang('@Save & Exit Design'), 'newName' => lang('@Name of the new layout (a copy of the layout shown):'),
        'rename' => lang('@New name of the layout:'), 'delete' => lang('@Delete the layout %s?'), 'title' => lang('@Layout'),
        'hide' => lang('@Hide this block in the layout'), 'show' => lang('@Show this block again'), 'err' => lang('@Error saving layout:'),
    ];
    $cfg['t'] += ['shapes' => lang('@Graphics'), 'hline' => lang('@Line'), 'vline' => lang('@Vertical line'), 'rect' => lang('@Frame'), 'fill' => lang('@Area'),
        'modeText' => lang('@Move text'), 'modeShapes' => lang('@Move graphics'), 'stroke' => lang('@Line colour'), 'fillc' => lang('@Fill'),
        'nofill' => lang('@No fill'), 'width' => lang('@Thickness (mm)'), 'radius' => lang('@Rounded corners (mm)'), 'del' => lang('@Delete'),
        'pick' => lang('@Click a graphic element to change it')];
    $cfg += ['own' => 0, 'confirm' => '', 'hide' => [], 'csrf' => csrf_token(), 'shapes' => false];
    return '<div id="doc-coord"></div>
<script src="https://cdn.jsdelivr.net/npm/interactjs@1.10.27/dist/interact.min.js"></script>
<script>
const DOCL = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ';
let docDesignActive = false;
function docLayoutShow(id) { id = parseInt(id, 10);  window.location.href = DOCL.url + (id && id !== DOCL.own ? "&layout=" + id : ""); }
function docLayoutPost(fields, done) {
    const fd = new FormData();
    Object.keys(fields).forEach(k => fd.append(k, fields[k]));
    fd.append("csrf_token", DOCL.csrf);
    fetch("invoice_layout_action.php", { method: "POST", body: fd })
        .then(r => r.json().catch(() => ({ success: false, error: "status " + r.status })))
        .then(d => { if (d.success) done(d); else sysAlert(d.error || "?", DOCL.t.title); })
        .catch(err => sysAlert(String(err), DOCL.t.title));
}
function docLayoutNew() {
    sysPrompt(DOCL.t.newName, name => {
        if (name === null || !name.trim()) return;
        docLayoutPost({ action: "create", name: name, copy_from: DOCL.layout, doc: DOCL.doc }, d => docLayoutShow(d.layout_id));
    }, DOCL.t.title);
}
function docLayoutRename() {
    sysPrompt(DOCL.t.rename, name => {
        if (name === null || !name.trim()) return;
        docLayoutPost({ action: "rename", layout_id: DOCL.layout, name: name }, () => location.reload());
    }, DOCL.t.title, DOCL.names[DOCL.layout] || "");
}
function docLayoutDefault() { docLayoutPost({ action: "set_default", layout_id: DOCL.layout }, () => location.reload()); }
function docLayoutDelete() {
    if (!confirm(DOCL.t.delete.replace("%s", "\"" + (DOCL.names[DOCL.layout] || "") + "\""))) return;
    docLayoutPost({ action: "delete", layout_id: DOCL.layout }, () => { window.location.href = DOCL.url; });
}
function docPxToMm() {
    // (samme koordinater som fakturaen: mm fra papirets kant, papiret er 210mm bredt)
    const paper = document.getElementById(DOCL.paper);
    return (paper && paper.offsetWidth > 0) ? paper.offsetWidth / 210 : 96 / 25.4;
}
function docSaveBlock(el) {
    const k = docPxToMm(), fd = new FormData();
    fd.append("id", el.id);
    fd.append("x", ((parseFloat(el.getAttribute("data-x")) || 0) / k).toFixed(2));
    fd.append("y", ((parseFloat(el.getAttribute("data-y")) || 0) / k).toFixed(2));
    fd.append("w", (el.offsetWidth / k).toFixed(2));
    fd.append("layout_id", DOCL.layout);
    fd.append("csrf_token", DOCL.csrf);
    fetch("save_layout.php", { method: "POST", body: fd })
        .then(r => r.json().catch(() => ({ success: false, error: "status " + r.status })))
        .then(d => { if (!d.success) sysAlert(DOCL.t.err + " " + (d.error || ""), DOCL.t.title); });
}
function docEyeIcon(b, visible) { b.innerHTML = "<i class=\"fa-solid " + (visible ? "fa-eye" : "fa-eye-slash") + "\"></i>"; b.title = visible ? DOCL.t.hide : DOCL.t.show; }
function docToggleBlock(el, b) {
    const k = docPxToMm(), visible = el.classList.contains("block-off");
    docLayoutPost({ action: "set_visible", layout_id: DOCL.layout, element_id: el.id, visible: visible ? 1 : 0,
        x: ((parseFloat(el.getAttribute("data-x")) || 0) / k).toFixed(2), y: ((parseFloat(el.getAttribute("data-y")) || 0) / k).toFixed(2), w: (el.offsetWidth / k).toFixed(2) },
        () => { el.classList.toggle("block-off", !visible);  docEyeIcon(b, visible); });
}
function docToggleDesign() {
    if (docDesignActive) { location.reload(); return; }
    if (typeof interact === "undefined") { sysAlert("interact.js?", DOCL.t.title); return; }
    if (DOCL.confirm && !confirm(DOCL.confirm)) return;
    docDesignActive = true;
    const paper = document.getElementById(DOCL.paper), label = document.getElementById("doc-coord"), btn = document.getElementById(DOCL.btn);
    // Blokke skubbet ned af doc_flow_js() designes på deres gemte plads
    paper.querySelectorAll("[data-flow-top]").forEach(el => { el.style.top = el.dataset.flowTop;  el.removeAttribute("data-flow-top"); });
    paper.style.minHeight = "";
    paper.classList.add("design-mode");
    if (btn) { btn.style.background = "var(--color-success, #2ecc71)"; btn.innerText = DOCL.t.save; }
    DOCL.hide.forEach(sel => document.querySelectorAll(sel).forEach(el => { el.style.display = "none"; }));
    const k = docPxToMm();
    paper.querySelectorAll(".design-block").forEach(el => {
        const x = el.offsetLeft, y = el.offsetTop;
        el.setAttribute("data-x", x);  el.setAttribute("data-y", y);
        el.style.left = "0";  el.style.top = "0";  el.style.transform = "translate(" + x + "px, " + y + "px)";
        if (DOCL.layout > 0 && el.hasAttribute("data-optional") && !el.querySelector(".il-eye")) {
            const b = document.createElement("button");
            b.type = "button";  b.className = "il-eye";
            docEyeIcon(b, !el.classList.contains("block-off"));
            b.addEventListener("click", e => { e.stopPropagation();  docToggleBlock(el, b); });
            el.appendChild(b);
        }
    });
    interact("#" + DOCL.paper + " .design-block")
        .draggable({
            ignoreFrom: ".il-eye",
            modifiers: [interact.modifiers.restrictRect({ restriction: "parent", endOnly: true })],
            listeners: {
                start() { label.style.display = "block"; },
                move(event) {
                    const t = event.target;
                    let x = (parseFloat(t.getAttribute("data-x")) || 0) + event.dx, y = (parseFloat(t.getAttribute("data-y")) || 0) + event.dy;
                    if (event.shiftKey) { const g = 18.9; x = Math.round(x / g) * g; y = Math.round(y / g) * g; }
                    t.style.transform = "translate(" + x + "px, " + y + "px)";
                    t.setAttribute("data-x", x);  t.setAttribute("data-y", y);
                    label.textContent = "X: " + Math.round(x / k) + "mm  Y: " + Math.round(y / k) + "mm";
                    label.style.left = (event.clientX + 15) + "px";  label.style.top = (event.clientY + 15) + "px";
                },
                end(event) { label.style.display = "none";  docSaveBlock(event.target); }
            }
        })
        .resizable({
            edges: { left: false, right: true, bottom: false, top: false },
            modifiers: [interact.modifiers.restrictSize({ min: { width: 50 } })],
            listeners: { move(event) { event.target.style.width = event.rect.width + "px"; }, end(event) { docSaveBlock(event.target); } }
        });
    if (window.docShapesInit) docShapesInit(paper, k, label);      // (grafik: se doc_shapes_js())
}
</script>' . (!empty($cfg['shapes']) && (int)$cfg['layout'] > 0 ? doc_shapes_js() : '');
}

// JS: grafik i designtilstand - tilføj linje/ramme/flade, træk og skalér, farver/tykkelse/hjørner, slet. En knap skifter
// mellem at flytte tekstblokke og grafik (grafik bag en tekstblok kan ellers ikke vælges). Gemmes med det samme pr. element.
function doc_shapes_js(): string {
    return <<<'JS'
<script>
function docShapeStyle(el, d) {
    const st = el.style, sw = Math.max(0.2, +d.stroke_mm || 0), sc = d.stroke || "transparent", fc = d.fill || "transparent";
    st.background = (d.type === "hline" || d.type === "vline") ? sc : fc;
    st.border = d.type === "rect" && +d.stroke_mm > 0 ? (+d.stroke_mm) + "mm solid " + sc : "none";
    st.borderRadius = (d.type === "rect" || d.type === "fill") ? (+d.radius_mm || 0) + "mm" : "0";
    if (d.type === "hline") st.height = sw + "mm";
    if (d.type === "vline") st.width = sw + "mm";
}
function docShapesInit(paper, k, label) {
    if (document.getElementById("doc-shape-bar")) return;
    const T = DOCL.t, css = document.createElement("style");
    css.textContent = ".design-mode .design-shape{outline:1px dashed #e67e22;}"
        + ".design-mode.shape-mode .design-shape{pointer-events:auto !important; cursor:move; z-index:5;}"
        + ".design-mode.shape-mode .design-block{pointer-events:none; opacity:.55;}"
        + ".design-mode .design-shape.sel{outline:2px solid #e67e22;}"
        + "#doc-shape-bar{position:sticky; top:0; z-index:50; width:210mm; max-width:100%; margin:0 auto 8px; display:flex; flex-wrap:wrap; gap:6px; align-items:center;"
        + " background:var(--bg-panel,#f8f9fa); border:1px solid var(--border-color,#ddd); border-radius:6px; padding:6px 8px; font-size:13px; box-sizing:border-box;}"
        + "#doc-shape-bar button{padding:4px 10px; border:1px solid var(--border-color,#ccc); border-radius:4px; background:var(--bg-card,#fff); color:inherit; cursor:pointer;}"
        + "#doc-shape-bar .on{background:#e67e22; color:#fff; border-color:#e67e22;} #doc-shape-props{display:flex; flex-wrap:wrap; gap:8px; align-items:center;}"
        + "#doc-shape-props input[type=number]{width:60px;}";
    document.head.appendChild(css);
    const bar = document.createElement("div");  bar.id = "doc-shape-bar";  bar.className = "no-print";
    const btn = (txt, fn) => { const b = document.createElement("button");  b.type = "button";  b.textContent = txt;  b.addEventListener("click", fn);  bar.appendChild(b);  return b; };
    const lbl = document.createElement("strong");  lbl.textContent = T.shapes + ":";  bar.appendChild(lbl);
    ["hline", "vline", "rect", "fill"].forEach(t => btn("+ " + T[t], () => docShapeAdd(t)));
    const mode = btn(T.modeShapes, () => setMode(!paper.classList.contains("shape-mode")));
    const props = document.createElement("span");  props.id = "doc-shape-props";  bar.appendChild(props);
    paper.parentNode.insertBefore(bar, paper);
    let sel = null;

    function setMode(on) {
        paper.classList.toggle("shape-mode", on);
        mode.textContent = on ? T.modeText : T.modeShapes;  mode.classList.toggle("on", on);
        if (!on) select(null);
        else if (!sel) props.textContent = T.pick;
    }
    function data(el) { return JSON.parse(el.dataset.shape); }
    function save(el, extra) {
        const d = data(el), f = { action: "shape_save", layout_id: DOCL.layout, shape_id: d.id,
            x: ((parseFloat(el.getAttribute("data-x")) || 0) / k).toFixed(2), y: ((parseFloat(el.getAttribute("data-y")) || 0) / k).toFixed(2),
            stroke: d.stroke, fill: d.fill, stroke_mm: d.stroke_mm, radius_mm: d.radius_mm };
        if (d.type !== "vline") f.w = (el.offsetWidth / k).toFixed(2);
        if (d.type !== "hline") f.h = (el.offsetHeight / k).toFixed(2);
        docLayoutPost(Object.assign(f, extra || {}), () => {});
    }
    function place(el) {      // fra mm-placering til translate (som tekstblokkene i designtilstand)
        const x = el.offsetLeft, y = el.offsetTop;
        el.setAttribute("data-x", x);  el.setAttribute("data-y", y);
        el.style.left = "0";  el.style.top = "0";  el.style.transform = "translate(" + x + "px, " + y + "px)";
    }
    function field(txt, input) { const l = document.createElement("label");  l.textContent = txt + " ";  l.appendChild(input);  props.appendChild(l);  return input; }
    function select(el) {
        if (sel) sel.classList.remove("sel");
        sel = el;  props.textContent = "";
        if (!el) return;
        el.classList.add("sel");
        const d = data(el), upd = (key, val) => { d[key] = val;  el.dataset.shape = JSON.stringify(d);  docShapeStyle(el, d);  save(el); };
        if (d.type !== "fill") {
            const c = document.createElement("input");  c.type = "color";  c.value = d.stroke || "#333333";
            field(T.stroke, c).addEventListener("change", () => upd("stroke", c.value));
            const n = document.createElement("input");  n.type = "number";  n.step = "0.1";  n.min = "0";  n.max = "10";  n.value = d.stroke_mm;
            field(T.width, n).addEventListener("change", () => upd("stroke_mm", Math.max(0, Math.min(10, +n.value || 0))));
        }
        if (d.type === "rect" || d.type === "fill") {
            let nf = null;
            const f = document.createElement("input");  f.type = "color";  f.value = d.fill || "#eeeeee";
            field(T.fillc, f).addEventListener("change", () => { upd("fill", f.value);  if (nf) nf.checked = false; });
            if (d.type === "rect") {
                nf = document.createElement("input");  nf.type = "checkbox";  nf.checked = !d.fill;
                field(T.nofill, nf).addEventListener("change", () => upd("fill", nf.checked ? "" : f.value));
            }
            const r = document.createElement("input");  r.type = "number";  r.step = "0.5";  r.min = "0";  r.max = "50";  r.value = d.radius_mm;
            field(T.radius, r).addEventListener("change", () => upd("radius_mm", Math.max(0, Math.min(50, +r.value || 0))));
        }
        const del = document.createElement("button");  del.type = "button";  del.textContent = T.del;
        del.addEventListener("click", () => docLayoutPost({ action: "shape_delete", layout_id: DOCL.layout, shape_id: d.id }, () => { el.remove();  select(null);  props.textContent = T.pick; }));
        props.appendChild(del);
    }
    window.docShapeAdd = function (type) {
        const def = { hline: { w: 180, h: 0.5, stroke_mm: 0.5 }, vline: { w: 0.5, h: 40, stroke_mm: 0.5 },
                      rect: { w: 80, h: 30, stroke_mm: 0.5 }, fill: { w: 80, h: 30, stroke_mm: 0, fill: "#eeeeee", stroke: "" } }[type];
        const y = Math.max(0, Math.round((150 - paper.getBoundingClientRect().top) / k));      // (synligt på skærmen)
        docLayoutPost(Object.assign({ action: "shape_add", layout_id: DOCL.layout, shape_type: type, x: 15, y: Math.min(y, 270) }, def), r => {
            const s = r.shape, el = document.createElement("div");
            el.className = "design-shape";
            el.dataset.shape = JSON.stringify({ id: s.shape_id, type: s.shape_type, stroke: s.stroke_color, fill: s.fill_color, stroke_mm: +s.stroke_mm, radius_mm: +s.radius_mm });
            el.style.cssText = "position:absolute; box-sizing:border-box; -webkit-print-color-adjust:exact; print-color-adjust:exact; left:" + s.pos_x + "mm; top:" + s.pos_y
                + "mm; width:" + s.width_mm + "mm; height:" + s.height_mm + "mm;";
            docShapeStyle(el, data(el));
            paper.insertBefore(el, paper.firstChild);      // (bag teksten)
            place(el);  setMode(true);  select(el);
        });
    };
    paper.querySelectorAll(".design-shape").forEach(place);
    paper.addEventListener("click", e => { const el = e.target.closest(".design-shape");  if (el && paper.classList.contains("shape-mode")) select(el); });
    interact("#" + DOCL.paper + " .design-shape")
        .draggable({
            listeners: {
                start(e) { select(e.target);  label.style.display = "block"; },
                move(e) {
                    const t = e.target;
                    let x = (parseFloat(t.getAttribute("data-x")) || 0) + e.dx, y = (parseFloat(t.getAttribute("data-y")) || 0) + e.dy;
                    if (e.shiftKey) { const g = 18.9;  x = Math.round(x / g) * g;  y = Math.round(y / g) * g; }
                    t.style.transform = "translate(" + x + "px, " + y + "px)";  t.setAttribute("data-x", x);  t.setAttribute("data-y", y);
                    label.textContent = "X: " + Math.round(x / k) + "mm  Y: " + Math.round(y / k) + "mm";
                    label.style.left = (e.clientX + 15) + "px";  label.style.top = (e.clientY + 15) + "px";
                },
                end(e) { label.style.display = "none";  save(e.target); }
            }
        })
        .resizable({
            edges: { left: false, top: false, right: true, bottom: true },
            modifiers: [interact.modifiers.restrictSize({ min: { width: 2, height: 2 } })],
            listeners: {
                move(e) {
                    const d = data(e.target);
                    if (d.type !== "vline") e.target.style.width = e.rect.width + "px";
                    if (d.type !== "hline") e.target.style.height = e.rect.height + "px";
                },
                end(e) { save(e.target); }
            }
        });
}
</script>
JS;
}
