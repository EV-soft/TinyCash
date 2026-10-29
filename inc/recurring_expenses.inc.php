<?php # /inc/recurring_expenses.inc.php v:1.3.1 d:2026-09-15 i:evs
# Automatisk generering af udgifter fra faste/gentagne skabeloner
# (recurring_expenses) - udgiftssidens modstykke til
# inc/recurring_invoices.inc.php, samme mønster (§bogholder-behov-gennemgang
# 2026-09-01): tjekkes ved hver sidevisning (htm_Footer()), da der ikke er
# rigtig cron-adgang på almindelig delt hosting, men udfører kun reelt
# arbejde når noget faktisk er forfaldent.
#
# VIGTIGT (bogføringslov/uforanderlighed - endnu strengere begrundet her end
# for fakturaer): denne funktion opretter ALTID en UBOGFØRT udgift
# (voucher_no IS NULL), aldrig en bogført udgift direkte. expense_edit.php's
# NORMALE opret-flow bogfører ganske vist altid med det samme (tildeler
# voucher_no ved selve INSERT'et, se dens egen kommentar) - men en
# automatisk, upasseret genereret udgift har INGEN menneskelig gennemgang
# overhovedet før den ville ramme bogen, i modsætning til selv den simpleste
# manuelt indtastede udgift. At bogføre den blindt ville underminere hele
# pointen med bogføringslovens krav om et reelt kontrolleret posteringsflow.
# Bogholderen finder den ubogførte generede udgift i expense_list.php (som
# allerede korrekt viser ubogførte kladder distinkt, jf. dens egen
# is_posted-logik) og gemmer den igen via expense_edit.php - selve
# genemgemningen tildeler voucher_no automatisk (samme mekanisme som allerede
# gælder for enhver anden ubogført kladde, ingen ny kode nødvendig for det
# trin).
require_once __DIR__ . '/recurring_invoices.inc.php'; // genbruger recurring_next_date()

// Samme mønster som expense_edit.php's egen expense_edit_col_exists() -
// tjekker om en given kolonne på expenses reelt findes (leverandørmodulets
// supplier_id/due_date/paid_date findes kun EFTER db-setup/migrate_suppliers.php
// er kørt).
function recurring_expenses_col_exists($conn, $col) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(expenses)");
        if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === strtolower($col)) return true; } }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE '$col'");
    return ($res && DB::num_rows($res) > 0);
}

function recurring_expenses_check($conn) {
    $today = date('Y-m-d');

    $res = @DB::query($conn, "SELECT recur_id FROM recurring_expenses
                              WHERE is_active = 1 AND next_run_date IS NOT NULL AND next_run_date >= '1990-01-01' AND next_run_date <= '$today'
                              ORDER BY next_run_date ASC");
    if (!$res) return; // tabel findes evt. ikke endnu (migrate_recurring_expenses.php ikke kørt)

    while ($row = DB::fetch_assoc($res)) {
        generate_recurring_expense($conn, (int)$row['recur_id'], true);
    }
}

// Genererer én UBOGFØRT udgift fra en skabelon og fremrykker next_run_date
// med ét interval. Kaldes både fra det automatiske tjek ovenfor ($auto=true)
// og fra "Generér nu"-knappen i recurring_expenses.php ($auto=false). Samme
// kapløbssikring (atomisk compare-and-swap på next_run_date) som
// generate_recurring_invoice() - se dens kommentar for fuld begrundelse.
function generate_recurring_expense($conn, int $recur_id, bool $auto = false): ?int {
    $tmpl = DB::fetch_assoc(DB::query($conn, "SELECT * FROM recurring_expenses WHERE recur_id = $recur_id"));
    if (!$tmpl) return null;

    $today = date('Y-m-d');

    if ($auto) {
        if (empty($tmpl['next_run_date']) || !strtotime((string)$tmpl['next_run_date']) || $tmpl['next_run_date'] < '1990-01-01' || $today < $tmpl['next_run_date']) return null;      // (en ugyldig dato kører aldrig)
        $claim_next = recurring_next_date($tmpl['next_run_date'], $tmpl['interval_type']);
        $old_next   = DB::escape($conn, $tmpl['next_run_date']);
        $claim_sql  = DB::query($conn, "UPDATE recurring_expenses
                                         SET last_run_date = '$today', next_run_date = '$claim_next'
                                         WHERE recur_id = $recur_id AND next_run_date = '$old_next'");
        if (!$claim_sql || DB::affected_rows($conn, $claim_sql) < 1) {
            return null; // en anden, samtidig sidevisning nåede allerede at behandle denne skabelon
        }
    }

    $supplier    = DB::escape($conn, $tmpl['supplier'] ?? '');
    $description = DB::escape($conn, $tmpl['description'] ?? '');
    $account_id  = (int)$tmpl['account_id'];
    $amount      = (float)$tmpl['amount'];
    $exp_type    = ($tmpl['exp_type'] === 'income') ? 'income' : 'expense';
    $proj_sql    = !empty($tmpl['proj_id']) ? (int)$tmpl['proj_id'] : 'NULL';
    $supplier_id_sql = !empty($tmpl['supplier_id']) ? (int)$tmpl['supplier_id'] : 'NULL';
    // Skabelonens EGEN ejer (den der satte den faste udgift op) som "oprettet
    // af" på den genererede udgift - ikke NULL/0 ("Ukendt"), og ikke den der
    // tilfældigvis besøger en side lige nu (auto-tjekket kører for enhver
    // indlogget bruger). Traceable + meningsfuldt for segregation_audit.php:
    // en anden bruger der senere gemmer/bogfører kladden tæller korrekt som
    // en reel adskillelse, en der selv laver begge dele tæller korrekt som
    // ikke.
    $created_by = (int)($tmpl['created_by'] ?? 0);

    $settings = get_settings($conn);
    $currency = DB::escape($conn, $settings['currency'] ?? 'DKK');

    // BEVIDST ingen 'attachment'/'voucher_no' i INSERT'en (begge forbliver
    // NULL) - se filens header. expense_edit.php's egen bilagskrav-validering
    // (§bilagskrav-på-udgifter) tager automatisk over, når bogholderen åbner
    // og gemmer denne kladde, præcis som ved enhver anden ubogført udgift.
    //
    // RETTET (selv-fanget under live-test): supplier_id findes kun EFTER
    // db-setup/migrate_suppliers.php er kørt (samme forbehold expense_edit.php
    // selv håndterer via sin $supplier_module_ready-variabel) - på en
    // installation UDEN leverandørmodulet ville en ubetinget "supplier_id"-
    // kolonne i INSERT'en gøre HELE forespørgslen ugyldig, DB::query() ville
    // returnere false, og funktionen ville stille returnere null uden
    // nogensinde at oprette rækken. Værre end blot manglende data: skemaets
    // next_run_date/last_run_date var allerede fremrykket i claim-trinnet
    // ovenfor, så den tabte kørsel ville aldrig blive forsøgt igen. Bygger nu
    // kolonnelisten dynamisk ud fra hvad der reelt findes, ligesom
    // expense_edit.php selv gør.
    $has_supplier_id = recurring_expenses_col_exists($conn, 'supplier_id');
    $cols = "exp_date, supplier, description, account_id, amount, created_by, proj_id, exp_type, currency";
    $vals = "'$today', '$supplier', '$description', $account_id, $amount, $created_by, $proj_sql, '$exp_type', '$currency'";
    if ($has_supplier_id) {
        $cols = "supplier_id, $cols";
        $vals = "$supplier_id_sql, $vals";
    }
    $ok = DB::query($conn, "INSERT INTO expenses ($cols) VALUES ($vals)");
    if (!$ok) return null;
    $exp_id = DB::insert_id($conn);

    // Samme "spring ikke den planlagte kørsel over ved et tidligt manuelt
    // tryk"-logik som generate_recurring_invoice() - se dens kommentar.
    if (!$auto) {
        if ($today >= $tmpl['next_run_date']) {
            $next_run = recurring_next_date($tmpl['next_run_date'], $tmpl['interval_type']);
            DB::query($conn, "UPDATE recurring_expenses SET last_run_date = '$today', next_run_date = '$next_run' WHERE recur_id = $recur_id");
        } else {
            DB::query($conn, "UPDATE recurring_expenses SET last_run_date = '$today' WHERE recur_id = $recur_id");
        }
    }

    if (function_exists('log_action')) {
        log_action($conn, 'GENERATE_RECURRING_EXPENSE', 'expenses', $exp_id,
            null, ['recur_id' => $recur_id, 'amount' => $amount]);
    }

    return $exp_id;
}
