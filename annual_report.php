<?php # /annual_report.php v:1.3.0 d:2026-08-30 i:evs
# Samler balance + resultatopgørelse + noter + ledelsespåtegning i én print-
# venlig visning med PDF-download (regnskabsklasse B: aggregeret "Bruttoresul-
# tat", ingen ledelsesberetning, da den er undtaget for klasse B). PDF genereres
# klient-side med html2pdf.js, samme mønster som invoice_view.php - ingen ny
# server-afhængighed. Dokumentet er reelt et udkast (kræver digital XBRL-
# indberetning via virk.dk, ikke en PDF) - titel/PDF-filnavn markerer tydeligt
# "Udkast". Underskriftsdatoen bruger regnskabsårets faktiske, fikserede
# afslutningsdato (closed_at) når året er lukket, og dagens dato kun som
# forhåndsvisning af et endnu ikke afsluttet år. Begrænset til user_level 3.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/annual_report.lib.php';

$s = get_settings($conn);
$currency = $s['currency'] ?? 'DKK';

// --- Gem redigeret regnskabspraksis-note ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_note'])) {
    $note = trim($_POST['accounting_policy_note'] ?? '');
    $note_esc = DB::escape($conn, $note);
    if ($db_type === 'sqlite') {
        DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('arl_accounting_policy_note', '$note_esc')
                           ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
    } else {
        DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('arl_accounting_policy_note', '$note_esc')
                           ON DUPLICATE KEY UPDATE setting_value = '$note_esc'");
    }
    header("Location: annual_report.php?year_id=" . (int)($_POST['year_id'] ?? 0) . "&msg=note_saved");
    exit;
}

// --- Vælg regnskabsår ---
$years = [];
$fy_res = DB::query($conn, "SELECT year_id, start_date, end_date, is_closed, closed_at FROM fiscal_years ORDER BY start_date DESC");
if ($fy_res) { while ($fy = DB::fetch_assoc($fy_res)) { $years[] = $fy; } }

$year_id = (int)($_GET['year_id'] ?? 0);
$selected = null;
foreach ($years as $y) { if ((int)$y['year_id'] === $year_id) { $selected = $y; break; } }
if (!$selected && !empty($years)) { $selected = $years[0]; }

htm_Header(lang('@Annual Report'));
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";

if (isset($_GET['msg']) && $_GET['msg'] === 'note_saved') {
    htm_Alert(lang('@Note saved.'), 'success');
}

if (empty($years)) {
    htm_Alert(lang('@No fiscal years found. Run migrate_fiscal_years.php once (see db-setup/), or close a fiscal year in Close Fiscal Year first.'), 'error');
    echo "</div>";
    htm_Footer();
    exit;
}

// --- Årsvælger ---
echo '<form method="get" style="margin-bottom:16px; display:flex; align-items:center; gap:10px;">';
echo '<label style="font-weight:bold; font-size:0.9em;">' . lang('@Fiscal Year') . ':</label>';
echo '<select name="year_id" onchange="this.form.submit()" style="padding:8px; border:1px solid var(--border-color); border-radius:4px;">';
foreach ($years as $y) {
    $sel = ((int)$y['year_id'] === (int)$selected['year_id']) ? 'selected' : '';
    $status = $y['is_closed'] ? ' (' . lang('@Closed') . ')' : ' (' . lang('@Open') . ')';
    echo '<option value="' . $y['year_id'] . '" ' . $sel . '>' . $y['start_date'] . ' – ' . $y['end_date'] . $status . '</option>';
}
echo '</select>';
echo '</form>';

if (!$selected['is_closed']) {
    htm_Banner('@This fiscal year has not been closed yet (Close Fiscal Year). The figures below are a preview and may still change.', 'warning');
}

$is = arl_income_statement($conn, $selected['start_date'], $selected['end_date']);
$bs = arl_balance_sheet($conn, $selected['end_date']);
$note = $s['arl_accounting_policy_note'] ?? '';
if ($note === '') {
    $note = lang('@Basis of preparation: The annual report is prepared in accordance with the Danish Financial Statements Act (Årsregnskabsloven) provisions for reporting class B. The accounting policies are unchanged from the previous year.');
}

echo '<div style="text-align:right; margin-bottom:10px;">';
htm_Button(icon: 'fa-file-pdf', labl: '@Download PDF', type: 'danger', attr: 'onclick="downloadReportPdf(); return false;" id="pdfBtn" data-hint="'.lang('@Generate a PDF of this draft annual report').'"');
echo '</div>';

// ===================== PRINT-/PDF-VENLIGT DOKUMENT =====================
// padding sat ned fra 20mm til 15mm (samme som invoice_view.php's .paper) og
// alle h3-overskrifter i indholdet nedenfor får et eksplicit, mindre margin i
// stedet for browserens standard (~1em top/bund) - det samlede indhold var
// reelt for højt til én A4-side (ikke en afrundingsfejl som først antaget),
// og gav en næsten tom side 2 med kun underskriftsblokken (bruger-rapporteret).
echo '<div class="paper" id="report-page" style="background:white; padding:15mm; box-shadow:0 0 10px rgba(0,0,0,0.1); color:#1a1a1a; font-family: \'Segoe UI\', Arial, sans-serif; font-size:14px;">';
echo '<style>#report-page h3 { margin: 14px 0 6px; }</style>';

    // --- Forside ---
    echo '<div style="text-align:center; margin-bottom:16px; padding-bottom:10px; border-bottom:2px solid #2c3e50;">';
    echo '<h1 style="margin:0 0 5px; font-size:1.5em;">' . htmlspecialchars($s['company_name'] ?? lang('@Company Name')) . '</h1>';
    if (!empty($s['company_cvr'])) echo '<p style="margin:0; color:#666;">CVR: ' . htmlspecialchars($s['company_cvr']) . '</p>';
    if (!empty($s['company_legal_form'])) echo '<p style="margin:0; color:#666;">' . htmlspecialchars($s['company_legal_form']) . '</p>';
    echo '<h2 style="margin:12px 0 4px; font-size:1.2em;">' . lang('@Annual Report') . '</h2>';
    echo '<p style="margin:0; color:#666;">' . $selected['start_date'] . ' – ' . $selected['end_date'] . '</p>';
    echo '<p style="margin:6px 0 0; font-size:0.85em; color:#999;">' . lang('@Reporting class B') . '</p>';
    echo '<p style="margin:8px 0 0; font-size:0.78em; color:#c0392b; font-weight:bold;">' . lang('@DRAFT - not suitable for direct filing with the Danish Business Authority. Requires digital XBRL submission via virk.dk or an accountant.') . '</p>';
    echo '</div>';

    // --- Resultatopgørelse ---
    echo '<h3 style="border-bottom:1px solid #ddd; padding-bottom:6px;">' . lang('@Income Statement') . '</h3>';
    echo '<table style="width:100%; border-collapse:collapse; font-size:0.95em; margin-bottom:14px;">';
    echo '<tr><td style="padding:4px 0;">' . lang('@Net Revenue') . '</td><td style="padding:4px 0; text-align:right;">' . number_format($is['revenue'], 2, ',', '.') . '</td></tr>';
    echo '<tr><td style="padding:4px 0;">' . lang('@Costs of goods and external expenses') . '</td><td style="padding:4px 0; text-align:right;">-' . number_format($is['total_costs'], 2, ',', '.') . '</td></tr>';
    echo '<tr style="border-top:1px solid #333; font-weight:bold;"><td style="padding:5px 0;">' . lang('@Gross Profit') . '</td><td style="padding:5px 0; text-align:right;">' . number_format($is['gross_result'], 2, ',', '.') . '</td></tr>';
    echo '<tr style="border-top:2px solid #333; font-weight:bold; font-size:1.05em;"><td style="padding:5px 0;">' . lang('@Result for the Year') . '</td><td style="padding:5px 0; text-align:right;">' . number_format($is['gross_result'], 2, ',', '.') . ' ' . $currency . '</td></tr>';
    echo '</table>';

    // --- Balance ---
    echo '<h3 style="border-bottom:1px solid #ddd; padding-bottom:6px;">' . lang('@Balance Sheet') . ' <span style="font-weight:normal; font-size:0.75em; color:#666;">(' . lang('@as of') . ' ' . $selected['end_date'] . ')</span></h3>';
    echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; font-size:0.95em; margin-bottom:10px;">';

        echo '<div><strong>' . lang('@Assets') . '</strong><table style="width:100%; border-collapse:collapse; margin-top:6px;">';
        foreach ($bs['asset_rows'] as $r) {
            echo '<tr><td style="padding:3px 0;">' . htmlspecialchars($r['acc_name']) . '</td><td style="padding:3px 0; text-align:right;">' . number_format($r['balance'], 2, ',', '.') . '</td></tr>';
        }
        if ($bs['vat_is_asset'] && $bs['vat_net'] != 0) {
            echo '<tr><td style="padding:3px 0;">' . lang('@VAT receivable') . '</td><td style="padding:3px 0; text-align:right;">' . number_format($bs['vat_net'], 2, ',', '.') . '</td></tr>';
        }
        echo '<tr style="border-top:2px solid #333; font-weight:bold;"><td style="padding:5px 0;">' . lang('@Total Assets') . '</td><td style="padding:6px 0; text-align:right;">' . number_format($bs['assets_total'], 2, ',', '.') . '</td></tr>';
        echo '</table></div>';

        echo '<div><strong>' . lang('@Liabilities and Equity') . '</strong><table style="width:100%; border-collapse:collapse; margin-top:6px;">';
        foreach ($bs['equity_rows'] as $r) {
            echo '<tr><td style="padding:3px 0;">' . htmlspecialchars($r['acc_name']) . '</td><td style="padding:3px 0; text-align:right;">' . number_format(-$r['balance'], 2, ',', '.') . '</td></tr>';
        }
        echo '<tr><td style="padding:3px 0; font-style:italic;">' . lang('@Result for the year (not yet closed)') . '</td><td style="padding:3px 0; text-align:right;">' . number_format($bs['unclosed_result'], 2, ',', '.') . '</td></tr>';
        foreach ($bs['liability_rows'] as $r) {
            echo '<tr><td style="padding:3px 0;">' . htmlspecialchars($r['acc_name']) . '</td><td style="padding:3px 0; text-align:right;">' . number_format(-$r['balance'], 2, ',', '.') . '</td></tr>';
        }
        if (!$bs['vat_is_asset'] && $bs['vat_net'] != 0) {
            echo '<tr><td style="padding:3px 0;">' . lang('@VAT payable') . '</td><td style="padding:3px 0; text-align:right;">' . number_format(-$bs['vat_net'], 2, ',', '.') . '</td></tr>';
        }
        echo '<tr style="border-top:2px solid #333; font-weight:bold;"><td style="padding:5px 0;">' . lang('@Total Liabilities and Equity') . '</td><td style="padding:6px 0; text-align:right;">' . number_format($bs['passives_total'], 2, ',', '.') . '</td></tr>';
        echo '</table></div>';

    echo '</div>';
    if (abs($bs['diff']) > 0.01) {
        echo '<p style="color:#c0392b; font-size:0.85em;">⚠ ' . sprintf(lang('@Balance does not match by %s %s.'), number_format($bs['diff'], 2, ',', '.'), $currency) . '</p>';
    }

    // --- Noter ---
    echo '<h3 style="border-bottom:1px solid #ddd; padding-bottom:6px; margin-top:16px;">' . lang('@Notes') . '</h3>';
    echo '<p style="font-size:0.9em; margin:6px 0;"><strong>1. ' . lang('@Accounting Policies') . '</strong><br>' . nl2br(htmlspecialchars($note)) . '</p>';

    // --- Ledelsespåtegning ---
    echo '<h3 style="border-bottom:1px solid #ddd; padding-bottom:6px; margin-top:16px;">' . lang('@Management Endorsement') . '</h3>';
    echo '<p style="font-size:0.9em; margin:6px 0;">' . lang('@We have today considered and approved the annual report for the fiscal year above. The annual report has been prepared in accordance with the Danish Financial Statements Act. In our opinion, the annual report gives a true and fair view of the company\'s financial position.') . '</p>';
    // RETTET (se [[year-end-close-bugs-review]]): datoen på selve
    // underskriftsblokken var altid dagens dato, gengenereret hver gang siden
    // vises - to udskrifter af den "samme", allerede godkendte årsrapport på
    // forskellige dage ville derfor vise to FORSKELLIGE underskriftsdatoer,
    // hvilket underminerer dokumentets værdi som et dateret, uforanderligt
    // juridisk bilag. Bruger nu den faktiske afslutningsdato (fiscal_years.
    // closed_at, sat én gang af year_end_close.php) når året er lukket;
    // falder kun tilbage til dags dato mens året stadig er en forhåndsvisning
    // (allerede tydeligt markeret som "ikke afsluttet endnu" ovenfor).
    $sign_date = !empty($selected['closed_at'])
        ? date(CONF_DATE_FORMAT, strtotime($selected['closed_at']))
        : date(CONF_DATE_FORMAT);
    echo '<div style="margin-top:22px; font-size:0.9em;">';
    echo htmlspecialchars($s['company_city'] ?? '____________') . ', ' . $sign_date;
    echo '<div style="margin-top:22px; border-top:1px solid #333; width:260px; padding-top:6px;">';
    echo htmlspecialchars($s['company_management_name'] ?? lang('@Management'));
    echo '</div></div>';

echo '</div>'; // #report-page

echo '</div>'; // container

// --- Redigér regnskabspraksis-note (uden for print-området) ---
htm_Card_(lang('@Edit Note: Accounting Policies'), 800);
echo '<form method="post">';
csrf_field();
echo '<input type="hidden" name="year_id" value="' . (int)$selected['year_id'] . '">';
echo '<textarea name="accounting_policy_note" rows="4" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; box-sizing:border-box;">' . htmlspecialchars($note) . '</textarea>';
htm_Button(icon: 'fa-save', labl: '@Save Note', type: 'success', attr: 'name="save_note" type="submit" data-hint="'.lang('@Save this accounting policy note for the annual report').'"', styl: 'margin-top:10px;');
echo '</form>';
htm_Card_end();

htm_Footer();
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  /* box-sizing:border-box er afgørende: #report-page har padding:20mm inline
     (linje ~87). Uden border-box tælles paddingen OVEN I width:210mm, så den
     reelle bredde bliver 250mm - bredere end selve A4-siden html2pdf/jsPDF
     eksporterer til, og højre side klippes af i den downloadede PDF
     (bruger-rapporteret). Samme mønster som invoice_view.php's .paper. */
  /* min-height:297mm fjernet: den tvang boksen til at ramme PRÆCIS én A4-
     sides højde, selvom det faktiske indhold er kortere - og fordi 297mm ikke
     konverterer til et helt pixel-tal, endte html2canvas's rasterisering (med
     scale:2) typisk 1-2px over sidegrænsen. jsPDF opretter så automatisk en
     ekstra side til den smule overskydende højde, som viser sig som en næsten
     tom side 2 (bruger-rapporteret). Uden den tvungne minimumshøjde følger
     boksen sit faktiske, kortere indhold og rammer ikke sidegrænsen. */
  .paper { width: 210mm; margin: 0 auto 30px; box-sizing: border-box; }
  @media print { .paper { box-shadow:none !important; } }
</style>
<script>
function downloadReportPdf() {
    const btn = document.getElementById('pdfBtn');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo lang('@Generating...'); ?>';
    const element = document.getElementById('report-page');
    const opt = {
        margin: 0,
        filename: 'arsrapport_udkast_<?php echo (int)$selected['year_id']; ?>.pdf',
        image: { type: 'jpeg', quality: 0.95 },
        // scrollX/scrollY:0 er afgørende her - siden har meget indhold over
        // #report-page (menu, årsvælger, PDF-knap), så uden dem fanger
        // html2canvas den forkerte lodrette startposition, hvilket viste sig
        // som en blank side 1 i den downloadede PDF (bruger-rapporteret).
        // Samme rettelse invoice_view.php allerede bruger.
        html2canvas: { scale: 2, useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        // Ekstra sikkerhed mod en næsten-tom ekstra side, hvis indholdet
        // alligevel skulle lande tæt på sidegrænsen.
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
    };
    html2pdf().set(opt).from(element).save().then(function () {
        btn.innerHTML = original;
    }).catch(function () {
        btn.innerHTML = original;
    });
}
</script>
