<?php # /expense_list.php v:1.3.1 d:2026-09-15 i:evs
# valuta var hårdkodet til DKK, ignorerede indstillingen "Valuta"
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';
// RETTET (§bugs-batch-14-review): expense_edit.php gemmer altid bilag fladt
// under uploads/ (se dens $target_dir), aldrig i en uploads/expenses/-
// undermappe, som ikke findes noget sted i projektet. Bilags-ikonet (📎)
// pegede derfor ALTID på en 404, uanset hvilken udgift man klikkede på -
// samme flade sti som resolve_doc_path()/get_tc_doc() i
// inc/core_utils.lib.php allerede bruger konsekvent alle andre steder.
$upload_dir = 'uploads/';

htm_Header('@Expenses');
showMenu();

// RETTET (§bugs-batch-19-review): denne side havde slet ingen håndtering af
// ?msg= overhovedet, selvom det er det etablerede mønster andre steder i
// appen (fx reconcile_list.php). expense_actions.php kan nu redirecte hertil
// med msg=already_cancelled, når et kapløbsforsøg (dobbeltklik/genindsendt
// formular) opdager at udgiften allerede blev annulleret af en anden,
// samtidig forespørgsel - uden denne besked ville brugeren stå med et
// ellers helt tavst redirect og ingen forklaring på hvorfor intet ændrede sig.
if (isset($_GET['msg']) && $_GET['msg'] == 'already_cancelled') {
    htm_Banner('<i class="fa fa-info-circle"></i> ' . lang('@This expense was already cancelled (possibly by a duplicate click) - nothing was posted twice.'), 'info');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'paid') {
    htm_Alert(lang('@Payment registered successfully.'), 'success');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'already_paid') {
    htm_Banner('<i class="fa fa-info-circle"></i> ' . lang('@This expense was already marked paid (possibly by a duplicate click).'), 'info');
}
// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): bulk-udgave af
// "Betal nu" - samme msg=bulk-mønster som reconcile_list.php/reminders.php
// fik tidligere i denne session.
if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_paid') {
    $b_paid   = (int)($_GET['paid'] ?? 0);
    $b_failed = (int)($_GET['failed'] ?? 0);
    if ($b_failed > 0) {
        htm_Banner('<i class="fa-solid fa-triangle-exclamation"></i> ' . sprintf(lang('@%d expense(s) marked paid. %d could not be paid (already paid, not posted, cancelled, or the accounting period is locked) - please check them individually.'), $b_paid, $b_failed), 'warning');
    } else {
        htm_Alert(sprintf(lang('@%d expense(s) marked paid successfully.'), $b_paid), 'success');
    }
}

// NYT (leverandørmodul): findes due_date/paid_date-kolonnerne endnu (kun
// tilfældet efter db-setup/migrate_suppliers.php er kørt)? Samme "stille
// degradering hvis ikke migreret endnu"-mønster som expense_edit.php.
$supplier_module_ready = false;
if (DB::is_sqlite()) {
    $col_check = DB::query($conn, "PRAGMA table_info(expenses)");
    if ($col_check) {
        while ($cr = DB::fetch_assoc($col_check)) { if ($cr['name'] === 'paid_date') { $supplier_module_ready = true; break; } }
    }
} else {
    $col_check = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE 'paid_date'");
    $supplier_module_ready = ($col_check && DB::num_rows($col_check) > 0);
}

// Vi bruger htm_Shell til den ydre container
htm_Shell_('max-width:1400px; margin:0 auto; padding:10px;');

$top_btn = htm_Button(icon:'fa-plus', labl:'@Add Expense', type:'success', link:'expense_edit.php', attr: 'data-hint="'.lang('@Register a new expense or voucher').'"', echo:false);

htm_Card_('@Expense Overview', 1200, '', 'exp_card', true, $top_btn); // fold: kun relevant på sider med flere cards

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 24): ingen
// side grupperede udgifter efter konto - kun "top-leverandører efter
// forbrug" (supplier_list.php, tidligere runde) fandtes på beslægtet vis
// på købssiden, uden noget kontooverblik. Samme collapsible-panel-mønster.
// Ekskluderer annullerede og rene indtægter (exp_type='income' er ikke en
// udgift i denne opgørelses forstand - samme filter som vat_reconciliation.php
// allerede bruger for "rigtige" udgifter).
// RETTET (runde 30, expense_lines): genbruger nu expense_get_lines() pr.
// udgift, så en opdelt udgift tælles med på ALLE sine konti, ikke kun den
// (nu potentielt misvisende) expenses.account_id. Samme "PHP-side aggregering
// pr. udgift"-mønster som vat_reconciliation.php's egen tilsvarende rettelse.
$exp_by_acc_ids = DB::query($conn, "SELECT exp_id FROM expenses WHERE is_cancelled = 0 AND (exp_type IS NULL OR exp_type != 'income')");
$exp_by_acc_sums = []; // acc_id => sum
$exp_by_acc_grand = 0.0;
if ($exp_by_acc_ids) {
    while ($eid = DB::fetch_assoc($exp_by_acc_ids)) {
        foreach (expense_get_lines($conn, (int)$eid['exp_id']) as $line) {
            $exp_by_acc_sums[$line['account_id']] = ($exp_by_acc_sums[$line['account_id']] ?? 0) + $line['gross'];
            $exp_by_acc_grand += $line['gross'];
        }
    }
}
arsort($exp_by_acc_sums);
$exp_by_acc_rows = [];
foreach ($exp_by_acc_sums as $acc_id => $total) {
    if (abs($total) < 0.01) continue;
    $acc_row = DB::fetch_assoc(DB::query($conn, "SELECT acc_name FROM accounts WHERE acc_id = " . (int)$acc_id));
    $pct = $exp_by_acc_grand > 0 ? ($total / $exp_by_acc_grand * 100) : 0;
    $exp_by_acc_rows[] = [
        htmlspecialchars($acc_row['acc_name'] ?? lang('@No account')),
        number_format($total, 2, ',', '.') . ' ' . $cur,
        number_format($pct, 1, ',', '.') . ' %',
    ];
}
if (!empty($exp_by_acc_rows)) {
    echo '<details style="margin-bottom:15px;"><summary style="cursor:pointer; font-weight:bold; color:var(--text-muted); user-select:none; list-style:none; display:flex; align-items:center; gap:6px;">'
        . '<i class="fa-solid fa-chart-pie" style="color:var(--color-primary);"></i> ' . lang('@Expense Breakdown by Account') . '</summary>';
    echo '<div style="margin-top:8px;">';
    htm_Table(['@Account', '@Amount', '@Share'], $exp_by_acc_rows, 'expByAccTbl', 20, '', true, ['', 'width:150px; text-align:right;', 'width:100px; text-align:right;']);
    echo '</div></details>';
}

// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): bulk "Betal
// nu" - kun relevant når leverandørmodulet overhovedet findes (ellers
// findes "ikke betalt endnu"-begrebet slet ikke). "&nbsp;" foran
// checkboksen - se reminders.php's egen kommentar samme sted for hvorfor
// (htm_Table erstatter ellers en tom action-kolonnes label med "@Actions").
$headers = $supplier_module_ready
    ? ['&nbsp;<input type="checkbox" id="bulkSelectAll" onchange="toggleAllExpensePayments(this.checked)" data-hint="' . htmlspecialchars(lang('@Select all')) . '">',
       '@Date', '@Supplier', '@Description', '@Account', '@Amount', '@Payment', '@File', '@Actions']
    : ['@Date', '@Supplier', '@Description', '@Account', '@Amount', '@File', '@Actions'];
$data = [];

if (!$conn) {
    htm_Alert("Database connection failed", "error");
} else {
    $sql = "SELECT e.*, a.acc_name
            FROM expenses e
            LEFT JOIN accounts a ON e.account_id = a.acc_id
            ORDER BY e.exp_date DESC";
    $res = DB::query($conn, $sql);

    // NYT (runde 30, expense_lines): en opdelt udgift har mere end én reel
    // konto - "Konto"-kolonnens enkelt-kontonavn ville være misvisende
    // (viser kun expenses.account_id, som forbliver et "primært"/historisk
    // felt, ikke længere nødvendigvis fyldestgørende). Henter ÉN gang, ikke
    // pr. udgift i loopet nedenfor.
    $exp_split_ids = [];
    if (expense_lines_has_table($conn)) {
        $spl_res = DB::query($conn, "SELECT exp_id, COUNT(*) AS n FROM expense_lines GROUP BY exp_id HAVING COUNT(*) > 1");
        if ($spl_res) { while ($sr = DB::fetch_assoc($spl_res)) { $exp_split_ids[(int)$sr['exp_id']] = true; } }
    }

    if (!$res) {
        htm_Alert("SQL Error: " . DB::error($conn), "error");
    } else {
        while ($r = DB::fetch_assoc($res)) {
            $id = (int)$r['exp_id'];
            $date = date(CONF_DATE_FORMAT, strtotime($r['exp_date']));
            $amt = number_format($r['amount'], 2, ',', '.') . " " . $cur;

            // Bilags-indikator med htm_Shell
            // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"):
            // skelner nu mellem tre tilstande i stedet for kun "har bilag"/
            // "---". En udgift uden bilag er enten lovligt undtaget
            // (no_attachment_reason udfyldt - beløbet var under
            // conf_attachment_limit ved oprettelsen, se expense_edit.php's
            // eget bilagskrav) eller reelt mangler et bilag den burde have
            // haft (bogføringslov, 5-års opbevaringspligt) - de så
            // tidligere ens ud som et fladt "---", uden nogen måde at samle
            // dem til en periodisk bilagsgennemgang. Teksten "Mangler
            // bilag"/"Undtaget" er bevidst en del af selve cellens tekst
            // (ikke kun et ikon) - htm_Table()'s indbyggede søgefelt søger i
            // cellens synlige tekst, så en bogholder kan skrive "mangler" i
            // søgefeltet og få listen filtreret til netop de rækker der
            // reelt trænger til opfølgning, uden noget nyt UI-element.
            $attachment_cell = '---';
            if (!empty($r['attachment'])) {
                $file_path = $upload_dir . $r['attachment'];
                $attachment_cell = htm_Shell_('display:inline-block; color:#e67e22;', 'span', false);
                $attachment_cell .= '<a href="'.$file_path.'" target="_blank" style="color:inherit;"><i class="fa-solid fa-paperclip" title="'.htmlspecialchars($r['attachment']).'"></i></a>';
                $attachment_cell .= htm_Shell_end(false);
            } elseif (!empty($r['no_attachment_reason'])) {
                $attachment_cell = '<span style="color:var(--text-muted); font-size:0.8em;" title="'.htmlspecialchars($r['no_attachment_reason']).'">'
                    . '<i class="fa-solid fa-circle-info"></i> ' . lang('@Exempt') . '</span>';
            } elseif ($r['exp_type'] === 'expense') {
                // Kun rigtige udgifter kan mangle et bilag i bogføringslovens
                // forstand - en indtægt (exp_type='income') har ikke samme
                // bilagskrav og skal ikke flages som non-compliant her.
                $attachment_cell = '<span style="color:var(--color-danger); font-size:0.8em; font-weight:bold;" data-hint="'.htmlspecialchars(lang('@This entry has no attachment and no documented reason - a bookkeeping-law risk')).'">'
                    . '<i class="fa-solid fa-triangle-exclamation"></i> ' . lang('@Missing Receipt') . '</span>';
            }

            // Bogført afgør selve handlingen (og dermed dens ikon/farve):
            // expense_actions.php soft-annullerer (bevarer alt til revisionssporet,
            // synkroniserer journalens is_cancelled) hvis udgiften har et voucher_no,
            // og hård-sletter kun rene, ubogførte kladder. Knappen viser derfor det
            // den reelt gør - ingen separat "Bogført"-kolonne nødvendig.
            $is_posted = !empty($r['voucher_no']);
            // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"):
            // samme alders-markering som sales_hub.php's fakturakladder
            // fik samme runde - en ubogført udgiftskladde ældre end 30 dage
            // fremhæves nu direkte i datocellen.
            if (!$is_posted) {
                $exp_age_days = days_between($r['exp_date'], date('Y-m-d'));
                if ($exp_age_days > 30) {
                    $date .= ' <span style="color:var(--color-danger); font-weight:bold; font-size:0.85em;" data-hint="'.htmlspecialchars(sprintf(lang('@This draft was created %d days ago and may have been forgotten'), $exp_age_days)).'">(' . sprintf(lang('@%d days'), $exp_age_days) . ')</span>';
                }
            }
            $delAction = $is_posted
                ? ['icon' => 'fa-rotate-left', 'link' => 'expense_actions.php?action=delete&id='.$id,
                   'hint' => '@Posted — this cancels the expense with a matching journal reversal and keeps everything for the audit trail.',
                   'confirm' => '@This expense is posted. It will be cancelled, not deleted — the entry and its journal are kept for the audit trail. Continue?',
                   'type' => 'warning']
                : ['icon' => 'fa-trash', 'link' => 'expense_actions.php?action=delete&id='.$id,
                   'hint' => '@Not yet posted — this permanently deletes the draft.',
                   'confirm' => '@Are you sure?', 'type' => 'danger'];

            $actionsList = [];
            // RETTET (bruger-rapporteret: "rediger-knap virker ikke" på en
            // annulleret udgift): expense_edit.php slår altid op med "AND
            // is_cancelled = 0" (den viser aldrig en annulleret post), så
            // Rediger-knappen endte som et dødt link, der blot sendte
            // brugeren videre til expense_list.php?err=not_found. En
            // annulleret postering er i forvejen urørlig (revisionsspor) -
            // Rediger giver derfor ingen mening og skal ikke vises.
            if (empty($r['is_cancelled'])) {
                $actionsList[] = ['icon' => 'fa-edit', 'link' => 'expense_edit.php?id='.$id, 'hint' => '@Edit', 'type' => 'primary'];
            }
            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"):
            // "Dupliker" - forudfylder en NY udgifts-formular (id=0) med
            // denne posts leverandør/beløb/konto - se expense_edit.php's
            // egen kommentar for hvorfor det bevidst IKKE opretter en
            // postering direkte (i modsætning til invoice_duplicate.php).
            $actionsList[] = ['icon' => 'fa-copy', 'link' => 'expense_edit.php?id=0&dup='.$id, 'hint' => '@Duplicate - pre-fill a new entry with this one\'s supplier, amount and account', 'type' => 'secondary'];
            // NYT (leverandørmodul): "Betal nu"-knap direkte i listen for en
            // bogført, endnu ikke betalt udgift - samme handling som knappen
            // på selve expense_edit.php-siden, blot tilgængelig uden at åbne
            // den enkelte udgift først.
            // RETTET (§bugs-batch-24-review): manglede is_cancelled-tjekket -
            // en udgift der blev annulleret (expense_actions.php?action=delete)
            // MENS den stadig stod som "ikke betalt endnu" beholder sin
            // oprindelige due_date/paid_date uændret (annulleringen rører dem
            // bevidst ikke, kun selve modposteringen), og ville derfor vise en
            // "Betal nu"-knap for en postering der reelt er død. Selve
            // mark_paid-handlingen tjekkede allerede is_cancelled korrekt
            // (SELECT ... WHERE is_cancelled = 0) og ville nægte at gøre
            // noget, men knappen burde slet ikke vises i første omgang.
            // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"):
            // $is_payable genbruges nu også af bulk-checkboksen nedenfor -
            // nøjagtig samme betingelse som "Betal nu"-knappen, så en
            // udgift aldrig kan markeres til bulk-betaling uden også at
            // kunne betales enkeltvist.
            $is_payable = $supplier_module_ready && $is_posted && empty($r['is_cancelled']) && !empty($r['due_date']) && empty($r['paid_date']);
            if ($is_payable) {
                $actionsList[] = ['icon' => 'fa-money-bill-wave', 'link' => 'expense_actions.php?action=mark_paid&id='.$id,
                    'hint' => '@Register that this expense has now been paid from the bank account', 'type' => 'success',
                    'confirm' => '@Register that this expense has now been paid from the bank account?'];
            }
            $actionsList[] = $delAction;
            $rowActions = htm_ActionButtons($actionsList, false);

            $row = [];
            if ($supplier_module_ready) {
                $row[] = $is_payable
                    ? '<input type="checkbox" class="bulk-pay-checkbox" value="'.$id.'" onchange="updateExpensePaySelectedCount()">'
                    : '';
            }
            $row[] = $date;
            $row[] = "<strong>" . htmlspecialchars($r['supplier']) . "</strong>";
            $row[] = "<span style='font-size:0.85em; color:var(--text-muted);'>" . htmlspecialchars($r['description'] ?? '') . "</span>";
            $row[] = !empty($exp_split_ids[$id])
                ? '<span data-hint="' . htmlspecialchars(lang('@Split across multiple accounts - see Edit for the breakdown')) . '">' . lang('@Multiple Accounts') . '</span>'
                : htmlspecialchars($r['acc_name'] ?? '---');      // RETTET: uescapet kontonavn
            $row[] = "<strong>$amt</strong>";
            if ($supplier_module_ready) {
                // RETTET (§bugs-batch-24-review): en annulleret udgift der
                // stod som "ikke betalt endnu" beholder sin due_date uændret
                // (annulleringen rører den bevidst ikke) - viste derfor
                // fejlagtigt en aktiv forfaldsdato-badge for noget der reelt
                // er død og ikke skal betales. Samme fund som "Betal nu"-
                // knappen ovenfor.
                if (!empty($r['is_cancelled'])) {
                    $row[] = '<span style="color:var(--text-muted);">' . lang('@Cancelled') . '</span>';
                } elseif (!empty($r['paid_date'])) {
                    $row[] = '<span style="color:var(--color-success);"><i class="fa-solid fa-circle-check"></i> ' . lang('@Paid') . '</span>';
                } elseif (!empty($r['due_date'])) {
                    $overdue = ($r['due_date'] < date('Y-m-d'));
                    $row[] = '<span style="color:'.($overdue ? 'var(--color-danger)' : 'var(--color-warning)').';"><i class="fa-solid fa-clock"></i> '
                        . date(CONF_DATE_FORMAT, strtotime($r['due_date'])) . '</span>';
                } else {
                    $row[] = '---'; // fx en indtægt, eller en udgift oprettet før leverandørmodulet fandtes
                }
            }
            $row[] = $attachment_cell;
            $row[] = $rowActions;
            $data[] = $row;
        }
    }
}

if (empty($data) && isset($res) && $res) {
    echo "<p style='padding:40px; text-align:center; color:var(--text-muted);'>" . lang('@No expenses found') . "</p>";
} elseif (!empty($data)) {
    // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): "Betal
    // valgte"-knappen indsat via htm_Table's $html-parameter, samme
    // placering/mønster som reminders.php's "Send Selected Reminders" fra
    // samme session. Kun relevant når leverandørmodulet findes - ellers har
    // ingen række en checkboks at vælge.
    $bulk_html = '';
    if ($supplier_module_ready) {
        $bulk_html = '<button type="button" id="bulkPayBtn" onclick="submitBulkExpensePayment()" disabled '
            . 'style="background:var(--color-success); color:#fff; border:none; padding:6px 14px; '
            . 'border-radius:4px; font-weight:bold; cursor:pointer; font-size:13px; opacity:0.5;">'
            . '<i class="fa-solid fa-money-bill-wave"></i> ' . lang('@Mark Selected as Paid')
            . ' (<span id="bulkPayCount">0</span>)</button>';
    }

    // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): CSV-
    // eksport tilføjet - samme allerede eksisterende $expo-parameter som
    // trial_balance.php/account_card.php/audit_log.php/inventory_status.php
    // bruger, blot manglende her på trods af at være en af de mest
    // oplagte sider at eksportere til Excel/regnskabsprogram fra.
    htm_Table($headers, $data, 'expTbl', 100, $bulk_html, true, [], '500px', 'udgiftsliste.csv');

    if ($supplier_module_ready) {
        ?>
        <form id="bulkPayForm" method="post" action="expense_actions.php" style="display:none;"><?php csrf_field(); ?></form>
        <script>
        function updateExpensePaySelectedCount() {
            var n = document.querySelectorAll('.bulk-pay-checkbox:checked').length;
            document.getElementById('bulkPayCount').innerText = n;
            var btn = document.getElementById('bulkPayBtn');
            btn.disabled = (n === 0);
            btn.style.opacity = (n === 0) ? '0.5' : '1';
        }
        function toggleAllExpensePayments(checked) {
            document.querySelectorAll('.bulk-pay-checkbox').forEach(function(cb) { cb.checked = checked; });
            updateExpensePaySelectedCount();
        }
        function submitBulkExpensePayment() {
            var checked = document.querySelectorAll('.bulk-pay-checkbox:checked');
            if (checked.length === 0) return;
            var confirmMsg = <?php echo json_encode(lang('@Register that %d selected expense(s) have now been paid from the bank account?')); ?>.replace('%d', checked.length);
            if (!confirm(confirmMsg)) return;
            var form = document.getElementById('bulkPayForm');
            checked.forEach(function(cb) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
                form.appendChild(inp);
            });
            form.submit();
        }
        </script>
        <?php
    }
}

htm_Card_end();
htm_Shell_end(); // Lukker den ydre container

htm_Footer();
ob_end_flush();
?>
