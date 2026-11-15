<?php # /subledger_reconciliation.php v:1.3.1 d:2026-09-15 i:evs
# Debitor-/kreditor-afstemning: sammenligner delregnskabet (åbne fakturaer
# hhv. ubetalte udgifter) mod hovedbogens kontrolkonto-saldo - den klassiske
# "stemmer under-regnskabet med hovedbogen"-kontrol, komplementær til
# trial_balance.php (konto-niveau) og entry_balance_check.php (bilag-niveau).
#
# Debitorsiden: samme beløbsformel som aging_report.php (faktura + kreditnotaer + gebyrer - betalinger, i
# bogføringsvalutaen), men over ALLE bogførte fakturaer med fortegn - kontrolkontoen rummer også tilgodehavender
# og gebyrer på krediterede fakturaer, som aldersfordelingen (kun skyldige beløb) ikke viser.
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
// Tallene: subledger_figures() i inc/health.inc.php - samme beregning som forsidens systemsundhed
require_once 'inc/health.inc.php';
$sf = subledger_figures($conn);
$acc_debitor  = $sf['acc_deb'];  $sub_debitor  = $sf['deb_sub'];  $gl_debitor  = $sf['deb_gl'];  $diff_debitor  = $sf['deb_diff'];
$acc_creditor = $sf['acc_cre'];  $sub_creditor = $sf['cre_sub'];  $gl_creditor = $sf['cre_gl'];  $diff_creditor = $sf['cre_diff'];
$creditor_supported = $sf['cre_ok'];

function subledger_render_section($capt, $sub_total, $gl_total, $diff, $acc_id, $cur) {
    htm_Card_($capt, 900);
    echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Sub-ledger Total') . '</div><div style="font-weight:bold; font-size:1.2em;">' . fmt_dk($sub_total) . ' ' . $cur . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@General Ledger Balance') . ' (' . lang('@Account') . ' ' . $acc_id . ')</div><div style="font-weight:bold; font-size:1.2em;"><a href="account_card.php?acc_id=' . $acc_id . '">' . fmt_dk($gl_total) . ' ' . $cur . '</a></div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Difference') . '</div><div style="font-weight:bold; font-size:1.2em; color:' . (abs(round($diff, 2)) >= 0.005 ? 'var(--color-danger)' : 'inherit') . ';">' . fmt_dk($diff) . ' ' . $cur . '</div></div>';
    echo '</div>';

    if (abs(round($diff, 2)) < 0.005) {      // (1 øre er også en difference - delregnskabet skal stemme helt)
        htm_Alert(lang('@Reconciled - the sub-ledger matches the general ledger control account.'), 'success', 900, true);
    } else {
        htm_Alert(sprintf(lang('@NOT reconciled - the sub-ledger and general ledger control account differ by %s. This may indicate a posting outside the normal invoice/expense flows.'), fmt_dk(abs($diff)) . ' ' . $cur), 'error', 900, true);
    }
    echo '<p><a href="aging_report.php">' . lang('@View Aging Report') . '</a> · <a href="account_card.php?acc_id=' . $acc_id . '">' . lang('@View Account Card') . '</a></p>';
    htm_Card_end();
}

htm_Header('@Subledger Reconciliation');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

htm_Note(lang('@Compares each sub-ledger total (open invoices, unpaid expenses) against its general ledger control account balance - the classic sub-ledger-to-general-ledger reconciliation. A mismatch means something posted to the control account outside the normal invoicing/expense flow.'), 'margin-bottom:20px;');

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
