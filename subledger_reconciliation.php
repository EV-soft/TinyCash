<?php # /subledger_reconciliation.php v:1.3.1 d:2026-09-15 i:evs
# Debitor-/kreditor-afstemning: sammenligner delregnskabet (åbne fakturaer
# hhv. ubetalte udgifter) mod hovedbogens kontrolkonto-saldo - den klassiske
# "stemmer under-regnskabet med hovedbogen"-kontrol, komplementær til
# trial_balance.php (konto-niveau) og entry_balance_check.php (bilag-niveau).
#
# Bruger BEVIDST præcis samme forespørgsler/formel som aging_report.php for
# de åbne beløb (samme etablerede, allerede-reviderede logik: eksklusionerne
# på inv_status='sent'/due_date/is_cancelled, betalinger fratrukket via
# invoice_payments, exch_rate-korrektion for fremmed valuta) - to rapporter
# der viser "det samme tal" må aldrig kunne regne det ud forskelligt.
#
# Fortegn: debitorkontoen er DEBET-normal (positiv saldo = tilgodehavende),
# sammenlignes direkte mod delregnskabets positive "skyldigt beløb". Kredit-
# orkontoen er KREDIT-normal (negativ saldo i hovedbogen), fortegnet vendes
# derfor FØR sammenligning med delregnskabets positive "skyldigt beløb" -
# samme fortegnskonvention som resten af systemet (inc/annual_report.lib.php).
#
# Kreditorsiden degraderer stille til en informationsbanner hvis leverandør-
# modulets migration ikke er kørt (samme mønster som aging_report.php).
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
$acc_debitor  = (int)($s['conf_acc_debitor'] ?? 8100);
$acc_creditor = (int)($s['conf_acc_creditor'] ?? 4000);

// --- DEBITOR: samme WHERE/beregning som aging_report.php's debitor-sektion ---
$sr_disc = invoice_discount_sql($conn);
$res_i = DB::query($conn, "SELECT
        (SELECT COALESCE(SUM(quantity * price_each $sr_disc * (100 + line_vat_rate) / 100.0), 0)
         FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . " AS total,
        (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
    FROM invoices i
    WHERE LOWER(i.inv_status) = 'sent' AND i.inv_due_date IS NOT NULL");
$sub_debitor = 0.0;
if ($res_i) {
    while ($r = DB::fetch_assoc($res_i)) {
        $due = (float)$r['total'] - (float)$r['paid'];
        if ($due > 0.01) $sub_debitor += $due;
    }
}
$gl_debitor_row = DB::fetch_assoc(DB::query($conn, "SELECT ROUND(SUM(amount), 2) AS bal FROM ledger WHERE acc_id = $acc_debitor"));
$gl_debitor = (float)($gl_debitor_row['bal'] ?? 0);
$diff_debitor = round($sub_debitor - $gl_debitor, 2);

// --- KREDITOR: samme WHERE/beregning som aging_report.php's kreditor-sektion ---
$res_e = @DB::query($conn, "SELECT e.amount FROM expenses e
    WHERE e.is_cancelled = 0 AND e.voucher_no IS NOT NULL AND (e.exp_type IS NULL OR e.exp_type != 'income') AND e.due_date IS NOT NULL AND e.paid_date IS NULL");
// RETTET: kun bogførte udgifter står på kreditorkontoen - en kladde med forfaldsdato gav en falsk difference
$creditor_supported = ($res_e !== false);
$sub_creditor = 0.0; $gl_creditor = 0.0; $diff_creditor = 0.0;
if ($creditor_supported) {
    while ($r = DB::fetch_assoc($res_e)) { $sub_creditor += (float)$r['amount']; }
    $gl_creditor_row = DB::fetch_assoc(DB::query($conn, "SELECT ROUND(SUM(amount), 2) AS bal FROM ledger WHERE acc_id = $acc_creditor"));
    $gl_creditor = -(float)($gl_creditor_row['bal'] ?? 0); // vendt fortegn, se header
    $diff_creditor = round($sub_creditor - $gl_creditor, 2);
}

function subledger_render_section($capt, $sub_total, $gl_total, $diff, $acc_id, $cur) {
    htm_Card_($capt, 900);
    echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Sub-ledger Total') . '</div><div style="font-weight:bold; font-size:1.2em;">' . number_format($sub_total, 2, ',', '.') . ' ' . $cur . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@General Ledger Balance') . ' (' . lang('@Account') . ' ' . $acc_id . ')</div><div style="font-weight:bold; font-size:1.2em;"><a href="account_card.php?acc_id=' . $acc_id . '">' . number_format($gl_total, 2, ',', '.') . ' ' . $cur . '</a></div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Difference') . '</div><div style="font-weight:bold; font-size:1.2em; color:' . (abs($diff) > 0.01 ? 'var(--color-danger)' : 'inherit') . ';">' . number_format($diff, 2, ',', '.') . ' ' . $cur . '</div></div>';
    echo '</div>';

    if (abs($diff) <= 0.01) {
        htm_Alert(lang('@Reconciled - the sub-ledger matches the general ledger control account.'), 'success', 900, true);
    } else {
        htm_Alert(sprintf(lang('@NOT reconciled - the sub-ledger and general ledger control account differ by %s. This may indicate a posting outside the normal invoice/expense flows.'), number_format(abs($diff), 2, ',', '.') . ' ' . $cur), 'error', 900, true);
    }
    echo '<p><a href="aging_report.php">' . lang('@View Aging Report') . '</a> · <a href="account_card.php?acc_id=' . $acc_id . '">' . lang('@View Account Card') . '</a></p>';
    htm_Card_end();
}

htm_Header('@Subledger Reconciliation');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<p style="color:var(--text-muted); margin-bottom:20px;">'
    . lang('@Compares each sub-ledger total (open invoices, unpaid expenses) against its general ledger control account balance - the classic sub-ledger-to-general-ledger reconciliation. A mismatch means something posted to the control account outside the normal invoicing/expense flow.')
    . '</p>';

subledger_render_section(lang('@Accounts Receivable Reconciliation'), $sub_debitor, $gl_debitor, $diff_debitor, $acc_debitor, $cur);

if (!$creditor_supported) {
    htm_Card_(lang('@Accounts Payable Reconciliation'), 900);
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The supplier module database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration to enable the creditor side of this report.'), 'warning');
    htm_Card_end();
} else {
    subledger_render_section(lang('@Accounts Payable Reconciliation'), $sub_creditor, $gl_creditor, $diff_creditor, $acc_creditor, $cur);
}

echo "</div>";
htm_Footer();
