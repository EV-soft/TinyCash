<?php # /trash_bin.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
# "Slet" på en kunde/leverandør blev tidligere en permanent, uigenkaldelig
# handling. Denne side lister alle blødslettede (deleted_at IS NOT NULL)
# kunder/leverandører og lader en admin enten gendanne dem (rydder
# deleted_at) eller slette dem PERMANENT (rigtig DELETE, med SAMME
# sikkerhedstjek for tilknyttede fakturaer/udgifter som customer_edit.php/
# supplier_list.php allerede har - se deres egne kommentarer).
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $type = $_POST['type'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    if ($type === 'customer' && $id > 0) {
        if ($_POST['action'] === 'restore') {
            DB::query($conn, "UPDATE customers SET deleted_at = NULL WHERE cust_id = $id");
            log_action($conn, 'RESTORE_CUSTOMER', 'customers', $id, null, null);
            $msg = lang('@Restored successfully');
        } elseif ($_POST['action'] === 'purge') {
            $check_inv = DB::num_rows(DB::query($conn, "SELECT inv_id FROM invoices WHERE cust_id = $id")) > 0;
            $check_proj = DB::num_rows(DB::query($conn, "SELECT proj_id FROM projects WHERE cust_id = $id")) > 0;
            $quotes_res = @DB::query($conn, "SELECT quote_id FROM quotes WHERE cust_id = $id");
            $check_quotes = $quotes_res && DB::num_rows($quotes_res) > 0;
            // RETTET: også faste fakturaer (en permanent sletning ville efterlade en skabelon uden kunde)
            $rec_res = @DB::query($conn, "SELECT recur_id FROM recurring_invoices WHERE cust_id = $id");
            if ($rec_res && DB::fetch_assoc($rec_res)) $check_quotes = true;
            if ($check_inv || $check_proj || $check_quotes) {
                $err = lang('@Cannot permanently delete: this record is still linked to invoices, projects or quotes.');
            } else {
                $old_row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM customers WHERE cust_id = $id"));
                // RETTET: kun en kunde der rent faktisk ligger i papirkurven kan slettes endeligt, og kundens
                // kontaktpersoner (navn, e-mail, telefon) slettes med - de blev før liggende som forældreløse
                // personoplysninger efter en "endelig" sletning (GDPR).
                $old_contacts = [];
                $oc_res = @DB::query($conn, "SELECT * FROM customer_contacts WHERE cust_id = $id");
                while ($oc_res && ($oc = DB::fetch_assoc($oc_res))) $old_contacts[] = $oc;
                if ($old_row) $old_row['contacts'] = $old_contacts;
                $purge_res = $old_row ? DB::query($conn, "DELETE FROM customers WHERE cust_id = $id AND deleted_at IS NOT NULL") : false;
                if ($purge_res && DB::affected_rows($conn, $purge_res) > 0) {
                    @DB::query($conn, "DELETE FROM customer_contacts WHERE cust_id = $id");
                }
                log_action($conn, 'PURGE_CUSTOMER', 'customers', $id, $old_row, null);
                $msg = lang('@Permanently deleted');
            }
        }
    } elseif ($type === 'supplier' && $id > 0) {
        if ($_POST['action'] === 'restore') {
            DB::query($conn, "UPDATE suppliers SET deleted_at = NULL WHERE supplier_id = $id");
            log_action($conn, 'RESTORE_SUPPLIER', 'suppliers', $id, null, null);
            $msg = lang('@Restored successfully');
        } elseif ($_POST['action'] === 'purge') {
            $check_exp = DB::num_rows(DB::query($conn, "SELECT exp_id FROM expenses WHERE supplier_id = $id")) > 0;
            $rec_res = @DB::query($conn, "SELECT recur_id FROM recurring_expenses WHERE supplier_id = $id");      // RETTET: også faste udgifter
            if ($rec_res && DB::fetch_assoc($rec_res)) $check_exp = true;
            if ($check_exp) {
                $err = lang('@Cannot permanently delete: this record is still linked to invoices, projects or quotes.');
            } else {
                $old_row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM suppliers WHERE supplier_id = $id"));
                DB::query($conn, "DELETE FROM suppliers WHERE supplier_id = $id AND deleted_at IS NOT NULL");  // kun fra papirkurven
                log_action($conn, 'PURGE_SUPPLIER', 'suppliers', $id, $old_row, null);
                $msg = lang('@Permanently deleted');
            }
        }
    }
}

htm_Header('@Trash Bin');
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";
if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

htm_Note(lang('@Customers and suppliers deleted from their own pages land here first, instead of being removed permanently right away. Restore them, or delete them permanently once you are sure.'), 'margin-bottom:20px;');

$has_cust_trash = db_has_column($conn, 'customers', 'deleted_at');

htm_Card_('@Deleted Customers', 900);
if (!$has_cust_trash) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The trash bin database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
} else {
    $res = DB::query($conn, "SELECT cust_id, cust_name, cust_email, deleted_at FROM customers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
    $rows = [];
    while ($r = DB::fetch_assoc($res)) {
        $rows[] = [
            htmlspecialchars($r['cust_name']),
            htmlspecialchars($r['cust_email'] ?? ''),
            date(CONF_DATE_FORMAT, strtotime($r['deleted_at'])),
            '<form method="post" style="display:inline;" onsubmit="return true;">' . csrf_field(false)
                . '<input type="hidden" name="type" value="customer"><input type="hidden" name="id" value="' . (int)$r['cust_id'] . '">'
                . '<button type="submit" name="action" value="restore" style="cursor:pointer; margin-right:6px;" data-hint="' . htmlspecialchars(lang('@Restore this record')) . '"><i class="fa fa-trash-restore"></i> ' . lang('@Restore') . '</button>'
                . '<button type="submit" name="action" value="purge" style="cursor:pointer; color:var(--color-danger);" onclick="' . htm_confirm_js('@Permanently delete this record? This cannot be undone.') . '" data-hint="' . htmlspecialchars(lang('@Permanently delete - cannot be undone')) . '"><i class="fa fa-trash"></i> ' . lang('@Delete Permanently') . '</button>'
                . '</form>',
        ];
    }
    if (empty($rows)) {
        htm_Note(lang('@Trash bin is empty.'), 'font-style:italic;');
    } else {
        htm_Table(['@Name', '@Email', '@Deleted At', '@Actions'], $rows, 'trashCustTbl', 50);
    }
}
htm_Card_end();

echo "<div style='margin-top:20px;'></div>";

$has_supp_trash = db_has_column($conn, 'suppliers', 'deleted_at');

htm_Card_('@Deleted Suppliers', 900);
if (!$has_supp_trash) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The trash bin database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.'), 'warning');
} else {
    $res = DB::query($conn, "SELECT supplier_id, supplier_name, email, deleted_at FROM suppliers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
    $rows = [];
    while ($r = DB::fetch_assoc($res)) {
        $rows[] = [
            htmlspecialchars($r['supplier_name']),
            htmlspecialchars($r['email'] ?? ''),
            date(CONF_DATE_FORMAT, strtotime($r['deleted_at'])),
            '<form method="post" style="display:inline;">' . csrf_field(false)
                . '<input type="hidden" name="type" value="supplier"><input type="hidden" name="id" value="' . (int)$r['supplier_id'] . '">'
                . '<button type="submit" name="action" value="restore" style="cursor:pointer; margin-right:6px;" data-hint="' . htmlspecialchars(lang('@Restore this record')) . '"><i class="fa fa-trash-restore"></i> ' . lang('@Restore') . '</button>'
                . '<button type="submit" name="action" value="purge" style="cursor:pointer; color:var(--color-danger);" onclick="' . htm_confirm_js('@Permanently delete this record? This cannot be undone.') . '" data-hint="' . htmlspecialchars(lang('@Permanently delete - cannot be undone')) . '"><i class="fa fa-trash"></i> ' . lang('@Delete Permanently') . '</button>'
                . '</form>',
        ];
    }
    if (empty($rows)) {
        htm_Note(lang('@Trash bin is empty.'), 'font-style:italic;');
    } else {
        htm_Table(['@Name', '@Email', '@Deleted At', '@Actions'], $rows, 'trashSuppTbl', 50);
    }
}
htm_Card_end();

echo "</div>";
htm_Footer();
