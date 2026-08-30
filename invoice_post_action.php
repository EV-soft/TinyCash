<?php # /invoice_post_action.php v:1.3.0 d:2026-08-30 i:evs
# v1.9.0: KRITISK FUND - "(100+rate)/100"-rettelsen fra tidligere er ufuldstændig.
# SQLite gemmer NUMERIC-kolonner som INTEGER, når værdien er et helt tal (fx
# pris 899 kr, moms 25%). Når antal*pris*sats ALLE er hele tal, forbliver hele
# SQL-udtrykket typen INTEGER helt frem til "/100", og SQLite udfører
# heltalsdivision der - momsen trunkeres til nærmeste hele krone og BOGFØRES
# forkert i selve hovedbogen (ikke kun en rapportfejl). Bekræftet direkte: en
# 2 stk. á 899 kr-faktura med 25% moms blev bogført med 449 kr i moms i stedet
# for korrekt 449,50 kr. Rettet ved at tvinge divisoren til flydende-komma
# ("/100.0") - SQLite regner så hele udtrykket som REAL uanset operandernes
# type. Se [[sqlite-integer-division-in-numeric-sql]] for de øvrige 8 steder
# samme mønster ramte (rapporter/visninger, mindre alvorligt men rettet samtidig).
# (Cross-engine via DB-laget; total fra invoice_lines; momskorrekt postering med separat moms-linje)
# v1.3.0: voucher_no fra fælles next_voucher_no() - adskilt fra invoice_no
# v1.4.0: tilføjet periodespærring (is_date_locked) - manglede helt, i
# modsætning til kreditnota-flowet der allerede havde det
# v1.5.0: total_excl/total_vat/total_incl afrundes nu til øre (round(...,2))
# før bogføring - manglede før, i modsætning til expense_edit.php's mønster
# v1.6.0: ALVORLIGT FUND - fremmed-valuta-fakturaer (fx EUR) blev bogført med
# deres rå udenlandske beløb som om det var DKK, uden nogensinde at gange med
# invoices.exch_rate (blev end ikke hentet). Rettet: henter nu exch_rate og
# omregner til DKK før bogføring, hvis sat.
# v1.7.0: ALVORLIGT FUND - lager blev ALDRIG trukket ved fakturering i den
# levende kode (kun en forladt, ukoblet prototype gjorde det). Tilføjet
# lagertræk ved selve bogføringen (kun her, ikke i invoice_edit.php's
# kladde-gem, som ville trække forkert flere gange).
# v1.8.0: fakturanummer hentes nu via den nye atomiske next_invoice_no()
# (inc/db_connect.inc.php) i stedet for et kapløbs-sårbart MAX(invoice_no)+1.
// Deaktiver visuelle fejlmeddelelser der kan ødelægge JSON-outputtet
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/audit.inc.php';   // definerer log_action() (revisionsspor) - manglede før

header('Content-Type: application/json; charset=utf-8');

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($inv_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ugyldigt ID-parameter']);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Databaseforbindelse mistet']);
    exit;
}

// Konti (kan overstyres via indstillinger; defaults matcher standard-kontoplanen).
// NB: udgående moms bogføres på "Moms, salg" (6900) - IKKE 2500 (Markedsføring),
// som kreditnota-flowet fejlagtigt bruger.
$acc_debitor = (int)($global_settings['conf_acc_debitor'] ?? 8100); // Tilgodehavender (debitor)
$acc_sales   = (int)($global_settings['conf_acc_sales']   ?? 1000); // Omsætning / salg
$acc_vat     = (int)($global_settings['conf_acc_vat']     ?? 6900); // Moms, salg (udgående)

DB::begin_transaction($conn);

try {
    // 1. Tjek status + hent dato + valutakurs.
    $row = DB::fetch_assoc(DB::query($conn, "SELECT inv_status, inv_date, exch_rate FROM invoices WHERE inv_id = $inv_id"));
    if (!$row) {
        throw new Exception('Fakturaen blev ikke fundet i systemet');
    }
    // Case-ufølsom: statusværdier er inkonsistente i data ('DRAFT' fra faktura-
    // formularen vs. 'draft' i backend/rapporter). strtolower gør posteringen
    // robust uanset skrivemåde.
    if (strtolower($row['inv_status']) !== 'draft') {
        throw new Exception('Fakturaen er allerede bogført (Status: ' . $row['inv_status'] . ')');
    }
    $inv_date = $row['inv_date'];

    // Periodespærring: en NY postering må ikke kunne bogføres ind i en
    // allerede låst periode - kreditnota-flowet (invoice_credit.php) havde
    // allerede dette tjek, men selve fakturabogføringen manglede det (fundet
    // ved en systematisk gennemgang, §bogforingslov-compliance).
    if (is_date_locked($conn, $inv_date)) {
        throw new Exception('Fakturadatoen er i en låst regnskabsperiode og kan ikke bogføres.');
    }

    // 2. Beløb: netto + moms + omregning til DKK - via den fælles
    // invoice_dkk_totals() (inc/db_connect.inc.php), som reconcile_action.php
    // nu også bruger, så de to ALDRIG kan glide fra hinanden igen (se dens
    // egen kommentar for baggrunden - §reel-multi-valuta-bogforing).
    $totals     = invoice_dkk_totals($conn, $inv_id);
    $total_excl = $totals['excl'];
    $total_vat  = $totals['vat'];
    $total_incl = $totals['incl'];

    // 3. Find næste fakturanummer - atomisk tæller (samme mønster som
    // next_voucher_no()), FØR blot "SELECT MAX(invoice_no)+1" uden nogen
    // låsning, sårbart for at to samtidige bogføringer kunne få samme
    // nummer. Se next_invoice_no() i inc/db_connect.inc.php.
    $next_invoice_no = next_invoice_no($conn);

    // 4. Opdater fakturaen med nummer og status 'sent'
    // RETTET (§bugs-batch-19-review): KRITISK kapløbsvindue - status-tjekket
    // i punkt 1 (SELECT ovenfor) og selve UPDATE'en her lå begge inde i
    // transaktionen, men UPDATE'ens WHERE-klausul genkontrollerede ALDRIG
    // status ved selve skrivningen. To næsten-samtidige bogføringsforsøg
    // (fx et utålmodigt dobbeltklik på "Bogfør" i sales_hub.php, som ikke
    // deaktiverer knappen mens forespørgslen er undervejs) kunne begge
    // bestå status-tjekket, FØR nogen af dem nåede at skrive - og begge ville
    // derefter uafhængigt tildele sig hvert sit fakturanummer/bilagsnummer
    // og bogføre fulde, dublerede posteringer for samme faktura. WHERE-
    // klausulen tjekker nu status ATOMISK sammen med selve skrivningen, og
    // et 0-rækker-resultat (nogen nåede først) afbryder øjeblikkeligt, FØR
    // noget som helst posteres til hovedbogen.
    DB::query($conn, "UPDATE invoices SET invoice_no = $next_invoice_no, inv_status = 'sent' WHERE inv_id = $inv_id AND inv_status = 'draft'");
    $recheck = DB::fetch_assoc(DB::query($conn, "SELECT inv_status, invoice_no FROM invoices WHERE inv_id = $inv_id"));
    if (!$recheck || strtolower($recheck['inv_status']) !== 'sent' || (int)$recheck['invoice_no'] !== $next_invoice_no) {
        throw new Exception('Fakturaen blev allerede bogført af en anden, samtidig forespørgsel (kapløb) - intet blev dublet.');
    }

    // 5. FINANSBOGFØRING (dobbelt bogholderi jf. bogføringsloven)
    // NB: voucher_no (bilagsnummer) er BEVIDST adskilt fra invoice_no (det
    // kundevendte fakturanummer) - de er to forskellige lovkrav om hulfri
    // nummerering, og voucher_no deles nu med kreditnotaer/udgifter/bank-
    // afstemning via next_voucher_no(), se inc/db_connect.inc.php.
    $voucher_no   = next_voucher_no($conn);
    $journal_text = DB::escape($conn, "Bogført salgsfaktura #" . $next_invoice_no);
    $jou_date     = DB::escape($conn, $inv_date);
    // RETTET (§currency-setting-is-cosmetic-label): journal.currency blev
    // aldrig sat (faldt altid tilbage til skemaets DEFAULT 'DKK'), uanset
    // firmaets faktisk konfigurerede bogføringsvaluta.
    $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
    if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, voucher_no, currency) VALUES ('$jou_date', '$journal_text', $voucher_no, '$jou_currency')") === false) {
        throw new Exception('Kunne ikke oprette journalpost: ' . DB::error($conn));
    }
    $jou_id = DB::insert_id($conn);

    // Momskorrekt dobbeltpostering (balancerer til 0):
    //   DEBET  debitor        = total inkl. moms
    //   KREDIT omsætning      = netto (ekskl. moms)
    //   KREDIT udgående moms  = momsbeløb
    ledger_post($conn, $jou_id, $acc_debitor, $total_incl);
    ledger_post($conn, $jou_id, $acc_sales, $total_excl * -1);
    if ($total_vat != 0) {
        ledger_post($conn, $jou_id, $acc_vat, $total_vat * -1);
    }

    // 6. LAGERREGULERING - trækkes fra HER (ved selve bogføringen), ikke ved
    // hvert kladde-gem i invoice_edit.php, som sletter+genindsætter linjerne
    // ved hvert gem og derfor ville trække forkert flere gange. Posteringen
    // her sker garanteret kun ÉN gang pr. faktura (status-tjekket i punkt 1
    // forhindrer genbogføring). Manglede FØR helt i den levende kode - kun
    // en forladt, ukoblet prototype (tools/faktura_gem.php) gjorde det
    // nogensinde. Fundet ved en lagerstyrings-gennemgang.
    $stock_lines = DB::query($conn, "SELECT prod_id, quantity FROM invoice_lines WHERE inv_id = $inv_id AND prod_id > 0");
    if ($stock_lines) {
        while ($sl = DB::fetch_assoc($stock_lines)) {
            $sp_id = (int)$sl['prod_id'];
            $sqty  = (float)$sl['quantity'];
            DB::query($conn, "UPDATE products SET prod_stock = prod_stock - $sqty WHERE prod_id = $sp_id");
        }
    }

    // Log handlingen i revisionssporet
    log_action($conn, 'POST_INVOICE', 'invoices', $inv_id, ['status' => 'draft'], ['status' => 'sent', 'invoice_no' => $next_invoice_no]);

    DB::commit($conn);
    echo json_encode(['success' => true, 'invoice_no' => $next_invoice_no]);

} catch (Exception $e) {
    DB::rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
?>
