<?php # /inc/recurring_invoices.inc.php v:1.3.0 d:2026-08-30 i:evs
# Automatisk generering af fakturaer fra faste/gentagne skabeloner
# (recurring_invoices + recurring_invoice_lines). TinyCash har ingen rigtig
# cron-adgang på almindelig delt hosting, så samme løsning som
# inc/auto_backup.inc.php genbruges: tjekkes ved hver sidevisning
# (htm_Footer()), men udfører kun reelt arbejde når noget faktisk er forfaldent.
#
# VIGTIGT (bogføringslov/uforanderlighed): denne funktion opretter ALTID en
# KLADDE, aldrig en bogført faktura direkte - nøjagtig samme princip som
# resten af fakturaflowet (§invoice-flow-integrity). Brugeren skal selv gå ind
# og bogføre (invoice_post_action.php), præcis som ved en manuelt oprettet
# faktura. Der genereres højst ÉN faktura pr. skabelon pr. sidevisning (ikke
# et helt "indhentnings"-baglæns i ét hug, hvis systemet fx ikke har været
# besøgt i flere måneder) - det throttler naturligt til højst én ny kladde
# pr. sidevisning, og indhenter sig selv over de næste sidevisninger i stedet
# for at eksplodere i én omgang.

function recurring_invoices_check($conn) {
    $today = date('Y-m-d');

    $res = DB::query($conn, "SELECT recur_id FROM recurring_invoices
                              WHERE is_active = 1 AND next_run_date IS NOT NULL AND next_run_date <= '$today'
                              ORDER BY next_run_date ASC");
    if (!$res) return;

    while ($row = DB::fetch_assoc($res)) {
        generate_recurring_invoice($conn, (int)$row['recur_id'], true);
    }
}

// Genererer én kladde-faktura fra en skabelon og fremrykker next_run_date med
// ét interval. Kaldes både fra det automatiske tjek ovenfor ($auto=true) og
// fra "Generér nu"-knappen i recurring_invoices.php ($auto=false).
function generate_recurring_invoice($conn, int $recur_id, bool $auto = false): ?int {
    $tmpl = DB::fetch_assoc(DB::query($conn, "SELECT * FROM recurring_invoices WHERE recur_id = $recur_id"));
    if (!$tmpl) return null;

    $today = date('Y-m-d');

    // RETTET (§bugs-batch-19-review): recurring_invoices_check() køres ved
    // HVER sidevisning (htm_Footer(), se filens header) - to næsten-samtidige
    // sidevisninger (fx to faner, eller to brugere online samtidig, mens en
    // skabelon er forfalden) kunne begge bestå det automatiske tjeks SELECT
    // (linje ~22) FØR nogen af dem nåede at behandle den, og dermed begge
    // generere en dublet-kladdefaktura for samme periode. Kun det automatiske
    // tjek ($auto=true) kapløbssikres her - "Generér nu"-knappen skal
    // bevidst kunne kaldes flere gange samme dag (se kommentaren nedenfor om
    // et tidligt manuelt tryk), så den må ikke låses af samme tjek. Bruger en
    // atomisk "compare-and-swap" på next_run_date's PRÆCISE gamle værdi - kun
    // den forespørgsel der reelt vinder kapløbet, får next_run_date != den
    // gamle værdi til at matche 0 rækker for taberen (målt via reelt
    // berørte rækker, DB::affected_rows() - ikke en efterfølgende SELECT,
    // som ville vise vinderens allerede-committede resultat for begge, se
    // [[db_connect.inc.php]]s affected_rows()-kommentar for baggrunden).
    if ($auto) {
        if (empty($tmpl['next_run_date']) || $today < $tmpl['next_run_date']) return null;
        $claim_next = recurring_next_date($tmpl['next_run_date'], $tmpl['interval_type']);
        $old_next   = DB::escape($conn, $tmpl['next_run_date']);
        $claim_sql  = DB::query($conn, "UPDATE recurring_invoices
                                         SET last_run_date = '$today', next_run_date = '$claim_next'
                                         WHERE recur_id = $recur_id AND next_run_date = '$old_next'");
        if (!$claim_sql || DB::affected_rows($conn, $claim_sql) < 1) {
            return null; // en anden, samtidig sidevisning nåede allerede at behandle denne skabelon
        }
    }

    $due_days = (int)($tmpl['inv_due_days'] ?? 8);
    $due_date = date('Y-m-d', strtotime("+$due_days days"));
    $proj_sql = !empty($tmpl['proj_id']) ? (int)$tmpl['proj_id'] : 'NULL';

    $cust_ref = DB::escape($conn, $tmpl['cust_reference'] ?? '');
    $note     = DB::escape($conn, $tmpl['inv_note'] ?? '');
    $deliv    = DB::escape($conn, $tmpl['delivery_address'] ?? '');
    // RETTET: hardkodede 'DKK' ignorerede virksomhedens faktisk konfigurerede
    // basisvaluta (Firmaindstillinger -> Valuta) - en automatisk genereret
    // gentagen faktura ville altid blive tagget som DKK, uanset hvad
    // virksomheden reelt har sat op. Samme fejlklasse som quote_actions.php's
    // konverterings-flow og denne sessions §currency-gainloss-feature-sweep.
    $settings     = get_settings($conn);
    $inv_currency = DB::escape($conn, $settings['currency'] ?? 'DKK');

    $ok = DB::query($conn, "INSERT INTO invoices
        (cust_id, inv_date, inv_due_date, inv_status, cust_reference, inv_note, delivery_address, currency, proj_id)
        VALUES (" . (int)$tmpl['cust_id'] . ", '$today', '$due_date', 'draft', '$cust_ref', '$note', '$deliv', '$inv_currency', $proj_sql)");
    if (!$ok) return null;
    $inv_id = DB::insert_id($conn);

    $lines_res = DB::query($conn, "SELECT * FROM recurring_invoice_lines WHERE recur_id = $recur_id");
    while ($l = DB::fetch_assoc($lines_res)) {
        $txt = DB::escape($conn, $l['line_text']);
        $lproj_sql = !empty($l['proj_id']) ? (int)$l['proj_id'] : $proj_sql;
        DB::query($conn, "INSERT INTO invoice_lines
            (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id)
            VALUES ($inv_id, '$txt', " . (float)$l['quantity'] . ", " . (float)$l['price_each'] . ", "
            . (float)$l['line_vat_rate'] . ", " . (int)$l['prod_id'] . ", $lproj_sql)");
    }

    // RETTET (ALVORLIGT FUND, se [[bugs-batch-10-review]]): recurring_next_date()
    // regner altid videre fra den GAMLE next_run_date, uanset hvornår denne
    // funktion reelt blev kaldt. Det er korrekt og bevidst for den
    // AUTOMATISKE tjek ovenfor (recurring_invoices_check() kalder kun hvis
    // next_run_date <= i dag, så "sent" er den eneste mulighed der - se
    // funktionens egen kommentar om at undgå datodrift). Men "Generér nu"-
    // knappen i recurring_invoices.php kan kaldes NÅR SOM HELST, også LÆNGE
    // før den planlagte dato - og da fremrykkede den ALTID skemaet ét
    // interval frem fra den gamle dato, uanset hvor tidligt den blev trykket.
    // Resultat: et tryk på "Generér nu" 3 måneder før forfald ville SPRINGE
    // den egentlige planlagte faktura for den periode helt over. Fremrykker
    // nu kun skemaet, hvis den faktisk var forfalden (i dag >= next_run_date)
    // - et tidligt manuelt tryk giver nu en ekstra faktura UDEN at forbruge
    // den kommende, planlagte kørsel.
    // ($auto=true har allerede kapløbssikret og fremrykket next_run_date i
    // claim-trinnet ovenfor - gøres den igen her, dobbelt-fremrykkes skemaet
    // ét helt ekstra interval for hver automatisk kørsel.)
    if (!$auto) {
        if ($today >= $tmpl['next_run_date']) {
            $next_run = recurring_next_date($tmpl['next_run_date'], $tmpl['interval_type']);
            DB::query($conn, "UPDATE recurring_invoices SET last_run_date = '$today', next_run_date = '$next_run' WHERE recur_id = $recur_id");
        } else {
            DB::query($conn, "UPDATE recurring_invoices SET last_run_date = '$today' WHERE recur_id = $recur_id");
        }
    }

    if (function_exists('log_action')) {
        log_action($conn, 'GENERATE_RECURRING_INVOICE', 'invoices', $inv_id,
            null, ['recur_id' => $recur_id, 'cust_id' => (int)$tmpl['cust_id']]);
    }

    return $inv_id;
}

// Beregner næste kørselsdato UD FRA den seneste planlagte dato (ikke fra
// "i dag") - undgår datodrift, hvis systemet fx ikke besøges i nogle dage
// efter forfald.
function recurring_next_date(string $from_date, string $interval_type): string {
    switch ($interval_type) {
        case 'quarterly': $step = '+3 months'; break;
        case 'yearly':    $step = '+1 year';   break;
        case 'monthly':
        default:          $step = '+1 month';  break;
    }
    return date('Y-m-d', strtotime($step, strtotime($from_date)));
}
