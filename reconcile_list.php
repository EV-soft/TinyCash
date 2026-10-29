<?php # /reconcile_list.php v:1.3.1 d:2026-09-15 i:evs
# ALVORLIGT FUND - "Fast beløb"-gebyrregler beregnede altid 0 kr i stedet for det konfigurerede beløb - se [[settings-fees-reconcile-bugs-review]]
# v1.4.6: applyFeeRule()'s 'fixed'-model-gren læste rule.rate i stedet for
# rule.fixed - bekræftet direkte at ramme en allerede eksisterende demo-regel
# (visa-1, fixed:4) ikke bare et syntetisk testtilfælde. Gebyret bogføres
# direkte på hovedbogen ved afstemning, så fejlen havde reelt regnskabsmæssigt
# gennemslag, ikke kun et kosmetisk visningsproblem.
# v1.4.2: valuta-suffiks på fakturabeløb i afstemnings-dropdown var hårdkodet
# "kr" i stedet for at følge indstillingen (glemte at bumpe versionen dengang)
# (Tilføjet htm_ProjektCodeField til udgiftslinjer)
# v1.3.0: tilføjet visning af import-resultat (msg=imported) inkl. advarsel
# om rækker hvor datoen ikke kunne læses - se bank_import_process.php
# v1.4.0: faktura-dropdown i bankafstemning viste kladder - de kunne dermed
# markeres 'paid' uden bogføring. Filtreret fra. Faktura-/fakturaflow-gennemgang.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

// --- DELETE FUNCTION ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    // Hent transaktionens data FØR sletning, til revisionssporet - en
    // ikke-afstemt banktransaktion er reel finansiel data, der ellers
    // forsvinder sporløst. Bruger-anmodet.
    $old_res = DB::query($conn, "SELECT tmp_id, trans_date, text_val, amount, import_source FROM bank_statement_temp WHERE tmp_id = $id");
    $old_row = $old_res ? DB::fetch_assoc($old_res) : null;
    // RETTET: linjen blev SLETTET - så kendte bankhentningen (PSD2, de sidste 90 dage) og en ny import af samme
    // CSV-fil den ikke længere som "allerede importeret", og den kom igen og igen. Den markeres nu som afvist
    // (is_processed = 2): skjult i afstemningen, men stadig kendt af dublet-tjekket. Kun en uafstemt linje.
    if (DB::query($conn, "UPDATE bank_statement_temp SET is_processed = 2 WHERE tmp_id = $id AND is_processed = 0") && $old_row) {
        log_action($conn, 'DELETE_BANK_TRANSACTION', 'bank_statement_temp', $id, $old_row, null);
    }
    header("Location: reconcile_list.php?msg=deleted");
    exit;
}

htm_Header('@Bank Reconciliation');
showMenu();

// Fejl fra en udbetaling fra en webbutik (reconcile_action.php -> reconcile_shop_payout()) - vises én gang
if (!empty($_SESSION['flash_error'])) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . htmlspecialchars((string)$_SESSION['flash_error']), 'error');
    unset($_SESSION['flash_error']);
}

// --- VISNING AF SENESTE BOGFØRING ---
if (isset($_GET['msg']) && $_GET['msg'] == 'success') {
    $success_notice = "<h4 style='margin: 0 0 10px 0; color: var(--color-success);'><i class='fa fa-check-circle'></i> " . lang('@Transaction Processed Successfully') . "</h4>
        <p style='font-size: 13px; margin: 5px 0;'><strong>" . lang('@What happened in the database:') . "</strong></p>
        <ul style='font-size: 12px; color: var(--text-main); line-height: 1.6;'>
            <li>✅ " . lang('@A new entry was created in the') . " <strong>journal</strong> table.</li>
            <li>✅ " . lang('@Two or more balance lines were added to') . " <strong>ledger</strong> (Double-entry).</li>
            <li>✅ " . lang('@The temporary bank entry was marked as') . " <strong>processed</strong>.</li>
        </ul>";
    htm_Banner($success_notice, 'success');
}

// RETTET (§bugs-batch-19-review): reconcile_action.php kan nu redirecte hertil
// med msg=already_processed, når et kapløbsforsøg (dobbeltklik/genindsendt
// formular) opdager at banklinjen allerede blev bogført af en anden,
// samtidig forespørgsel - vis en tydelig, ikke-alarmerende besked i stedet
// for at lade brugeren stå uden nogen forklaring på hvorfor intet nyt skete.
if (isset($_GET['msg']) && $_GET['msg'] == 'already_processed') {
    htm_Banner("<i class='fa fa-info-circle'></i> " . lang('@This transaction was already processed (possibly by a duplicate click) - nothing was posted twice.'), 'info');
}

// --- VISNING AF BULK-BEKRÆFTELSE-RESULTAT (bruger-anmodet) ---
if (isset($_GET['msg']) && $_GET['msg'] == 'bulk') {
    $b_sent   = (int)($_GET['sent'] ?? 0);
    $b_failed = (int)($_GET['failed'] ?? 0);
    if ($b_failed > 0) {
        htm_Banner("<i class='fa fa-exclamation-triangle'></i> " . sprintf(lang('@%d transaction(s) matched and posted. %d could not be posted (already processed in the meantime, or a posting error) - please check them individually.'), $b_sent, $b_failed), 'warning');
    } else {
        htm_Banner("<i class='fa fa-check-circle'></i> " . sprintf(lang('@%d transaction(s) matched and posted successfully.'), $b_sent), 'success');
    }
}

// --- VISNING AF IMPORT-RESULTAT (inkl. advarsel om uparsbare datoer) ---
if (isset($_GET['msg']) && $_GET['msg'] == 'imported') {
    $imp_count = (int)($_GET['count'] ?? 0);
    $date_warn = (int)($_GET['date_warnings'] ?? 0);
    $dup_count = (int)($_GET['duplicates'] ?? 0);
    htm_Banner("<i class='fa fa-check-circle'></i> " . sprintf(lang('@%d transaction(s) imported.'), $imp_count), 'success');
    if ($date_warn > 0) {
        htm_Banner("<i class='fa fa-exclamation-triangle'></i> " . sprintf(lang('@%d row(s) had a date that could not be read and were set to today\'s date instead - marked in the text field below, please correct manually.'), $date_warn), 'warning');
    }
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): dublet-tjekket i
    // bank_import_process.php var allerede stumt - se dens egen kommentar for
    // baggrunden. Gør nu tydeligt hvorfor et lavere antal end forventet blev
    // importeret, i stedet for at det ligner en fejl.
    if ($dup_count > 0) {
        htm_Banner("<i class='fa fa-circle-info'></i> " . sprintf(lang('@%d row(s) were skipped because a transaction with the same date, text and amount was already imported previously.'), $dup_count), 'info');
    }
}

// 1. Get rules from settings
$rules_res = DB::query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
$fee_rules = [];
while($r = DB::fetch_assoc($rules_res)) {
    $fee_rules[$r['setting_key']] = json_decode($r['setting_value'], true);
}
?>

<script>
const feeRules = <?php echo json_encode($fee_rules); ?>;
// NYT (§reel-multi-valuta-bogforing): hvilke fakturaer i dropdown'en er i
// fremmed valuta - styrer om "afslut trods kursforskel"-afkrydsningsfeltet
// vises for den valgte faktura i en given banklinjes formular. Finder selv
// den rigtige .fx-wrap via DOM-relation (samme <form>), IKKE via et fast id -
// htm_Select() bygger selv "id" ud fra feltnavnet ('target_id'), som derfor
// er IDENTISK (og dermed ugyldigt/upålideligt til getElementById) på tværs
// af hver banklinjes egen formular på denne side.
const fxInvoiceMap = <?php echo json_encode($fx_invoice_map); ?>;
function toggleFxCheckbox(selectEl) {
    const form = selectEl.closest('form');
    const wrap = form ? form.querySelector('.fx-wrap') : null;
    if (!wrap) return;
    const isFx = !!fxInvoiceMap[selectEl.value];
    wrap.style.display = isFx ? 'flex' : 'none';
    if (!isFx) wrap.querySelector('input[type="checkbox"]').checked = false;
}
function applyFeeRule(tmpId, amount) {
    const sourceKey = document.getElementById('source_' + tmpId).value;
    const ruleKey = 'fee_rule_' + sourceKey.toLowerCase();
    const rule = feeRules[ruleKey];
    const feeField = document.getElementById('fee_input_' + tmpId);
    
    if (!rule) { 
        feeField.value = "0.00"; 
        return; 
    }

    let fee = 0;
    if (rule.model === 'fixed') {
        // RETTET: læste tidligere rule.rate (altid 0 for en ren fast-beløb-
        // regel, jf. settings_fees.php's eget skema/visning) i stedet for
        // rule.fixed, som er der hvor selve fastbeløbet reelt gemmes. Gav en
        // stille 0-udfyldning af gebyret i stedet for det konfigurerede
        // beløb - og gebyret bogføres direkte på hovedbogen ved afstemning
        // (se reconcile_action.php), så fejlen var ikke kun kosmetisk.
        fee = parseFloat(rule.fixed);
    } else if (rule.model === 'relative') {
        fee = (amount / (1 - parseFloat(rule.rate))) - amount;
    } else if (rule.model === 'mixed') {
        fee = ((amount + parseFloat(rule.fixed)) / (1 - parseFloat(rule.rate))) - amount;
    }
    feeField.value = fee.toFixed(2);
}
</script>

<?php 
// Get unpaid, ALLEREDE BOGFØRTE fakturaer - kladder skal ikke kunne vælges
// her, da de aldrig er bogført (intet fakturanummer, ingen posteringer);
// reconcile_action.php afviser dem nu også server-side, men de skal ikke
// engang kunne vælges i første omgang. Fundet ved en faktura-/fakturaflow-
// gennemgang.
// RETTET 2026-08-20: valuta-suffiks på fakturabeløb var hårdkodet " kr" i
// stedet for at følge den konfigurerede valuta - get_settings() flyttet
// herop (var før først hentet nede ved projekt-options).
$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$inv_options = ['' => '-- ' . lang('@Select Invoice') . ' --'];
// RETTET 2026-08-20: ALVORLIGT FUND - "(1 + rate/100)" udregner rate/100 som
// heltalsdivision på SQLite FØR additionen (25/100 = 0), så "1+0=1" betød
// momsen aldrig blev lagt til - fakturabeløbet i denne dropdown ekskluderede
// momsen helt. Rettet til "(100+rate)/100", hvor divisionen sker sidst på
// hele udtrykket i stedet.
// NYT 2026-08-20 (delvis betaling): "total" er nu RESTBELØBET (fuld
// fakturasum minus allerede registrerede indbetalinger via
// invoice_payments), ikke den oprindelige fakturasum - ellers ville en
// bankindbetaling der dækker resten af en delvist betalt faktura aldrig
// blive foreslået som match, og en allerede delvist betalt faktura ville
// stadig vise sit fulde, vildledende oprindelige beløb i dropdownen.
// RETTET (§bugs-batch-32-review): denne kommentar (fra selve FX-
// funktionens egen første version) antog fejlagtigt at "total" i fakturaens
// EGEN valuta var uden betydning her, fordi feltet "kun" bruges til
// visning - men det bruges OGSÅ til selve auto-match-forslaget nedenfor
// ("abs($inv['total'] - $b['amount']) < 0.01"), som sammenligner DIREKTE
// mod banktransaktionens beløb (altid DKK). En udenlandsk fakturas rest
// blev derfor aldrig korrekt kunnet auto-foreslås (ufarlig fejlretning i
// sig selv - intet forkert gæt - men stadig en reel fejl i selve tallet),
// og selve visningen i dropdown'en ($cur er allerede firmaets DKK-baserede
// indstilling, ikke fakturaens egen valuta) viste et vildledende blandet
// tal. Ganger nu med exch_rate, samme rettelse som reminders.php/aging_
// report.php/sales_hub.php/invoice_view.php samme runde.
$rl_disc = invoice_discount_sql($conn);
$res_inv = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.exch_rate, c.cust_name,
    (SELECT SUM(quantity * price_each $rl_disc * (100 + line_vat_rate) / 100.0) FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . "
    - COALESCE((SELECT SUM(amount) FROM invoice_payments WHERE inv_id = i.inv_id), 0) as total
    FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_status NOT IN ('paid', 'draft', 'credited')
      AND NOT (i.credit_ref IS NOT NULL AND EXISTS (SELECT 1 FROM invoices o WHERE o.inv_id = i.credit_ref AND o.inv_status <> 'credited'))");
      // (en DELVIS kreditnota er allerede trukket fra fakturaens åbne beløb ovenfor - ikke en post for sig)      // (RETTET: en fuldt krediteret faktura blev foreslået med hele sit beløb)

$invoices = [];
$fx_invoice_map = [];
while($row = DB::fetch_assoc($res_inv)) {
    $invoices[] = $row;
    $inv_options[$row['inv_id']] = "#{$row['invoice_no']} - {$row['cust_name']} (" . number_format($row['total'] ?? 0, 2, ',', '.') . " $cur)";
    $fx_invoice_map[$row['inv_id']] = ((float)($row['exch_rate'] ?? 0) > 0);
}
// NYT (webbutik): en udbetaling fra butikkens betalingsudbyder (Bambora, Stripe, Shopify Payments ...) afstemmes mod
// butikkens mellemregningskonto - gebyret i gebyrfeltet bogføres samtidig (reconcile_shop_payout()). Vises for
// butikker med en mellemregningskonto der har en saldo; foreslås når banklinjens tekst nævner butikkens navn.
$payout_shops = [];
require_once __DIR__ . '/inc/shop.inc.php';
require_once __DIR__ . '/inc/reconcile.inc.php';
if (shop_ready($conn)) {
    foreach (shop_list($conn) as $ps_id => $ps) {
        $ps_clear = (int)($ps['clearing_acc'] ?? 0);
        if ($ps_clear <= 0) continue;
        $ps_bal = shop_clearing_balance($conn, $ps_clear);
        if ($ps_bal < 0.01) continue;
        $payout_shops[$ps_id] = ['name' => (string)$ps['name'], 'balance' => $ps_bal];
        $inv_options['shop:' . $ps_id] = '💳 ' . sprintf(lang('@Payout from web shop %s'), $ps['name']) . ' (' . lang('@clearing') . ' ' . number_format($ps_bal, 2, ',', '.') . " $cur)";
    }
}

// Get chart of accounts
$acc_options = ['' => '-- ' . lang('@Select Account') . ' --'];
// Forslået gebyrkonto: 2320 (TinyCash' egen kontoplan), ellers en udgiftskonto med "gebyr" / "fee" i navnet
// (standardkontoplanen: 2390 "Porto og gebyrer") - ellers intet forslag (se inc/reconcile.inc.php)
// RETTET: en SLUKKET 2320 (fx afløst ved et skift til standardkontoplanen) blev stadig foreslået - den findes ikke i
// valglisten, så forslaget forsvandt helt, i stedet for at falde videre til afløseren 2390 "Porto og gebyrer"
require_once __DIR__ . '/inc/accounts.inc.php';
$fee_on = acc_status_ready($conn) ? " AND acc_status <> 'off'" : '';
$fee_acc_default = DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = 2320$fee_on")) ? '2320' : '';
if ($fee_acc_default === '') {
    $fa_row = DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_type = 'expense'$fee_on AND (LOWER(acc_name) LIKE '%gebyr%' OR LOWER(acc_name) LIKE '%fee%') ORDER BY acc_id LIMIT 1"));
    $fee_acc_default = $fa_row ? (string)$fa_row['acc_id'] : '';
}
require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$res_acc = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE " . acc_visible_sql($conn) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
while($row = DB::fetch_assoc($res_acc)) { $acc_options[$row['acc_id']] = $row['acc_id'] . ' - ' . acc_label($row['acc_name'], $row['acc_status'] ?? null); }

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33): gemte
// "hvis beskrivelse indeholder X -> konto Y"-regler (bank_match_rules, se
// db-setup/migrate_bank_match_rules.php) - slås op nedenfor pr. banklinje,
// længste mønster først, så en mere specifik regel vinder over en generel.
// Stille degradering hvis migrationen ikke er kørt endnu.
$bank_rules = [];
$has_rules_table = false;
if (DB::is_sqlite()) {
    $brt = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='bank_match_rules'");
    $has_rules_table = ($brt && DB::fetch_assoc($brt));
} else {
    $brt = DB::query($conn, "SHOW TABLES LIKE 'bank_match_rules'");
    $has_rules_table = ($brt && DB::num_rows($brt) > 0);
}
if ($has_rules_table) {
    $res_rules = DB::query($conn, "SELECT * FROM bank_match_rules ORDER BY LENGTH(pattern) DESC");
    if ($res_rules) { while ($rr = DB::fetch_assoc($res_rules)) { $bank_rules[] = $rr; } }
}
function find_matching_bank_rule(array $rules, string $text_val) {
    foreach ($rules as $rule) {
        if ($rule['pattern'] !== '' && mb_stripos($text_val, $rule['pattern']) !== false) return $rule;
    }
    return null;
}

// Byg projekt-options til udgiftslinjer (kun hvis modulet er aktivt)
$projects_active = !empty($s['module_projects']) && $s['module_projects'] == '1';
$proj_options = ['' => '-- ' . lang('@No project') . ' --'];
if ($projects_active) {
    $res_proj = DB::query($conn, "SELECT proj_id, proj_no FROM projects WHERE is_active = 1 ORDER BY proj_no ASC");
    if ($res_proj) {
        while ($p = DB::fetch_assoc($res_proj)) {
            $proj_options[$p['proj_id']] = htmlspecialchars($p['proj_no']);
        }
    }
}

// Get bank entries
$res_bank = DB::query($conn, "SELECT * FROM bank_statement_temp WHERE is_processed = 0 ORDER BY trans_date DESC");

$rc_tool = $has_rules_table ? htm_Button(icon:'fa-list-check', labl:'@Matching Rules', type:'info', link:'bank_rules.php', attr:'data-hint="'.lang('@Manage saved bank description matching rules').'"', echo:false) : '';
htm_Card_('@Bank Reconciliation', 1100, '', '', true, $rc_tool);
?>

<?php /* BULK-BEKRÆFTELSE (bruger-anmodet): bevidst begrænset til rækker med
   ét entydigt, allerede foreslået fakturamatch (samme $match_id-logik som
   nedenfor) - se reconcile_action.php's egen kommentar for hvorfor. En
   separat, tom formular (ikke indlejret i nogen af de eksisterende pr.-linje-
   formularer, hvilket ville være ugyldig HTML) - JS bygger dens skjulte felter
   ud fra de afkrydsede rækker og indsender den, når knappen trykkes. */ ?>
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.9em;">
        <input type="checkbox" id="bulkSelectAll" onchange="toggleAllBulkMatches(this.checked)">
        <?php echo lang('@Select all suggested matches'); ?>
    </label>
    <button type="button" id="bulkConfirmBtn" onclick="submitBulkReconcile()" disabled
        style="background:var(--color-success); color:#fff; border:none; padding:8px 16px; border-radius:4px; font-weight:bold; cursor:pointer; opacity:0.5;">
        <i class="fa-solid fa-check-double"></i> <?php echo lang('@Confirm Selected Matches'); ?> (<span id="bulkSelectedCount">0</span>)
    </button>
</div>
<form id="bulkReconcileForm" method="post" action="reconcile_action.php" style="display:none;"><?php csrf_field(); ?></form>
<script>
function updateBulkSelectedCount() {
    const n = document.querySelectorAll('.bulk-match-checkbox:checked').length;
    document.getElementById('bulkSelectedCount').innerText = n;
    const btn = document.getElementById('bulkConfirmBtn');
    btn.disabled = (n === 0);
    btn.style.opacity = (n === 0) ? '0.5' : '1';
}
function toggleAllBulkMatches(checked) {
    document.querySelectorAll('.bulk-match-checkbox').forEach(function(cb) { cb.checked = checked; });
    updateBulkSelectedCount();
}
function submitBulkReconcile() {
    const checked = document.querySelectorAll('.bulk-match-checkbox:checked');
    if (checked.length === 0) return;
    const confirmMsg = <?php echo json_encode(lang('@Confirm and post %d matched transaction(s)?')); ?>.replace('%d', checked.length);
    if (!confirm(confirmMsg)) return;
    const form = document.getElementById('bulkReconcileForm');
    checked.forEach(function(cb, i) {
        const t1 = document.createElement('input'); t1.type = 'hidden'; t1.name = 'bulk_pairs[' + i + '][tmp_id]'; t1.value = cb.dataset.tmpId;
        const t2 = document.createElement('input'); t2.type = 'hidden'; t2.name = 'bulk_pairs[' + i + '][target_id]'; t2.value = cb.dataset.targetId;
        form.appendChild(t1); form.appendChild(t2);
    });
    form.submit();
}
</script>

<table style="width:100%; border-collapse:collapse;">
    <tr style="background:var(--bg-panel); text-align:left; border-bottom:2px solid var(--border-color);">
        <th style="padding:10px; width:30px; color:var(--text-main);"></th>
        <th style="padding:10px; width:80px; color:var(--text-main);"><?php echo lang('@Date'); ?></th>
        <th style="padding:10px; color:var(--text-main);"><?php echo lang('@Description'); ?></th>
        <th style="padding:10px; text-align:right; color:var(--text-main);"><?php echo lang('@Amount'); ?></th>
        <th style="padding:10px; color:var(--text-main);"><?php echo lang('@Reconciliation Action'); ?></th>
        <th style="padding:10px; width:30px; color:var(--text-main);"></th>
    </tr>

    <?php while($b = DB::fetch_assoc($res_bank)):
        $is_inc = ($b['amount'] > 0);
        // RETTET (§bugs-batch-18-review): løkken overskrev $match_id ved HVERT
        // fund uden break - fandtes to eller flere åbne fakturaer med præcis
        // samme resterende beløb (fx to forskellige kunder der begge skylder
        // 1.250,00 kr), endte forslaget altid på den SIDSTE i forespørgslens
        // (udefinerede, motor-afhængige) rækkefølge, ikke nødvendigvis den
        // rigtige - og brugerfladen viste den med nøjagtig samme selvsikre,
        // grønne markering som et reelt entydigt match. Foreslår nu kun en
        // faktura, hvis PRÆCIS én matcher beløbet - er der flere kandidater,
        // er det tryggere slet ikke at gætte end at gætte forkert med skråsikkert udseende.
        $match_candidates = [];
        foreach($invoices as $inv) { if(abs($inv['total'] - $b['amount']) < 0.01) $match_candidates[] = $inv['inv_id']; }
        $match_id = (count($match_candidates) === 1) ? $match_candidates[0] : 0;
        // Ingen faktura passer: nævner teksten en webbutik (og dækker dens mellemregning beløbet), foreslås udbetalingen
        $target_sel = $match_id;
        if (!$match_id && $is_inc && $payout_shops) {
            $hits = [];
            foreach ($payout_shops as $ps_id => $ps) if (mb_stripos((string)$b['text_val'], $ps['name']) !== false && $ps['balance'] + 0.01 >= (float)$b['amount']) $hits[] = 'shop:' . $ps_id;
            if (count($hits) === 1) $target_sel = $hits[0];
        }
        $source = $b['import_source'] ?? 'bank';
    ?>
    <tr style="border-bottom:1px solid var(--border-color);">
        <td style="padding:10px; text-align:center;">
            <?php if ($is_inc && $match_id > 0): ?>
                <input type="checkbox" class="bulk-match-checkbox" data-tmp-id="<?php echo (int)$b['tmp_id']; ?>" data-target-id="<?php echo (int)$match_id; ?>" onchange="updateBulkSelectedCount()" data-hint="<?php echo htmlspecialchars(lang('@Include this suggested match in the bulk confirmation')); ?>">
            <?php endif; ?>
        </td>
        <td style="padding:10px;"><?php echo date(CONF_DATE_FORMAT, strtotime($b['trans_date'])); ?></td>
        <td style="padding:10px;"><?php echo htmlspecialchars($b['text_val']); ?></td>
        <td style="padding:10px; text-align:right; font-weight:bold; color:<?php echo $is_inc?'green':'red';?>">
            <?php echo number_format($b['amount'], 2, ',', '.'); ?>
        </td>
        <td style="padding:10px;">
            <form action="reconcile_action.php" method="post" style="display:flex; gap:10px; align-items:stretch; margin:0; flex-wrap:wrap;">
                <?php csrf_field(); ?>
                <input type="hidden" name="tmp_id" value="<?php echo $b['tmp_id']; ?>">
                <input type="hidden" id="source_<?php echo $b['tmp_id']; ?>" value="<?php echo $source; ?>">
                
                <?php if($is_inc): ?>
                    <div style="display: flex; gap: 8px; background: var(--bg-panel); padding: 5px; border-radius: 4px; border: 1px solid var(--border-color); align-items: center; flex: 1;">
                        <div style="display: flex; flex-direction: column; width: 80px;">
                            <label style="font-size: 10px; cursor: pointer; color: blue; text-decoration: underline;" onclick="applyFeeRule(<?php echo $b['tmp_id']; ?>, <?php echo $b['amount']; ?>)">
                                ⚡ <?php echo lang('@Fee'); ?>
                            </label>
                            <input type="number" name="fee_amount" id="fee_input_<?php echo $b['tmp_id']; ?>" value="0.00" step="0.01" style="width: 100%; height: 28px; text-align: right;">
                        </div>
                        <div style="display: flex; flex-direction: column; width: 130px;">
                            <label style="font-size: 10px; color: var(--text-muted);">&nbsp;</label>
                            <?php htm_Select('fee_acc_id', $acc_options, $fee_acc_default, 'width:100%; height:28px; font-size:11px;'); ?>
                        </div>
                        <div style="display: flex; flex-direction: column; flex: 2;">
                            <label style="font-size: 10px; color: var(--text-muted);">&nbsp;</label>
                            <?php
                                $target_style = 'width:100%; height:28px;' . ($target_sel ? ' border:2px solid #2ecc71;' : '');
                                // NYT (§reel-multi-valuta-bogforing): onchange kalder
                                // toggleFxCheckbox(this) - viser/skjuler "afslut trods
                                // kursforskel" nedenfor, alt efter om den VALGTE faktura
                                // reelt er i fremmed valuta (fxInvoiceMap).
                                htm_Select('target_id', $inv_options, $target_sel, $target_style, 'onchange="toggleFxCheckbox(this)" data-hint="' . htmlspecialchars(lang('@Choose the invoice the payment is for - or a payout from a web shop (the fee field is the payment provider fee)')) . '"');
                            ?>
                        </div>
                        <div class="fx-wrap" style="display:<?php echo ($match_id && !empty($fx_invoice_map[$match_id])) ? 'flex' : 'none'; ?>; flex-direction:column; width:170px;">
                            <label style="font-size: 10px; color: var(--text-muted);">&nbsp;</label>
                            <label style="font-size:10px; display:flex; align-items:center; gap:4px; height:28px; cursor:pointer; color:var(--color-warning); font-weight:bold;" data-hint="<?php echo htmlspecialchars(lang('@This invoice is in a foreign currency and was likely invoiced at a different exchange rate than today\'s payment. Check this to close the invoice anyway - the difference is posted as a currency gain or loss instead of leaving an unexplained remainder.')); ?>">
                                <input type="checkbox" name="close_fx_invoice" value="1" style="width:14px; height:14px;">
                                <?php echo lang('@Close despite FX difference'); ?>
                            </label>
                        </div>
                    </div>
                <?php else:
                    // NYT (runde 33): findes der ingen allerede-kendt konto (PSD2),
                    // foreslå en ud fra en gemt bank_match_rules-regel i stedet for
                    // altid at starte tomt.
                    $matched_rule = null;
                    $acc_preselect = (string)($b['acc_id'] ?? '');
                    if ($acc_preselect === '' && !empty($bank_rules)) {
                        $matched_rule = find_matching_bank_rule($bank_rules, $b['text_val']);
                        if ($matched_rule) $acc_preselect = (string)$matched_rule['acc_id'];
                    }
                ?>
                    <?php /* Udgiftslinje: konto-vælger + evt. projektfelt */ ?>
                    <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <div style="flex:2;">
                                <?php
                                // RETTET (§bugs-batch-17-review): dropdown'en var altid tom
                                // ('' som valgt værdi), selv når bank_statement_temp.acc_id
                                // rent faktisk allerede kender den rigtige bankkonto -
                                // bank_integration_sync.php udfylder den korrekt for hver
                                // hentet PSD2-transaktion (se dens INSERT), men dette felt
                                // blev aldrig læst her (kun brugt af den ældre, rene CSV-
                                // import, som ikke kender kontoen på forhånd og derfor
                                // altid sætter den til NULL). Brugeren skulle derfor altid
                                // vælge kontoen manuelt igen, selv når systemet allerede
                                // vidste svaret - og et forkert valg her poster beløbet på
                                // en forkert bankkonto.
                                $acc_select_style = 'width:100%; padding:6px; height:38px;' . ($matched_rule ? ' border:2px solid #2ecc71;' : '');
                                htm_Select('acc_id', $acc_options, $acc_preselect, $acc_select_style);
                                ?>
                            </div>
                            <?php if ($projects_active): ?>
                            <div style="flex:1;">
                                <?php htm_Select('proj_id', $proj_options, '', 'width:100%; padding:6px; height:38px; font-size:11px;', 'title="' . lang('@Project Code') . '"'); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($matched_rule): ?>
                            <div style="font-size:10px; color:var(--color-success);"><i class="fa fa-magic-wand-sparkles"></i> <?php echo sprintf(lang('@Suggested by rule: "%s"'), htmlspecialchars($matched_rule['pattern'])); ?></div>
                        <?php elseif ($has_rules_table): ?>
                            <label style="font-size:10px; color:var(--text-muted); display:flex; align-items:center; gap:4px; cursor:pointer;">
                                <input type="checkbox" name="save_rule" value="1" onchange="this.nextElementSibling.style.display = this.checked ? 'inline-block' : 'none';" style="width:12px; height:12px;">
                                <?php echo lang('@Remember this rule for next time'); ?>
                                <input type="text" name="rule_pattern" placeholder="<?php echo htmlspecialchars(lang('@Pattern to match (part of the description)')); ?>" value="<?php echo htmlspecialchars($b['text_val']); ?>" style="display:none; width:180px; font-size:10px; padding:2px 4px;">
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-success" style="padding: 0 15px; height: 40px; margin: 0; font-weight: bold;">
                    <?php echo lang('@OK'); ?>
                </button>
            </form>
        </td>
        <td style="padding:10px; text-align:right;">
            <?php
                htm_ConfirmLink(
                    icon: 'fa-trash',
                    link: 'reconcile_list.php?action=delete&id='.$b['tmp_id'],
                    mess: '@Are you sure?',
                    type: 'danger',
                    styl: 'font-size:18px; padding:0; background:transparent; color:var(--color-danger);',
                    attr: 'data-hint="'.lang('@Remove this unmatched bank transaction').'"'
                );
            ?>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

<?php 
htm_Card_end(); 
htm_Footer(); 
ob_end_flush(); 
?>
