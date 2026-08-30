<?php # /export_oioubl.php v:1.3.0 d:2026-08-30 i:evs
# (Genskabt: OIOUBL-2.02 e-faktura eksport med korrekte beløb)
# v1.3.0: linjebeløb/-moms afrundes nu til øre PR. LINJE før summering -
# undgår at "rund summen til sidst" kan afvige 1 øre fra summen af de viste
# linjebeløb (BR-CO-10/BR-CO-14), som kunne få en formel e-fakturavalidering
# til at afvise filen.
/* ==========================================================================
   OIOUBL e-faktura eksport (dansk offentlig UBL-2.02 profil).

   Kaldes fra invoice_view.php via knappen "OIOUBL XML"
   (export_oioubl.php?id=<inv_id>). Henter fakturaen, dens linjer, kunden og
   firma-stamdata, beregner beløb (samme logik som invoice_view.php:
   linje = quantity * price_each, moms = linje * line_vat_rate/100) og
   returnerer en downloadbar XML-fil. En kopi arkiveres i storage/einvoices/.
   ========================================================================== */

ob_start(); // fang evt. tilfældig output fra includes, så header() virker bagefter
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';        // kræver login (redirect til login.php ellers)
require_once 'inc/php2htm.lib.php';     // lang()

// NYT (§currency-setting-is-cosmetic-label, Fase 2): OIOUBL er et dansk
// offentligt e-faktura-format (NemHandel) og giver ikke mening for en
// virksomhed, der bruger en anden bogføringsvaluta end DKK.
require_dkk_base_currency($conn);

$inv_id = (int)($_GET['id'] ?? 0);
if ($inv_id <= 0) { ob_end_clean(); http_response_code(400); die('Invalid invoice id'); }

// --- 1. HENT FAKTURA + KUNDE (samme join som invoice_view.php) ---
$sql = "SELECT i.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr
        FROM invoices i JOIN customers c ON i.cust_id = c.cust_id
        WHERE i.inv_id = $inv_id";
$res = DB::query($conn, $sql);
$inv = $res ? DB::fetch_assoc($res) : null;
if (!$inv) { ob_end_clean(); http_response_code(404); die(lang('@Invoice not found.')); }

// En kladde har intet invoice_no endnu (tildeles først af next_invoice_no()
// ved bogføring, se invoice_post_action.php) - uden dette tjek ville
// $doc_id nedenfor falde tilbage til det interne $inv_id, og en helt
// ubogført faktura kunne genereres og arkiveres i storage/einvoices/ som en
// formelt gyldig OIOUBL-fil, præcis samme klasse fejl som blev rettet i
// send_invoice_action.php (en kladde må aldrig kunne sendes/eksporteres som
// om den var en rigtig faktura).
if (strtolower($inv['inv_status'] ?? '') === 'draft') {
    ob_end_clean(); http_response_code(409); die(lang('@Invoice must be posted before it can be exported.'));
}

// --- 2. FIRMA-STAMDATA ---
$settings = get_settings($conn);
$co_name = $settings['company_name']    ?? '';
$co_cvr  = preg_replace('/\D/', '', $settings['company_cvr'] ?? '');   // kun cifre til DK:CVR
$co_addr = $settings['company_address'] ?? '';
$co_city = $settings['company_city']    ?? '';
$co_zip  = preg_replace('/\D/', '', $settings['company_zip'] ?? '');
$bank_reg = preg_replace('/\D/', '', $settings['bank_reg'] ?? '');
$bank_acc = preg_replace('/\D/', '', $settings['bank_acc'] ?? '');
$pay_note = $settings['default_payment_terms'] ?? 'Betaling bedes overført til vores bankkonto.';

$cust_cvr = preg_replace('/\D/', '', $inv['cust_cvr'] ?? '');

// --- 3. VALUTA & DATOER ---
$cur      = strtoupper(preg_replace('/[^A-Za-z]/', '', $inv['currency'] ?? 'DKK')) ?: 'DKK';
$doc_id   = $inv['invoice_no'] ?: $inv_id;
$issue    = date('Y-m-d', strtotime($inv['inv_date'] ?: 'now'));
$due      = date('Y-m-d', strtotime($inv['inv_due_date'] ?: $inv['inv_date'] ?: 'now'));

// --- 4. LINJER + BELØB ---
$res_l = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC");
$lines      = [];
$subtotal   = 0.0;   // sum af linjebeløb ekskl. moms
$vat_total  = 0.0;   // samlet moms
$vat_groups = [];    // grupperet pr. momssats: ['25.00' => ['taxable'=>, 'tax'=>]]

while ($res_l && ($l = DB::fetch_assoc($res_l))) {
    $qty   = (float)$l['quantity'];
    $price = (float)$l['price_each'];
    $rate  = (float)($l['line_vat_rate'] ?? $l['vat_rate'] ?? 25);
    // Afrundes til øre PR. LINJE, før noget som helst summeres. UBL/OIOUBL's
    // egne konsistens-regler (bl.a. BR-CO-10/BR-CO-14) kræver at summen af de
    // viste linjebeløb er PRÆCIS lig totalen - "rund summen til sidst" kan
    // afvige med 1 øre fra "summér de rundede linjer" (klassisk rundings-
    // rækkefølge-fælde), hvilket kan få en formel e-fakturavalidering (fx
    // NemHandel/PEPPOL) til at afvise filen. Ved at runde her, arves
    // konsistensen automatisk af alt der summeres nedenfor.
    $line_ext = round($qty * $price, 2);
    $line_vat = round($line_ext * ($rate / 100), 2);

    $subtotal  += $line_ext;
    $vat_total += $line_vat;

    $rk = number_format($rate, 2, '.', '');
    if (!isset($vat_groups[$rk])) $vat_groups[$rk] = ['taxable' => 0.0, 'tax' => 0.0];
    $vat_groups[$rk]['taxable'] += $line_ext;
    $vat_groups[$rk]['tax']     += $line_vat;

    $lines[] = ['text' => $l['line_text'], 'qty' => $qty, 'price' => $price, 'rate' => $rate, 'ext' => $line_ext];
}
$grand_total = round($subtotal + $vat_total, 2);

// --- 5. HJÆLPERE ---
function esc($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function amt($v) { return number_format((float)$v, 2, '.', ''); }

// --- 6. BYG XML ---
$x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$x .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
    . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
    . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n";
$x .= '  <cbc:UBLVersionID>2.0</cbc:UBLVersionID>' . "\n";
$x .= '  <cbc:CustomizationID>OIOUBL-2.02</cbc:CustomizationID>' . "\n";
$x .= '  <cbc:ProfileID>urn:www.nesubl.eu:profiles:profile5:ver2.0</cbc:ProfileID>' . "\n";
$x .= '  <cbc:ID>' . esc($doc_id) . '</cbc:ID>' . "\n";
$x .= '  <cbc:CopyIndicator>false</cbc:CopyIndicator>' . "\n";
$x .= '  <cbc:IssueDate>' . esc($issue) . '</cbc:IssueDate>' . "\n";
$x .= '  <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>' . "\n";
$x .= '  <cbc:DocumentCurrencyCode>' . esc($cur) . '</cbc:DocumentCurrencyCode>' . "\n";

// Sælger
$x .= '  <cac:AccountingSupplierParty>' . "\n" . '    <cac:Party>' . "\n";
$x .= '      <cbc:EndpointID schemeID="DK:CVR">' . esc($co_cvr ?: '00000000') . '</cbc:EndpointID>' . "\n";
$x .= '      <cac:PartyName><cbc:Name>' . esc($co_name) . '</cbc:Name></cac:PartyName>' . "\n";
$x .= '      <cac:PostalAddress>' . "\n";
$x .= '        <cbc:StreetName>' . esc($co_addr) . '</cbc:StreetName>' . "\n";
$x .= '        <cbc:CityName>' . esc($co_city) . '</cbc:CityName>' . "\n";
$x .= '        <cbc:PostalZone>' . esc($co_zip ?: '0000') . '</cbc:PostalZone>' . "\n";
$x .= '        <cac:Country><cbc:IdentificationCode>DK</cbc:IdentificationCode></cac:Country>' . "\n";
$x .= '      </cac:PostalAddress>' . "\n";
$x .= '      <cac:PartyLegalEntity>' . "\n";
$x .= '        <cbc:RegistrationName>' . esc($co_name) . '</cbc:RegistrationName>' . "\n";
$x .= '        <cbc:CompanyID schemeID="DK:CVR">' . esc($co_cvr ?: '00000000') . '</cbc:CompanyID>' . "\n";
$x .= '      </cac:PartyLegalEntity>' . "\n";
$x .= '    </cac:Party>' . "\n" . '  </cac:AccountingSupplierParty>' . "\n";

// Køber
$x .= '  <cac:AccountingCustomerParty>' . "\n" . '    <cac:Party>' . "\n";
$x .= '      <cbc:EndpointID schemeID="DK:CVR">' . esc($cust_cvr ?: '00000000') . '</cbc:EndpointID>' . "\n";
$x .= '      <cac:PartyName><cbc:Name>' . esc($inv['cust_name']) . '</cbc:Name></cac:PartyName>' . "\n";
$x .= '      <cac:PostalAddress>' . "\n";
$x .= '        <cbc:StreetName>' . esc($inv['cust_address']) . '</cbc:StreetName>' . "\n";
$x .= '        <cbc:CityName/>' . "\n";
$x .= '        <cbc:PostalZone>0000</cbc:PostalZone>' . "\n";
$x .= '        <cac:Country><cbc:IdentificationCode>DK</cbc:IdentificationCode></cac:Country>' . "\n";
$x .= '      </cac:PostalAddress>' . "\n";
$x .= '      <cac:PartyLegalEntity>' . "\n";
$x .= '        <cbc:RegistrationName>' . esc($inv['cust_name']) . '</cbc:RegistrationName>' . "\n";
if ($cust_cvr) $x .= '        <cbc:CompanyID schemeID="DK:CVR">' . esc($cust_cvr) . '</cbc:CompanyID>' . "\n";
$x .= '      </cac:PartyLegalEntity>' . "\n";
$x .= '    </cac:Party>' . "\n" . '  </cac:AccountingCustomerParty>' . "\n";

// Leveringsadresse (valgfri cac:Delivery - samme placering i sekvensen som
// UBL 2.0/OIOUBL's egen XSD kræver: lige efter AccountingCustomerParty, før
// PaymentMeans/PaymentTerms). Kun med når fakturaen faktisk har en
// leveringsadresse, der afviger fra faktureringsadressen - samme betingelse
// og datakilde som invoice_view.php's block-delivery, se [[bugs-batch-21-review]].
// Adressen er ligesom køberens et fritekstfelt uden strukturerede
// vej/by/postnr-dele - lægges derfor i StreetName, samme forenkling som
// AccountingCustomerParty's egen adresse allerede bruger ovenfor.
$deliv_addr = trim($inv['delivery_address'] ?? '');
$bill_addr  = trim($inv['cust_address'] ?? '');
if ($deliv_addr !== '' && $deliv_addr !== $bill_addr) {
    $x .= '  <cac:Delivery>' . "\n";
    $x .= '    <cac:DeliveryLocation>' . "\n";
    $x .= '      <cac:Address>' . "\n";
    $x .= '        <cbc:StreetName>' . esc($deliv_addr) . '</cbc:StreetName>' . "\n";
    $x .= '        <cbc:CityName/>' . "\n";
    $x .= '        <cbc:PostalZone>0000</cbc:PostalZone>' . "\n";
    $x .= '        <cac:Country><cbc:IdentificationCode>DK</cbc:IdentificationCode></cac:Country>' . "\n";
    $x .= '      </cac:Address>' . "\n";
    $x .= '    </cac:DeliveryLocation>' . "\n";
    $x .= '  </cac:Delivery>' . "\n";
}

// Betaling
$x .= '  <cac:PaymentMeans>' . "\n";
$x .= '    <cbc:PaymentMeansCode>31</cbc:PaymentMeansCode>' . "\n";
$x .= '    <cbc:PaymentDueDate>' . esc($due) . '</cbc:PaymentDueDate>' . "\n";
$x .= '    <cac:PayeeFinancialAccount>' . "\n";
$x .= '      <cbc:ID>' . esc($bank_acc ?: '0000000000') . '</cbc:ID>' . "\n";
$x .= '      <cac:FinancialInstitutionBranch><cbc:ID>' . esc($bank_reg ?: '0000') . '</cbc:ID></cac:FinancialInstitutionBranch>' . "\n";
$x .= '    </cac:PayeeFinancialAccount>' . "\n";
$x .= '  </cac:PaymentMeans>' . "\n";
$x .= '  <cac:PaymentTerms><cbc:Note>' . esc($pay_note) . '</cbc:Note></cac:PaymentTerms>' . "\n";

// Moms (grupperet pr. sats)
$x .= '  <cac:TaxTotal>' . "\n";
$x .= '    <cbc:TaxAmount currencyID="' . esc($cur) . '">' . amt($vat_total) . '</cbc:TaxAmount>' . "\n";
foreach ($vat_groups as $rate_str => $g) {
    $x .= '    <cac:TaxSubtotal>' . "\n";
    $x .= '      <cbc:TaxableAmount currencyID="' . esc($cur) . '">' . amt($g['taxable']) . '</cbc:TaxableAmount>' . "\n";
    $x .= '      <cbc:TaxAmount currencyID="' . esc($cur) . '">' . amt($g['tax']) . '</cbc:TaxAmount>' . "\n";
    $x .= '      <cac:TaxCategory>' . "\n";
    $x .= '        <cbc:ID schemeID="UN/ECE 5305">' . ((float)$rate_str > 0 ? 'S' : 'Z') . '</cbc:ID>' . "\n";
    $x .= '        <cbc:Percent>' . amt($rate_str) . '</cbc:Percent>' . "\n";
    $x .= '        <cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153">VAT</cbc:ID></cac:TaxScheme>' . "\n";
    $x .= '      </cac:TaxCategory>' . "\n";
    $x .= '    </cac:TaxSubtotal>' . "\n";
}
$x .= '  </cac:TaxTotal>' . "\n";

// Totaler
$x .= '  <cac:LegalMonetaryTotal>' . "\n";
$x .= '    <cbc:LineExtensionAmount currencyID="' . esc($cur) . '">' . amt($subtotal) . '</cbc:LineExtensionAmount>' . "\n";
$x .= '    <cbc:TaxExclusiveAmount currencyID="' . esc($cur) . '">' . amt($subtotal) . '</cbc:TaxExclusiveAmount>' . "\n";
$x .= '    <cbc:TaxInclusiveAmount currencyID="' . esc($cur) . '">' . amt($grand_total) . '</cbc:TaxInclusiveAmount>' . "\n";
$x .= '    <cbc:PayableAmount currencyID="' . esc($cur) . '">' . amt($grand_total) . '</cbc:PayableAmount>' . "\n";
$x .= '  </cac:LegalMonetaryTotal>' . "\n";

// Fakturalinjer
$line_no = 0;
foreach ($lines as $ln) {
    $line_no++;
    $x .= '  <cac:InvoiceLine>' . "\n";
    $x .= '    <cbc:ID>' . $line_no . '</cbc:ID>' . "\n";
    $x .= '    <cbc:InvoicedQuantity unitCode="EA">' . amt($ln['qty']) . '</cbc:InvoicedQuantity>' . "\n";
    $x .= '    <cbc:LineExtensionAmount currencyID="' . esc($cur) . '">' . amt($ln['ext']) . '</cbc:LineExtensionAmount>' . "\n";
    $x .= '    <cac:Item>' . "\n";
    $x .= '      <cbc:Name>' . esc($ln['text']) . '</cbc:Name>' . "\n";
    $x .= '      <cac:ClassifiedTaxCategory>' . "\n";
    $x .= '        <cbc:ID schemeID="UN/ECE 5305">' . ($ln['rate'] > 0 ? 'S' : 'Z') . '</cbc:ID>' . "\n";
    $x .= '        <cbc:Percent>' . amt($ln['rate']) . '</cbc:Percent>' . "\n";
    $x .= '        <cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153">VAT</cbc:ID></cac:TaxScheme>' . "\n";
    $x .= '      </cac:ClassifiedTaxCategory>' . "\n";
    $x .= '    </cac:Item>' . "\n";
    $x .= '    <cac:Price><cbc:PriceAmount currencyID="' . esc($cur) . '">' . amt($ln['price']) . '</cbc:PriceAmount></cac:Price>' . "\n";
    $x .= '  </cac:InvoiceLine>' . "\n";
}

$x .= '</Invoice>' . "\n";

// --- 7. ARKIVÉR KOPI (best-effort) ---
$filename = 'OIOUBL_Invoice_' . $doc_id . '.xml';
$archive_dir = __DIR__ . '/storage/einvoices';
if (is_dir($archive_dir) && is_writable($archive_dir)) {
    @file_put_contents($archive_dir . '/' . $filename, $x);
}

// --- 8. SEND SOM DOWNLOAD ---
ob_end_clean(); // smid evt. buffret output fra includes væk, så kun XML sendes
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($x));
echo $x;
exit;
