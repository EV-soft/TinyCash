<?php # /fixed_asset_actions.php v:1.3.1 d:2026-09-15 i:evs
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
require_once 'inc/fixed_assets.inc.php';

$action = $_GET['action'] ?? '';

if (!in_array($action, ['run_depreciation', 'cancel'], true)) {
    redirect("fixed_asset_list.php");
}

// NYT: et fejlregistreret anlægsaktiv (forkert beløb/dato/konto) havde INGEN korrektionsvej overhovedet - hvert
// andet posteringsflow i appen har en (udgifter annulleres med en modpostering, fakturaer krediteres, banklinjer
// kan slettes), men et anlægsaktiv kunne hverken rettes (finansielle felter låses bevidst ved bogføring, samme
// princip som udgifter) eller fjernes - kun "Afhænd" fandtes, som semantisk betyder et REELT salg/kasseret, ikke
// en fortrydelse af en fejltastning. Tilføjet "Fortryd registrering": kun muligt hvis aktivet ALDRIG er afskrevet
// endnu (accumulated_depreciation = 0) og ikke allerede afhændet - altså kun mens den eneste økonomiske hændelse
// er selve anskaffelses-posteringen.
if ($action === 'cancel') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) { redirect("fixed_asset_list.php"); }

    $res   = DB::query($conn, "SELECT * FROM fixed_assets WHERE asset_id = $id");
    $asset = $res ? DB::fetch_assoc($res) : null;
    if (!$asset) redirect('fixed_asset_list.php', '@Not found - it may already have been deleted.', 'error');      // (fik før beskeden "allerede afskrevet")
    if ($asset['status'] !== 'active' || (float)$asset['accumulated_depreciation'] != 0) {
        redirect('fixed_asset_list.php', '@This asset cannot be undone - it has already been depreciated or disposed of. Use Dispose instead.', 'error');
    }

    $today = date('Y-m-d');
    if (is_date_locked($conn, $today)) {
        redirect('fixed_asset_list.php', "@Today's date is in a locked accounting period and this cannot be posted.", 'error');
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
        // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
        $cb = journal_created_by_sql($conn);
        DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
            VALUES ('$today', '$jou_text', 'fixed_asset_cancellation', $voucher_no, '$jou_currency'{$cb['val']})");
        $jou_id = DB::insert_id($conn);

        // Spejler anskaffelsens EGNE linjer (aktiv, evt. købsmoms, bank) - en anskaffelse inkl. moms (fixed_asset_edit.php)
        // har en købsmomslinje, som en fast "aktiv mod bank"-modpostering ville efterlade, og banken fik så for lidt tilbage.
        $acq_lines = [];
        if (!empty($asset['voucher_no'])) {
            $al_res = DB::query($conn, "SELECT l.acc_id, l.amount FROM ledger l JOIN journal j ON j.jou_id = l.jou_id
                WHERE j.voucher_no = " . (int)$asset['voucher_no'] . " AND j.trans_type = 'fixed_asset_acquisition'");
            while ($al_res && ($al = DB::fetch_assoc($al_res))) $acq_lines[] = $al;
        }
        if ($acq_lines) {
            foreach ($acq_lines as $al) ledger_post($conn, $jou_id, (int)$al['acc_id'], -1 * (float)$al['amount'], true);
        } else {
            ledger_post($conn, $jou_id, (int)$asset['asset_account_id'], $cost * -1); // KREDIT aktivkonto (fortrydes)
            ledger_post($conn, $jou_id, $acc_bank, $cost);                           // DEBET bank (pengene "kommer tilbage")
        }

        log_action($conn, 'CANCEL_FIXED_ASSET', 'fixed_assets', $id,
            ['status' => 'active'], ['status' => 'cancelled', 'cancel_voucher_no' => $voucher_no]);

        DB::commit($conn);
        redirect('fixed_asset_list.php', '@Asset registration undone - the acquisition posting has been reversed.');
    } catch (Exception $e) {
        DB::rollback($conn);
        redirect('fixed_asset_list.php', lang('@Error:') . ' ' . htmlspecialchars($e->getMessage()), 'error');
    }
}

// RETTET: fa_whole_months_between flyttet til inc/fixed_assets.inc.php (kræves ovenfor) - genbruges nu også af
// fixed_asset_list.php's nye "skyldig afskrivning"-forhåndsvisning, se den fils egen kommentar for baggrunden.
$today = date('Y-m-d');
if (is_date_locked($conn, $today)) {
    redirect('fixed_asset_list.php', "@Today's date is in a locked accounting period and depreciation cannot be posted.", 'error');
}

$res = DB::query($conn, "SELECT * FROM fixed_assets WHERE status = 'active'");
$assets = [];
if ($res) { while ($row = DB::fetch_assoc($res)) { $assets[] = $row; } }

$posted_count = 0;

foreach ($assets as $asset) {
    $due = fa_calculate_due_depreciation($asset, $today);
    if ($due === null) continue;
    $amount        = $due['amount'];
    $months        = $due['months'];
    $start_from    = $due['start_from'];
    $new_last_date = $due['new_last_date'];

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

        $jou_text   = DB::escape($conn, lang('@Depreciation:') . ' ' . $asset['asset_name']);
        // RETTET (§currency-setting-is-cosmetic-label): journal.currency
        // blev aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
        $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
        // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
        $cb = journal_created_by_sql($conn);

        // RETTET: alle skyldige måneder blev bogført som ÉT beløb med DAGS dato - kørt i januar for
        // oktober-december, landede hele afskrivningen i det nye år (forkert regnskabsår, forkert resultat i begge
        // år). Nu én postering pr. kalenderår, dateret på den sidste afskrevne måned i året.
        $monthly = round(round((float)$asset['acquisition_cost'] - (float)$asset['residual_value'], 2) / ((int)$asset['useful_life_years'] * 12), 2);
        $chunks = [];      // år => ['date' => sidste afskrevne måneds dato, 'months' => n]
        for ($mi = 1; $mi <= $months; $mi++) {
            $md = fa_add_months($start_from, $mi);
            $yk = substr($md, 0, 4);
            $chunks[$yk] = ['date' => $md, 'months' => ($chunks[$yk]['months'] ?? 0) + 1];
        }
        $parts = [];  $carry = 0;  $left = $amount;  $keys = array_keys($chunks);
        foreach ($keys as $ki => $yr) {
            $last = ($ki === count($keys) - 1);
            $n  = $chunks[$yr]['months'] + $carry;
            $dt = min($chunks[$yr]['date'], $today);
            if (is_date_locked($conn, $dt)) {
                if (!$last) { $carry = $n;  continue; }
                $dt = $today;
            }
            $carry = 0;
            $amt = $last ? round($left, 2) : min(round($monthly * $n, 2), round($left, 2));
            if ($amt <= 0.005) continue;
            $left = round($left - $amt, 2);
            $parts[] = [$dt, $amt];
        }
        $vouchers = [];
        foreach ($parts as [$dt, $amt]) {
            $voucher_no = next_voucher_no($conn);
            $vouchers[] = $voucher_no;
            DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, proj_id, currency{$cb['col']})
                VALUES ('" . DB::escape($conn, $dt) . "', '$jou_text', 'depreciation', $voucher_no, " . ($asset['proj_id'] ? (int)$asset['proj_id'] : 'NULL') . ", '$jou_currency'{$cb['val']})");
            $jou_id = DB::insert_id($conn);
            ledger_post($conn, $jou_id, (int)$asset['depreciation_account_id'], $amt);       // DEBET afskrivning (udgift)
            ledger_post($conn, $jou_id, (int)$asset['asset_account_id'], $amt * -1);         // KREDIT aktivkonto (bogført værdi falder)
        }
        $voucher_no = implode(',', $vouchers);

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

redirect('fixed_asset_list.php', $posted_count > 0 ? sprintf(lang('@Depreciation posted for %d asset(s).'), $posted_count) : '@No depreciation was due for any active asset.');
