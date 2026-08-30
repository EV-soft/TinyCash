<?php # /expense_list.php v:1.3.0 d:2026-08-30 i:evs
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

$headers = $supplier_module_ready
    ? ['@Date', '@Supplier', '@Description', '@Account', '@Amount', '@Payment', '@File', '@Actions']
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

    if (!$res) {
        htm_Alert("SQL Error: " . DB::error($conn), "error");
    } else {
        while ($r = DB::fetch_assoc($res)) {
            $id = (int)$r['exp_id'];
            $date = date(CONF_DATE_FORMAT, strtotime($r['exp_date']));
            $amt = number_format($r['amount'], 2, ',', '.') . " " . $cur;

            // Bilags-indikator med htm_Shell
            $attachment_cell = '---';
            if (!empty($r['attachment'])) {
                $file_path = $upload_dir . $r['attachment'];
                $attachment_cell = htm_Shell_('display:inline-block; color:#e67e22;', 'span', false);
                $attachment_cell .= '<a href="'.$file_path.'" target="_blank" style="color:inherit;"><i class="fa-solid fa-paperclip" title="'.htmlspecialchars($r['attachment']).'"></i></a>';
                $attachment_cell .= htm_Shell_end(false);
            }

            // Bogført afgør selve handlingen (og dermed dens ikon/farve):
            // expense_actions.php soft-annullerer (bevarer alt til revisionssporet,
            // synkroniserer journalens is_cancelled) hvis udgiften har et voucher_no,
            // og hård-sletter kun rene, ubogførte kladder. Knappen viser derfor det
            // den reelt gør - ingen separat "Bogført"-kolonne nødvendig.
            $is_posted = !empty($r['voucher_no']);
            $delAction = $is_posted
                ? ['icon' => 'fa-rotate-left', 'link' => 'expense_actions.php?action=delete&id='.$id,
                   'hint' => '@Posted — this cancels the expense with a matching journal reversal and keeps everything for the audit trail.',
                   'confirm' => '@This expense is posted. It will be cancelled, not deleted — the entry and its journal are kept for the audit trail. Continue?',
                   'type' => 'warning']
                : ['icon' => 'fa-trash', 'link' => 'expense_actions.php?action=delete&id='.$id,
                   'hint' => '@Not yet posted — this permanently deletes the draft.',
                   'confirm' => '@Are you sure?', 'type' => 'danger'];

            $actionsList = [
                ['icon' => 'fa-edit', 'link' => 'expense_edit.php?id='.$id, 'hint' => '@Edit', 'type' => 'primary'],
            ];
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
            if ($supplier_module_ready && $is_posted && empty($r['is_cancelled']) && !empty($r['due_date']) && empty($r['paid_date'])) {
                $actionsList[] = ['icon' => 'fa-money-bill-wave', 'link' => 'expense_actions.php?action=mark_paid&id='.$id,
                    'hint' => '@Register that this expense has now been paid from the bank account', 'type' => 'success',
                    'confirm' => '@Register that this expense has now been paid from the bank account?'];
            }
            $actionsList[] = $delAction;
            $rowActions = htm_ActionButtons($actionsList, false);

            $row = [
                $date,
                "<strong>" . htmlspecialchars($r['supplier']) . "</strong>",
                "<span style='font-size:0.85em; color:var(--text-muted);'>" . htmlspecialchars($r['description'] ?? '') . "</span>",
                ($r['acc_name'] ?? '---'),
                "<strong>$amt</strong>",
            ];
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
    htm_Table($headers, $data, 'expTbl');
}

htm_Card_end();
htm_Shell_end(); // Lukker den ydre container

htm_Footer();
ob_end_flush();
?>
