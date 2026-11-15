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

// RETTET: bogføringen (uigenkaldelig - tildeler fakturanummer, poster i hovedbogen, trækker lager) kunne
// udløses af et almindeligt GET-link uden CSRF-nøgle, fx et link i en mail til en indlogget bruger. Nu kun
// POST, som inc/auth.inc.php CSRF-tjekker centralt (sales_hub.php sender nøglen med).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($inv_id <= 0) {
    echo json_encode(['success' => false, 'error' => lang('@Invoice or customer not found')]);      // (hårdkodet dansk)
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'error' => lang('@Could not connect to the database.')]);
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
    // Moms til en virksomhed i et andet EU-land (med momsnummer) er næsten altid en fejl: varer er en momsfri EU-levering,
    // ydelser omvendt betalingspligt - en advarsel (ikke en afvisning: fx ydelser vedr. fast ejendom beskattes hvor de er)
    $pw = DB::fetch_assoc(DB::query($conn, "SELECT c.cust_country, c.cust_vat_id, (SELECT COUNT(*) FROM invoice_lines il WHERE il.inv_id = i.inv_id AND COALESCE(il.line_vat_rate, 0) > 0) AS taxed
                                            FROM invoices i JOIN customers c ON c.cust_id = i.cust_id WHERE i.inv_id = " . (int)$inv_id));
    $pw_cc = strtoupper(trim((string)($pw['cust_country'] ?? '')));
    $pw_home = strtoupper((string)tc_country_pack('iso', 'DK'));
    $pw_home_eu = in_array($pw_home, TC_EU_COUNTRIES, true);
    if ($pw && (int)$pw['taxed'] > 0 && trim((string)($pw['cust_vat_id'] ?? '')) !== '' && $pw_cc !== '' && $pw_cc !== $pw_home
        && $pw_home_eu && in_array($pw_cc, TC_EU_COUNTRIES, true)) {
        flash(sprintf(lang('@The invoice has VAT, but the customer is a business in %s with a VAT number. Goods to another EU country are an intra-Community supply without VAT, and most services are reverse charge - check it, and correct it with a credit note if needed.'), htmlspecialchars($pw_cc)), 'warning');
    } elseif ($pw && (int)$pw['taxed'] > 0 && trim((string)($pw['cust_vat_id'] ?? '')) !== '' && $pw_cc !== '' && $pw_cc !== $pw_home && !$pw_home_eu) {
        // (Norge/Island: et salg til en virksomhed i udlandet er normalt eksport uden moms - også til et EU-land)
        flash(sprintf(lang('@The invoice has VAT, but the customer is a business in %s. A sale to a business abroad is normally an export without VAT - check it, and correct it with a credit note if needed.'), htmlspecialchars($pw_cc)), 'warning');
    }
    echo json_encode(['success' => true, 'invoice_no' => $next_invoice_no]);

} catch (\Throwable $e) {      // (kun Exception: en PHP-fejl under bogføringen gav en 500 uden svar - knappen stod bare og ventede)
    DB::rollback($conn);
    if (!($e instanceof Exception)) error_log('[invoice_post] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => $e instanceof Exception ? $e->getMessage() : lang('@Error:') . ' ' . $e->getMessage()]);
}
exit;
?>
