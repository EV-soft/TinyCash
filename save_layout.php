<?php # /save_layout.php v:1.3.0 d:2026-08-30 i:evs
/* ==========================================================================
   Gemmer position/bredde for en design-blok på fakturaen (kaldt fra
   invoice_view.php's Design Mode via saveToDB()-JS-funktionen).

   Denne fil manglede helt i projektet, selvom invoice_view.php allerede
   kaldte den - derfor blev ændrede placeringer aldrig gemt: fetch() fejler
   ikke synligt ved en 404, så det så ud som om intet gik galt.
   ========================================================================== */

require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/invoice_layouts.inc.php';

header('Content-Type: application/json');

// Kun indloggede brugere må gemme layout-ændringer
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$element_id = $_POST['id'] ?? '';
$pos_x      = $_POST['x'] ?? null;
$pos_y      = $_POST['y'] ?? null;
$width_mm   = $_POST['w'] ?? null;

// Whitelist af gyldige bloknøgler - matcher design-block id'erne i invoice_view.php.
// Forhindrer at et vilkårligt element_id kan indsættes/opdateres via dette endpoint.
// RETTET (bruger-rapporteret: "Fejl ved gem af layout... status 400" -
// vedvarede efter en tidligere, ANDEN rettelse af samme fejlmelding, se
// invoice_view.php's egen kommentar for den første). block-foot-message
// findes på selve fakturasiden (invoice_view.php), men manglede her -
// ethvert forsøg på at flytte/resize netop DEN blok blev derfor korrekt,
// men vildledende, afvist som "ugyldigt element-id".
$allowed_ids = IL_BLOCK_IDS;      // (listen bor nu i inc/invoice_layouts.inc.php - én kilde)

if (!in_array($element_id, $allowed_ids, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid element id: ' . $element_id]);
    exit;
}

if (!is_numeric($pos_x) || !is_numeric($pos_y) || !is_numeric($width_mm)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}

$pos_x    = (float)$pos_x;
$pos_y    = (float)$pos_y;
$width_mm = (float)$width_mm;

// Flere fakturalayouts (migreret): placeringen gemmes i det layout, der redigeres i Design Mode
if (il_ready($conn)) {
    $layout_id = il_valid_id($conn, $_POST['layout_id'] ?? 0);
    if ($layout_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown layout']);
        exit;
    }
    $eid = DB::escape($conn, $element_id);
    $row = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoice_layout_blocks WHERE layout_id = $layout_id AND element_id = '$eid'"));
    $ok = ($row && (int)$row[0] > 0)
        ? DB::query($conn, "UPDATE invoice_layout_blocks SET pos_x = $pos_x, pos_y = $pos_y, width_mm = $width_mm WHERE layout_id = $layout_id AND element_id = '$eid'")
        : DB::insert($conn, 'invoice_layout_blocks', ['layout_id' => $layout_id, 'element_id' => $element_id,
              'pos_x' => $pos_x, 'pos_y' => $pos_y, 'width_mm' => $width_mm, 'is_visible' => 1]);
    if ($ok) {
        echo json_encode(['success' => true, 'layout_id' => $layout_id, 'element_id' => $element_id, 'pos_x' => $pos_x, 'pos_y' => $pos_y, 'width_mm' => $width_mm]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => DB::error($conn)]);
    }
    exit;
}

// Tjek om der allerede findes en gemt position for denne blok (UPDATE),
// ellers opret en ny række (INSERT) - layout_settings har intet indbygget
// upsert-mønster i DB::-laget, så vi tjekker eksistens manuelt.
$check = DB::query($conn, "SELECT COUNT(*) FROM layout_settings WHERE element_id = '" . DB::escape($conn, $element_id) . "'");
$exists = false;
if ($check) {
    $row = DB::fetch_row($check);
    $exists = ((int)$row[0] > 0);
}

if ($exists) {
    $ok = DB::update($conn, 'layout_settings', [
        'pos_x' => $pos_x, 'pos_y' => $pos_y, 'width_mm' => $width_mm
    ], 'element_id', $element_id);
} else {
    $ok = DB::insert($conn, 'layout_settings', [
        'element_id' => $element_id, 'pos_x' => $pos_x, 'pos_y' => $pos_y,
        'is_visible' => 1, 'width_mm' => $width_mm
    ]);
}

if ($ok) {
    echo json_encode(['success' => true, 'element_id' => $element_id, 'pos_x' => $pos_x, 'pos_y' => $pos_y, 'width_mm' => $width_mm]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => DB::error($conn)]);
}
