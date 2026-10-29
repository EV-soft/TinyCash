<?php # /invoice_layout_action.php v:1.3.1 d:2026-09-15 i:evs
# JSON-endepunkt for flere fakturalayouts (kaldt fra invoice_view.php):
#   create      - nyt layout med et navn, som kopi af et andet layouts placeringer (copy_from)
#   rename      - nyt navn
#   set_default - gør layoutet til standard (for kunder og fakturaer uden eget valg)
#   delete      - sletter layoutet; kunder og kladder der brugte det falder tilbage til
#                 standarden - et layout som en bogført faktura bruger kan ikke slettes
#                 (den skal kunne vises som den blev sendt)
#   assign      - vælger layoutet for en kladde (0 = kundens / standarden)
#   set_visible - viser / skjuler en valgfri blok i layoutet (pligtoplysningerne kan ikke skjules)
# Kun POST. CSRF håndhæves af inc/auth.inc.php for enhver POST. Selve placeringen
# af blokkene gemmes af save_layout.php. Se inc/invoice_layouts.inc.php.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/invoice_layouts.inc.php';

header('Content-Type: application/json; charset=utf-8');

function il_answer(bool $ok, array $data = [], int $status = 200): void {
    if (!$ok && $status === 200) $status = 400;
    http_response_code($status);
    echo json_encode(['success' => $ok] + $data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') il_answer(false, ['error' => 'POST only'], 405);
if (!il_ready($conn)) {
    il_answer(false, ['error' => lang('@Several invoice layouts need a database update: System -> Maintenance -> Database migration (Several invoice layouts).')], 500);
}

$action = (string)($_POST['action'] ?? '');
$id     = il_valid_id($conn, $_POST['layout_id'] ?? 0);

if ($action === 'create') {
    $name = il_clean_name($_POST['name'] ?? '');
    if ($name === '')                  il_answer(false, ['error' => lang('@Please enter a name for the layout.')]);
    if (il_name_taken($conn, $name))   il_answer(false, ['error' => lang('@A layout with this name already exists.')]);
    $from = il_valid_id($conn, $_POST['copy_from'] ?? 0) ?: il_default_id($conn);
    DB::begin_transaction($conn);
    $ok = DB::insert($conn, 'invoice_layouts', ['name' => $name, 'is_default' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    $new_id = $ok ? (int)DB::insert_id($conn) : 0;
    if ($new_id > 0) {
        foreach (il_blocks($conn, $from) as $b) {
            $ok = $ok && DB::insert($conn, 'invoice_layout_blocks', ['layout_id' => $new_id, 'element_id' => $b['element_id'],
                'pos_x' => (float)$b['pos_x'], 'pos_y' => (float)$b['pos_y'], 'width_mm' => (float)$b['width_mm'], 'is_visible' => (int)($b['is_visible'] ?? 1)]);
        }
    }
    if (!$ok || $new_id <= 0) { DB::rollback($conn);  il_answer(false, ['error' => DB::error($conn)], 500); }
    DB::commit($conn);
    log_action($conn, 'CREATE_INVOICE_LAYOUT', 'invoice_layouts', $new_id, null, ['name' => $name, 'copy_from' => $from]);
    il_answer(true, ['layout_id' => $new_id]);
}

if ($id <= 0) il_answer(false, ['error' => lang('@Unknown layout.')]);
$list = il_list($conn);
$old  = $list[$id];

if ($action === 'rename') {
    $name = il_clean_name($_POST['name'] ?? '');
    if ($name === '')                     il_answer(false, ['error' => lang('@Please enter a name for the layout.')]);
    if (il_name_taken($conn, $name, $id)) il_answer(false, ['error' => lang('@A layout with this name already exists.')]);
    if (!DB::update($conn, 'invoice_layouts', ['name' => $name], 'layout_id', $id)) il_answer(false, ['error' => DB::error($conn)], 500);
    log_action($conn, 'RENAME_INVOICE_LAYOUT', 'invoice_layouts', $id, ['name' => $old['name']], ['name' => $name]);
    il_answer(true, ['layout_id' => $id]);
}

if ($action === 'set_default') {
    DB::begin_transaction($conn);
    $ok = DB::query($conn, "UPDATE invoice_layouts SET is_default = 0 WHERE layout_id <> $id")
       && DB::query($conn, "UPDATE invoice_layouts SET is_default = 1 WHERE layout_id = $id");
    if (!$ok) { DB::rollback($conn);  il_answer(false, ['error' => DB::error($conn)], 500); }
    DB::commit($conn);
    log_action($conn, 'DEFAULT_INVOICE_LAYOUT', 'invoice_layouts', $id, null, ['name' => $old['name']]);
    il_answer(true, ['layout_id' => $id]);
}

if ($action === 'delete') {
    if ((int)$old['is_default'] === 1) il_answer(false, ['error' => lang('@The default layout cannot be deleted - make another layout the default first.')]);
    $used = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoices WHERE inv_layout_id = $id AND LOWER(inv_status) <> 'draft'"));
    if ($used && (int)$used[0] > 0) {
        il_answer(false, ['error' => sprintf(lang('@This layout is used by %d posted invoice(s) and cannot be deleted - they must still look the way they were sent.'), (int)$used[0])]);
    }
    DB::begin_transaction($conn);
    $ok = DB::query($conn, "UPDATE customers SET cust_layout_id = NULL WHERE cust_layout_id = $id")
       && DB::query($conn, "UPDATE invoices SET inv_layout_id = NULL WHERE inv_layout_id = $id AND LOWER(inv_status) = 'draft'")
       && DB::query($conn, "DELETE FROM invoice_layout_blocks WHERE layout_id = $id")
       && DB::query($conn, "DELETE FROM invoice_layouts WHERE layout_id = $id");
    if (!$ok) { DB::rollback($conn);  il_answer(false, ['error' => DB::error($conn)], 500); }
    DB::commit($conn);
    log_action($conn, 'DELETE_INVOICE_LAYOUT', 'invoice_layouts', $id, ['name' => $old['name']], null);
    il_answer(true, ['layout_id' => il_default_id($conn)]);
}

if ($action === 'assign') {
    $inv_id = (int)($_POST['inv_id'] ?? 0);
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT inv_id, inv_status FROM invoices WHERE inv_id = $inv_id"));
    if (!$inv) il_answer(false, ['error' => lang('@Invoice not found.')]);
    if (strtolower((string)$inv['inv_status']) !== 'draft') il_answer(false, ['error' => lang('@Only a draft can get another layout - a posted invoice keeps the layout it was sent with.')]);
    $use = !empty($_POST['use_default']) ? 'NULL' : $id;      // use_default: follow the customer / the default again
    if (!DB::query($conn, "UPDATE invoices SET inv_layout_id = $use WHERE inv_id = $inv_id")) il_answer(false, ['error' => DB::error($conn)], 500);
    log_action($conn, 'ASSIGN_INVOICE_LAYOUT', 'invoices', $inv_id, null, ['inv_layout_id' => $use === 'NULL' ? null : $id]);
    il_answer(true, ['layout_id' => $id]);
}

if ($action === 'set_visible') {
    $eid = (string)($_POST['element_id'] ?? '');
    if (!in_array($eid, IL_BLOCK_IDS, true)) il_answer(false, ['error' => 'Unknown block']);
    if (!in_array($eid, IL_OPTIONAL_BLOCKS, true)) il_answer(false, ['error' => lang('@This information must be on an invoice and cannot be hidden.')]);
    $vis = !empty($_POST['visible']) ? 1 : 0;
    $e = DB::escape($conn, $eid);
    $row = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoice_layout_blocks WHERE layout_id = $id AND element_id = '$e'"));
    if ($row && (int)$row[0] > 0) {
        $ok = DB::query($conn, "UPDATE invoice_layout_blocks SET is_visible = $vis WHERE layout_id = $id AND element_id = '$e'");
    } else {      // ingen række endnu (blokken står på sin standardplads): pladsen fra siden gemmes med
        $ok = DB::insert($conn, 'invoice_layout_blocks', ['layout_id' => $id, 'element_id' => $eid, 'pos_x' => (float)($_POST['x'] ?? 0),
            'pos_y' => (float)($_POST['y'] ?? 0), 'width_mm' => (float)($_POST['w'] ?? 0), 'is_visible' => $vis]);
    }
    if (!$ok) il_answer(false, ['error' => DB::error($conn)], 500);
    log_action($conn, $vis ? 'SHOW_LAYOUT_BLOCK' : 'HIDE_LAYOUT_BLOCK', 'invoice_layouts', $id, null, ['layout' => $old['name'], 'block' => $eid]);
    il_answer(true, ['layout_id' => $id, 'visible' => $vis]);
}

il_answer(false, ['error' => 'Unknown action']);
