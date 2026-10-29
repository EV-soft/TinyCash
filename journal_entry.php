<?php # /journal_entry.php v:1.3.1 d:2026-09-15 i:evs
# Diverse postering: fri, manuel journalpostering (dobbelt bogholderi) - det
# eneste af de rigtige posteringsflows (udgift/faktura/anlægsaktiv/bankafstemning)
# der IKKE er bundet til en bestemt forretningshandling. Bruges til periode-
# afgrænsninger/hensættelser, rettelser der ikke passer i Annullér-mønsteret,
# og - vigtigst - INDTASTNING AF PRIMO ved opstart midt i et regnskabsår
# (fandtes der intet sted for før §revisor-behov-gennemgang 2026-09-01).
#
# Følger nøjagtig samme posteringsopskrift som resten af systemet (jf.
# invoice_credit.php/expense_edit.php): is_date_locked() FØR transaktionen
# startes, next_voucher_no() KALDT INDE I DB::begin_transaction() (så et
# rullet-tilbage forsøg frigiver bilagsnummeret igen), ledger_post() pr.
# linje, log_action() til revisionssporet. En gemt postering er bogført
# (har voucher_no) og dermed øjeblikkeligt urørlig via DB-triggerne
# (append-only-ledger-db-triggers) - kan kun rettes via Annullér i
# ledger_view.php, som allerede virker generisk for ALLE journalposter
# uanset trans_type.
#
# Dobbelt-bogholderi håndhæves server-side, ikke kun i UI'et: mindst 2
# linjer med beløb, hver linje enten debet ELLER kredit (aldrig begge),
# og summen af alle linjer skal balancere til 0 (indenfor 1 øres
# afrundingstolerance) - ellers afvises hele posteringen.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_entry'])) {
    $jou_date  = trim($_POST['jou_date'] ?? '');
    $jou_text  = trim($_POST['jou_text'] ?? '');
    $raw_lines = $_POST['lines'] ?? [];
    // RETTET (KRITISK): felterne hedder lines[][acc_id], lines[][debit] og lines[][credit] - PHP laver et NYT
    // array-element for hvert "[]", så én linje kom ind som TRE: [acc_id], [debit], [credit]. Kontoen stod
    // derfor altid uden beløb (sprunget over) og beløbet uden konto ("vælg en konto for hver linje") - en
    // manuel postering kunne slet ikke bogføres. Elementerne samles igen: et acc_id starter en ny linje.
    $je_grouped = [];
    foreach ((array)$raw_lines as $je_part) {
        if (!is_array($je_part)) continue;
        if (array_key_exists('acc_id', $je_part) || !$je_grouped) $je_grouped[] = [];
        $je_grouped[count($je_grouped) - 1] += $je_part;
    }
    $raw_lines = $je_grouped;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jou_date)) {
        $err = lang('@Please enter a valid date.');
    } elseif ($jou_text === '') {
        $err = lang('@Please enter a description for this entry.');
    } elseif (is_date_locked($conn, $jou_date)) {
        $err = lang('@This date is in a locked accounting period.');
    } else {
        $clean_lines = [];
        $total = 0.0;
        foreach ($raw_lines as $l) {
            $acc_id = (int)($l['acc_id'] ?? 0);
            $debit  = (float)str_replace(',', '.', (string)($l['debit'] ?? '0'));
            $credit = (float)str_replace(',', '.', (string)($l['credit'] ?? '0'));

            if ($acc_id <= 0 && $debit == 0.0 && $credit == 0.0) continue; // ubrugt skabelon-række

            if ($debit != 0.0 && $credit != 0.0) {
                $err = lang('@A line cannot have both a debit and a credit amount - use separate lines.');
                break;
            }
            if ($acc_id <= 0) {
                $err = lang('@Please select an account for every line with an amount.');
                break;
            }

            $amount = round($debit - $credit, 2);
            if ($amount == 0.0) continue; // konto valgt, men intet beløb - ignoreres stille

            $clean_lines[] = ['acc_id' => $acc_id, 'amount' => $amount];
            $total += $amount;
        }

        if ($err === '' && count($clean_lines) < 2) {
            $err = lang('@A journal entry needs at least two lines (double-entry bookkeeping).');
        } elseif ($err === '' && abs($total) > 0.01) {
            $err = sprintf(lang('@The entry does not balance: debit and credit differ by %s. Debit must equal credit.'), number_format(abs($total), 2, ',', '.'));
        }

        if ($err === '') {
            DB::begin_transaction($conn);
            try {
                $voucher_no = next_voucher_no($conn);
                $text_esc = DB::escape($conn, $jou_text);
                $date_esc = DB::escape($conn, $jou_date);
                $currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
                // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
                $cb = journal_created_by_sql($conn);
                DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
                    VALUES ('$date_esc', '$text_esc', 'manual', $voucher_no, '$currency'{$cb['val']})");
                $jou_id = DB::insert_id($conn);

                foreach ($clean_lines as $cl) {
                    ledger_post($conn, $jou_id, $cl['acc_id'], $cl['amount']);
                }

                log_action($conn, 'CREATE_MANUAL_JOURNAL_ENTRY', 'journal', $jou_id, null,
                    ['jou_date' => $jou_date, 'jou_text' => $jou_text, 'voucher_no' => $voucher_no, 'lines' => $clean_lines]);

                DB::commit($conn);
                header("Location: journal_entry.php?msg=posted&voucher=" . $voucher_no);
                exit;
            } catch (Exception $e) {
                DB::rollback($conn);
                $err = lang('@Error saving entry') . ': ' . $e->getMessage();
            }
        }
    }
}

// Kontoplan til dropdowns
$acc_options = [];
// NYT (BEK 811/2026-gennemgang, Bilag 3 "bogføringsguide/konterings-
// vejledning", se [[digital-standard-bookkeeping-registration-goal]]):
// kontoens frie konteringsvejledning (account_edit.php) sendes med til
// JS'en nedenfor, så den kan vises direkte når brugeren vælger kontoen -
// det mest håndgribelige sted at vise en konteringsvejledning, da dette er
// den eneste frie, manuelle posteringsside i systemet.
$acc_guidance = [];
require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$je_keep = array_map(fn($l) => (int)(is_array($l) ? ($l['acc_id'] ?? 0) : 0), (array)($raw_lines ?? []));      // (en afvist postering vises igen med sine konti)
$acc_res = DB::query($conn, "SELECT acc_id, acc_name, guidance" . acc_status_col($conn) . " FROM accounts WHERE " . acc_visible_sql($conn, '', $je_keep) . " ORDER BY " . acc_order_sql($conn) . "acc_id ASC");
if ($acc_res) {
    while ($a = DB::fetch_assoc($acc_res)) {
        $acc_options[$a['acc_id']] = $a['acc_id'] . ' - ' . acc_label($a['acc_name'], $a['acc_status'] ?? null);
        if (!empty($a['guidance'])) $acc_guidance[$a['acc_id']] = $a['guidance'];
    }
}

htm_Header('@Manual Journal Entry');
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";

if (isset($_GET['msg']) && $_GET['msg'] === 'posted') {
    $v = (int)($_GET['voucher'] ?? 0);
    htm_Alert(sprintf(lang('@Journal entry posted successfully (voucher no. %d).'), $v)
        . ' <a href="ledger_view.php">' . lang('@View in General Ledger') . '</a>', 'success', 700);
}
if ($err !== '') {
    htm_Alert($err, 'error', 700);
}

htm_Card_('@Manual Journal Entry', 900, '', 'journalEntryForm');

echo '<div style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">';
echo '<div style="flex:1; min-width:180px;"><label style="display:block; font-weight:bold; margin-bottom:5px;">' . lang('@Date') . ':</label>'
    . '<input type="date" name="jou_date" value="' . htmlspecialchars($_POST['jou_date'] ?? date('Y-m-d')) . '" required style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);"></div>';
echo '<div style="flex:2; min-width:280px;"><label style="display:block; font-weight:bold; margin-bottom:5px;">' . lang('@Description') . ':</label>'
    . '<input type="text" name="jou_text" value="' . htmlspecialchars($_POST['jou_text'] ?? '') . '" required maxlength="255" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);"></div>';
echo '</div>';

echo '<table id="linesTable" style="width:100%; border-collapse:collapse; margin-bottom:10px;">';
echo '<thead><tr style="border-bottom:2px solid var(--border-color);">';
echo '<th style="text-align:left; padding:6px;">' . lang('@Account') . '</th>';
echo '<th style="text-align:right; padding:6px; width:140px;">' . lang('@Debit') . '</th>';
echo '<th style="text-align:right; padding:6px; width:140px;">' . lang('@Credit') . '</th>';
echo '<th style="width:40px;"></th>';
echo '</tr></thead>';
echo '<tbody id="linesBody">';

$acc_select_html = '';
foreach (['' => lang('@Select account...')] + $acc_options as $val => $labl) {
    $acc_select_html .= '<option value="' . htmlspecialchars((string)$val) . '">' . htmlspecialchars($labl) . '</option>';
}

for ($i = 0; $i < 4; $i++) {
    echo '<tr>';
    echo '<td style="padding:4px;"><select name="lines[][acc_id]" style="width:100%; padding:6px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);">' . $acc_select_html . '</select></td>';
    echo '<td style="padding:4px;"><input type="number" step="0.01" name="lines[][debit]" style="width:100%; padding:6px; text-align:right; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);"></td>';
    echo '<td style="padding:4px;"><input type="number" step="0.01" name="lines[][credit]" style="width:100%; padding:6px; text-align:right; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);"></td>';
    echo '<td style="padding:4px; text-align:center;"><button type="button" onclick="removeLine(this)" title="' . htmlspecialchars(lang('@Remove line')) . '" style="background:none; border:none; color:var(--color-danger); cursor:pointer; font-size:16px;"><i class="fa fa-times-circle"></i></button></td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo '<div id="acctGuidanceBox" style="display:none; background:var(--bg-panel); border-left:3px solid var(--color-info); padding:8px 12px; margin-bottom:10px; font-size:0.9em;"><i class="fa-solid fa-circle-info" style="color:var(--color-info);"></i> <span id="acctGuidanceText"></span></div>';

echo '<template id="lineTemplate"><tr>';
echo '<td style="padding:4px;"><select name="lines[][acc_id]" style="width:100%; padding:6px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);">' . $acc_select_html . '</select></td>';
echo '<td style="padding:4px;"><input type="number" step="0.01" name="lines[][debit]" style="width:100%; padding:6px; text-align:right; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);"></td>';
echo '<td style="padding:4px;"><input type="number" step="0.01" name="lines[][credit]" style="width:100%; padding:6px; text-align:right; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-card); color:var(--text-main);"></td>';
echo '<td style="padding:4px; text-align:center;"><button type="button" onclick="removeLine(this)" title="' . htmlspecialchars(lang('@Remove line')) . '" style="background:none; border:none; color:var(--color-danger); cursor:pointer; font-size:16px;"><i class="fa fa-times-circle"></i></button></td>';
echo '</tr></template>';

echo '<div style="margin-bottom:15px;">';
htm_Button('fa-plus', '@Add Line', 'secondary', '', '', 'type="button" onclick="addLine()"');
echo '</div>';

echo '<div id="balanceBox" style="display:flex; gap:25px; padding:12px; background:var(--bg-panel); border-radius:6px; margin-bottom:15px; font-weight:bold;">';
echo '<div>' . lang('@Debit') . ': <span id="balDebit">0,00</span></div>';
echo '<div>' . lang('@Credit') . ': <span id="balCredit">0,00</span></div>';
echo '<div id="balDiffWrap">' . lang('@Difference') . ': <span id="balDiff">0,00</span></div>';
echo '</div>';

echo '<div style="display:flex; justify-content:space-between;">';
htm_Button('fa-save', '@Post Entry', 'primary', '', '', 'name="post_entry" data-hint="' . lang('@Post this journal entry - it will be immutable once saved, same as every other posted entry.') . '"');
htm_Button('fa-arrow-left', '@Back', 'secondary', 'ledger_view.php', '', 'data-hint="' . lang('@Return to the general ledger - the entry is not saved') . '"');
echo '</div>';

htm_Card_end();
echo "</div>";
?>
<script>
function addLine() {
    var tpl = document.getElementById('lineTemplate');
    var clone = tpl.content.cloneNode(true);
    document.getElementById('linesBody').appendChild(clone);
    updateBalance();
}
function removeLine(btn) {
    var tbody = document.getElementById('linesBody');
    if (tbody.rows.length <= 2) return; // mindst 2 linjer skal altid stå tilbage
    btn.closest('tr').remove();
    updateBalance();
}
function parseAmt(v) {
    return parseFloat((v || '0').toString().replace(',', '.')) || 0;
}
function updateBalance() {
    var debit = 0, credit = 0;
    document.querySelectorAll('#linesBody [name="lines[][debit]"]').forEach(function(el) { debit += parseAmt(el.value); });
    document.querySelectorAll('#linesBody [name="lines[][credit]"]').forEach(function(el) { credit += parseAmt(el.value); });
    var diff = Math.round((debit - credit) * 100) / 100;
    var fmt = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    document.getElementById('balDebit').innerText = debit.toLocaleString('da-DK', fmt);
    document.getElementById('balCredit').innerText = credit.toLocaleString('da-DK', fmt);
    document.getElementById('balDiff').innerText = diff.toLocaleString('da-DK', fmt);
    document.getElementById('balDiffWrap').style.color = (Math.abs(diff) < 0.01) ? 'var(--color-success)' : 'var(--color-danger)';
}
document.getElementById('linesTable').addEventListener('input', updateBalance);
document.addEventListener('DOMContentLoaded', updateBalance);

// NYT (BEK 811/2026-gennemgang, Bilag 3 "bogføringsguide/konterings-
// vejledning", se [[digital-standard-bookkeeping-registration-goal]]): viser
// kontoens frie konteringsvejledning (sat på account_edit.php) direkte, når
// den vælges i en af linjernes konto-dropdowns - event delegation på selve
// tabellen, da rækker klones dynamisk via <template> (addLine()) og derfor
// ikke findes i DOM'et ved sideindlæsning.
var ACCT_GUIDANCE = <?php echo json_encode($acc_guidance, JSON_UNESCAPED_UNICODE); ?>;
document.getElementById('linesTable').addEventListener('change', function(e) {
    if (!e.target.matches('[name="lines[][acc_id]"]')) return;
    var g = ACCT_GUIDANCE[e.target.value];
    var box = document.getElementById('acctGuidanceBox');
    if (g) {
        document.getElementById('acctGuidanceText').textContent = g;
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
});
</script>
<?php
htm_Footer();
