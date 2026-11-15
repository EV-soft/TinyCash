<?php # /vies_check.php v:1.3.1 d:2026-09-15 i:evs
// Kontrollerer et EU-momsnummer i VIES (inc/vies.inc.php) - kaldt fra kunde- og leverandørkortets "Tjek i VIES".
// POST med CSRF-nøgle; svaret logges i revisionssporet som dokumentation for kontrollen (VIES_CHECK).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/vies.inc.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    if (ob_get_level()) ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => lang('@Security check failed (CSRF). Please reload the page and try again.')], JSON_UNESCAPED_UNICODE);
    exit;
}
$table = ($_POST['table'] ?? '') === 'suppliers' ? 'suppliers' : 'customers';
$row_id = max(0, (int)($_POST['row_id'] ?? 0));
$r = tc_vies_check((string)($_POST['vat_id'] ?? ''));
if (in_array($r['status'], ['valid', 'invalid'], true)) {
    log_action($conn, 'VIES_CHECK', $table, $row_id, null, $r);
}
$r['checked_at_fmt'] = date(CONF_DATE_FORMAT . ' H:i', strtotime($r['checked_at']));
if (ob_get_level()) ob_end_clean();
echo json_encode($r, JSON_UNESCAPED_UNICODE);
