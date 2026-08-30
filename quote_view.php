<?php # /quote_view.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Tilbud/Ordrebekræftelse (bruger-anmodet) - udskriftsvenlig
# visning til kunden. Bevidst en LET udgave sammenlignet med invoice_view.
# php's fulde design-mode/træk-og-slip-system (interactjs/layout_settings) -
# et tilbud har ikke samme genbrug/volumen til at retfærdiggøre den
# kompleksitet, og en fast, simpel opsætning (samme @media print-mønster som
# customer_statement.php/supplier_statement.php) er langt hurtigere at bygge
# rigtigt og lige så brugbar. Titlen skifter automatisk mellem "TILBUD" og
# "ORDREBEKRÆFTELSE" alt efter status - se filens header i CLAUDE.md-stilen:
# de er bevidst ÉT dokument, ikke to separate skabeloner.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$quote_id = (int)($_GET['id'] ?? 0);
$sql = "SELECT q.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr
        FROM quotes q JOIN customers c ON q.cust_id = c.cust_id WHERE q.quote_id = $quote_id";
$q = DB::fetch_assoc(DB::query($conn, $sql));
if (!$q) die(lang('@Invoice not found'));

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$coName = $s['company_name']    ?? 'Company Name ApS';
$coAddr = $s['company_address'] ?? 'Street Address 1';
$coCity = $s['company_city']    ?? '8000 Aarhus';
$coCVR  = $s['company_cvr']     ?? '12345678';

// Titel afhænger af status - se filens header-kommentar.
$doc_title = in_array($q['status'], ['accepted', 'converted'], true) ? lang('@Order Confirmation') : lang('@Quote');
$quote_no_display = 'T-' . str_pad((string)$q['quote_no'], 6, '0', STR_PAD_LEFT);

htm_Header($doc_title . ' ' . $quote_no_display);
showMenu();
?>
<style>
    .paper { background:#fff; width:210mm; min-height:297mm; padding:15mm; margin:20px auto; box-sizing:border-box; box-shadow:0 0 10px rgba(0,0,0,0.1); color:#222; }
    .q-line-table { width:100%; border-collapse:collapse; margin-top:20px; }
    .q-line-table th { background:#f8f9fa; border-bottom:2px solid #333; padding:8px; text-align:left; font-size:13px; }
    .q-line-table td { padding:10px 8px; border-bottom:1px solid #eee; font-size:15px; }
    .q-status-stamp { display:inline-block; border:3px solid; padding:4px 14px; font-weight:bold; font-size:16px; text-transform:uppercase; transform:rotate(-8deg); border-radius:4px; }
    @media print {
        .no-print { display:none !important; }
        body { background:#fff; margin:0; padding:0; }
        .paper { box-shadow:none; margin:0; }
    }
</style>
<div class="no-print" style="width:210mm; margin:10px auto; display:flex; justify-content:flex-end; gap:10px;">
<?php
$status = $q['status'];
if ($status === 'draft' || $status === 'sent') {
    htm_Button(icon:'fa-envelope', labl:'@Send via Mail', type:'info', attr:'onclick="generateAndSendQuote('.$quote_id.')" id="mailBtn" data-hint="'.lang('@Email this quote to the customer').'"');
}
if ($status === 'draft') {
    htm_Button(icon:'fa-check', labl:'@Mark as Sent', type:'secondary', link:'quote_actions.php?action=mark_sent&id='.$quote_id, attr:'data-hint="'.lang('@Mark as sent without emailing (e.g. handed over in person)').'"');
}
if ($status === 'draft' || $status === 'sent') {
    htm_Button(icon:'fa-thumbs-up', labl:'@Mark Accepted', type:'success', link:'quote_actions.php?action=mark_accepted&id='.$quote_id, attr:'data-hint="'.lang('@Record that the customer has accepted this quote').'"');
    htm_Button(icon:'fa-thumbs-down', labl:'@Mark Rejected', type:'danger', link:'quote_actions.php?action=mark_rejected&id='.$quote_id, attr:'data-hint="'.lang('@Record that the customer has declined this quote').'"');
}
if ($status === 'accepted') {
    htm_Button(icon:'fa-file-invoice', labl:'@Convert to Invoice', type:'success', link:'quote_actions.php?action=convert&id='.$quote_id, attr:'onclick="return confirm(\''.addslashes(lang('@Convert this quote to a draft invoice? You can still review it before posting.')).'\');" data-hint="'.lang('@Create a draft invoice from this quote').'"');
}
if (in_array($status, ['sent','rejected'], true)) {
    htm_Button(icon:'fa-rotate-left', labl:'@Reopen to Draft', type:'secondary', link:'quote_actions.php?action=reopen&id='.$quote_id, attr:'data-hint="'.lang('@Undo and make this quote editable again').'"');
}
htm_Button(icon:'fa-print', labl:'@Print', type:'primary', attr:'onclick="window.print()" data-hint="'.lang('@Print this quote').'"');
htm_Button(icon:'fa-arrow-left', labl:'@Back', type:'secondary', link:'quote_list.php', attr:'data-hint="'.lang('@Return to the quote list').'"');
?>
</div>

<div class="paper" id="quote-page">
    <div style="display:flex; justify-content:space-between;">
        <div>
            <strong style="font-size:16px;"><?php echo htmlspecialchars($coName); ?></strong><br>
            <?php echo htmlspecialchars($coAddr); ?><br>
            <?php echo htmlspecialchars($coCity); ?><br>
            <?php echo lang('@CVR'); ?>: <?php echo htmlspecialchars($coCVR); ?>
        </div>
        <div style="text-align:right;">
            <div style="font-size:22px; font-weight:bold; text-transform:uppercase; margin-bottom:6px;"><?php echo $doc_title; ?></div>
            <div><?php echo $quote_no_display; ?></div>
            <?php if (in_array($status, ['accepted','rejected','converted'], true)): ?>
                <div style="margin-top:10px;">
                    <span class="q-status-stamp" style="color:<?php echo $status === 'accepted' || $status === 'converted' ? '#2ecc71' : '#e74c3c'; ?>; border-color:<?php echo $status === 'accepted' || $status === 'converted' ? '#2ecc71' : '#e74c3c'; ?>;">
                        <?php
                        // RETTET: byggede nøglen direkte af $status ('accepted'/'rejected'/
                        // 'converted', altid småt), men hele projektets egen konvention for
                        // status-ord er stort forbogstav (@Sent, @Draft, @Paid, @Cancelled
                        // findes alle sammen kun i den store variant) - lang('@accepted')
                        // matchede derfor ALDRIG den faktiske nøgle @Accepted i languages.json,
                        // og stemplet kunne aldrig oversættes, uanset sprogvalg.
                        // NB: lang('@'.ucfirst($status)) kan frase-skanneren ikke selv opdage -
                        // de faktiske nøgler ($status er her altid en af de tre, se in_array()
                        // ovenfor) nævnes derfor bevidst som strengliteraler herunder:
                        // '@Accepted', '@Rejected', '@Converted'
                        echo lang('@' . ucfirst($status));
                        ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-top:40px;">
        <div>
            <small style="color:#888;"><?php echo lang('@Recipient'); ?>:</small><br>
            <strong><?php echo htmlspecialchars($q['cust_name']); ?></strong><br>
            <?php echo nl2br(htmlspecialchars($q['cust_address'] ?? '')); ?>
            <?php if (!empty($q['cust_reference'])): ?>
                <div style="margin-top:8px; font-size:13px;"><small style="color:#888;"><?php echo lang('@Your Reference'); ?>:</small> <?php echo htmlspecialchars($q['cust_reference']); ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right; font-size:14px;">
            <div><strong><?php echo lang('@Quote Date'); ?>:</strong> <?php echo date(CONF_DATE_FORMAT, strtotime($q['quote_date'])); ?></div>
            <?php if (!empty($q['valid_until'])): ?>
            <div><strong><?php echo lang('@Valid Until'); ?>:</strong> <?php echo date(CONF_DATE_FORMAT, strtotime($q['valid_until'])); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty(trim($q['delivery_address'] ?? ''))): ?>
    <div style="margin-top:15px; font-size:13px;"><small style="color:#888;"><?php echo lang('@Delivery Address'); ?>:</small><br><?php echo nl2br(htmlspecialchars($q['delivery_address'])); ?></div>
    <?php endif; ?>

    <table class="q-line-table">
        <thead><tr><th><?php echo lang('@Description'); ?></th><th style="text-align:right;"><?php echo lang('@Qty'); ?></th><th style="text-align:right;"><?php echo lang('@Price'); ?></th><th style="text-align:right;"><?php echo lang('@Line total'); ?></th></tr></thead>
        <tbody>
        <?php
        $res_l = DB::query($conn, "SELECT * FROM quote_lines WHERE quote_id = $quote_id ORDER BY line_id ASC");
        $sub = 0; $vat = 0;
        while ($l = DB::fetch_assoc($res_l)) {
            $lt   = (float)$l['quantity'] * (float)$l['price_each'];
            $sub += $lt;
            $vat += $lt * ((float)$l['line_vat_rate'] / 100);
            echo '<tr><td>'.htmlspecialchars($l['line_text']).'</td>'
                . '<td align="right">'.number_format((float)$l['quantity'], 2, ',', '.').'</td>'
                . '<td align="right">'.number_format((float)$l['price_each'], 2, ',', '.').'</td>'
                . '<td align="right" style="font-weight:bold;">'.number_format($lt, 2, ',', '.').'</td></tr>';
        }
        ?>
        </tbody>
    </table>

    <table style="width:280px; margin-left:auto; margin-top:15px; border-collapse:collapse;">
        <tr><td style="padding:3px 0;"><?php echo lang('@Subtotal'); ?>:</td><td align="right"><?php echo number_format($sub, 2, ',', '.'); ?></td></tr>
        <tr><td style="padding:3px 0;"><?php echo lang('@VAT'); ?>:</td><td align="right"><?php echo number_format($vat, 2, ',', '.'); ?></td></tr>
        <tr style="font-size:1.15em; font-weight:bold; border-top:2px solid #333;"><td style="padding:6px 0;"><?php echo lang('@Total'); ?>:</td><td align="right"><?php echo number_format($sub + $vat, 2, ',', '.') . ' ' . $cur; ?></td></tr>
    </table>

    <?php if (!empty(trim($q['quote_note'] ?? ''))): ?>
    <div style="margin-top:25px; border-top:1px solid #eee; padding-top:8px; font-size:13px;"><small style="color:#888;"><?php echo lang('@Notes'); ?>:</small><br><?php echo nl2br(htmlspecialchars($q['quote_note'])); ?></div>
    <?php endif; ?>

    <?php if ($status === 'draft' || $status === 'sent'): ?>
    <div style="margin-top:40px; font-size:12px; color:#888; border-top:1px solid #eee; padding-top:8px;">
        <?php echo lang('@This is a quote, not an invoice. No goods or services have been delivered and no payment is due.'); ?>
    </div>
    <?php endif; ?>
</div>
<?php
$GLOBALS['no_floating_menu'] = true;
?>
<script>
const CSRF_TOKEN_Q = <?php echo json_encode(csrf_token()); ?>;

function generateAndSendQuote(quoteId) {
    const element = document.getElementById('quote-page');
    const mailBtn = document.getElementById('mailBtn');
    if (!element) { alert("<?php echo lang('@Could not find quote element.'); ?>"); return; }
    if (typeof html2pdf === 'undefined') { alert("<?php echo lang('@PDF library not loaded.'); ?>"); return; }
    if (mailBtn) { mailBtn.disabled = true; mailBtn.innerText = "<?php echo lang('@Sending...'); ?>"; }
    const opt = {
        margin: 0,
        filename: 'quote_' + quoteId + '.pdf',
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).outputPdf('blob').then(function(pdfBlob) {
        const formData = new FormData();
        formData.append('pdf_file', pdfBlob, 'quote_' + quoteId + '.pdf');
        formData.append('csrf_token', CSRF_TOKEN_Q);
        fetch('send_quote_action.php?id=' + quoteId, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = 'quote_view.php?id=' + quoteId;
                } else {
                    alert("<?php echo lang('@Error during dispatch:'); ?> " + data.error);
                    if (mailBtn) { mailBtn.disabled = false; mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>"; }
                }
            })
            .catch(function(err) {
                alert("<?php echo lang('@Network error: Server rejected data. Check console (F12) for details.'); ?>");
                if (mailBtn) { mailBtn.disabled = false; mailBtn.innerText = "<?php echo lang('@Send via Mail'); ?>"; }
            });
    });
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<?php htm_Footer(); ?>
