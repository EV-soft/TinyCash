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

require_once 'inc/invoice_post.inc.php';     // invoice_post() - samme bogføring som webbutik-ordrer bruger

DB::begin_transaction($conn);

try {
    $next_invoice_no = invoice_post($conn, $inv_id);
    // Webbutik: en betalt ordre, der blev lagt som kladde, får nu sin betaling registreret (mellemregningskontoen)
    if (file_exists(__DIR__ . '/inc/shop.inc.php')) {
        require_once __DIR__ . '/inc/shop.inc.php';
        if (shop_ready($conn)) shop_after_invoice_posted($conn, $inv_id);
    }

    DB::commit($conn);
    echo json_encode(['success' => true, 'invoice_no' => $next_invoice_no]);

} catch (Exception $e) {
    DB::rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
?>
