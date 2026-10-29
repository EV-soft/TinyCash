<?php # /field_hide_action.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet): lille JSON-endepunkt der lader en admin
# højreklikke DIREKTE på et markeret (stiplet blå) formularfelt og skjule
# det for L1/L2-brugere - samme mønster som menu_hide_action.php (menu-
# punkter), blot for enkelte felter via field_visibility (inc/field_
# visibility.inc.php). Genbruger BEVIDST samme "kun de faktisk ændrede
# niveauer sendes"-rettelse fra menu_hide_action.php (§bugs-batch-41-review)
# fra dag ét, i stedet for at genindføre samme tabt-opdatering-race.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/field_visibility.inc.php';
require_once 'inc/audit.inc.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => lang('@Only an administrator can change field visibility.')]);
    ob_end_flush();
    exit;
}

$key    = trim($_POST['key'] ?? '');
$action = trim($_POST['action'] ?? '');

if ($key === '' || !in_array($action, ['set_levels', 'restore'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    ob_end_flush();
    exit;
}

$table_exists = @DB::query($conn, "SELECT 1 FROM field_visibility LIMIT 1") !== false;
if (!$table_exists) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => lang('@The field visibility database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.')]);
    ob_end_flush();
    exit;
}

$overrides = get_field_visibility_overrides($conn);
$current   = $overrides[$key] ?? field_visibility_defaults();
$l3        = !empty($current[3]) ? 1 : 0;

if ($action === 'restore') {
    $del_result = DB::query($conn, "DELETE FROM field_visibility WHERE item_key = '" . DB::escape($conn, $key) . "'");
    if (!$del_result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => lang('@Could not save this change.') . ' ' . DB::error($conn)]);
        ob_end_flush();
        exit;
    }
    log_action($conn, 'RESTORE_FIELD_VISIBILITY', 'field_visibility', 0, ['key' => $key], null);
    echo json_encode(['success' => true]);
    ob_end_flush();
    exit;
}

// Samme race-sikre "kun ændrede niveauer" som menu_hide_action.php - se
// dens egen kommentar for den fulde baggrund (§bugs-batch-41-review).
$current_l1 = !empty($current[1]) ? 1 : 0;
$current_l2 = !empty($current[2]) ? 1 : 0;
$l1 = isset($_POST['hide_l1']) ? (($_POST['hide_l1'] === '1') ? 0 : 1) : $current_l1;
$l2 = isset($_POST['hide_l2']) ? (($_POST['hide_l2'] === '1') ? 0 : 1) : $current_l2;
$key_esc = DB::escape($conn, $key);
$save_result = DB::is_sqlite()
    ? DB::query($conn, "INSERT INTO field_visibility (item_key, level_1, level_2, level_3) VALUES ('$key_esc', $l1, $l2, $l3)
                       ON CONFLICT(item_key) DO UPDATE SET level_1=$l1, level_2=$l2")
    : DB::query($conn, "INSERT INTO field_visibility (item_key, level_1, level_2, level_3) VALUES ('$key_esc', $l1, $l2, $l3)
                       ON DUPLICATE KEY UPDATE level_1=$l1, level_2=$l2");

if (!$save_result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => lang('@Could not save this change.') . ' ' . DB::error($conn)]);
    ob_end_flush();
    exit;
}

log_action($conn, 'UPDATE_FIELD_VISIBILITY', 'field_visibility', 0, ['key' => $key, 'was' => $current], ['key' => $key, 'level_1' => $l1, 'level_2' => $l2]);

echo json_encode(['success' => true]);
ob_end_flush();
exit;
?>
