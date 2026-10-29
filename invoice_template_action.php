<?php # /invoice_template_action.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 32):
# lille JSON-endepunkt der lader invoice_edit.php gemme det nuværende sæt
# fakturalinjer som en navngivet, genbrugelig skabelon, liste eksisterende
# skabeloner, hente én skabelons linjer til indsættelse, eller slette en
# skabelon. Rører ALDRIG selve invoices/invoice_lines - kun de nye
# invoice_line_templates(_items)-tabeller.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

header('Content-Type: application/json; charset=utf-8');

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

$has_table = false;
if (DB::is_sqlite()) {
    $r = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='invoice_line_templates'");
    $has_table = $r && DB::fetch_assoc($r);
} else {
    $r = DB::query($conn, "SHOW TABLES LIKE 'invoice_line_templates'");
    $has_table = $r && DB::num_rows($r) > 0;
}
if (!$has_table) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => lang('@The invoice templates database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.')]);
    exit;
}

if ($action === 'list') {
    $rows = [];
    $res = DB::query($conn, "SELECT template_id, name FROM invoice_line_templates ORDER BY name ASC");
    if ($res) { while ($r = DB::fetch_assoc($res)) { $rows[] = $r; } }
    echo json_encode(['success' => true, 'templates' => $rows]);
    exit;
}

if ($action === 'get') {
    $tid = (int)($_GET['template_id'] ?? $_POST['template_id'] ?? 0);
    $items = [];
    $res = DB::query($conn, "SELECT line_text, quantity, price_each, line_vat_rate, prod_id FROM invoice_line_template_items WHERE template_id = $tid ORDER BY item_id ASC");
    if ($res) { while ($r = DB::fetch_assoc($res)) { $items[] = $r; } }
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

if ($action === 'save') {
    // NYT: CSRF for POST er allerede håndhævet globalt af inc/auth.inc.php
    // (csrf_verify(), kaldt for enhver POST) - intet ekstra tjek nødvendigt her.
    $name = trim($_POST['name'] ?? '');
    $lines = json_decode($_POST['lines'] ?? '[]', true);
    if ($name === '' || !is_array($lines) || empty($lines)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => lang('@Please enter a name and include at least one non-empty line.')]);
        exit;
    }
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    // RETTET: created_at var datetime('now') - findes kun i SQLite, så det fejlede på MySQL (og linjerne
    // blev da gemt under template_id 0, mens svaret alligevel sagde success). Tidspunktet laves nu i PHP
    // (samme format på begge motorer), og en fejlet INSERT stopper med en fejlmelding.
    $ok_tpl = DB::query($conn, "INSERT INTO invoice_line_templates (name, created_by, created_at) VALUES ('" . DB::escape($conn, $name) . "', $current_user_id, '" . date('Y-m-d H:i:s') . "')");
    $tid = $ok_tpl ? (int)DB::insert_id($conn) : 0;
    if ($tid <= 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => lang('@Error saving to database:') . ' ' . DB::error($conn)]);
        exit;
    }
    foreach ($lines as $l) {
        $txt = DB::escape($conn, trim($l['line_text'] ?? ''));
        if ($txt === '') continue;
        $qty = (float)($l['quantity'] ?? 0);
        $prc = (float)($l['price_each'] ?? 0);
        $vat = (float)($l['line_vat_rate'] ?? 0);
        $pid = (int)($l['prod_id'] ?? 0);
        $pid_sql = $pid > 0 ? $pid : 'NULL';
        DB::query($conn, "INSERT INTO invoice_line_template_items (template_id, line_text, quantity, price_each, line_vat_rate, prod_id) VALUES ($tid, '$txt', $qty, $prc, $vat, $pid_sql)");
    }
    echo json_encode(['success' => true, 'template_id' => $tid]);
    exit;
}

if ($action === 'delete') {
    $tid = (int)($_POST['template_id'] ?? 0);
    DB::query($conn, "DELETE FROM invoice_line_template_items WHERE template_id = $tid");
    DB::query($conn, "DELETE FROM invoice_line_templates WHERE template_id = $tid");
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
