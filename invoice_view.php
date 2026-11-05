<?php # /invoice_view.php v:1.3.1 d:2026-09-15 i:evs
# bruger-rapporteret: æøå vist forkert i modtagerfeltet - manglede <meta charset>
# v1.2.4: siden bygger sin egen <head> i stedet for at gå gennem htm_Header()
# (nødvendigt pga. print-/PDF-layoutet) - og havde derfor ALDRIG en <meta
# charset="UTF-8">, i modsætning til resten af appen (se inc/htm_page.lib.php).
# Siden har hidtil kun stolet på PHP's default_charset-baserede HTTP-header
# (Content-Type: text/html; charset=UTF-8) - virker de fleste steder, men en
# manglende/overskrevet header (fx pga. en proxy/CDN, en anden default_charset
# i php.ini på en anden installation, eller når siden gemmes/udskrives til PDF)
# tvinger browseren til at GÆTTE encoding, hvilket typisk rammer netop æøå.
# Tilføjet <meta charset="UTF-8"> som fallback, samme mønster som alle andre
# sider. Fandt samtidig at $inv['cust_name'] (modtagerfeltet) var det ENESTE
# felt i blokken der ikke gik gennem htmlspecialchars() - rettet for
# konsistens (kunder-navne med fx "&" kunne før ødelægge HTML-strukturen).
# (Print: fab og scroll-knap skjules via onbeforeprint)
# Flere fakturalayouts (inc/invoice_layouts.inc.php): siden tegner blokkene efter
# fakturaens layout - ?layout=<id> viser et andet layout som forhåndsvisning (og
# er det layout Design Mode retter i). PDF'en laves af html2pdf.js ud fra denne
# side og følger derfor layoutet.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/invoice_pdf_archive.inc.php';
require_once 'inc/invoice_layouts.inc.php';
require_once 'inc/doc_design.inc.php';
require_once 'inc/invoice_post.inc.php';     // invoice_is_written_off() (kreditnota-knappen)

$inv_id = (int)($_GET['id'] ?? 0);
// RETTET: ?status=sent alene viste "Fakturaen er sendt til ..." - også ved genindlæsning, fra historikken eller et
// bogmærke, uden at noget blev sendt. Bekræftelsen kræver nu at send_invoice_action.php lige har sendt netop denne
// faktura, og den vises én gang.
$is_sent_view = isset($_GET['status']) && $_GET['status'] === 'sent'
    && (int)($_SESSION['tc_sent_invoice'] ?? 0) === (int)($_GET['id'] ?? -1);
if ($is_sent_view) unset($_SESSION['tc_sent_invoice']);
$layouts = [];      // Blokplaceringerne - hentes når fakturaen er læst (layoutet kan afhænge af den og dens kunde)

// NYT: cust_language findes kun efter db-setup/migrate_customer_language.php er kørt - stille degraderet til ''
// (brug bogholderens eget sprog, uændret adfærd) hvis ikke migreret endnu.
$has_cust_lang_col = db_has_column($conn, 'customers', 'cust_language');
$cust_lang_select = $has_cust_lang_col ? ", c.cust_language" : "";

$sql = "SELECT i.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr $cust_lang_select FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id";
$res = DB::query($conn, $sql);
$inv = DB::fetch_assoc($res);
if (!$inv) redirect('sales_hub.php', '@Not found - it may already have been deleted.', 'error');      // RETTET: hvid side uden menu

// Flere fakturalayouts: fakturaens eget layout ($il_own) - eller et andet til
// forhåndsvisning/redigering (?layout=). Ikke migreret: det ene fælles layout.
$il_on = il_ready($conn);
$il_list = [];  $il_own = 0;  $il_id = 0;  $il_preview = false;  $il_posted = 0;
if ($il_on) {
    $il_list    = il_list($conn);
    $il_own     = il_for_invoice($conn, $inv);
    $il_id      = il_valid_id($conn, $_GET['layout'] ?? 0) ?: $il_own;
    $il_preview = ($il_id !== $il_own);
    $layouts    = il_blocks($conn, $il_id);
    $il_posted  = (int)(DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoices WHERE inv_layout_id = $il_id AND LOWER(inv_status) <> 'draft'"))[0] ?? 0);
} else {
    $l_res = DB::query($conn, "SELECT * FROM layout_settings");
    while ($l = DB::fetch_assoc($l_res)) { $layouts[$l['element_id']] = $l; }
}
$inv_is_draft = strtolower($inv['inv_status'] ?? 'draft') === 'draft';

// NYT: en bogført faktura uden allerede arkiveret PDF-kopi udløser en STILLE, automatisk genering+ arkivering i
// baggrunden (JS nederst i filen) - "selvhelbredende" ved hvert besøg på denne side, uanset om brugeren nogensinde
// trykker "Send via Mail". Kladder skal (endnu) ikke arkiveres (intet fakturanummer).
$needs_pdf_archive = (strtolower($inv['inv_status'] ?? 'draft') !== 'draft') && !invoice_has_archived_pdf($conn, $inv_id)
    && !$il_preview;    // Aldrig en forhåndsvisning af et andet layout - arkivet skal være fakturaen som den blev sendt

$settings = []; 
$set_res = DB::query($conn, "SELECT setting_key, setting_value FROM settings");
while ($s = DB::fetch_assoc($set_res)) { $settings[$s['setting_key']] = $s['setting_value']; }

function getSet($key) { global $settings; return $settings[$key] ?? ''; }

// En valgfri blok får data-optional (øje-ikonet i Design Mode) - og klassen block-off, når den er skjult i layoutet
function blockAttrs(string $id): string {
    $opt = in_array($id, IL_OPTIONAL_BLOCKS, true);
    return ($opt && il_block_hidden($GLOBALS['layouts'], $id) ? ' block-off' : '') . '"' . ($opt ? ' data-optional="1"' : '');
}

function echoBlock($id, $content, $extra_class = '') {
    $cls = 'design-block' . ($extra_class ? ' ' . $extra_class : '');
    echo '<div class="'.$cls.blockAttrs($id).' id="'.$id.'" style="'.doc_block_style($id, $GLOBALS['layouts']).'">'.$content.'</div>';
}

function echoFooterBlock($id, $title, $content) {
    echo '<div class="design-block'.blockAttrs('block-'.$id).' id="block-'.$id.'" style="'.doc_block_style('block-'.$id, $GLOBALS['layouts']).'; font-size:10px; color: var(--text-muted);">';
    echo '<strong>'.lang($title).'</strong><br>'.$content.'</div>';
}

// RETTET: disse fire felter faldt før tilbage til OPDIGTEDE, plausibelt-udseende værdier ("Company Name ApS",
// "Street Address 1", "8000 Aarhus", CVR "12345678") - hvis firmaprofilen ikke var udfyldt i Firmaindstillinger,
// ville en RIGTIG faktura sendt til en RIGTIG kunde vise et falsk firmanavn og et falsk CVR-nummer, uden nogen
// advarsel nogen steder. Viser nu et tydeligt, umuligt-at-forveksle-med-ægte "[mangler]"-mærke i stedet - synligt
// for brugeren selv (feltet er contenteditable og kan rettes direkte her), men aldrig en plausibel løgn.
require_once __DIR__ . '/inc/doc_paper.inc.php';
['name' => $coName, 'addr' => $coAddr, 'city' => $coCity, 'cvr' => $coCVR, 'incomplete' => $profile_incomplete] = doc_company($settings);
$cur    = $inv['currency'] ?? 'DKK';

echo '<!DOCTYPE html><html><head>
<meta charset="UTF-8">
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
    .html2pdf__container .paper { box-shadow: none !important; margin: 0 !important; padding: 0 !important; min-height: 296mm; }      /* (297mm gav en tom side 2 i PDF-filen) */
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
    /* Skjult i layoutet: væk på fakturaen (skærm, udskrift, PDF) - i Design Mode gennemsigtig, så den kan vises igen */
    .block-off { display:none !important; }
    .design-mode .block-off { display:block !important; opacity:.3; outline:1px dashed var(--color-danger); }
    .il-eye { display:none; position:absolute; top:-10px; right:-10px; width:22px; height:22px; border-radius:50%; border:1px solid var(--border-color);
              background:var(--bg-paper); color:var(--text-main); font-size:11px; cursor:pointer; align-items:center; justify-content:center; z-index:5; padding:0; }
    .design-mode .il-eye { display:flex; }
    .design-mode .block-off .il-eye { opacity:1; color:var(--color-danger); }
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
    /* Knap-rækken: ÉN linje der flyder ud til højre (bruger-anmodet), med vandret scroll hvis der bliver for mange
       knapper. E-mailbeskeden ligger nedenunder, ikke i knap-rækken. */
    .inv-toolbar { width:210mm; margin:5px auto; }
    /* RETTET: nowrap + justify-content:flex-end skubbede et overløb ud over VENSTRE kant, hvor det hverken kunne ses
       eller rulles frem - med 10 knapper blev de første klippet af. Rækken står stadig på én linje til højre, når der
       er plads, og brydes ellers om til en linje mere (alle knapper synlige). */
    .inv-btn-row { display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap:wrap; row-gap:8px; padding-bottom:4px; }
    .inv-mail-below { margin-top:10px; text-align:left; }
    .inv-mail-below fieldset { width:100% !important; }
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

    /* Layout-linje */
    .il-bar { width:210mm; margin:0 auto 8px; display:flex; flex-wrap:wrap; align-items:center; gap:8px; font-size:13px; color:var(--text-main); }
    .il-bar select { padding:4px 6px; font-size:13px; max-width:260px; }
    .il-note { color:var(--text-muted); }
    .il-tools { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
    .il-bar button[disabled] { opacity:.45; cursor:default !important; }

    /* Koordinat-label */
    #doc-coord { position:fixed !important; display:none; background:#333; color:#fff; padding:4px 8px; border-radius:4px; pointer-events:none; z-index:999999; font-size:12px; white-space:nowrap; }

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
        #doc-coord { display:none !important; }
        .block-empty       { display:none !important; }
        .block-off, .il-eye { display:none !important; }
        body  { background:var(--bg-paper); margin:0; padding:0; }
        .paper { box-shadow:none; margin:0; padding:0; border:none; }
    }
</style><script>' . htm_ActionLinkJs() . '</script></head><body>';      // (handlings-links som POST - se htm_ActionLinkJs())

// ── Sendt-bekræftelse ─────────────────────────────────────────────────────────
if ($is_sent_view) {
    echo '<div class="alert-sent-success">';
    echo '✓ ' . lang("@Invoice was generated successfully and has been sent to") . ' ' . htmlspecialchars($inv['cust_email']);
    echo '</div>';
}

// NYT: synlig advarsel når firmaprofilen er ufuldstændig - se $coName/$coCVR m.fl.'s egen kommentar ovenfor for
// baggrunden..no-print, så den ikke selv havner på den udskrevne/mailede faktura - formålet er udelukkende at gøre
// DEN INTERNE bruger opmærksom, før noget sendes ud med manglende/forkerte oplysninger.
if ($profile_incomplete) {
    echo '<div class="no-print" style="width:210mm; margin:5px auto; background:var(--bg-panel, #fff3cd); color:var(--text-alert-warning, #856404); border-left:4px solid var(--color-warning, #ffc107); border-radius:4px; padding:12px 15px; font-size:0.9em;">'
        . '<i class="fa-solid fa-triangle-exclamation"></i> '
        . lang('@Your company profile is incomplete (name, CVR, address or city is missing). This invoice will show placeholder text where data is missing until you fill it in under Company Settings.')
        . ' <a href="company_settings.php" style="font-weight:bold;">' . lang('@Go to Company Settings') . '</a>'
        . '</div>';
}

// Flash-besked fra en handling (betaling, afskrivning, kreditnota ...) - siden bygger sin egen <head>, så den vises her
if (!$is_sent_view && ($flash_htm = flash_render(echo: false)) !== '') echo '<div class="no-print" style="width:210mm; margin:5px auto;">' . $flash_htm . '</div>';

// ── Topbar: knapperne på ÉN linje der flyder til højre, e-mailbeskeden nedenunder (bruger-anmodet) ──────────────
echo '<div class="inv-toolbar no-print">';
// E-mailbeskedens standardtekst beregnes her; selve feltet vises under knapperne længere nede.
if (!$is_sent_view) {
    // RETTET: hårdkodet engelsk forslag - nu oversat, på kundens fakturasprog (som selve fakturaen), og for en
    // kreditnota en kreditnotatekst (se send_invoice_action.php)
    doc_lang_push((string)($inv['cust_language'] ?? ''));
    $mb_default = sprintf(lang(!empty($inv['credit_ref']) ? "@Hi %s,\n\nPlease find your credit note attached.\n\nBest regards," : "@Hi %s,\n\nPlease find your invoice attached.\n\nBest regards,"), $inv['cust_name']);
    doc_lang_pop();
    $standard_hilsen = (empty($inv['credit_ref']) && tc_custom_mail_body($settings) !== '') ? tc_custom_mail_body($settings) : $mb_default;
}
echo '<div class="inv-btn-row">';
if (!$is_sent_view) {
    htm_Button(icon:'fa-pencil-ruler', labl:'@Design Mode',  type:'secondary', attr:'onclick="docToggleDesign()" id="designBtn" data-hint="'.lang('@Reposition invoice elements freely').'"');
    if (!$il_preview)      // (en forhåndsvisning af et andet layout må ikke blive den PDF kunden får)
    htm_Button(icon:'fa-envelope',     labl:'@Send via Mail', type:'info',      attr:'onclick="generateAndSendInvoice('.$inv_id.')" id="mailBtn" data-hint="'.lang('@Email this invoice to the customer').'"');
    // RETTET: OIOUBL er et dansk e-faktura-format - knappen er grå/deaktiveret når firmaets bogføringsvaluta ikke
    // er DKK (export_oioubl.php spærrer stadig selv direkte adgang, dette er kun for at undgå et dødt link).
    if (strtoupper($global_settings['currency'] ?? 'DKK') === 'DKK') {
        htm_Button(icon:'fa-file-code',    labl:'@OIOUBL XML',   type:'warning',   attr:'onclick="window.location.href=\'export_oioubl.php?id='.$inv_id.'\'" data-hint="'.lang('@Download this invoice as an OIOUBL e-invoice XML file').'"');
    } else {
        htm_Button(icon:'fa-file-code',    labl:'@OIOUBL XML',   type:'secondary', attr:'disabled data-hint="'.lang('@This feature follows Danish-specific tax and bookkeeping rules, and is only available when your company\'s base currency is DKK.').'"');
    }
    // NYT: export_oioubl.php arkiverer allerede en kopi i storage/einvoices/ ved hvert eksport (.php). Filnavnet
    // er deterministisk ($doc_id = invoice_no) og skrives via en almindelig file_put_contents (ingen
    // append/historik) - filens mtime er derfor præcis tidspunktet for SENESTE eksport, ikke den første.
    if (!empty($inv['invoice_no'])) {
        $einvoice_path = __DIR__ . '/storage/einvoices/' . tc_account_storage_subdir() . 'OIOUBL_Invoice_' . $inv['invoice_no'] . '.xml';
        if (file_exists($einvoice_path)) {
            echo '<span style="font-size:0.8em; color:var(--text-muted); align-self:center; margin-left:4px;">'
                . '<i class="fa-solid fa-check"></i> ' . sprintf(lang('@Last exported as e-invoice: %s'), date(CONF_DATE_FORMAT . ' H:i', filemtime($einvoice_path)))
                . '</span>';
        }
    }
}
// NYT: "Dupliker faktura" - altid tilgængelig uanset status (også en betalt/krediteret faktura er en gyldig kilde
// til gentagelse), i modsætning til Send/OIOUBL-knapperne ovenfor som kun giver mening før fakturaen er sendt.
if (!$is_sent_view && empty($inv['credit_ref'])) {      // (en kreditnota kopieres ikke - se invoice_duplicate.php)
    htm_Button(icon:'fa-copy', labl:'@Duplicate Invoice', type:'secondary', attr:'onclick="' . htm_confirm_js('@Create a new draft invoice with the same customer and lines?', "tcPost('invoice_duplicate.php?id=$inv_id')") . '" data-hint="'.lang('@Create a new draft with the same customer, lines and settings - useful for repeat billing').'"');
}
// NYT: der var INGEN måde at markere en faktura som betalt uden om bankafstemning - en kunde der betaler
// kontant/MobilePay uden for banken kunne aldrig markeres betalt. Kun relevant for en bogført,
// endnu-ikke-fuldt-betalt faktura ('sent').
// RETTET: kreditnota-siden (invoice_credit.php) kunne kun nås fra salgsoversigten - fakturaen selv, hvor man står
// når fejlen opdages, havde ingen knap. Samme betingelse som salgsoversigten: bogført, ikke selv en kreditnota,
// ikke allerede krediteret (én kreditnota pr. faktura).
if (!$is_sent_view && empty($inv['credit_ref']) && !in_array(strtolower($inv['inv_status'] ?? ''), ['draft', 'credited', 'credit'], true)
    && !DB::fetch_assoc(DB::query($conn, "SELECT inv_id FROM invoices WHERE credit_ref = " . (int)$inv_id . " LIMIT 1"))
    && !invoice_is_written_off($conn, (int)$inv_id)) {
    htm_Button(icon:'fa-undo', labl:'@Create Credit Note', type:'warning', link:'invoice_credit.php?credit_ref='.$inv_id, attr:'data-hint="'.lang('@Credit this invoice (Auto-create)').'"');
}
if (!$is_sent_view && strtolower($inv['inv_status'] ?? '') === 'sent') {
    htm_Button(icon:'fa-money-bill-wave', labl:'@Register Payment', type:'success', attr:'onclick="window.location.href=\'invoice_mark_paid.php?id='.$inv_id.'\'" data-hint="'.lang('@Register a payment that did not come through a bank import (cash, MobilePay, etc.)').'"');
    // NYT: der var ingen formel måde at afskrive en uerholdelig faktura som et reelt tab - kun "marker betalt"
    // (forudsætter en reel indbetaling) eller kreditnota (som reelt fortryder salget). Samme betingelse ('sent' =
    // bogført, endnu ikke fuldt betalt) som "Registrér betaling" ovenfor.
    require_once __DIR__ . '/inc/reminder.inc.php';
    $rv_inv = reminder_fetch($conn, $inv_id);
    if ($rv_inv && (reminder_due($rv_inv) || (int)$rv_inv['reminder_count'] > 0)) {      // rykkerblanketten: en rykker at sende - eller en sendt at vise
        htm_Button(icon:'fa-file-lines', labl:'@Reminder letter', type:'warning', link:'reminder_view.php?id='.$inv_id, attr:'data-hint="'.lang('@Show the reminder as a letter - print it, save it as PDF or design its layout').'"');
    }
    htm_Button(icon:'fa-file-invoice-dollar', labl:'@Write Off as Bad Debt', type:'danger', attr:'onclick="window.location.href=\'invoice_writeoff.php?id='.$inv_id.'\'" data-hint="'.lang('@Formally write off this invoice as an uncollectible loss - the sale itself stays on the books, only the outstanding amount is written off').'"');
}
htm_Button(icon:'fa-print',     labl:'@Print Invoice',  type:'primary', attr:'onclick="window.print()" id="printBtn" data-hint="'.lang('@Print this invoice').'"');
if (($_SESSION['user_role'] ?? '') === 'admin') {
    // NYT: direkte link til netop DENNE fakturas historik, i stedet for at skulle filtrere audit_log.php manuelt
    // på "invoices" og lede efter den rigtige række. Bruger CREATE_DRAFT_INVOICE-posten som row_id-reference,
    // samme datakilde som segregation_audit.php allerede etablerede for fakturaer (invoices har ingen egen
    // created_by-kolonne).
    htm_Button(icon:'fa-clock-rotate-left', labl:'@View History', type:'secondary', attr:'onclick="window.location.href=\'audit_log.php?table_name=invoices&row_id='.$inv_id.'\'" data-hint="'.lang('@See who created, edited or posted this invoice').'"');
}
htm_Button(icon:'fa-door-open', labl:'@Leave the page', type:'danger',  attr:'onclick="window.location.href=\'sales_hub.php\'" data-hint="'.lang('@Return to the sales hub').'"');
echo '</div>'; // .inv-btn-row
// E-mailbeskeden nedenunder knapperne (bruger-anmodet). Teksten læses af "Send via mail" via feltets navn.
if (!$is_sent_view && isset($standard_hilsen)) {
    echo '<div class="inv-mail-below no-print">';
    htm_Field(icon:'fa-envelope-open-text', labl:'@Email Message', name:'mail_body', valu:$standard_hilsen, type:'textarea', wdth:'100%', hint:'@Write a personal message to the customer here...', extr:'rows="3" style="font-size:12px; line-height:1.3;"');
    echo '</div>';
}
echo '</div>'; // .inv-toolbar

// ── Layout-linje: vælg, opret, omdøb, standard, slet - og brug til denne kladde ─
if ($il_on && !$is_sent_view) {
    echo '<div class="no-print il-bar">';
    echo '<label for="ilSel"><i class="fa-solid fa-table-cells-large"></i> ' . lang('@Layout') . ':</label> ';
    echo '<select id="ilSel" onchange="docLayoutShow(this.value)" data-hint="' . htmlspecialchars(lang('@Show the invoice with another layout - Design Mode moves the blocks of the layout shown here')) . '">';
    foreach ($il_list as $lid => $l) {
        $txt = $l['name'] . ((int)$l['is_default'] === 1 ? ' ★' : '') . ($lid === $il_own ? ' - ' . lang('@this invoice') : '');
        echo '<option value="' . (int)$lid . '"' . ($lid === $il_id ? ' selected' : '') . '>' . htmlspecialchars($txt) . '</option>';
    }
    echo '</select> ';
    if ($il_preview) {
        echo '<span class="il-note">' . sprintf(lang('@Preview - this invoice uses: %s'), '<b>' . htmlspecialchars($il_list[$il_own]['name'] ?? '') . '</b>') . '</span> ';
        if ($inv_is_draft) {
            htm_Button(icon:'fa-check', labl:'@Use for this invoice', styl:'padding:4px 10px; font-size:12px;', type:'success', attr:'onclick="ilAssign()" data-hint="'.lang('@This draft gets the layout shown - the PDF and the email follow it').'"');
        }
    } elseif ($inv_is_draft && !empty($inv['inv_layout_id'])) {
        echo '<a href="#" class="il-note" onclick="ilAssign(true); return false;" data-hint="' . htmlspecialchars(lang('@The draft no longer has its own layout - it uses the layout of the customer, or the default')) . '">' . lang('@Use the layout of the customer') . '</a> ';
    }
    echo '<span class="il-tools">';
    htm_Button(icon:'fa-plus',          labl:'@New layout', styl:'padding:4px 10px; font-size:12px;',   type:'secondary', attr:'onclick="docLayoutNew()" data-hint="'.lang('@A new layout - a copy of the layout shown, then move the blocks in Design Mode').'"');
    htm_Button(icon:'fa-i-cursor',      labl:'@Rename', styl:'padding:4px 10px; font-size:12px;',       type:'secondary', attr:'onclick="docLayoutRename()"');
    $il_is_default = (int)($il_list[$il_id]['is_default'] ?? 0) === 1;
    htm_Button(icon:'fa-star',          labl:'@Make default', styl:'padding:4px 10px; font-size:12px;', type:'secondary', attr:($il_is_default ? 'disabled ' : '').'onclick="docLayoutDefault()" data-hint="'.lang('@Used by customers and invoices that have no layout of their own').'"');
    htm_Button(icon:'fa-trash',         labl:'@Delete', styl:'padding:4px 10px; font-size:12px;',       type:'danger',    attr:($il_is_default ? 'disabled ' : '').'onclick="docLayoutDelete()" data-hint="'.lang('@Customers and drafts that use it get the default layout - a layout used by a posted invoice cannot be deleted').'"');
    echo '</span></div>';
}

// ── Faktura-papir ─────────────────────────────────────────────────────────────
// NYT: selve faktura-papiret (fanget til PDF af html2pdf.js nedenfor og det kunden reelt ser) skal kunne vises på
// KUNDENS sprog, uden at de omkringliggende handlingsknapper ovenfor (Send/Rediger/Print osv., som bogholderen
// selv bruger) skifter sprog med. lang læser $_SESSION['lang'] ved hvert kald (se inc/core_utils.lib.php) - denne
// blok bufferer derfor KUN selve papirets output, skifter sproget midlertidigt under bufferingen, og gendanner
// bogholderens eget sprog UMIDDELBART bagefter (før noget andet på siden nås) - ingen anden samtidig fane/session
// kan nå at se den midlertidige værdi, da PHP's session-lås serialiserer requests fra samme session, og ændringen
// er tilbagerullet længe før dette scripts output afsluttes og sessionen skrives tilbage.
$inv_paper_lang_override = $has_cust_lang_col && !empty($inv['cust_language']);
if ($inv_paper_lang_override) {
    doc_lang_push((string)$inv['cust_language']);
    // (pladsholdere for manglende firmaoplysninger på kundens sprog, som resten af papiret)
    ['name' => $coName, 'addr' => $coAddr, 'city' => $coCity, 'cvr' => $coCVR] = doc_company($settings);
    ob_start();
}
echo '<div class="paper" id="invoice-page">';
echo '<div class="design-zone zone-header" style="top:0; height:56.7px;">Header</div>';
echo '<div class="design-zone zone-lines"  style="top:calc(100mm - 15mm); height:120mm;">Varelinjer</div>';
echo '<div class="design-zone zone-totals" style="top:calc(230mm - 15mm); height:35mm;">Totaler &amp; noter</div>';
echo '<div class="design-zone zone-footer" style="top:calc(270mm - 15mm); height:22mm;">Footer</div>';
echo '<div class="design-envelope"></div>';

// Stempel
// RETTET: inv_status er altid småt i databasen ('draft'/'sent'/'paid'/'void'), men de faktiske oversættelsesnøgler
// er med stort forbogstav (@Draft/@Sent/@Paid/@Void) - lang('@draft') matchede derfor ALDRIG noget, og selve
// faktura-stemplet (det mest synlige sted af alle) har aldrig kunnet oversættes, uanset sprogvalg. De faktiske
// nøgler, der reelt kan opstå her, angives derfor bevidst som almindelige strengliteraler herunder, udelukkende så
// skanneren selv finder og registrerer dem: '@Draft', '@Sent', '@Paid', '@Void', '@Credited'
$status_class = 'status-' . ($inv['inv_status'] ?? 'draft');
// En afskrevet faktura (tab på debitor) har status 'paid' - den stod med stemplet BETALT, selv om kunden aldrig betalte
require_once __DIR__ . '/inc/customer_statement.inc.php';      // cs_is_writeoff_note()
$inv_written_off = false;
if (strtolower((string)$inv['inv_status']) === 'paid') {
    $wo_res = DB::query($conn, "SELECT note FROM invoice_payments WHERE inv_id = $inv_id");
    while ($wo_res && ($wo = DB::fetch_assoc($wo_res))) if (cs_is_writeoff_note((string)($wo['note'] ?? ''))) $inv_written_off = true;
}
echoBlock('block-stamp', '<div class="status-stamp '.$status_class.'">' . ($inv_written_off ? lang('@Written off') : tc_invoice_status_label($inv['inv_status'])) . '</div>');

// Logo
// RETTET: logo-uploadet på company_settings.php kan gemme med flere forskellige billedendelser (samme "gem med
// original endelse"-tilgang som expense_edit.php's bilagsupload, ingen billedkonvertering nødvendig) - tjekker nu
// dem alle, ikke kun.png.
$logo_path = tc_logo_path();      // pr. regnskab - se tc_logo_dir()
$logo_html = $logo_path
    ? '<img src="'.htmlspecialchars($logo_path).'" style="max-height:60px; display:block;">'
    : '<div style="padding:10px; border:1px solid var(--btn-disabled); font-size:10px;">'.lang('@Logo missing').'</div>';
echoBlock('block-logo', $logo_html);

// Afsender
$sender_html = '<div style="font-size:14px; color:var(--text-main);" contenteditable="'.($is_sent_view ? 'false' : 'true').'"><strong>'.htmlspecialchars($coName).'</strong><br>'.htmlspecialchars($coAddr).'<br>'.htmlspecialchars($coCity).'<br>'.lang('@CVR').': '.htmlspecialchars($coCVR).'</div>';
echoBlock('block-sender', $sender_html);

// Modtager
$recip_html = '<div style="width:90mm; font-size:14px;"><small style="color:var(--text-muted);">'.lang('@Recipient').':</small><br><strong>'.htmlspecialchars($inv['cust_name'] ?? '').'</strong><br>'.nl2br(htmlspecialchars($inv['cust_address'] ?? '')).'</div>';
echoBlock('block-recipient', $recip_html);

// Leveringsadresse - egen designblok (altid i DOM, ligesom block-cust-ref/block-notes), så den kan placeres frit i
// Design Mode UANSET om den aktuelle faktura har en. Vises kun med reelt indhold når den faktisk afviger fra
// faktureringsadressen - er den identisk (eller tom), er blokken tom på selve fakturaen (men ses stadig med en
// pladsholder i Design Mode, så den kan placeres på forhånd).
$deliv_val    = trim($inv['delivery_address'] ?? '');
$bill_addr    = trim($inv['cust_address'] ?? '');
$deliv_shown  = ($deliv_val !== '' && $deliv_val !== $bill_addr);
$deliv_content = $deliv_shown
    ? nl2br(htmlspecialchars($deliv_val))
    : '<span class="design-placeholder">(' . lang('@Delivery Address') . ')</span>';
$deliv_empty  = $deliv_shown ? '' : 'block-empty';
echoBlock('block-delivery',
    '<div style="font-size:14px;"><small style="color:var(--text-muted);">'.lang('@Delivery Address').':</small><br>'.$deliv_content.'</div>',
    $deliv_empty
);

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
// RETTET: en kreditnota har intet invoice_no (kun sit eget, separate credit_note_no - se next_credit_note_no,
// inc/db_connect.inc.php) - viste derfor fejlagtigt "@DRAFT", selvom kreditnotaen er en fuldt bogført,
// uforanderlig transaktion i samme øjeblik den oprettes, aldrig en reel kladde.
$is_credit_note = !empty($inv['credit_ref']);
if ($is_credit_note) {
    $f_no_label = lang('@Credit Note No');
    $f_no = !empty($inv['credit_note_no']) ? 'KN-'.str_pad($inv['credit_note_no'], 6, "0", STR_PAD_LEFT) : lang('@Not yet numbered');
} else {
    $f_no_label = lang('@Invoice No');
    $f_no = $inv['invoice_no'] ? '#'.str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT) : lang('@DRAFT');
}
echoBlock('block-inv-no',   '<strong>'.$f_no_label.':</strong><br><span style="font-size:16px;">'.$f_no.'</span>');
echoBlock('block-inv-date', '<strong>'.lang('@Date').':</strong><br>'.date(CONF_DATE_FORMAT, strtotime($inv['inv_date'])));
echoBlock('block-inv-due',  '<strong>'.lang('@Due Date').':</strong><br>'.(!empty($inv['inv_due_date']) ? date(CONF_DATE_FORMAT, strtotime($inv['inv_due_date'])) : '-'));      // (tomt forfald viste 01-01-1970)

// Linjer
// NYT: valgfri rabat pr. linje - hentes ind i et array FØRST (i stedet for direkte i en løkke der bygger HTML), så
// det kan afgøres om NOGEN linje overhovedet har en rabat, før selve tabellen bygges - en "Rabat"-kolonne vist på
// EVERY faktura, også dem uden nogen rabat, ville være unødvendigt støj. Samme "(100-rabat)/100"- formel som
// invoice_dkk_totals (inc/db_connect.inc.php), den ENESTE autoritative kilde - denne visning må ALDRIG kunne
// afvige fra det der reelt bliver bogført.
$inv_lines_arr = [];
$has_any_discount = false;
$res_l = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC");
while ($l = DB::fetch_assoc($res_l)) {
    if (!empty($l['discount_pct'])) $has_any_discount = true;
    $inv_lines_arr[] = $l;
}

$lines_html = '<table class="line-table"><thead><tr><th>'.lang('@Description').'</th><th style="text-align:right;">'.lang('@Qty').'</th><th style="text-align:right;">'.lang('@Price').'</th>'
    . ($has_any_discount ? '<th style="text-align:right;">'.lang('@Discount').'</th>' : '')
    . '<th style="text-align:right;">'.lang('@Line total').'</th></tr></thead><tbody>';
$sub = 0; $vat = 0;
foreach ($inv_lines_arr as $l) {
    $disc = (float)($l['discount_pct'] ?? 0);
    $lt   = $l['quantity'] * $l['price_each'] * (100 - $disc) / 100.0;
    $sub  += $lt;
    $vat  += ($lt * ($l['line_vat_rate'] / 100));
    $lines_html .= '<tr><td>'.htmlspecialchars($l['line_text']).'</td>'
        . '<td align="right">'.fmt_dk($l['quantity']).'</td>'
        . '<td align="right">'.fmt_dk($l['price_each']).'</td>'
        . ($has_any_discount ? '<td align="right">'.($disc > 0 ? fmt_dk($disc).'%' : '—').'</td>' : '')
        . '<td align="right" style="font-weight:bold;">'.fmt_dk($lt).'</td></tr>';
}
$lines_html .= '</tbody></table>';
echoBlock('block-lines', $lines_html);

// Totaler
$total_html  = '<table style="width:98%; margin-left:5%; border-collapse:collapse;">';
$total_html .= '<tr><td>'.lang('@Subtotal').':</td><td align="right">'.fmt_dk($sub).'</td></tr>';
$total_html .= '<tr><td>'.lang('@VAT').':</td><td align="right">'.fmt_dk($vat).'</td></tr>';
$total_html .= '<tr style="font-size:1.2em;font-weight:bold;border-top:2px solid var(--border-dark);"><td>'.lang('@Total').':</td><td align="right">'.fmt_dk($sub+$vat).' '.$cur.'</td></tr>';
// RETTET: en faktura i fremmed valuta viste kun momsen i fakturaens valuta - når der faktureres dansk moms
// i fremmed valuta, skal momsbeløbet også angives i kroner (momsbekendtgørelsen), og kursen bør fremgå
$iv_rate = (float)($inv['exch_rate'] ?? 0);
$iv_base = strtoupper((string)($global_settings['currency'] ?? 'DKK'));
if ($iv_rate > 0 && strtoupper((string)$cur) !== $iv_base && abs($vat) >= 0.005) {
    $total_html .= '<tr style="font-size:0.85em;"><td>' . lang('@VAT in') . ' ' . htmlspecialchars($iv_base) . ' (' . lang('@rate') . ' ' . fmt_dk($iv_rate, 4) . '):</td>'
                 . '<td align="right">' . fmt_dk(round($vat * $iv_rate, 2)) . ' ' . htmlspecialchars($iv_base) . '</td></tr>';
}
$total_html .= '</table>';
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
// RETTET: faldt tilbage til et OPDIGTET, ægte bankfilial-navn ("Nordea") plus falske reg./kontonumre
// ('0000'/'00000000') hvis bankoplysningerne ikke var udfyldt i Firmaindstillinger - en kunde der forsøgte at
// betale efter denne fakturas betalingsoplysninger ville få en reel, navngiven bank og et konkret (falsk)
// kontonummer at indbetale til. Viser nu et ærligt "[mangler]"-mærke i stedet, samme princip som CVR-feltet.
$bankName = ($settings['bank_name'] ?? '') ?: lang('@[Bank name missing]');
$bankReg  = ($settings['bank_reg'] ?? '')  ?: lang('@[Reg. no. missing]');
$bankAcc  = ($settings['bank_acc'] ?? '')  ?: lang('@[Account no. missing]');
echoFooterBlock('foot-pay',     '@Payment', htmlspecialchars($bankName).'<br>'.lang('@Reg').': '.htmlspecialchars($bankReg).' '.lang('@Acc').': '.htmlspecialchars($bankAcc));
echoFooterBlock('foot-contact', '@Contact', ($settings['company_phone'] ?? '').'<br>'.($settings['company_email'] ?? ''));
// RETTET: faldt tilbage til et opdigtet MobilePay-nummer ('00000') og en falsk, plausibelt-udseende hjemmeside
// ('www.company.com') - MobilePay/hjemmeside er reelt OPTIONELLE kontaktkanaler (ikke alle virksomheder har
// begge), samme princip som telefon/email lige ovenfor allerede følger korrekt (viser blot tomt, ingen
// fup-fallback) - viser nu kun de linjer der faktisk er udfyldt, ingen opdigtet erstatning for de manglende.
$online_lines = [];
if (!empty($settings['company_mobilepay'])) $online_lines[] = lang('@MobilePay') . ': ' . htmlspecialchars($settings['company_mobilepay']);
if (!empty($settings['company_web']))       $online_lines[] = htmlspecialchars($settings['company_web']);
echoFooterBlock('foot-online',  '@Online',  implode('<br>', $online_lines));
// RETTET: samme falske CVR-fallback som $coCVR ovenfor, blot et ANDET falsk tal ('00000000' i stedet for
// '12345678') - to indbyrdes uenige opdigtede CVR-numre samme faktura, afhængig af hvilken blok man kiggede på.
// Genbruger nu $coName/$coCVR (allerede rettet ovenfor til ærlige "[mangler]"-mærker).
echoFooterBlock('foot-legal',   '@Legal',   '<strong>'.htmlspecialchars($coName).'</strong><br>'.lang('@CVR').': '.htmlspecialchars($coCVR));

// NYT: invoice_footer fandtes allerede som en udfyldt værdi i settings-tabellen ("Tak fordi du handler i
// eventyrbutikken."), men INTET sted i kodebasen læste den - reelt død data. Genopliver den her som en fri,
// valgfri "tak for handlen"-tekst nederst på selve fakturaen - vises KUN når den faktisk er udfyldt (ingen tom
// design-blok at rode med for installationer der ikke bruger den), uden den faste
// "<strong>Titel</strong>"-forudsætning echoFooterBlock ellers tvinger (ville se mærkeligt ud på en varm
// afsluttende hilsen).
if (!empty($settings['invoice_footer'])) {
    echo '<div class="design-block'.blockAttrs('block-foot-message').' id="block-foot-message" style="'.doc_block_style('block-foot-message', $GLOBALS['layouts']).'; font-size:10px; color: var(--text-muted); text-align:center; font-style:italic;">';
    echo htmlspecialchars($settings['invoice_footer']);
    echo '</div>';
}

echo '</div>'; // .paper
if ($inv_paper_lang_override) {
    $paper_html = ob_get_clean();
    doc_lang_pop(); // gendan FØR noget som helst andet output/kald
    echo $paper_html;
}
echo doc_flow_js('invoice-page', 'block-lines');      // (mange varelinjer skubber totaler og fod ned)

// Betalingshistorik (delvis betaling, 2026-08-20) - kun i den almindelige admin-visning, ikke i den "rene"
// ?status=sent-visning der bruges til at generere PDF'en til kunden. Viser hver enkelt indbetaling registreret via
// bankafstemningen (reconcile_action.php), samt restbeløb hvis fakturaen endnu ikke er fuldt betalt.
// RETTET: nøjagtig samme fejlklasse som lige fundet og rettet i reconcile_action.php - "$inv_total = $sub + $vat"
// er fakturaens EGEN valuta (fx EUR, den kunden ser i selve fakturahovedet ovenfor - korrekt DÉR), men blev her
// sammenlignet direkte mod $total_paid, som ALTID er i DKK (de reelle bankbeløb fra invoice_payments). En
// udenlandsk faktura viste derfor en helt forkert "restbeløb"/betalingshistorik i admin-visningen - kunne vise
// penge til gode der reelt ikke var der, eller omvendt.
if (!$is_sent_view && strtolower($inv['inv_status'] ?? '') !== 'draft') {
    $pay_res = DB::query($conn, "SELECT payment_date, amount, note FROM invoice_payments WHERE inv_id = $inv_id ORDER BY payment_date ASC, payment_id ASC");
    $payments = [];
    $total_paid = 0;
    if ($pay_res) {
        while ($p = DB::fetch_assoc($pay_res)) {
            $payments[] = $p;
            $total_paid += (float)$p['amount'];
        }
    }
    // Delvis kreditnota: vises i historikken og trækkes fra restbeløbet (en fuldt krediteret faktura: ingen rest)
    $credit_notes = [];
    if (empty($inv['credit_ref'])) {
        $cn_res = DB::query($conn, "SELECT * FROM invoices WHERE credit_ref = $inv_id ORDER BY inv_date ASC, inv_id ASC");
        if ($cn_res) while ($cn = DB::fetch_assoc($cn_res)) { $cn['total'] = invoice_dkk_totals($conn, (int)$cn['inv_id'])['incl'];  $credit_notes[] = $cn; }
    }
    if (!empty($payments) || !empty($credit_notes)) {
        $inv_total  = invoice_dkk_totals($conn, $inv_id)['incl'];
        $remaining  = round($inv_total + array_sum(array_column($credit_notes, 'total')) - $total_paid, 2);
        $paid_cur   = $settings['currency'] ?? 'DKK'; // betalinger er altid i regnskabets bogføringsvaluta, ikke fakturaens visningsvaluta
        echo '<div style="max-width:900px; margin:20px auto 0; padding:0 15px;" class="no-print">';
        echo '<div style="background:var(--bg-panel); border-radius:8px; padding:15px 20px;">';
        echo '<h4 style="margin:0 0 10px 0;">' . lang('@Payment History') . '</h4>';
        echo '<table style="width:100%; border-collapse:collapse; font-size:0.9em;">';
        foreach ($payments as $p) {
            // En afskrivning gemmes som en "betaling" - den stod som en indbetaling i grønt; nu med sin tekst
            $p_wo = cs_is_writeoff_note((string)($p['note'] ?? ''));
            echo '<tr style="border-bottom:1px solid var(--border-color);">'
               . '<td style="padding:6px 0;">' . date(CONF_DATE_FORMAT, strtotime($p['payment_date']))
               . (trim((string)($p['note'] ?? '')) !== '' ? ' &nbsp;<span style="color:var(--text-muted); font-size:0.9em;">' . htmlspecialchars((string)$p['note']) . '</span>' : '') . '</td>'
               . '<td style="padding:6px 0; text-align:right; color:' . ($p_wo ? 'var(--color-danger)' : 'var(--color-success)') . ';">' . fmt_dk((float)$p['amount']) . ' ' . $paid_cur . '</td>'
               . '</tr>';
        }
        foreach ($credit_notes as $cn) {
            $cn_label = lang('@Credit Note') . ' ' . (!empty($cn['credit_note_no']) ? 'KN-' . str_pad($cn['credit_note_no'], 6, '0', STR_PAD_LEFT) : '');
            echo '<tr style="border-bottom:1px solid var(--border-color);">'
               . '<td style="padding:6px 0;">' . date(CONF_DATE_FORMAT, strtotime($cn['inv_date'])) . ' &nbsp;<a href="invoice_view.php?id=' . (int)$cn['inv_id'] . '">' . htmlspecialchars($cn_label) . '</a></td>'
               . '<td style="padding:6px 0; text-align:right; color:var(--color-info);">' . fmt_dk(abs((float)$cn['total'])) . ' ' . $paid_cur . '</td>'
               . '</tr>';
        }
        echo '</table>';
        echo '<div style="display:flex; justify-content:space-between; margin-top:10px; padding-top:10px; border-top:2px solid var(--border-dark); font-weight:bold;">';
        if (strtolower($inv['inv_status'] ?? '') === 'credited') {
            echo '<span style="color:var(--color-info);">' . lang('@Credited') . '</span><span></span>';
        } elseif ($remaining > 0.01) {
            echo '<span>' . lang('@Remaining balance') . ':</span><span style="color:var(--color-warning);">' . fmt_dk($remaining) . ' ' . $paid_cur . '</span>';
        } elseif ($inv_written_off) {
            echo '<span style="color:var(--color-danger);">' . lang('@Written off as bad debt') . '</span><span></span>';
        } else {
            echo '<span style="color:var(--color-success);">' . lang('@Fully paid') . '</span><span></span>';
        }
        echo '</div></div></div>';
    }
}

// Hjælpe-popup
echo '<div id="help-system-popup">
    <h3><i class="fa-solid fa-circle-question"></i> ' . lang('@Help & Guide') . '</h3>
    <div id="help-system-content">' . lang('@Loading help...') . '</div>
</div>';
echo doc_pdf_js();
echo doc_design_js(['paper' => 'invoice-page', 'layout' => $il_id, 'doc' => 'invoice', 'btn' => 'designBtn', 'own' => $il_own,
    'url' => 'invoice_view.php?id=' . $inv_id, 'names' => array_map(fn($l) => $l['name'], $il_list), 'hide' => ['#mailBtn', '#printBtn'],
    'confirm' => ($il_on && $il_posted > 0) ? sprintf(lang('@The layout %s is used by %d posted invoice(s). Moving its blocks changes how they look when shown or printed again (the archived PDF stays as it was sent). Continue?'), '"' . ($il_list[$il_id]['name'] ?? '') . '"', $il_posted) : '',
    't' => ['title' => lang('@Invoice layout')]]);
?>
<script>
// RETTET (§bugs-batch-22-review): save_layout.php og send_invoice_action.php
// kræver nu begge en gyldig CSRF-token (se inc/auth.inc.php) - denne side
// bygger sin egen <head> fra bunden og går ikke gennem htm_Header(), så
// tokenen skal indlejres eksplicit her for at de to fetch()-kald nedenfor
// (saveToDB/generateAndSendInvoice) stadig kan virke.
const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
// Kun "brug til denne kladde" er fakturaens egen - resten af layout-/designhandlingerne er i doc_design_js()
function ilAssign(useDefault) {
    docLayoutPost({ action: 'assign', layout_id: DOCL.layout, inv_id: <?php echo (int)$inv_id; ?>, use_default: useDefault ? 1 : 0 }, () => { window.location.href = DOCL.url; });
}
if (typeof interact !== 'undefined') interact('#help-system-popup').draggable({
    allowFrom: 'h3',
    listeners: { move(event) {
        const t = event.target, x = (parseFloat(t.getAttribute('data-x')) || 0) + event.dx, y = (parseFloat(t.getAttribute('data-y')) || 0) + event.dy;
        t.style.transform = `translate(${x}px, ${y}px)`;  t.setAttribute('data-x', x);  t.setAttribute('data-y', y);
    } }
});

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

function generateAndSendInvoice(invoiceId) {
    const element = document.getElementById('invoice-page');
    const mailBtn = document.getElementById('mailBtn');
    if (!element) { alert("<?php echo lang('@Could not find invoice element.'); ?>"); return; }
    if (mailBtn)  { mailBtn.disabled = true; mailBtn.innerText = "<?php echo lang('@Sending...'); ?>"; }
    docPdf(element, 'invoice_' + invoiceId + '.pdf').outputPdf('blob').then(function(pdfBlob) {
        const formData = new FormData();
        formData.append('pdf_file', pdfBlob, 'invoice_' + invoiceId + '.pdf');
        const customBody = document.getElementsByName('mail_body')[0];
        if (customBody) formData.append('custom_body', customBody.value);
        formData.append('csrf_token', CSRF_TOKEN);
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

// NYT (bruger-anmodet lukning af BEK 811/2026-gennemgangens fund 2, se
// [[digital-standard-bookkeeping-registration-goal]]): genbruger PRÆCIS
// samme html2pdf.js-opsætning som generateAndSendInvoice() ovenfor, men
// uploader til invoice_archive_pdf.php (ren arkivering, ingen mail) i
// stedet for send_invoice_action.php - helt stille, ingen synlig UI-
// ændring, ingen fejlbesked til brugeren hvis det skulle mislykkes (det
// forsøges igen af sig selv, næste gang nogen besøger denne side, se
// $needs_pdf_archive i selve PHP-koden).
function archiveInvoicePdfSilently(invoiceId) {
    const element = document.getElementById('invoice-page');
    if (!element) return;
    docPdf(element, 'invoice_' + invoiceId + '.pdf').outputPdf('blob').then(function(pdfBlob) {
        const formData = new FormData();
        formData.append('pdf_file', pdfBlob, 'invoice_' + invoiceId + '.pdf');
        formData.append('csrf_token', CSRF_TOKEN);
        fetch('invoice_archive_pdf.php?id=' + invoiceId, { method: 'POST', body: formData })
            .catch(function(err) { console.error('Stille PDF-arkivering fejlede (ikke-kritisk, forsøges igen senere):', err); });
    }).catch(function(err) { console.error('PDF-generering til arkivering fejlede (ikke-kritisk, forsøges igen senere):', err); });
}
<?php if ($needs_pdf_archive): ?>
document.addEventListener('DOMContentLoaded', function() { archiveInvoicePdfSilently(<?php echo (int)$inv_id; ?>); });
<?php endif; ?>

// Scroll-knap synlighed
window.onscroll = function() {
    const btn = document.getElementById("fab-scroll-top");
    if (btn) btn.style.display = (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) ? "flex" : "none";
};

// ── Print: skjul floating-knapper via JS (CSS kan ikke overtrumfe inline display:block !important)
const _printHideIds = ['fab-scroll-top', 'tc-hint', 'custom-alert', 'help-system-popup', 'doc-coord'];
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

</body>
<?php
$GLOBALS['no_floating_menu'] = true;
htm_Footer();
?>
