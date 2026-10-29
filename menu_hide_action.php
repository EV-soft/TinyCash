<?php # /menu_hide_action.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet): lille JSON-endepunkt der lader en admin
# højreklikke DIREKTE på et menupunkt (i selve menuen, ikke via den separate
# menu_visibility.php-administrationsside) og skjule det for L1/L2-brugere -
# se inc/menu.inc.php's context-menu-JS (kun indlejret for admins) for selve
# UI'en. Genbruger SAMME menu_visibility-tabel og get_menu_visibility_
# overrides()/get_menu_visibility_defaults() som menu_visibility.php selv -
# ingen ny, parallel synligheds-mekanisme.
# RETTET (bruger-rapporteret: "Det skal være valgbart L1 / L2 alene eller
# begge"): den oprindelige "hide"-handling skjulte ALTID begge niveauer
# samlet. Erstattet med "set_levels", som sætter L1 og L2 UAFHÆNGIGT af
# hinanden ud fra to separate afkrydsningsfelter i selve context-menuen.
# Ændrer BEVIDST aldrig level_3 (admin) - en admin kan derfor altid selv se
# og fortryde ændringen, uanset hvad der skjules for de to andre niveauer.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => lang('@Only an administrator can change menu visibility.')]);
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

// RETTET (selv-fanget under live-test): manglede FØR helt at tjekke om
// menu_visibility-tabellen overhovedet findes, og - mere alvorligt - tjekkede
// ALDRIG selve DB::query()-resultatet nedenfor, så en fejlet gemning (fx
// denne manglende tabel) rapporterede stille "success":true til brugeren,
// som troede punktet var skjult, mens intet reelt skete. Samme fejlklasse
// som denne sessions "silent-success failed restore"-fund
# (§bugs-batch-12-review).
$table_exists = @DB::query($conn, "SELECT 1 FROM menu_visibility LIMIT 1") !== false;
if (!$table_exists) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => lang('@The menu visibility database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration.')]);
    ob_end_flush();
    exit;
}

// Nuværende synlighed (gemt override, ellers den indbyggede standard) - kun
// level_3 (admin) bevares altid uændret her.
$overrides = get_menu_visibility_overrides($conn);
$current   = $overrides[$key] ?? get_menu_visibility_defaults($key);
$l3        = !empty($current[3]) ? 1 : 0;

// "hide": skjuler for L1+L2. "restore": nulstiller til den indbyggede
// standard for dette punkt (fjerner overstyringen helt, samme som
// menu_visibility.php's egen "Nulstil"-knap, blot for ét enkelt punkt).
if ($action === 'restore') {
    $del_result = DB::query($conn, "DELETE FROM menu_visibility WHERE item_key = '" . DB::escape($conn, $key) . "'");
    if (!$del_result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => lang('@Could not save this change.') . ' ' . DB::error($conn)]);
        ob_end_flush();
        exit;
    }
    log_action($conn, 'RESTORE_MENU_ITEM', 'menu_visibility', 0, ['key' => $key], null);
    echo json_encode(['success' => true]);
    ob_end_flush();
    exit;
}

// "set_levels": L1/L2 sættes UAFHÆNGIGT ud fra de to separate afkrydsnings-
// felter (hide_l1/hide_l2, "1" = skjult, alt andet = synlig) - kan derfor
// skjule kun L1, kun L2, begge, eller gøre begge synlige igen (uden helt at
// slette overstyringen, som "restore" gør).
// RETTET (selv-fanget, "find fem fejl", live-bekræftet): skrev FØR ALTID
// begge niveauer ud fra $_POST, uanset om klienten reelt havde ændret dem -
// klienten sendte et snapshot taget da context-menuen blev åbnet. To
// administratorer der samtidig højreklikkede SAMME punkt og hver især kun
// ændrede ÉT niveau kunne dermed tabe hinandens ændring til den anden
// administrators forældede snapshot af det niveau, VEDKOMMENDE ikke rørte
// (bekræftet reproduceret: admin A skjuler L1, admin B - med et forældet
// snapshot fra FØR A's ændring - skjuler uafhængigt L2, og B's gem
// overskriver stille A's L1-skjulning tilbage til synlig). $current er
// allerede læst friskt herover - bruges nu FAKTISK som fallback for et
// niveau der slet ikke indgår i denne request (se inc/menu.inc.php's
// tilsvarende rettelse, som nu kun sender det/de niveauer brugeren reelt
// lige har ændret).
$current_l1 = !empty($current[1]) ? 1 : 0;
$current_l2 = !empty($current[2]) ? 1 : 0;
$l1 = isset($_POST['hide_l1']) ? (($_POST['hide_l1'] === '1') ? 0 : 1) : $current_l1;
$l2 = isset($_POST['hide_l2']) ? (($_POST['hide_l2'] === '1') ? 0 : 1) : $current_l2;
$key_esc = DB::escape($conn, $key);
$save_result = DB::is_sqlite()
    ? DB::query($conn, "INSERT INTO menu_visibility (item_key, level_1, level_2, level_3) VALUES ('$key_esc', $l1, $l2, $l3)
                       ON CONFLICT(item_key) DO UPDATE SET level_1=$l1, level_2=$l2")
    : DB::query($conn, "INSERT INTO menu_visibility (item_key, level_1, level_2, level_3) VALUES ('$key_esc', $l1, $l2, $l3)
                       ON DUPLICATE KEY UPDATE level_1=$l1, level_2=$l2");

if (!$save_result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => lang('@Could not save this change.') . ' ' . DB::error($conn)]);
    ob_end_flush();
    exit;
}

log_action($conn, 'UPDATE_MENU_ITEM_VISIBILITY', 'menu_visibility', 0, ['key' => $key, 'was' => $current], ['key' => $key, 'level_1' => $l1, 'level_2' => $l2]);

echo json_encode(['success' => true]);
ob_end_flush();
exit;
?>
