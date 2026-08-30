<?php # /time_actions.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Timeregistrering (bruger-anmodet) - "Opret faktura af timer".
# Samler ALLE ikke-fakturerede, fakturerbare timer for ét projekt til ÉN
# fakturakladde, med ét fakturalinje PR. registrering (bevidst ingen
# sammenlægning af flere timer til én linje - fuld gennemsigtighed for
# kunden om hvilke dage/opgaver der faktureres, og undgår enhver risiko for
# en forkert sammenlægnings-beregning). Samme "opret altid en KLADDE, aldrig
# bogført direkte"-princip som recurring_invoices/quotes - hele den
# eksisterende bogførings-pipeline (invoice_edit.php -> invoice_post_
# action.php) genbruges uændret derfra.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

$action  = $_GET['action'] ?? '';
$proj_id = isset($_GET['proj_id']) ? (int)$_GET['proj_id'] : 0;

if ($action !== 'invoice' || $proj_id <= 0) {
    header("Location: time_list.php"); exit;
}

$proj = DB::fetch_assoc(DB::query($conn, "SELECT * FROM projects WHERE proj_id = $proj_id"));
if (!$proj || empty($proj['cust_id'])) {
    header("Location: time_list.php?proj_id=$proj_id&msg=nothing_to_invoice"); exit;
}

DB::begin_transaction($conn);
try {
    // Hent kandidat-rækkerne FØR claim, så vi ved præcis hvilke entry_id'er
    // vi forsøger at tage (claimet nedenfor bruger denne liste, ikke en frisk
    // SELECT - undgår at "vinde" nogle rækker der kom til efter selve claimet).
    $entries = [];
    $res = DB::query($conn, "SELECT * FROM time_entries WHERE proj_id = $proj_id AND is_billable = 1 AND is_invoiced = 0");
    while ($row = DB::fetch_assoc($res)) { $entries[] = $row; }

    if (empty($entries)) {
        DB::rollback($conn);
        header("Location: time_list.php?proj_id=$proj_id&msg=nothing_to_invoice");
        exit;
    }

    $ids = array_map(fn($e) => (int)$e['entry_id'], $entries);
    $ids_list = implode(',', $ids);

    // Atomisk claim - kun ét "Opret faktura"-forsøg må reelt tage disse
    // præcise rækker, uanset dobbeltklik/to samtidige faner (samme TOCTOU-
    // mønster som resten af appens statusskift). Claimer FØRST, opretter
    // fakturaen bagefter - hvis en anden forespørgsel allerede har taget en
    // eller flere af rækkerne, matcher affected_rows ikke antallet forventet.
    $claim = DB::query($conn, "UPDATE time_entries SET is_invoiced = 1 WHERE entry_id IN ($ids_list) AND is_invoiced = 0");
    if (!$claim || DB::affected_rows($conn, $claim) < count($ids)) {
        throw new Exception(lang('@Some of these hours were already invoiced by another, simultaneous request. Please try again.'));
    }

    $inv_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+8 days'));
    // RETTET: hardkodede 'DKK' ignorerede virksomhedens faktisk konfigurerede
    // basisvaluta (Firmaindstillinger -> Valuta) - tredje forekomst af samme
    // fejl fundet i quote_actions.php og inc/recurring_invoices.inc.php i
    // denne runde, alle tre tydeligvis kopieret fra samme oprindelige mønster.
    $settings     = get_settings($conn);
    $inv_currency = DB::escape($conn, $settings['currency'] ?? 'DKK');
    $ok = DB::query($conn, "INSERT INTO invoices
        (cust_id, inv_date, inv_due_date, inv_status, currency, proj_id)
        VALUES (" . (int)$proj['cust_id'] . ", '$inv_date', '$due_date', 'draft', '$inv_currency', $proj_id)");
    if (!$ok) throw new Exception('Kunne ikke oprette fakturakladde: ' . DB::error($conn));
    $inv_id = DB::insert_id($conn);

    foreach ($entries as $e) {
        $txt = DB::escape($conn, date(CONF_DATE_FORMAT, strtotime($e['entry_date'])) . ' - ' . $e['description']);
        DB::query($conn, "INSERT INTO invoice_lines
            (inv_id, line_text, quantity, price_each, line_vat_rate, proj_id)
            VALUES ($inv_id, '$txt', " . (float)$e['hours'] . ", " . (float)$e['hourly_rate'] . ", "
            . (float)$e['line_vat_rate'] . ", $proj_id)");
    }

    DB::query($conn, "UPDATE time_entries SET inv_id = $inv_id WHERE entry_id IN ($ids_list)");

    log_action($conn, 'INVOICE_TIME_ENTRIES', 'time_entries', $proj_id, null,
        ['invoice_id' => $inv_id, 'entry_count' => count($ids), 'entry_ids' => $ids_list]);

    DB::commit($conn);
    header("Location: invoice_edit.php?id=$inv_id&msg=saved");
} catch (Exception $e) {
    DB::rollback($conn);
    die(lang('@Error:') . ' ' . $e->getMessage());
}
?>
