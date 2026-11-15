<?php # /invoice_duplicate.php v:1.3.1 d:2026-09-15 i:evs
# NY (bruger-anmodet, "kan bogholderen ønske sig mere"): opretter en ny
# fakturakladde med samme kunde/linjer/valuta/projekt som en eksisterende
# faktura (uanset dennes egen status - kladde, sendt, betalt eller
# krediteret er alle gyldige kilder). Til gengangs-fakturering uden den
# fulde recurring_invoices.php-maskine, og uden at skulle taste alt forfra.
# Den nye faktura er ALTID en ren, ubogført kladde (intet invoice_no, intet
# jou_id, ingen credit_ref) - brugeren lander på invoice_edit.php bagefter
# og skal selv gennemgå/rette dato og evt. beløb før afsendelse. Rører
# hverken den oprindelige faktura, journalen eller lageret - en duplikering
# er ikke en bogføringshandling.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

$src_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($src_id <= 0) { redirect("sales_hub.php"); }

$src = DB::fetch_assoc(DB::query($conn, "SELECT * FROM invoices WHERE inv_id = $src_id"));
if (!$src) { redirect("sales_hub.php"); }
// RETTET: en kreditnota kunne kopieres - kopien blev en almindelig fakturakladde med negative linjer, som ved bogføring
// fik et nummer i FAKTURA-serien (ikke kreditnota-serien), uden kobling til den krediterede faktura - og dermed uden om
// reglen om højst én kreditnota pr. faktura (samme faktura kunne krediteres to gange). En kreditnota laves via
// invoice_credit.php fra den oprindelige faktura.
if (!empty($src['credit_ref'])) { redirect("invoice_view.php?id=$src_id"); }

// Forfaldsdato beregnes som i dag + kundens betalingsdage - samme kilde
// (customers.cust_payment_days, fallback 8) som recurring_invoice_edit.php
// allerede bruger, i stedet for blot at kopiere den oprindelige forfaldsdato
// (som typisk ville ligge i fortiden for en genbrugt faktura).
if (!tc_customer_ok($conn, (int)$src['cust_id'])) redirect("invoice_view.php?id=$src_id", '@The customer is in the trash bin - restore the customer first.', 'error');      // RETTET: dannede et dokument til en kunde i papirkurven
$cust = DB::fetch_assoc(DB::query($conn, "SELECT cust_payment_days FROM customers WHERE cust_id = " . (int)$src['cust_id']));
$payment_days = (int)($cust['cust_payment_days'] ?? 8);

$today    = date('Y-m-d');
$due_date = date('Y-m-d', strtotime("+$payment_days days"));

$cust_id_sql   = (int)$src['cust_id'];
$deliv_sql     = "'" . DB::escape($conn, $src['delivery_address'] ?? '') . "'";
$currency_sql  = "'" . DB::escape($conn, $src['currency'] ?? 'DKK') . "'";
$note_sql      = "'" . DB::escape($conn, $src['inv_note'] ?? '') . "'";
$cust_ref_sql  = "'" . DB::escape($conn, $src['cust_reference'] ?? '') . "'";
$proj_sql      = !empty($src['proj_id']) ? (int)$src['proj_id'] : 'NULL';
$orig_cur_sql  = !empty($src['orig_currency']) ? "'" . DB::escape($conn, $src['orig_currency']) . "'" : 'NULL';
$exch_rate_sql = !empty($src['exch_rate']) ? (float)$src['exch_rate'] : 'NULL';
// En kopi dateret i dag skal have dagens kurs, ikke originalens (der kan være måneder gammel) - fra kurscachen
// (currency_proxy.php, højst 7 dage gammel); ellers beholdes kursen, og brugeren bedes kontrollere den
$dup_rate_note = '';
if (!empty($src['orig_currency']) && !empty($src['exch_rate'])) {
    $fx = json_decode((string)@file_get_contents(__DIR__ . '/storage/currency_rates.json'), true);
    $base = strtoupper((string)($global_settings['currency'] ?? 'DKK'));  $oc = strtoupper((string)$src['orig_currency']);
    $per_eur = fn(string $c) => $c === 'EUR' ? 1.0 : (float)($fx['rates'][$c] ?? 0);
    if (is_array($fx) && tc_is_date((string)($fx['date'] ?? '')) && $fx['date'] >= date('Y-m-d', strtotime('-7 days'))
        && $per_eur($base) > 0 && $per_eur($oc) > 0) {
        $exch_rate_sql = round($per_eur($base) / $per_eur($oc), 6);
        $dup_rate_note = ' ' . sprintf(lang('@The exchange rate was updated to %s (%s).'), fmt_dk($exch_rate_sql, 4), date(CONF_DATE_FORMAT, strtotime($fx['date'])));
    } else {
        $dup_rate_note = ' ' . lang('@Check the exchange rate - it was copied from the original invoice.');
    }
}

DB::begin_transaction($conn);

$ok = DB::query($conn, "INSERT INTO invoices
    (cust_id, inv_date, inv_due_date, inv_status, cust_reference, inv_note,
     delivery_address, currency, proj_id, orig_currency, exch_rate)
    VALUES ($cust_id_sql, '$today', '$due_date', 'draft', $cust_ref_sql, $note_sql,
     $deliv_sql, $currency_sql, $proj_sql, $orig_cur_sql, $exch_rate_sql)");

if (!$ok) {
    $dup_err = (string)DB::error($conn);  DB::rollback($conn);
    redirect("invoice_view.php?id=$src_id", lang('@Error saving to database:') . ' ' . htmlspecialchars($dup_err), 'error');      // (var en hvid side)
}
$new_id = DB::insert_id($conn);
// IOSS-mærket følger med (linjerne har modtagerlandets satser - uden mærket kunne kopien ikke bogføres)
if (ioss_ready($conn) && trim((string)($src['inv_ioss'] ?? '')) !== '') DB::query($conn, "UPDATE invoices SET inv_ioss = 'Y' WHERE inv_id = " . (int)$new_id);
if (!empty($src['inv_oss_off'])) DB::query($conn, "UPDATE invoices SET inv_oss_off = 1 WHERE inv_id = " . (int)$new_id);      // (ydelse beskattet i eget land)
// Flere fakturalayouts: et layout valgt til den oprindelige faktura følger med (ellers kundens / standarden)
require_once __DIR__ . '/inc/invoice_layouts.inc.php';
if (il_ready($conn) && ($il_src = il_valid_id($conn, $src['inv_layout_id'] ?? 0)) > 0) DB::query($conn, "UPDATE invoices SET inv_layout_id = $il_src WHERE inv_id = $new_id");

$lres = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $src_id ORDER BY line_id ASC");
// RETTET: linjens rabat (discount_pct) blev ikke kopieret - kopien blev dyrere end originalen uden at
// det kunne ses, før kunden klagede
$dup_has_disc = invoice_lines_has_discount_col($conn);
while ($l = DB::fetch_assoc($lres)) {
    DB::insert($conn, 'invoice_lines', ($dup_has_disc ? ['discount_pct' => (float)($l['discount_pct'] ?? 0)] : []) + [
        'inv_id'         => $new_id,
        'line_text'      => $l['line_text'],
        'quantity'       => $l['quantity'],
        'price_each'     => $l['price_each'],
        'acc_id'         => $l['acc_id'],
        'line_vat_rate'  => $l['line_vat_rate'],
        'prod_id'        => $l['prod_id'],
        'currency'       => $l['currency'],
        'proj_id'        => $l['proj_id'],
        'orig_currency'  => $l['orig_currency'],
        'orig_amount'    => $l['orig_amount'],
        'exch_rate'      => ($l['exch_rate'] !== null && $exch_rate_sql !== 'NULL') ? $exch_rate_sql : $l['exch_rate'],
    ]);
}

log_action($conn, 'DUPLICATE_INVOICE', 'invoices', $new_id, ['source_inv_id' => $src_id], ['cust_id' => $cust_id_sql, 'inv_date' => $today]);

DB::commit($conn);
// Kildens fakturanummer - ikke det interne id (faktura #1005 blev vist som "#7"); en kladde har intet nummer endnu
$dup_src_label = !empty($src['invoice_no']) ? '#' . $src['invoice_no'] : lang('@draft') . ' (' . $src_id . ')';
redirect("invoice_edit.php?id=$new_id", sprintf(lang('@New draft created as a copy of invoice %s - review and adjust before sending.'), $dup_src_label) . $dup_rate_note, 'info');
ob_end_flush();
exit;
?>
