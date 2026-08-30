<?php # /supplier_list.php v:1.3.0 d:2026-08-30 i:evs
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
    if ($in_use) {
        $err = lang('@Cannot delete supplier: one or more expenses are linked to it.');
    } else {
        $old_res = DB::query($conn, "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_id = $del_id");
        $old_row = $old_res ? DB::fetch_assoc($old_res) : null;
        if (DB::query($conn, "DELETE FROM suppliers WHERE supplier_id = $del_id")) {
            if ($old_row) log_action($conn, 'DELETE_SUPPLIER', 'suppliers', $del_id, $old_row, null);
            header("Location: supplier_list.php?msg=deleted"); exit;
        } else {
            $err = lang('@SQL Error:') . " " . DB::error($conn);
        }
    }
}

htm_Header('@Suppliers', 1200);
showMenu();

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') htm_Alert(lang('@Deleted successfully'), 'success');
if ($err) htm_Alert($err, 'error');

$tools = htm_Button(icon: 'fa-plus', labl: '@New Supplier', type: 'success', link: 'supplier_edit.php?id=0', attr: 'data-hint="'.lang('@Create a new supplier').'"', echo: false);
htm_Card_(capt: '@Suppliers', wdth: 1200, tool: $tools);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Master data for suppliers/vendors, linked to expenses. Amount owed reflects expenses registered as "not yet paid" (see Aging Report).') .
    '</p>';

// Findes leverandørgæld-kolonnerne endnu (kun tilfældet efter
// db-setup/migrate_suppliers.php er kørt)?
$has_debt_columns = false;
if (DB::is_sqlite()) {
    $ccheck = DB::query($conn, "PRAGMA table_info(expenses)");
    if ($ccheck) { while ($cr = DB::fetch_assoc($ccheck)) { if ($cr['name'] === 'due_date') { $has_debt_columns = true; break; } } }
} else {
    $ccheck = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE 'due_date'");
    $has_debt_columns = ($ccheck && DB::num_rows($ccheck) > 0);
}

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$res = DB::query($conn, "SELECT * FROM suppliers ORDER BY is_active DESC, supplier_name ASC");

$headers = ['@Name', '@Contact', '@Phone / Email', '@CVR', '@Amount Owed', '@Actions'];
$data = [];

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $sid = (int)$row['supplier_id'];

        $owed = 0.0;
        if ($has_debt_columns) {
            $owed_row = DB::fetch_assoc(DB::query($conn,
                "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses
                 WHERE supplier_id = $sid AND is_cancelled = 0 AND due_date IS NOT NULL AND paid_date IS NULL"));
            $owed = (float)($owed_row['total'] ?? 0);
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
                ? '<strong style="color:var(--color-warning);">' . number_format($owed, 2, ',', '.') . ' ' . $cur . '</strong>'
                : '<span style="color:var(--text-muted);">' . number_format(0, 2, ',', '.') . ' ' . $cur . '</span>',
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
    htm_Table($headers, $data, 'supplierTbl', 100, '', true,
        ['width:220px;', 'width:160px;', 'width:200px;', 'width:120px;', 'width:150px; text-align:right;', 'width:150px; text-align:left;']);
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
