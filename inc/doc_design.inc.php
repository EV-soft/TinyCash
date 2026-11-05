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
    htm_Banner('<i class="fa-solid fa-triangle-exclamation"></i> ' . lang('@Your company profile is incomplete (name, CVR, address or city is missing). This invoice will show placeholder text where data is missing until you fill it in under Company Settings.')
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
        . htmlspecialchars($co['city']) . '<br>' . lang('@CVR') . ': ' . htmlspecialchars($co['cvr']) . '</div>', $layouts);
    doc_block('block-recipient', '<div style="font-size:14px;"><small style="color:#888;">' . lang('@Recipient') . ':</small><br><strong>'
        . htmlspecialchars($cust_name) . '</strong><br>' . nl2br(htmlspecialchars($cust_address)) . $extra . '</div>', $layouts);
}

// Foden: betaling (ikke på et tilbud - der er intet at betale endnu), kontakt, online, firma
function doc_foot_blocks(array $s, array $layouts, array $co, bool $pay = true): void {
    $style = ' font-size:10px; color:#666;';
    $foot  = fn(string $t, string $h) => '<strong>' . lang($t) . '</strong><br>' . $h;
    if ($pay) {
        doc_block('block-foot-pay', $foot('@Payment', htmlspecialchars(($s['bank_name'] ?? '') ?: lang('@[Bank name missing]')) . '<br>'
            . lang('@Reg') . ': ' . htmlspecialchars(($s['bank_reg'] ?? '') ?: lang('@[Reg. no. missing]'))
            . ' ' . lang('@Acc') . ': ' . htmlspecialchars(($s['bank_acc'] ?? '') ?: lang('@[Account no. missing]'))), $layouts, '', $style);
    }
    $online = [];
    if (!empty($s['company_mobilepay'])) $online[] = lang('@MobilePay') . ': ' . htmlspecialchars($s['company_mobilepay']);
    if (!empty($s['company_web']))       $online[] = htmlspecialchars($s['company_web']);
    doc_block('block-foot-contact', $foot('@Contact', htmlspecialchars($s['company_phone'] ?? '') . '<br>' . htmlspecialchars($s['company_email'] ?? '')), $layouts, '', $style);
    doc_block('block-foot-online', $foot('@Online', implode('<br>', $online)), $layouts, $online ? '' : 'block-empty', $style);
    doc_block('block-foot-legal', $foot('@Legal', '<strong>' . htmlspecialchars($co['name']) . '</strong><br>' . lang('@CVR') . ': ' . htmlspecialchars($co['cvr'])), $layouts, '', $style);
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
</script>';
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
        const below = Array.from(p.querySelectorAll(".design-block")).filter(el => el !== g && el.offsetParent !== null && el.offsetTop > gTop);
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
    $cfg += ['own' => 0, 'confirm' => '', 'hide' => [], 'csrf' => csrf_token()];
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
}
</script>';
}
