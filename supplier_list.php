<?php # /supplier_list.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION: Leverandørmodul (bruger-anmodet, "byg leverandørmodul og
# aldersfordelt restanceliste"). Leverandørliste - samme rolle på købssiden
# som sales_hub.php/customer_edit.php allerede har på salgssiden. Skyldigt
# beløb pr. leverandør beregnes live fra expenses.due_date/paid_date (se
# db-setup/migrate_suppliers.php) - kun bogførte, ikke-annullerede udgifter
# der reelt endnu ikke er markeret betalt.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$msg = ''; $err = '';

// --- Slet ---
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $del_id = (int)$_GET['del'];

    // Sikkerhedstjek: en leverandør der er koblet på en eller flere udgifter
    // (via supplier_id) kan ikke slettes - ville efterlade en løs reference
    // og en historik der pludselig ikke længere kan slås op fra leverandørsiden.
    $in_use = DB::num_rows(DB::query($conn, "SELECT exp_id FROM expenses WHERE supplier_id = $del_id LIMIT 1")) > 0;
    // RETTET: en fast udgift (recurring_expenses) på leverandøren blev ikke tjekket - den blev ved med at danne
    // udgifter til en "slettet" leverandør (samme fejl som kunderne og faste fakturaer, se customer_edit.php)
    $rec_chk = @DB::query($conn, "SELECT recur_id FROM recurring_expenses WHERE supplier_id = $del_id LIMIT 1");
    if (!$in_use && $rec_chk && DB::fetch_assoc($rec_chk)) $in_use = true;
    if ($in_use) {
        $err = lang('@Cannot delete supplier: one or more expenses are linked to it.');
    } else {
        $old_res = DB::query($conn, "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_id = $del_id");
        $old_row = $old_res ? DB::fetch_assoc($old_res) : null;
        if (!$old_row) redirect('supplier_list.php', '@Not found - it may already have been deleted.', 'error');      // RETTET: meldte succes, selv om intet fandtes (dobbeltklik, en anden fane)

        // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere", runde
        // 31): samme princip som customer_edit.php's tilsvarende rettelse -
        // "Slet" er nu en gendannelig blødsletning, se trash_bin.php.
        $has_trash_col = db_has_column($conn, 'suppliers', 'deleted_at');
        $del_sql = $has_trash_col
            ? "UPDATE suppliers SET deleted_at = '" . date('Y-m-d H:i:s') . "' WHERE supplier_id = $del_id"
            : "DELETE FROM suppliers WHERE supplier_id = $del_id";

        if (DB::query($conn, $del_sql)) {
            if ($old_row) log_action($conn, 'DELETE_SUPPLIER', 'suppliers', $del_id, $old_row, null);
            redirect('supplier_list.php', '@Deleted successfully');
        } else {
            $err = lang('@SQL Error:') . " " . DB::error($conn);
        }
    }
}

htm_Header('@Suppliers', 1200);
showMenu();

if ($err) htm_Alert($err, 'error');

$tools = htm_Button(icon: 'fa-plus', labl: '@New Supplier', type: 'success', link: 'supplier_edit.php?id=0', attr: 'data-hint="'.lang('@Create a new supplier').'"', echo: false);
htm_Card_(capt: '@Suppliers', wdth: 1200, tool: $tools);

htm_Note(lang('@Master data for suppliers/vendors, linked to expenses. Amount owed reflects expenses registered as "not yet paid" (see Aging Report).'), 'font-size:0.9em; margin-top:-5px;');

// Findes leverandørgæld-kolonnerne endnu (kun tilfældet efter
// db-setup/migrate_suppliers.php er kørt)?
$has_debt_columns = db_has_column($conn, 'expenses', 'due_date');

$s     = get_settings($conn);
$cur   = $s['currency'] ?? 'DKK';
$today = date('Y-m-d');

// NYT: ingen side i systemet rangerede leverandører efter reelt forbrug - "Skyldigt beløb" nedenfor viser kun
// UBETALTE udgifter, ikke den samlede historik. "amount" bruges direkte (ikke ganget med exch_rate) - samme
// konvention som "Skyldigt beløb"-kolonnen herunder allerede bruger: expenses.amount er altid indtastet i
// regnskabets basisvaluta, orig_amount/exch_rate er kun supplerende metadata, ikke en omregning der mangler at
// blive foretaget.
if ($has_debt_columns) {
    // RETTET: kladder og indtægtsbilag talte med som "forbrug" hos leverandøren (samme filter som supplier_statement.php)
    $top_supp_res = DB::query($conn, "
        SELECT s.supplier_id, s.supplier_name, SUM(e.amount) AS spend
        FROM expenses e
        JOIN suppliers s ON e.supplier_id = s.supplier_id
        WHERE e.is_cancelled = 0 AND e.voucher_no IS NOT NULL AND (e.exp_type IS NULL OR e.exp_type != 'income')
        GROUP BY s.supplier_id, s.supplier_name
        HAVING SUM(e.amount) != 0
        ORDER BY spend DESC
        LIMIT 10");
    $top_supp_rows = [];
    while ($tsr = DB::fetch_assoc($top_supp_res)) {
        $top_supp_rows[] = [
            '<a href="supplier_edit.php?id='.(int)$tsr['supplier_id'].'">'.htmlspecialchars($tsr['supplier_name']).'</a>',
            fmt_dk((float)$tsr['spend']) . ' ' . $cur,
        ];
    }
    if (!empty($top_supp_rows)) {
        echo '<details style="margin-bottom:15px;"><summary style="cursor:pointer; font-weight:bold; color:var(--text-muted); user-select:none; list-style:none; display:flex; align-items:center; gap:6px;">'
            . '<i class="fa-solid fa-ranking-star" style="color:var(--color-primary);"></i> ' . lang('@Top Suppliers by Spend') . '</summary>';
        echo '<div style="margin-top:8px;">';
        htm_Table(['@Supplier', '@Spend'], $top_supp_rows, 'topSuppTbl', 10, '', true, ['', 'width:150px; text-align:right;']);
        echo '</div></details>';
    }
}

$res = DB::query($conn, "SELECT * FROM suppliers WHERE " . suppliers_active_filter($conn) . " ORDER BY is_active DESC, supplier_name ASC");

$headers = ['@Name', '@Contact', '@Phone / Email', '@CVR', '@Amount Owed', '@Actions'];
$data = [];

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $sid = (int)$row['supplier_id'];

        // NYT: "Skyldigt beløb" viste FØR kun ét samlet tal, uden at skelne mellem beløb der reelt er FORFALDNE
        // (due_date overskredet) og beløb der bare endnu ikke er forfaldet - samme skelnen aging_report.php
        // allerede laver for begge sider (kunder/leverandører), men som manglede her på selve listen. Beregnes i
        // PHP med en streng dato-sammenligning, samme cross-engine-mønster som
        // aging_report.php/customer_statement.php.
        $owed = 0.0;
        $owed_overdue = 0.0;
        if ($has_debt_columns) {
            $owed_res = DB::query($conn,
                "SELECT amount, due_date FROM expenses
                 WHERE supplier_id = $sid AND is_cancelled = 0 AND voucher_no IS NOT NULL AND (exp_type IS NULL OR exp_type != 'income') AND due_date IS NOT NULL AND paid_date IS NULL");      // RETTET: kun bogførte udgifter
            if ($owed_res) {
                while ($or = DB::fetch_assoc($owed_res)) {
                    $owed += (float)$or['amount'];
                    if ($or['due_date'] < $today) $owed_overdue += (float)$or['amount'];
                }
            }
        }

        $name_cell = htmlspecialchars($row['supplier_name']);
        if (!$row['is_active']) $name_cell .= ' <span style="color:var(--text-muted); font-size:0.8em;">(' . lang('@Inactive') . ')</span>';

        $contact_email = trim(($row['phone'] ?? '') . ($row['phone'] && $row['email'] ? ' / ' : '') . ($row['email'] ?? ''));

        $actions = [
            ['icon' => 'fa-pencil', 'link' => 'supplier_edit.php?id='.$sid, 'hint' => '@Edit', 'type' => 'primary'],
            ['icon' => 'fa-file-invoice-dollar', 'link' => 'supplier_statement.php?id='.$sid, 'hint' => '@Supplier Statement', 'type' => 'info'],
        ];

        $data[] = [
            $name_cell,
            htmlspecialchars($row['contact_person'] ?? ''),
            htmlspecialchars($contact_email),
            htmlspecialchars($row['cvr'] ?? ''),
            $owed > 0.01
                ? '<strong style="color:var(--color-'.($owed_overdue > 0.01 ? 'danger' : 'warning').');">' . fmt_dk($owed) . ' ' . $cur . '</strong>'
                    . ($owed_overdue > 0.01 ? '<br><small style="color:var(--color-danger); font-weight:bold;"><i class="fa-solid fa-triangle-exclamation"></i> ' . sprintf(lang('@%s overdue'), fmt_dk($owed_overdue) . ' ' . $cur) . '</small>' : '')
                : '<span style="color:var(--text-muted);">' . fmt_dk(0) . ' ' . $cur . '</span>',
            htm_ActionButtons($actions, false) . htm_ConfirmLink(
                icon: 'fa-trash', labl: '', link: 'supplier_list.php?del='.$sid,
                mess: '@Are you sure you want to delete this supplier? This cannot be undone.',
                type: 'danger', styl: 'display:inline-block; margin-left:4px; padding:4px 8px;',
                attr: 'data-hint="'.lang('@Delete this supplier - only possible if no expenses are linked').'"', echo: false
            ),
        ];
    }
}

if (empty($data)) {
    if (!$has_debt_columns) {
        htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The supplier module database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
    }
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No suppliers registered yet.') . "</p>";
} else {
    // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): CSV-eksport
    // tilføjet - samme $expo-parameter som expense_list.php/inventory_status.php
    // m.fl. allerede bruger, blot manglende her.
    htm_Table($headers, $data, 'supplierTbl', 100, '', true,
        ['width:220px;', 'width:160px;', 'width:200px;', 'width:120px;', 'width:150px; text-align:right;', 'width:150px; text-align:left;'],
        '500px', 'leverandoerliste.csv');
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
