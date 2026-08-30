<?php # /fixed_asset_actions.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Anlægsaktiver/afskrivninger - "Kør afskrivninger". Beregner og
# bogfører lineær afskrivning (lige store rater pr. hele måned) for HVERT
# aktivt aktiv, frem til i dag, i én batch-kørsel. Fremrykker altid fra
# aktivets EGEN last_depreciated_date (eller anskaffelsesdatoen, hvis det
# aldrig er afskrevet før) - samme datodrift-undgåelses-princip som
# recurring_invoices.inc.php's recurring_next_date(): fremrykker pr. hele
# måned fra den sidste kendte dato, aldrig fra "i dag" direkte, så en
# glemt/sjældent kørt afskrivning ikke taber eller dobbelttæller måneder.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

$action = $_GET['action'] ?? '';

if (!in_array($action, ['run_depreciation', 'cancel'], true)) {
    header("Location: fixed_asset_list.php");
    exit;
}

// NYT (§bugs-batch-26-review): et fejlregistreret anlægsaktiv (forkert beløb/
// dato/konto) havde INGEN korrektionsvej overhovedet - hvert andet
// posteringsflow i appen har en (udgifter annulleres med en modpostering,
// fakturaer krediteres, banklinjer kan slettes), men et anlægsaktiv kunne
// hverken rettes (finansielle felter låses bevidst ved bogføring, samme
// princip som udgifter) eller fjernes - kun "Afhænd" fandtes, som semantisk
// betyder et REELT salg/kasseret, ikke en fortrydelse af en fejltastning.
// Tilføjet "Fortryd registrering": kun muligt hvis aktivet ALDRIG er
// afskrevet endnu (accumulated_depreciation = 0) og ikke allerede afhændet -
// altså kun mens den eneste økonomiske hændelse er selve anskaffelses-
// posteringen. Poster en ren modpostering (samme beløb, modsat fortegn) og
// markerer aktivet 'cancelled', ligesom expense_actions.php's annullering -
// aldrig en hård sletning, af hensyn til revisionssporet.
if ($action === 'cancel') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) { header("Location: fixed_asset_list.php"); exit; }

    $res   = DB::query($conn, "SELECT * FROM fixed_assets WHERE asset_id = $id");
    $asset = $res ? DB::fetch_assoc($res) : null;
    if (!$asset || $asset['status'] !== 'active' || (float)$asset['accumulated_depreciation'] != 0) {
        header("Location: fixed_asset_list.php?msg=cannot_cancel");
        exit;
    }

    $today = date('Y-m-d');
    if (is_date_locked($conn, $today)) {
        die(lang('@Today\'s date is in a locked accounting period and this cannot be posted.'));
    }

    DB::begin_transaction($conn);
    try {
        $claim = DB::prepare_and_execute($conn,
            "UPDATE fixed_assets SET status = 'cancelled' WHERE asset_id = ? AND status = 'active' AND accumulated_depreciation = 0",
            [$id]);
        if (!$claim || DB::affected_rows($conn, $claim) < 1) {
            throw new Exception(lang('@This asset was already changed by another, simultaneous request.'));
        }

        $acc_bank   = (int)(get_settings($conn)['conf_acc_bank'] ?? 5000);
        $cost       = (float)$asset['acquisition_cost'];
        $voucher_no = next_voucher_no($conn);
        $jou_text   = DB::escape($conn, lang('@Cancellation of fixed asset registration:') . ' ' . $asset['asset_name']);
        // RETTET (§currency-setting-is-cosmetic-label): journal.currency
        // blev aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
        $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
        DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency)
            VALUES ('$today', '$jou_text', 'fixed_asset_cancellation', $voucher_no, '$jou_currency')");
        $jou_id = DB::insert_id($conn);

        ledger_post($conn, $jou_id, (int)$asset['asset_account_id'], $cost * -1); // KREDIT aktivkonto (fortrydes)
        ledger_post($conn, $jou_id, $acc_bank, $cost);                           // DEBET bank (pengene "kommer tilbage")

        log_action($conn, 'CANCEL_FIXED_ASSET', 'fixed_assets', $id,
            ['status' => 'active'], ['status' => 'cancelled', 'cancel_voucher_no' => $voucher_no]);

        DB::commit($conn);
        header("Location: fixed_asset_list.php?msg=cancelled");
        exit;
    } catch (Exception $e) {
        DB::rollback($conn);
        die(lang('@Error:') . ' ' . $e->getMessage());
    }
}

// Antal HELE måneder mellem to datoer (afrundet ned) - en påbegyndt, endnu
// ikke fuldført måned afskrives ikke endnu, den tælles med ved NÆSTE kørsel.
function fa_whole_months_between(string $from, string $to): int {
    $f = new DateTime($from);
    $t = new DateTime($to);
    if ($t <= $f) return 0;
    $diff = $f->diff($t);
    return $diff->y * 12 + $diff->m;
}

$today = date('Y-m-d');
if (is_date_locked($conn, $today)) {
    die(lang('@Today\'s date is in a locked accounting period and depreciation cannot be posted.'));
}

$res = DB::query($conn, "SELECT * FROM fixed_assets WHERE status = 'active'");
$assets = [];
if ($res) { while ($row = DB::fetch_assoc($res)) { $assets[] = $row; } }

$posted_count = 0;

foreach ($assets as $asset) {
    $depreciable_base = round((float)$asset['acquisition_cost'] - (float)$asset['residual_value'], 2);
    if ($depreciable_base <= 0) continue;

    $monthly_dep = round($depreciable_base / ((int)$asset['useful_life_years'] * 12), 2);
    if ($monthly_dep <= 0) continue;

    $start_from = $asset['last_depreciated_date'] ?: $asset['acquisition_date'];
    $months     = fa_whole_months_between($start_from, $today);
    if ($months <= 0) continue;

    $amount = round($monthly_dep * $months, 2);

    // Aldrig afskriv ud over den afskrivningsbare basis (anskaffelsessum
    // minus scrapværdi) - sidste rate afkortes til reelt at ramme præcis
    // scrapværdien, ikke et par kroner under eller over pga. afrunding.
    $remaining = round($depreciable_base - (float)$asset['accumulated_depreciation'], 2);
    if ($remaining <= 0.01) continue;
    if ($amount > $remaining) $amount = $remaining;
    if ($amount <= 0.01) continue;

    $new_last_date = (new DateTime($start_from))->modify("+$months months")->format('Y-m-d');

    DB::begin_transaction($conn);
    try {
        // Atomisk claim - forhindrer at to samtidige kørsler (fx to åbne
        // faner, eller et dobbeltklik på "Kør afskrivninger") begge poster
        // afskrivning for samme aktiv i samme omgang.
        $claim = DB::prepare_and_execute($conn,
            "UPDATE fixed_assets SET accumulated_depreciation = accumulated_depreciation + ?, last_depreciated_date = ?
             WHERE asset_id = ? AND status = 'active' AND (last_depreciated_date = ? OR (last_depreciated_date IS NULL AND ? IS NULL))",
            [$amount, $new_last_date, $asset['asset_id'], $asset['last_depreciated_date'], $asset['last_depreciated_date']]);
        if (!$claim || DB::affected_rows($conn, $claim) < 1) {
            DB::rollback($conn);
            continue; // en anden, samtidig kørsel nåede allerede dette aktiv
        }

        $voucher_no = next_voucher_no($conn);
        $jou_text   = DB::escape($conn, lang('@Depreciation:') . ' ' . $asset['asset_name']);
        // RETTET (§currency-setting-is-cosmetic-label): journal.currency
        // blev aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
        $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
        DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, proj_id, currency)
            VALUES ('$today', '$jou_text', 'depreciation', $voucher_no, " . ($asset['proj_id'] ? (int)$asset['proj_id'] : 'NULL') . ", '$jou_currency')");
        $jou_id = DB::insert_id($conn);

        ledger_post($conn, $jou_id, (int)$asset['depreciation_account_id'], $amount);       // DEBET afskrivning (udgift)
        ledger_post($conn, $jou_id, (int)$asset['asset_account_id'], $amount * -1);         // KREDIT aktivkonto (bogført værdi falder)

        log_action($conn, 'POST_DEPRECIATION', 'fixed_assets', (int)$asset['asset_id'],
            ['accumulated_depreciation' => (float)$asset['accumulated_depreciation']],
            ['accumulated_depreciation' => (float)$asset['accumulated_depreciation'] + $amount, 'amount' => $amount, 'months' => $months, 'voucher_no' => $voucher_no]);

        DB::commit($conn);
        $posted_count++;
    } catch (Exception $e) {
        DB::rollback($conn);
        // En fejlet postering for ÉT aktiv skal ikke stoppe resten af
        // batch-kørslen for de øvrige aktiver.
        error_log('[fixed_asset_actions] Afskrivning fejlede for asset_id ' . $asset['asset_id'] . ': ' . $e->getMessage());
    }
}

header("Location: fixed_asset_list.php?msg=depreciated&count=$posted_count");
exit;
