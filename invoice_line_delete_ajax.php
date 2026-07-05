<?php # /invoice_line_delete_ajax.php v:0.9.1 d:2026-05-07 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) { // Slet linjen (vi tjekker ikke for inv_id her, da line_id er unik nok)
    $sql = "DELETE FROM invoice_lines WHERE line_id = $id";
    if (DB::query($conn, $sql)) {
        echo lang("@OK");
    } else { echo lang("@Database error"); }
} else { echo lang("@ ID"); }
exit;