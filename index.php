<?php # /index.php v:1.3.1 d:2026-09-15 i:evs
# v1.3.0: ALVORLIGT FUND - pengestrøms-grafen ("Financial Performance") viste
# ALTID en kunstig, hårdkodet procentvis opdeling af alt-tids-totalsalget/
# -udgiften, aldrig rigtige periodetal, uanset om databasen indeholdt rigtige
# data. Erstattet med rigtige, dato-afgrænsede summer pr. bucket. Fandt
# samtidig at "NOW"-referencen for begge grafer var hårdkodet til en fast
# dato (2026-06-24) i stedet for den faktiske aktuelle dato, og at salgs-KPI'et
# (og nu også pengestrøms-grafen) inkluderede ubogførte kladdefakturaer som
# rigtigt salg. Alle tre rettet samtidig, fra forslagslisten.
# (Tvinger $is_demo_data til true hvis tabeller er tomme eller tabellerne ikke findes)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header(capt: '@Dashboard Overview', mwidth: 1000);
showMenu();

// --- 1. HÅNDTER VALG AF PERIODE FOR BEGGE GRAFER ---
$months_selected = isset($_POST['period_months']) ? (int)$_POST['period_months'] : 3;
if (!in_array($months_selected, [1, 3, 6, 12])) $months_selected = 3;

$flow_months_selected = isset($_POST['flow_period_months']) ? (int)$_POST['flow_period_months'] : 12;
if (!in_array($flow_months_selected, [3, 6, 12])) $flow_months_selected = 12;

// --- 2. HENT STATISTIK TIL KPI KORT ---
$total_sales = 0.0;
$total_expenses = 0.0;
$is_demo_data = false;

if (isset($conn) && $conn) {
    // RETTET: inkluderede før kladder ('draft') som rigtigt salg - kun
    // 'void' blev udelukket. En ubogført kladde er ikke en reel handel
    // endnu (intet fakturanummer, ingen postering) og skal ikke tælle med
    // i "Total Sales". Fundet ved gennemgangen af pengestrøms-grafen.
    // RETTET: salget var SUM(antal × pris) - uden linjerabat og uden valutakurs (en EUR-faktura talte som kroner),
    // og udgifterne var SUM(expenses.amount) - INKL. moms, inkl. ubogførte kladder og inkl. indtægtsposter
    // (exp_type 'income'). Resultatet sammenlignede altså salg ekskl. moms med udgifter inkl. moms. Begge tal kommer
    // nu fra hovedbogen (indtægts- og udgiftskonti, uden årsafslutningens lukkepostering) - samme tal som
    // resultatopgørelsen.
    $sales_query = "SELECT SUM(-l.amount) FROM ledger l JOIN journal j ON j.jou_id = l.jou_id JOIN accounts a ON a.acc_id = l.acc_id
                    WHERE a.acc_type = 'income' AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')";
    $res = @DB::query($conn, $sales_query);
    if ($res) {
        $row = DB::fetch_row($res);
        if ($row && isset($row[0]) && $row[0] !== null) {
            $total_sales = (float)$row[0];
        }
    }

    $expenses_query = "SELECT SUM(l.amount) FROM ledger l JOIN journal j ON j.jou_id = l.jou_id JOIN accounts a ON a.acc_id = l.acc_id
                       WHERE a.acc_type = 'expense' AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')";
    $res2 = @DB::query($conn, $expenses_query);
    if ($res2) {
        $row2 = DB::fetch_row($res2);
        if ($row2 && isset($row2[0]) && $row2[0] !== null) {
            $total_expenses = (float)$row2[0];
        }
    }
}

// Vis demo hvis ingen reel omsætning (under 100 kr. total)
if ($total_sales < 100.0) {
    $total_sales = 45000.00;
    $total_expenses = 18500.00;
    $is_demo_data = true;
}

$net_profit = $total_sales - $total_expenses;
$profit_color = $net_profit >= 0 ? 'var(--theme-success, #2ecc71)' : 'var(--theme-danger, #e74c3c)';

// --- 2b. "MINE OPGAVER" - hvad venter på bogholderen lige nu ---
// NY WIDGET (bruger-anmodet, fra §revisor/bogholder-behov-gennemgangen):
// forsiden viste FØR udelukkende bagudskuende grafer (saldo-udvikling/
// pengestrøm) - intet sted samlede den de ting der reelt venter på at blive
// gjort. Al data herunder findes allerede andre steder i systemet
// (sales_hub.php/expense_list.php/reconcile_list.php/reminders.php) - denne
// widget samler blot de fire tælletal ét sted med direkte links, i stedet
// for at bogholderen selv skal huske at tjekke fire forskellige sider hver
// dag. Genbruger BEVIDST samme WHERE-formel som reminders.php for "forfalden
// faktura" (inv_status='sent' AND forfaldsdato overskredet AND stadig
// udestående efter fratræk af invoice_payments), så tallet her aldrig kan
// afvige fra hvad reminders.php selv ville vise.
$todo_draft_invoices = 0;
$todo_draft_expenses = 0;
$todo_unreconciled_bank = 0;
$todo_overdue_invoices = 0;
// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): widgeten var
// blevet forældet af to funktioner bygget senere samme session
// ([[bookkeeper-wishlist-round2]]'s bulk "Betal nu", [[bookkeeper-wishlist-
// round3]]'s tilbud-opfølgning) - begge datakilder fandtes allerede, men
// var aldrig koblet på forsiden.
$todo_bills_due_soon    = 0;
$todo_quotes_followup   = 0;
// RETTET (bruger-anmodet, "kan bogholderen ønske sig mere", 3. runde):
// products.prod_min_stock findes allerede (udfyldes på product_edit.php),
// men blev reelt ALDRIG brugt nogen steder i appen - end ikke på
// inventory_status.php selv, se dens egen rettelse samme runde. Samme
// "data findes, men er aldrig koblet på noget synligt"-mønster som de to
// tællere ovenfor.
$todo_low_stock = 0;
$todo_missing_email = 0;

if (isset($conn) && $conn) {
    $r = DB::fetch_row(@DB::query($conn, "SELECT COUNT(*) FROM invoices WHERE LOWER(inv_status) = 'draft'"));
    $todo_draft_invoices = $r ? (int)$r[0] : 0;

    $r = DB::fetch_row(@DB::query($conn, "SELECT COUNT(*) FROM expenses WHERE voucher_no IS NULL AND is_cancelled = 0"));
    $todo_draft_expenses = $r ? (int)$r[0] : 0;

    // Leverandørmodul/bank_statement_temp findes ikke nødvendigvis på en
    // frisk/ikke-migreret installation - degraderer stille til 0, samme
    // mønster som aging_report.php's kreditor-sektion.
    $r = @DB::query($conn, "SELECT COUNT(*) FROM bank_statement_temp WHERE is_processed = 0");
    $r = $r ? DB::fetch_row($r) : null;
    $todo_unreconciled_bank = $r ? (int)$r[0] : 0;

    $today_iso = date('Y-m-d');
    $idx_disc = invoice_discount_sql($conn);
    $ov_res = @DB::query($conn, "SELECT
            (SELECT COALESCE(SUM(quantity * price_each $idx_disc * (100 + line_vat_rate) / 100.0), 0)
             FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . " AS total,
            (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
        FROM invoices i
        WHERE i.inv_status = 'sent' AND i.inv_due_date IS NOT NULL AND i.inv_due_date < '$today_iso'");
    if ($ov_res) {
        while ($ov_row = DB::fetch_assoc($ov_res)) {
            if ((float)$ov_row['total'] - (float)$ov_row['paid'] > 0.01) $todo_overdue_invoices++;
        }
    }

    // "Udgifter der forfalder snart" - samme kriterium som expense_list.php's
    // "Betal nu"-knap ($is_payable), afgrænset til de næste 7 dage (inkl.
    // allerede overskredne) - matcher quote_followup.php's egen 7-dages-
    // horisont for den anden nye tæller nedenfor. Leverandørmodulet findes
    // ikke nødvendigvis endnu - stille degradering til 0, samme mønster som
    // bank_statement_temp-tjekket ovenfor.
    $due_soon_cutoff = date('Y-m-d', strtotime('+7 days'));
    $r = @DB::query($conn, "SELECT COUNT(*) FROM expenses
        WHERE is_cancelled = 0 AND voucher_no IS NOT NULL AND paid_date IS NULL
          AND due_date IS NOT NULL AND due_date <= '$due_soon_cutoff'");
    $r = $r ? DB::fetch_row($r) : null;
    $todo_bills_due_soon = $r ? (int)$r[0] : 0;

    // "Tilbud der kræver opfølgning" - nøjagtig samme kriterium som
    // quote_followup.php's egen liste (status='sent', valid_until sat og
    // inden for 7 dage, kunden har en mailadresse) - tallet her må aldrig
    // vise flere end der reelt er noget at handle på, hvis man klikker
    // igennem. quotes-tabellen findes ikke nødvendigvis endnu - stille
    // degradering til 0.
    $r = @DB::query($conn, "SELECT COUNT(*) FROM quotes q JOIN customers c ON q.cust_id = c.cust_id
        WHERE q.status = 'sent' AND q.valid_until IS NOT NULL AND q.valid_until <= '$due_soon_cutoff'
          AND c.cust_email IS NOT NULL AND c.cust_email != ''");
    $r = $r ? DB::fetch_row($r) : null;
    $todo_quotes_followup = $r ? (int)$r[0] : 0;

    // "Lavt lager" - samme sammenligning (stock <= min_stock, fallback 5)
    // som inventory_status.php's nye advarselsvisning bruger. products-
    // tabellen findes altid (kernetabel, ikke et valgfrit modul), så intet
    // stille-degraderings-tjek nødvendigt her.
    $r = @DB::query($conn, "SELECT COUNT(*) FROM products WHERE prod_stock <= COALESCE(prod_min_stock, 5)");
    $r = $r ? DB::fetch_row($r) : null;
    $todo_low_stock = $r ? (int)$r[0] : 0;

    // "Kunder uden mailadresse" - forklarer stille-springes-over-adfærden i
    // reminders.php/customer_statement_send.php/quote_followup.php, se
    // sales_hub.php's samme rettelse for den fulde begrundelse. Ingen
    // LIMIT her (i modsætning til sales_hub.php's egen 100-rækkers-visning)
    // - tælleren skal vise det REELLE antal på tværs af hele kundekartoteket.
    $r = @DB::query($conn, "SELECT COUNT(*) FROM customers WHERE (cust_email IS NULL OR cust_email = '') AND " . customers_active_filter($conn));      // RETTET: kunder i papirkurven talte med
    $r = $r ? DB::fetch_row($r) : null;
    $todo_missing_email = $r ? (int)$r[0] : 0;

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 25): et
    // bogholder-indtastet "næste momsfrist"-felt (company_settings.php) -
    // ikke en selv-udregnet SKAT-formel (se dens egen kommentar for hvorfor).
    // Tælles med her når fristen er inden for 14 dage ELLER allerede
    // overskredet - forbliver bevidst "1" indtil bogholderen selv opdaterer
    // datoen, samme "reminder der ikke forsvinder af sig selv"-idé som PSD2-
    // udløbsadvarslen fra runde 23.
    $todo_vat_deadline = 0;
    $vat_deadline_raw = $global_settings['next_vat_deadline'] ?? '';
    if (!empty($vat_deadline_raw)) {
        $vat_days_left = days_between(date('Y-m-d'), $vat_deadline_raw);
        if ($vat_days_left <= 14) $todo_vat_deadline = 1;
    }

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
    // budget-vs-faktisk (§bookkeeper-wishlist-round21) fandtes hidtil KUN på
    // report_income.php - krævede at man aktivt gik ind og valgte året for
    // overhovedet at opdage et overforbrug. Tæller nu proaktivt, hvor mange
    // udgiftskonti der allerede har overskredet DERES EGET årsbudget for
    // indeværende år - samme forespørgsler (ledger+journal, acc_type=
    // 'expense', årsafgrænset) som report_income.php's $res_costs, for at
    // undgå at de to nogensinde kan give forskelligt svar på "er vi over
    // budget". Stille degraderet til 0 hvis account_budgets ikke findes
    // endnu (samme mønster som report_income.php).
    $todo_over_budget = 0;
    $has_budget_table_idx = false;
    if (DB::is_sqlite()) {
        $btchk_idx = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='account_budgets'");
        $has_budget_table_idx = ($btchk_idx && DB::fetch_assoc($btchk_idx));
    } else {
        $btchk_idx = DB::query($conn, "SHOW TABLES LIKE 'account_budgets'");
        $has_budget_table_idx = ($btchk_idx && DB::num_rows($btchk_idx) > 0);
    }
    if ($has_budget_table_idx) {
        $cur_year_idx = (int)date('Y');
        $bud_res_idx = @DB::query($conn, "SELECT acc_id, budget_amount FROM account_budgets WHERE year = $cur_year_idx AND budget_amount > 0");
        $budgets_idx = [];
        if ($bud_res_idx) { while ($b = DB::fetch_assoc($bud_res_idx)) { $budgets_idx[(int)$b['acc_id']] = (float)$b['budget_amount']; } }
        if (!empty($budgets_idx)) {
            $actual_res_idx = @DB::query($conn, "
                SELECT a.acc_id, SUM(l.amount) AS spent
                FROM ledger l JOIN journal j ON l.jou_id = j.jou_id JOIN accounts a ON l.acc_id = a.acc_id
                WHERE a.acc_type = 'expense' AND j.jou_date BETWEEN '$cur_year_idx-01-01' AND '$cur_year_idx-12-31'
                GROUP BY a.acc_id");
            if ($actual_res_idx) {
                while ($ar = DB::fetch_assoc($actual_res_idx)) {
                    $acc_id_idx = (int)$ar['acc_id'];
                    if (isset($budgets_idx[$acc_id_idx]) && (float)$ar['spent'] > $budgets_idx[$acc_id_idx]) $todo_over_budget++;
                }
            }
        }
    }

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 29):
    // gentagne fakturaer/udgifter kører kun når NOGEN rent faktisk besøger
    // en side (hooket ind i htm_Footer(), se inc/recurring_invoices.inc.php's
    // egen kommentar - "TinyCash har ingen rigtig cron-adgang på almindelig
    // delt hosting"). Genererer desuden bevidst kun ÉN kladde pr. skabelon
    // pr. sidevisning (indhenter sig selv gradvist, ikke i ét hug) - hvis
    // ingen har besøgt systemet i en periode, kan next_run_date derfor falde
    // markant bagud UDEN at nogen opdager det. Tæller skabeloner der er mere
    // end 7 dage forfaldne (uafhængigt af interval_type - selv en årlig
    // skabelon der er 7+ dage forfalden er værd at kigge på, da det typisk
    // betyder systemet slet ikke er besøgt i en periode, ikke en fejl i
    // selve intervalberegningen).
    $todo_recur_overdue = 0;
    $recur_cutoff = date('Y-m-d', strtotime('-7 days'));
    $ri_res = @DB::query($conn, "SELECT COUNT(*) FROM recurring_invoices WHERE is_active = 1 AND next_run_date IS NOT NULL AND next_run_date <= '$recur_cutoff'");
    $ri_res = $ri_res ? DB::fetch_row($ri_res) : null;
    $todo_recur_overdue += $ri_res ? (int)$ri_res[0] : 0;
    $re_res = @DB::query($conn, "SELECT COUNT(*) FROM recurring_expenses WHERE is_active = 1 AND next_run_date IS NOT NULL AND next_run_date <= '$recur_cutoff'");
    $re_res = $re_res ? DB::fetch_row($re_res) : null;
    $todo_recur_overdue += $re_res ? (int)$re_res[0] : 0;
}
$todo_total = $todo_draft_invoices + $todo_draft_expenses + $todo_unreconciled_bank + $todo_overdue_invoices
    + $todo_bills_due_soon + $todo_quotes_followup + $todo_low_stock + $todo_missing_email + $todo_vat_deadline + $todo_over_budget + $todo_recur_overdue;

// --- 3. HENT DATA TIL GRAF 1 (SALDO-KURVE) ---
$chart_dates = [];
$chart_balance = [];

if (isset($conn) && $conn && !$is_demo_data) {
    // RETTET: "NOW" var hårdkodet til en fast dato (2026-06-24) i stedet for
    // at bruge den faktiske aktuelle dato - grafens "sidste N måneder" endte
    // derfor et stykke i fortiden i stedet for i dag, uanset hvornår siden
    // reelt blev besøgt. Fundet ved gennemgangen af pengestrøms-grafen.
    $target_date = date('Y-m-d', strtotime("-$months_selected months"));
    // RETTET: kurven summerede de IMPORTEREDE banklinjer (bank_statement_temp) fra 0 - den startede altid i 0 i
    // stedet for den faktiske saldo, viste fx et minus selvom banken stod i plus, talte ubehandlede og allerede
    // afstemte linjer ens, og en bankpostering uden import (manuel betaling, udgift betalt fra banken) kom slet
    // ikke med. Nu den bogførte saldo på bank- / kassekontiene (hovedbogen): primosaldo før perioden + dagens bevægelser.
    $s_bank   = get_settings($conn);
    $bank_ids = [(int)($s_bank['conf_acc_bank'] ?? 5000)];
    $res_b = DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_type = 'bank'");
    while ($res_b && ($rb = DB::fetch_row($res_b))) $bank_ids[] = (int)$rb[0];
    $bank_in  = implode(',', array_unique(array_filter($bank_ids)));
    $td_esc   = DB::escape($conn, $target_date);
    $open_row = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(l.amount), 0) FROM ledger l JOIN journal j ON j.jou_id = l.jou_id
                                                WHERE l.acc_id IN ($bank_in) AND j.jou_date < '$td_esc'"));
    $running_balance = round((float)($open_row[0] ?? 0), 2);
    $res3 = @DB::query($conn, "SELECT j.jou_date AS d, SUM(l.amount) AS amt FROM ledger l JOIN journal j ON j.jou_id = l.jou_id
                               WHERE l.acc_id IN ($bank_in) AND j.jou_date >= '$td_esc' GROUP BY j.jou_date ORDER BY j.jou_date ASC");
    if ($res3 && DB::num_rows($res3) > 0) {
        $chart_dates[]   = date("d. M", strtotime($target_date));
        $chart_balance[] = $running_balance;
        while ($row3 = DB::fetch_assoc($res3)) {
            $running_balance = round($running_balance + (float)$row3['amt'], 2);
            $chart_dates[] = date("d. M", strtotime($row3['d']));
            $chart_balance[] = $running_balance;
        }
    } elseif (abs($running_balance) >= 0.005) {
        // ingen bevægelser i perioden: en flad linje med saldoen (ikke opdigtede tal)
        $chart_dates     = [date("d. M", strtotime($target_date)), date("d. M")];
        $chart_balance   = [$running_balance, $running_balance];
    }
}

if (empty($chart_dates)) {
    $is_demo_data = true; // Ingen banktransaktioner — vis demo-info
    $start_ts = strtotime("-$months_selected months");
    $end_ts = time();
    $step = ($end_ts - $start_ts) / 9;

    $fake_trends = [
        1  => [25000, 28000, 24000, 31000, 29000, 35000, 32000, 41000, 39000, 45000],
        3  => [15000, 19000, 12000, 22000, 18000, 29000, 24000, 34000, 31000, 38500],
        6  => [10000, 14000, 9000,  18000, 15000, 24000, 21000, 31000, 28000, 42000],
        12 => [5000,  12000, 8000,  19000, 14000, 26000, 22000, 35000, 31000, 55000]
    ];

    for ($i = 0; $i < 10; $i++) {
        $chart_dates[] = date("d. M", $start_ts + ($i * $step));
        $chart_balance[] = $fake_trends[$months_selected][$i];
    }
}

// --- 4. HENT DATA TIL GRAF 2 (PENGESTRØM) - RIGTIGE PERIODE-TAL ---
// RETTET: viste FØR altid en kunstig procentvis opdeling af den ALT-TIDS
// totalsalg/-udgift (fx "salesBase * 0.2, * 0.3, * 0.25, * 0.25" for
// kvartaler) - simulerede tal, aldrig hvad der reelt skete i den valgte
// periode, uanset om databasen indeholdt rigtige data eller ej. Erstattet
// med rigtige, dato-afgrænsede summer pr. bucket. Samme "ekskludér kladder"-
// rettelse som KPI-kortet ovenfor er anvendt her.
$flow_labels = [];
$flow_sales = [];
$flow_expenses = [];

if (isset($conn) && $conn) {
    $num_buckets  = ($flow_months_selected == 12) ? 4 : 3;
    $span_months  = intdiv($flow_months_selected, $num_buckets);

    // Bucket-grænser, ældste til nyeste - grænse[0] = periodens start,
    // grænse[num_buckets] = i morgen (så dagens dato altid er med i sidste
    // bucket, uden overlap ved bucket-skel).
    $boundaries = [];
    for ($k = 0; $k <= $num_buckets; $k++) {
        $boundaries[$k] = ($k == $num_buckets)
            ? date('Y-m-d', strtotime('+1 day'))
            : date('Y-m-d', strtotime('-' . ($flow_months_selected - $k * $span_months) . ' months'));
    }

    for ($i = 0; $i < $num_buckets; $i++) {
        $from = $boundaries[$i];
        $to   = $boundaries[$i + 1];

        $label_start = date('M', strtotime($from));
        $label_end   = date('M', strtotime($to . ' -1 day'));
        $flow_labels[] = ($label_start === $label_end) ? $label_start : "$label_start-$label_end";

        // RETTET (selv-fanget, runde 24 - samme fejlklasse som §bookkeeper-
        // wishlist-round23's rabat-gennemgang overså her): summerede FØR
        // linjernes rå quantity*price_each direkte, uden hverken
        // invoice_discount_sql()'s rabatfaktor eller exch_rate-korrektionen
        // - en rabatteret eller fremmedvaluta-faktura viste et for højt
        // salgstal i netop dette diagram. Ekskl. moms er bevidst bevaret
        // (samme semantik som før - dette er et netto-salgs-diagram, ikke
        // en inkl.-moms-opgørelse).
        $sales_row = DB::fetch_row(@DB::query($conn,
            "SELECT SUM((SELECT COALESCE(SUM(quantity * price_each $idx_disc), 0) FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1))
             FROM invoices i
             WHERE LOWER(i.inv_status) NOT IN ('void', 'draft') AND i.inv_date >= '$from' AND i.inv_date < '$to'"));
        $flow_sales[] = ($sales_row && $sales_row[0] !== null) ? (float)$sales_row[0] : 0.0;

        // RETTET: udgifterne var bilagenes BRUTTObeløb (inkl. moms) - mod en omsætning EKSKL. moms - og talte også
        // ubogførte kladder og indtægtsbilag (exp_type 'income') med som omkostninger. Nu det bogførte på
        // udgiftskontiene i perioden (netto, inkl. afskrivninger), uden årsafslutningens nulstilling.
        $exp_row = DB::fetch_row(@DB::query($conn,
            "SELECT SUM(l.amount) FROM ledger l JOIN journal j ON j.jou_id = l.jou_id JOIN accounts a ON a.acc_id = l.acc_id
             WHERE a.acc_type = 'expense' AND j.jou_date >= '$from' AND j.jou_date < '$to'
               AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')"));
        $flow_expenses[] = ($exp_row && $exp_row[0] !== null) ? (float)$exp_row[0] : 0.0;
    }
}

// Samme demo-fallback-princip som graf 1 - kun når der reelt ingen data er
// (ikke bare fordi $is_demo_data blev sat af graf 1's egen, uafhængige tjek).
$flow_is_demo = (array_sum($flow_sales) < 1.0 && array_sum($flow_expenses) < 1.0);
if ($flow_is_demo) {
    $demo_flow_trends = [
        3  => [0.3, 0.4, 0.3],
        6  => [0.35, 0.3, 0.35],
        12 => [0.2, 0.3, 0.25, 0.25],
    ];
    $weights = $demo_flow_trends[$flow_months_selected] ?? $demo_flow_trends[3];
    $demo_sales_base = 45000.00;
    $demo_expense_base = 18500.00;
    foreach ($weights as $i => $w) {
        $flow_sales[$i] = round($demo_sales_base * $w, 2);
        $flow_expenses[$i] = round($demo_expense_base * $w, 2);
    }
}

// --- 5. HENT DATA TIL GRAF 3 (LIKVIDITETSPROGNOSE, FREMADSKUENDE) ---
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 24): graf 2
// herover er udelukkende HISTORISK (allerede bogførte, forgangne perioder) -
// der fandtes ingen fremadskuende prognose noget sted i systemet. Bruger
// PRÆCIS samme åbne-faktura- og ubetalt-udgift-forespørgsler som
// aging_report.php/subledger_reconciliation.php (samme etablerede,
// allerede-reviderede beløbslogik, inkl. invoice_discount_sql()/exch_rate) -
// to steder der begge viser "hvad der skyldes" må aldrig kunne regne det ud
// forskelligt. Kreditorsiden degraderer stille (tom outgoing-serie) hvis
// leverandørmodulets due_date-kolonne ikke er migreret endnu, samme mønster.
$fc_labels   = [lang('@Overdue'), lang('@This week'), lang('@Week 2'), lang('@Week 3'), lang('@Week 4'), lang('@Later')];
$fc_incoming = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
$fc_outgoing = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];

if (isset($conn) && $conn) {
    $fc_today = date('Y-m-d');
    $fc_bounds = [
        date('Y-m-d', strtotime('+7 days')),
        date('Y-m-d', strtotime('+14 days')),
        date('Y-m-d', strtotime('+21 days')),
        date('Y-m-d', strtotime('+28 days')),
    ];
    $fc_bucket_of = function($due) use ($fc_today, $fc_bounds) {
        if ($due < $fc_today) return 0;
        if ($due < $fc_bounds[0]) return 1;
        if ($due < $fc_bounds[1]) return 2;
        if ($due < $fc_bounds[2]) return 3;
        if ($due < $fc_bounds[3]) return 4;
        return 5;
    };

    $fc_disc = invoice_discount_sql($conn);
    $fc_i_res = DB::query($conn, "SELECT i.inv_due_date,
            (SELECT COALESCE(SUM(quantity * price_each $fc_disc * (100 + line_vat_rate) / 100.0), 0)
             FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) + " . invoice_credited_sql($conn) . " AS total,
            (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE inv_id = i.inv_id) AS paid
        FROM invoices i
        WHERE LOWER(i.inv_status) = 'sent' AND i.inv_due_date IS NOT NULL");
    if ($fc_i_res) {
        while ($fc_row = DB::fetch_assoc($fc_i_res)) {
            $fc_due = (float)$fc_row['total'] - (float)$fc_row['paid'];
            if ($fc_due > 0.01) $fc_incoming[$fc_bucket_of($fc_row['inv_due_date'])] += $fc_due;
        }
    }

    // RETTET: kun BOGFØRTE udgifter (voucher_no) er en skyld til leverandøren - en kladde med forfaldsdato talte med (som "Mine opgaver" allerede tjekker)
    $fc_e_res = @DB::query($conn, "SELECT due_date, amount FROM expenses
        WHERE is_cancelled = 0 AND voucher_no IS NOT NULL AND (exp_type IS NULL OR exp_type != 'income') AND due_date IS NOT NULL AND paid_date IS NULL");
    if ($fc_e_res) {
        while ($fc_row = DB::fetch_assoc($fc_e_res)) {
            $fc_outgoing[$fc_bucket_of($fc_row['due_date'])] += (float)$fc_row['amount'];
        }
    }
}
$fc_has_data = (array_sum($fc_incoming) > 0.01 || array_sum($fc_outgoing) > 0.01);
?>

<style>
    .kpi-card { background: var(--theme-card-bg, #ffffff); color: var(--theme-text, #2c3e50); }
    .kpi-title { color: var(--theme-text-muted, #7f8c8d); }
    .period-select { border: 1px solid var(--theme-border, #ced4da); background: var(--theme-field-bg, #ffffff); color: var(--theme-text, #495057); }

    [data-theme="dark"] .kpi-card { background: #1e272e !important; color: #f5f6fa !important; box-shadow: 0 4px 6px rgba(0,0,0,0.3) !important; }
    [data-theme="dark"] .kpi-title { color: #a4b0be !important; }
    [data-theme="dark"] .period-select { background: #2f3542 !important; color: #f5f6fa !important; border-color: #57606f !important; }

    [data-theme="dark"] [class*="card"], [data-theme="dark"] [class*="panel"],
    [data-theme="dark"] .card-header, [data-theme="dark"] .panel-heading, [data-theme="dark"] .card-title,
    [data-theme="dark"] h1, [data-theme="dark"] h2, [data-theme="dark"] h3, [data-theme="dark"] h4 {
        color: #ffffff !important;
    }
</style>

<?php
// RETTET (bruger-anmodet): det fælles banner herover er fjernet - samme
// besked vises nu direkte på begge udviklingsgrafer længere nede (se
// [[topnav-backup-warning-overlap]]s opfølgning), hvor øjet rent faktisk
// er når de opdigtede tal skal fortolkes. At vise den BÅDE her øverst OG
// nede ved graferne var overflødigt dobbelt op.

// "Mine opgaver" - placeret FØR KPI-tallene, da handlinger der venter er
// mere presserende end historiske nøgletal for den daglige bogholder-brug.
$todo_items = [
    ['count' => $todo_draft_invoices,   'label' => '@Draft Invoices',          'link' => 'sales_hub.php',    'icon' => '📄'],
    ['count' => $todo_draft_expenses,   'label' => '@Draft Expenses',          'link' => 'expense_list.php', 'icon' => '📥'],
    ['count' => $todo_unreconciled_bank,'label' => '@Unreconciled Bank Lines', 'link' => 'reconcile_list.php','icon' => '🏦'],
    ['count' => $todo_overdue_invoices, 'label' => '@Overdue Invoices',        'link' => 'reminders.php',    'icon' => '⏰'],
    ['count' => $todo_bills_due_soon,   'label' => '@Bills Due Soon',          'link' => 'expense_list.php', 'icon' => '💸'],
    ['count' => $todo_quotes_followup,  'label' => '@Quotes Needing Follow-up','link' => 'quote_followup.php','icon' => '📨'],
    ['count' => $todo_low_stock,        'label' => '@Low Stock Products',      'link' => 'inventory_status.php','icon' => '📦'],
    ['count' => $todo_missing_email,    'label' => '@Customers Missing Email', 'link' => 'sales_hub.php#c_card','icon' => '✉️'],
    ['count' => $todo_vat_deadline,     'label' => '@VAT Deadline Approaching','link' => 'vat_report.php',       'icon' => '📅'],
    ['count' => $todo_over_budget,      'label' => '@Accounts Over Budget',    'link' => 'report_income.php?year=' . date('Y'), 'icon' => '📈'],
    ['count' => $todo_recur_overdue,    'label' => '@Recurring Templates Overdue', 'link' => 'recurring_invoices.php', 'icon' => '🔁'],
];
?>
<div style="width: 1000px; max-width: 100%; box-sizing: border-box; margin: 0 auto 20px auto;">
    <div class="kpi-card" style="padding: 16px 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold; margin-bottom: 12px;"><?php echo lang('@My Tasks'); ?></div>
        <?php if ($todo_total === 0): ?>
            <div style="color: var(--theme-success, #2ecc71); font-weight: bold;">✅ <?php echo lang('@Everything is up to date!'); ?></div>
        <?php else: ?>
            <?php /* RETTET (bruger-anmodet, "kan bogholderen ønske sig mere"): var
                     hårdkodet til nøjagtig 4 kolonner - passede kun så længe
                     $todo_items altid havde præcis 4 elementer. To nye tællere
                     tilføjet samme runde (i alt 6) ville have efterladt et
                     skævt 4+2-layout med tomme huller i anden række. auto-fit/
                     minmax lader gridden selv finde det rigtige antal kolonner,
                     uanset hvor mange opgaver widgeten fremover viser. */ ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px;">
                <?php foreach ($todo_items as $ti):
                    $has = $ti['count'] > 0;
                    $color = $has ? 'var(--theme-danger, #e74c3c)' : 'var(--theme-text-muted, #7f8c8d)';
                ?>
                <a href="<?php echo htmlspecialchars($ti['link']); ?>" style="text-decoration:none; color:inherit; display:block; padding:10px; border-radius:4px; background: var(--theme-todo-bg, rgba(0,0,0,0.02));">
                    <div style="font-size: 22px; font-weight: bold; color: <?php echo $color; ?>;"><?php echo $ti['icon']; ?> <?php echo $ti['count']; ?></div>
                    <div style="font-size: 12px; color: var(--theme-text-muted, #7f8c8d); margin-top: 3px;"><?php echo lang($ti['label']); ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; width: 1000px; max-width: 100%; box-sizing: border-box; margin: 0 auto 20px auto;">
    <div class="kpi-card" style="padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 8px dotted #2ecc71;">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold;"><?php echo lang('@Total Sales'); ?></div>
        <div style="font-size: 24px; font-weight: bold; margin-top: 5px;"><?php echo number_format($total_sales, 2, ',', '.'); ?> kr.</div>
    </div>
    <div class="kpi-card" style="padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 8px dotted #e74c3c;">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold;"><?php echo lang('@Total Expenses'); ?></div>
        <div style="font-size: 24px; font-weight: bold; margin-top: 5px;"><?php echo number_format($total_expenses, 2, ',', '.'); ?> kr.</div>
    </div>
    <div class="kpi-card" style="padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 8px dotted <?php echo $profit_color; ?>;">
        <div class="kpi-title" style="font-size: 12px; text-transform: uppercase; font-weight: bold;"><?php echo lang('@Net Profit / Balance'); ?></div>
        <div style="font-size: 24px; font-weight: bold; color: <?php echo $profit_color; ?>; margin-top: 5px;"><?php echo number_format($net_profit, 2, ',', '.'); ?> kr.</div>
    </div>
</div>

<?php
$s = (isset($conn) && $conn) ? get_settings($conn) : [];
$db_date_format = (!empty($s['date_format'])) ? $s['date_format'] : "d.m.Y";

$date_start_balance = date($db_date_format, strtotime("-$months_selected months"));
$date_start_flow = date($db_date_format, strtotime("-$flow_months_selected months"));

$hint_raw = "@The baseline is NOW. The chart displays the period from %s up until NOW.";
$hint_text_balance = sprintf(lang($hint_raw), $date_start_balance);
$hint_text_flow = sprintf(lang($hint_raw), $date_start_flow);

function optiRow($var, $nbr = 1, $period = 'month') {
    $label_key = ($nbr == 1) ? "@{$nbr} {$period}" : "@{$nbr} " . $period . "s";
    return '<option value="' . $nbr . '" ' . ($var == $nbr ? 'selected' : '') . '>' . lang($label_key) . '</option>';
}

$period_selector_balance = '
<form method="post" style="margin: 0; display: inline-block;">
    '.csrf_field(false).'
    <select name="period_months" onchange="this.form.submit();" data-hint="'.htmlspecialchars($hint_text_balance, ENT_QUOTES).'" class="period-select" style="padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold;">
        ' . implode('', array_map(fn($n) => optiRow($months_selected, $n), [1, 3, 6, 12])) . '
    </select>
    <input type="hidden" name="flow_period_months" value="'.$flow_months_selected.'">
</form>';

$period_selector_flow = '
<form method="post" style="margin: 0; display: inline-block;">
    '.csrf_field(false).'
    <select name="flow_period_months" onchange="this.form.submit();" data-hint="'.htmlspecialchars($hint_text_flow, ENT_QUOTES).'" class="period-select" style="padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold;">
        ' . implode('', array_map(fn($n) => optiRow($flow_months_selected, $n), [3, 6, 12])) . '
    </select>
    <input type="hidden" name="period_months" value="'.$months_selected.'">
</form>';

htm_Card_(capt: '@Account Balance Trend', wdth: 1000, info: '', form: false, echo: true, tool: $period_selector_balance);
if ($is_demo_data) {
    // RETTET (bruger-anmodet): det fælles "Databasen er tom"-banner
    // øverst på siden var den ENESTE demo-data-markering - efter at have
    // scrollet forbi Mine opgaver og de tre nøgletalskort er det let at
    // glemme, at grafen herunder også viser opdigtede tal, ikke rigtig
    // udvikling. Samme notits-stil som den fælles banner, blot lokalt på
    // selve grafkortet, hvor øjet rent faktisk er når tallene aflæses.
    echo "<div style='background: var(--theme-info-bg, #eaf2f8); border-left: 5px dotted var(--theme-primary, #3498db); color: var(--theme-info-text, #2980b9); padding: 10px 12px; margin-bottom: 12px; font-size: 12px; border-radius: 4px;'>"
        . "ℹ️ " . lang('@The database is empty. Simulated demo data is displayed.') . "</div>";
}
?>
<div style="width: 100%; height: 250px; position: relative; padding: 5px 0;"><canvas id="balanceTrendChart"></canvas></div>
<?php
htm_Card_end();
echo '<div style="margin-top: 20px;"></div>';
htm_Card_(capt: '@Financial Performance (Cash Flow)', wdth: 1000, info: '', form: false, echo: true, tool: $period_selector_flow);
if ($is_demo_data) {
    // RETTET (bruger-anmodet, samme begrundelse som graf 1 ovenfor): vises
    // nu ALTID lokalt på grafen når HELE siden er i demo-tilstand, i
    // stedet for at stole på at brugeren husker det fælles banner langt
    // oppe i toppen af siden.
    echo "<div style='background: var(--theme-info-bg, #eaf2f8); border-left: 5px dotted var(--theme-primary, #3498db); color: var(--theme-info-text, #2980b9); padding: 10px 12px; margin-bottom: 12px; font-size: 12px; border-radius: 4px;'>"
        . "ℹ️ " . lang('@The database is empty. Simulated demo data is displayed.') . "</div>";
} elseif ($flow_is_demo) {
    // Uændret fra før: DENNE graf specifikt falder tilbage til demo-tal,
    // mens resten af siden (KPI-kort, saldo-graf) viser rigtige data - fx
    // hvis der findes bogførte fakturaer/udgifter, men ingen af dem falder
    // inden for den valgte periode.
    echo "<div style='background: var(--theme-info-bg, #eaf2f8); border-left: 5px dotted var(--theme-primary, #3498db); color: var(--theme-info-text, #2980b9); padding: 10px 12px; margin-bottom: 12px; font-size: 12px; border-radius: 4px;'>"
        . "ℹ️ " . lang('@No posted sales or expenses in this period - simulated demo data is shown instead.') . "</div>";
}
?>
<div style="width: 100%; height: 250px; position: relative; padding: 5px 0;"><canvas id="cashFlowChart"></canvas></div>
<?php htm_Card_end(); ?>

<?php if ($fc_has_data): ?>
<div style="margin-top: 20px;"></div>
<?php
htm_Card_(capt: '@Cash Flow Forecast (Next 4 Weeks)', wdth: 1000, info: '', form: false, echo: true);
echo '<p style="color:var(--text-muted); font-size:0.85em; margin-top:-5px;">'
    . lang('@Based on open invoice due dates and unpaid expense due dates - not yet booked income/expenses are not included.')
    . '</p>';
?>
<div style="width: 100%; height: 250px; position: relative; padding: 5px 0;"><canvas id="cashFlowForecastChart"></canvas></div>
<?php htm_Card_end(); ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var isDark = (document.documentElement.getAttribute("data-theme") || "light") === "dark";
    var gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
    var textColor = isDark ? '#e0e0e0' : '#666666';
    var chartScales = { x: { grid: { color: gridColor }, ticks: { color: textColor } }, y: { grid: { color: gridColor }, ticks: { color: textColor } } };
    var chartPlugins = { legend: { labels: { color: textColor } } };

    new Chart(document.getElementById('balanceTrendChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_dates); ?>,
            datasets: [{
                label: '<?php echo lang("@Running Balance"); ?>',
                data: <?php echo json_encode($chart_balance); ?>,
                borderColor: '#3498db',
                backgroundColor: isDark ? 'rgba(52, 152, 219, 0.15)' : 'rgba(52, 152, 219, 0.05)',
                fill: true, tension: 0.15, borderWidth: 2, pointRadius: 2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: chartScales, plugins: chartPlugins }
    });

    // RETTET: viste før en kunstig procentvis opdeling af alt-tids-totalen,
    // udregnet her i JS - aldrig hvad der reelt skete i den valgte periode.
    // $flow_labels/$flow_sales/$flow_expenses er nu rigtige, dato-afgrænsede
    // summer pr. bucket, udregnet server-side (se PHP-blokken "GRAF 2").
    new Chart(document.getElementById('cashFlowChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($flow_labels); ?>,
            datasets: [
                { label: '<?php echo lang("@Incomes (Sales)"); ?>', data: <?php echo json_encode($flow_sales); ?>, backgroundColor: isDark ? '#26de81' : '#2ecc71', borderRadius: 4 },
                { label: '<?php echo lang("@Outcomes (Purchases)"); ?>', data: <?php echo json_encode($flow_expenses); ?>, backgroundColor: isDark ? '#ff5252' : '#e74c3c', borderRadius: 4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: chartScales, plugins: chartPlugins }
    });

    <?php if ($fc_has_data): ?>
    new Chart(document.getElementById('cashFlowForecastChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($fc_labels); ?>,
            datasets: [
                { label: '<?php echo lang("@Expected Incoming"); ?>', data: <?php echo json_encode($fc_incoming); ?>, backgroundColor: isDark ? '#26de81' : '#2ecc71', borderRadius: 4 },
                { label: '<?php echo lang("@Expected Outgoing"); ?>', data: <?php echo json_encode($fc_outgoing); ?>, backgroundColor: isDark ? '#ff5252' : '#e74c3c', borderRadius: 4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: chartScales, plugins: chartPlugins }
    });
    <?php endif; ?>
});
</script>

<?php
htm_Footer();
ob_end_flush();
?>