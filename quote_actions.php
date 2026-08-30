<?php # /quote_actions.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Tilbud/Ordrebekræftelse (bruger-anmodet) - statusskift.
# Tilstandsmaskine: draft -> sent -> accepted/rejected -> (accepted) converted.
# "reopen" giver en fortrydelsesvej tilbage til draft fra sent/rejected (IKKE
# fra accepted/converted - først der er der en reel kunde-beslutning/en
# affødt faktura at tage hensyn til), samme filosofi som fixed_asset_actions.
# php's "Fortryd registrering". Alle overgange bruger en atomisk claim
# (UPDATE ... WHERE status = <forventet gammel status>), samme TOCTOU-mønster
# som resten af appens statusskift, selvom konsekvensen af et sjældent
# dobbeltklik her er langt mindre alvorlig end ved en regnskabspostering.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!in_array($action, ['mark_sent', 'mark_accepted', 'mark_rejected', 'reopen', 'convert'], true) || $id <= 0) {
    header("Location: quote_list.php"); exit;
}

$q = DB::fetch_assoc(DB::query($conn, "SELECT * FROM quotes WHERE quote_id = $id"));
if (!$q) { header("Location: quote_list.php"); exit; }

if ($action === 'mark_sent') {
    $claim = DB::prepare_and_execute($conn, "UPDATE quotes SET status = 'sent' WHERE quote_id = ? AND status = 'draft'", [$id]);
    if ($claim && DB::affected_rows($conn, $claim) > 0) {
        log_action($conn, 'MARK_QUOTE_SENT', 'quotes', $id, ['status' => 'draft'], ['status' => 'sent']);
        header("Location: quote_list.php?msg=sent");
    } else {
        header("Location: quote_list.php?msg=bad_status");
    }
    exit;
}

if ($action === 'mark_accepted' || $action === 'mark_rejected') {
    $new_status = ($action === 'mark_accepted') ? 'accepted' : 'rejected';
    $stamp_col  = ($action === 'mark_accepted') ? 'accepted_at' : 'rejected_at';
    $now        = date('Y-m-d H:i:s');
    // Tilladt fra BÅDE draft og sent - nogle virksomheder springer den
    // formelle "sendt"-status over (kunden accepterer mundtligt/pr. telefon
    // ud fra en kladde der aldrig formelt blev "sendt" i systemet).
    $claim = DB::prepare_and_execute($conn,
        "UPDATE quotes SET status = ?, $stamp_col = ? WHERE quote_id = ? AND status IN ('draft','sent')",
        [$new_status, $now, $id]);
    if ($claim && DB::affected_rows($conn, $claim) > 0) {
        log_action($conn, 'MARK_QUOTE_' . strtoupper($new_status), 'quotes', $id, ['status' => $q['status']], ['status' => $new_status]);
        header("Location: quote_list.php?msg=" . ($new_status === 'accepted' ? 'accepted' : 'rejected'));
    } else {
        header("Location: quote_list.php?msg=bad_status");
    }
    exit;
}

if ($action === 'reopen') {
    $claim = DB::prepare_and_execute($conn, "UPDATE quotes SET status = 'draft' WHERE quote_id = ? AND status IN ('sent','rejected')", [$id]);
    if ($claim && DB::affected_rows($conn, $claim) > 0) {
        log_action($conn, 'REOPEN_QUOTE', 'quotes', $id, ['status' => $q['status']], ['status' => 'draft']);
        header("Location: quote_list.php?msg=reopened");
    } else {
        header("Location: quote_list.php?msg=bad_status");
    }
    exit;
}

if ($action === 'convert') {
    // Atomisk claim FØRST - kun ét konverteringsforsøg må reelt oprette en
    // faktura, uanset dobbeltklik/to samtidige faner (samme TOCTOU-princip
    // som resten af appens statusskift).
    DB::begin_transaction($conn);
    try {
        $claim = DB::prepare_and_execute($conn, "UPDATE quotes SET status = 'converted' WHERE quote_id = ? AND status = 'accepted'", [$id]);
        if (!$claim || DB::affected_rows($conn, $claim) < 1) {
            throw new Exception('bad_status');
        }

        $s = get_settings($conn);
        $module_projects = !empty($s['module_projects']) && $s['module_projects'] == '1';
        $proj_sql = ($module_projects && !empty($q['proj_id'])) ? (int)$q['proj_id'] : 'NULL';
        $due_days = 8; // samme standard som invoice_edit.php's nye-faktura-forvalg
        $inv_date = date('Y-m-d');
        $due_date = date('Y-m-d', strtotime("+$due_days days"));
        $cust_ref = DB::escape($conn, $q['cust_reference'] ?? '');
        $note     = DB::escape($conn, $q['quote_note'] ?? '');
        $deliv    = DB::escape($conn, $q['delivery_address'] ?? '');
        // RETTET: hardkodede 'DKK' herunder ignorerede virksomhedens faktisk
        // konfigurerede basisvaluta (Firmaindstillinger -> Valuta, det samme
        // felt quote_view.php selv læser til at VISE tilbuddets beløb med) -
        // en faktura oprettet herfra ville altså kunne blive tagget forkert
        // som DKK, selvom tilbuddet blev vist og godkendt i en anden valuta.
        // Samme fejlklasse som denne sessions §currency-gainloss-feature-sweep.
        $inv_currency = DB::escape($conn, $s['currency'] ?? 'DKK');

        // Samme princip som recurring_invoices/inc/recurring_invoices.inc.php:
        // opretter ALTID en KLADDE-faktura, aldrig bogført direkte - brugeren
        // skal selv gennemgå og bogføre den via den eksisterende, allerede
        // grundigt testede invoice_edit.php -> invoice_post_action.php-flow.
        $ok = DB::query($conn, "INSERT INTO invoices
            (cust_id, inv_date, inv_due_date, inv_status, cust_reference, inv_note, delivery_address, currency, proj_id)
            VALUES (" . (int)$q['cust_id'] . ", '$inv_date', '$due_date', 'draft', '$cust_ref', '$note', '$deliv', '$inv_currency', $proj_sql)");
        if (!$ok) throw new Exception('Kunne ikke oprette fakturakladde: ' . DB::error($conn));
        $new_inv_id = DB::insert_id($conn);

        $lines_res = DB::query($conn, "SELECT * FROM quote_lines WHERE quote_id = $id");
        while ($l = DB::fetch_assoc($lines_res)) {
            $txt = DB::escape($conn, $l['line_text']);
            $lproj_sql = !empty($l['proj_id']) ? (int)$l['proj_id'] : $proj_sql;
            DB::query($conn, "INSERT INTO invoice_lines
                (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id)
                VALUES ($new_inv_id, '$txt', " . (float)$l['quantity'] . ", " . (float)$l['price_each'] . ", "
                . (float)$l['line_vat_rate'] . ", " . (int)$l['prod_id'] . ", $lproj_sql)");
        }

        DB::query($conn, "UPDATE quotes SET converted_invoice_id = $new_inv_id WHERE quote_id = $id");

        log_action($conn, 'CONVERT_QUOTE_TO_INVOICE', 'quotes', $id, ['status' => 'accepted'],
            ['status' => 'converted', 'invoice_id' => $new_inv_id]);

        DB::commit($conn);
        header("Location: invoice_edit.php?id=$new_inv_id&msg=saved");
    } catch (Exception $e) {
        DB::rollback($conn);
        if ($e->getMessage() === 'bad_status') {
            header("Location: quote_list.php?msg=bad_status");
        } else {
            die(lang('@Error:') . ' ' . $e->getMessage());
        }
    }
    exit;
}
?>
