<?php # /project_actions.php v:1.2.0 d:2026-08-11 i:evs 
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'create_project':
        $proj_no   = DB::escape($conn, trim($_POST['proj_no'] ?? ''));
        $cust_id   = (int)($_POST['cust_id'] ?? 0);
        $start     = DB::escape($conn, $_POST['proj_start'] ?? '');
        $stop      = DB::escape($conn, $_POST['proj_stop']  ?? '');
        $desc      = DB::escape($conn, $_POST['proj_description'] ?? '');
        $concept   = DB::escape($conn, $_POST['proj_concept'] ?? '');
        $is_active = (int)($_POST['is_active'] ?? 1);

        if (empty($proj_no)) {
            header("Location: project_edit.php?id=0&err=missing_code"); exit;
        }

        $start_sql   = $start   ? "'$start'"   : 'NULL';
        $stop_sql    = $stop    ? "'$stop'"    : 'NULL';
        $cust_sql    = $cust_id ? $cust_id     : 'NULL';

        DB::query($conn, "INSERT INTO projects
            (proj_no, cust_id, proj_start, proj_stop, proj_description, proj_concept, is_active)
            VALUES ('$proj_no', $cust_sql, $start_sql, $stop_sql, '$desc', '$concept', $is_active)");

        $new_id = DB::insert_id($conn);
        header("Location: project_view.php?id=$new_id&msg=created"); exit;

    case 'update_project':
        $proj_id   = (int)($_POST['proj_id'] ?? 0);
        $proj_no   = DB::escape($conn, trim($_POST['proj_no'] ?? ''));
        $cust_id   = (int)($_POST['cust_id'] ?? 0);
        $start     = DB::escape($conn, $_POST['proj_start'] ?? '');
        $stop      = DB::escape($conn, $_POST['proj_stop']  ?? '');
        $desc      = DB::escape($conn, $_POST['proj_description'] ?? '');
        $concept   = DB::escape($conn, $_POST['proj_concept'] ?? '');
        $is_active = (int)($_POST['is_active'] ?? 1);

        if ($proj_id <= 0 || empty($proj_no)) {
            header("Location: project_edit.php?id=$proj_id&err=missing_code"); exit;
        }

        $start_sql = $start   ? "'$start'"   : 'NULL';
        $stop_sql  = $stop    ? "'$stop'"    : 'NULL';
        $cust_sql  = $cust_id ? $cust_id     : 'NULL';

        DB::query($conn, "UPDATE projects SET
            proj_no = '$proj_no',
            cust_id = $cust_sql,
            proj_start = $start_sql,
            proj_stop  = $stop_sql,
            proj_description = '$desc',
            proj_concept = '$concept',
            is_active = $is_active
            WHERE proj_id = $proj_id");

        header("Location: project_view.php?id=$proj_id&msg=saved"); exit;

    case 'delete_project':
        $proj_id = (int)($_GET['id'] ?? 0);
        if ($proj_id > 0) {
            // Nulstil proj_id referencer i stedet for at slette data
            DB::query($conn, "UPDATE invoices      SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE invoice_lines SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE expenses       SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE journal        SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "DELETE FROM projects WHERE proj_id = $proj_id");
        }
        header("Location: project_view.php?msg=deleted"); exit;

    case 'toggle_module':
        // Slå projekt-modulet til/fra fra settings-siden
        $val = ($_POST['module_projects'] ?? '0') === '1' ? '1' : '0';
        DB::query($conn, "UPDATE settings SET setting_value = '$val' WHERE setting_key = 'module_projects'");
        header("Location: " . ($_POST['return_to'] ?? 'project_view.php')); exit;

    default:
        header("Location: project_view.php"); exit;
}
?>
