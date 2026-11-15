<?php # /inc/peppol.inc.php v:1.3.1 d:2026-09-15 i:evs
// Peppol BIS Billing 3.0 (EN 16931) e-faktura og e-kreditnota - EU's og Nordens fælles format (OIOUBL er den danske
// variant). Samme linjer og totaler som OIOUBL (inc/oioubl.inc.php), men momskategorier efter EN 16931:
// S = almindelig moms, Z = 0 % indenlands, K = EU-levering til en virksomhed med momsnummer, G = eksport uden for EU.
// Modtageren adresseres med kundens Peppol-ID ("0184:12345678"); uden det udledes det kun hvor reglen er sikker:
// EAN/GLN (0088), dansk CVR (0184), norsk org.nr. (0192), svensk org.nr. (0007), finsk Y-tunnus (0216 OVT), islandsk kennitala (0196).
require_once __DIR__ . '/oioubl.inc.php';

const PEPPOL_CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
const PEPPOL_PROFILE       = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

// "0184:12345678" -> ['0184', '12345678'], eller null hvis formatet er forkert
function peppol_id_parse(string $s): ?array {
    $s = trim($s);
    return preg_match('/^(\d{4}):([A-Za-z0-9][A-Za-z0-9.\-_:+]{0,49})$/', $s, $m) ? [$m[1], $m[2]] : null;
}

// Fritekstadresse -> gade, postnr., by efter landets postnummerform (tc_split_address())
function peppol_split_address(string $txt, string $zip = '', string $city = '', string $cc = ''): array {
    return tc_split_address($txt, $zip, $city, $cc);
}

function peppol_document($conn, int $inv_id): array {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $amt = fn($v) => number_format((float)$v, 2, '.', '');
    $num = fn($v) => rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    $opt = fn(string $col) => db_has_column($conn, 'customers', $col) ? ", c.$col" : '';
    $res = DB::query($conn, "SELECT i.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr, c.cust_phone, c.cust_contact_person"
                          . $opt('cust_ean') . $opt('cust_country') . $opt('cust_vat_id') . $opt('cust_peppol_id')
                          . " FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id");
    $inv = $res ? DB::fetch_assoc($res) : null;
    if (!$inv) throw new Exception(lang('@Invoice not found.'));
    if (strtolower($inv['inv_status'] ?? '') === 'draft') throw new Exception(lang('@Invoice must be posted before it can be exported.'));
    $credit = !empty($inv['credit_ref']);
    $s = get_settings($conn);
    $home = strtoupper((string)tc_country_pack('iso', 'DK'));
    $co_id = preg_replace('/\D/', '', $s['company_cvr'] ?? '');
    $co_name = trim($s['company_name'] ?? '');
    if ($co_name === '' || $co_id === '') throw new Exception(sprintf(lang('@Your company name and %s must be filled in under Company Settings before an e-invoice can be generated.'), tc_company_id_label()));
    require_once __DIR__ . '/registry_nordic.inc.php';
    if (in_array(tc_country_pack('company_id.checksum'), ['no_mod11', 'se_luhn', 'fi_ytunnus', 'is_kennitala', 'be_mod97', 'ee_mod11', 'lv_mod11', 'lt_mod11', 'pl_nip'], true) && !tc_company_id_valid($co_id))
        throw new Exception(sprintf(lang('@Your company number (%s) is not valid - correct it under Company Settings.'), tc_company_id_label()));
    // Momsnummer: tc_company_vat_id() (NO...MVA, SE...01; Island: det indtastede VSK-nummer - ikke kennitalaen).
    // Finlands endpoint er OVT (0216: "0037" + Y-tunnus)
    $co_vat = tc_company_vat_id();
    if ($co_vat === '') throw new Exception(lang('@Your VAT number is missing - enter it under Company Settings before an e-invoice can be generated.'));
    $co_scheme = (string)tc_country_pack('peppol.endpoint_scheme', '0184');
    $co_ep     = (string)tc_country_pack('peppol.endpoint_prefix', '') . $co_id;
    $co_legal  = (string)tc_country_pack('peppol.legal_scheme', $co_scheme);
    // Tyskland/Polen: Peppol-adressen (og den juridiske id) er momsnummeret (9930:DE..., 9945:PL...), ikke firma-id'et
    if (tc_country_pack('peppol.endpoint_id') === 'vat') { $co_ep = $co_vat;  $co_id = $co_vat; }

    // Modtagerens Peppol-ID
    $cust_country = strtoupper(trim((string)($inv['cust_country'] ?? ''))) ?: $home;
    $cust_vat = (string)(tc_vat_id_clean($inv['cust_vat_id'] ?? '') ?? '');
    $cust_ean = (string)(tc_ean($inv['cust_ean'] ?? '') ?? '');
    $cust_reg = preg_replace('/\D/', '', (string)($inv['cust_cvr'] ?? ''));
    $ep = peppol_id_parse((string)($inv['cust_peppol_id'] ?? ''));
    if (!$ep && $cust_ean !== '') $ep = ['0088', $cust_ean];
    // (kun et nummer med gyldigt kontrolciffer - Peppol afviser hele fakturaen ellers, PEPPOL-COMMON-R041)
    require_once __DIR__ . '/registry_nordic.inc.php';
    if (!$ep && $cust_country === 'DK' && strlen($cust_reg) === 8) $ep = ['0184', $cust_reg];
    if (!$ep && $cust_country === 'NO' && tc_company_id_valid($cust_reg, 'no_mod11')) $ep = ['0192', $cust_reg];
    if (!$ep && $cust_country === 'SE' && strlen($cust_reg) === 10 && tc_company_id_valid($cust_reg, 'se_luhn')) $ep = ['0007', $cust_reg];
    if (!$ep && $cust_country === 'FI' && tc_company_id_valid($cust_reg, 'fi_ytunnus')) $ep = ['0216', '0037' . str_pad($cust_reg, 8, '0', STR_PAD_LEFT)];
    if (!$ep && $cust_country === 'IS' && tc_company_id_valid($cust_reg, 'is_kennitala')) $ep = ['0196', $cust_reg];
    if (!$ep && $cust_country === 'BE' && tc_company_id_valid($cust_reg, 'be_mod97')) $ep = ['0208', str_pad($cust_reg, 10, '0', STR_PAD_LEFT)];
    if (!$ep && $cust_country === 'NL' && strlen($cust_reg) === 8) $ep = ['0106', $cust_reg];
    if (!$ep && $cust_country === 'DE' && preg_match('/^DE[0-9]{9}$/', $cust_vat)) $ep = ['9930', $cust_vat];
    if (!$ep && $cust_country === 'EE' && tc_company_id_valid($cust_reg, 'ee_mod11')) $ep = ['0191', $cust_reg];
    if (!$ep && $cust_country === 'LV' && tc_company_id_valid($cust_reg, 'lv_mod11')) $ep = ['0218', $cust_reg];
    if (!$ep && $cust_country === 'LT' && tc_company_id_valid($cust_reg, 'lt_mod11')) $ep = ['0200', $cust_reg];
    if (!$ep && $cust_country === 'PL' && preg_match('/^PL[0-9]{10}$/', $cust_vat)) $ep = ['9945', $cust_vat];
    if (!$ep) throw new Exception(lang("@The customer has no Peppol ID - enter it on the customer card (e.g. 0184:12345678), or an EAN number."));

    if ($credit) {
        if (empty($inv['credit_note_no'])) throw new Exception(lang('@The credit note has no credit note number yet - run the credit note number migration first.'));
        $orig = DB::fetch_assoc(DB::query($conn, "SELECT invoice_no, inv_date FROM invoices WHERE inv_id = " . (int)$inv['credit_ref']));
        if (!$orig || empty($orig['invoice_no'])) throw new Exception(lang('@The credited invoice was not found.'));
        $doc_no = oioubl_credit_no($inv['credit_note_no']);
    } else {
        $doc_no = (string)$inv['invoice_no'];
    }
    $root = $credit ? 'CreditNote' : 'Invoice';
    $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', $inv['currency'] ?? setting('currency', 'DKK'))) ?: 'DKK';
    $cid = ' currencyID="' . $esc($cur) . '"';
    $issue = date('Y-m-d', strtotime($inv['inv_date'] ?: 'now'));
    $due = date('Y-m-d', strtotime($inv['inv_due_date'] ?: $inv['inv_date'] ?: 'now'));

    // Momskategori for 0 %-linjer: EU-levering (K) kræver kundens momsnummer i et andet EU-land, eksport (G) et land uden for EU
    $zero_cat = 'Z';
    if ($cust_country !== $home) {
        // (kun en sælger i EU leverer inden for EU - fra Norge/Island er alt salg til udlandet eksport)
        $home_eu = in_array($home, TC_EU_COUNTRIES, true);
        if ($home_eu && in_array($cust_country, TC_EU_COUNTRIES, true) && $cust_vat !== '') $zero_cat = 'K';
        elseif (!$home_eu || !in_array($cust_country, TC_EU_COUNTRIES, true)) $zero_cat = 'G';
    }
    $exempt = ['K' => ['VATEX-EU-IC', 'Intra-Community supply'], 'G' => ['VATEX-EU-G', 'Export outside the EU'], 'AE' => ['VATEX-EU-AE', 'Reverse charge'],
               'E' => [null, 'Exempt from VAT']];
    // Indenlandsk omvendt betalingspligt (fx Sveriges byggeydelser, Finlands rakentamispalvelut): kategori AE, når
    // linjens konto har momskodetypen reverse_domestic og køberen er i samme land. Momsfritaget (typen exempt, fx husleje
    // eller sundhedsydelser) er kategori E, ikke nulsats (Z)
    $line_types = tc_invoice_line_vat_types($conn, $inv_id);

    $lines = [];  $groups = [];  $subtotal = 0.0;
    $res_l = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC");
    while ($res_l && ($l = DB::fetch_assoc($res_l))) {
        $qty = abs((float)$l['quantity']);
        if ($qty == 0) continue;
        $price = (float)$l['price_each'];
        $disc = (float)($l['discount_pct'] ?? 0);
        if ($disc > 0) $price = round($price * (100 - $disc) / 100, 4);
        $rate = (float)($l['line_vat_rate'] ?? tc_vat_std_rate());
        $lt  = $line_types[(int)$l['line_id']] ?? null;
        $cat = $rate > 0 ? 'S' : (($lt === 'reverse_domestic' && $cust_country === $home) ? 'AE' : ($lt === 'exempt' ? 'E' : $zero_cat));
        $ext = round($qty * $price, 2);
        $subtotal += $ext;
        $gk = $cat . '|' . number_format($rate, 2, '.', '');
        $groups[$gk] = ['cat' => $cat, 'rate' => $rate, 'taxable' => ($groups[$gk]['taxable'] ?? 0) + $ext];
        $lines[] = ['text' => trim((string)$l['line_text']) ?: '-', 'qty' => $qty, 'price' => $price, 'rate' => $rate, 'cat' => $cat, 'ext' => $ext];
    }
    if (!$lines) throw new Exception(lang('@The invoice has no lines.'));
    // EN 16931: momsen pr. kategori = grundlaget × sats, afrundet (BR-CO-17) - totalen er summen af kategorierne
    $vat_total = 0.0;
    foreach ($groups as $k => $g) { $groups[$k]['tax'] = round($g['taxable'] * $g['rate'] / 100, 2);  $vat_total += $groups[$k]['tax']; }
    $subtotal = round($subtotal, 2);  $vat_total = round($vat_total, 2);  $total = round($subtotal + $vat_total, 2);

    $co_addr = peppol_split_address((string)($s['company_address'] ?? ''), (string)($s['company_zip'] ?? ''), (string)($s['company_city'] ?? ''), $home);
    $cust_addr = peppol_split_address((string)$inv['cust_address'], '', '', $cust_country);
    // Tyskland og Holland: en indenlandsk køber skal have gade, postnummer og by (DE-R-008/009, NL-R-004), og en tysk
    // sælger skal have en kontakt (DE-R-002) - ellers afviste modtagerens accesspunkt hele fakturaen
    if ($cust_country === $home && in_array($home, ['DE', 'NL'], true) && ($cust_addr['street'] === '' || $cust_addr['zip'] === '' || $cust_addr['city'] === ''))
        throw new Exception(lang('@The customer\'s address must have a street, a postcode and a city (one line each, or "street, postcode city") before a Peppol e-invoice can be made.'));
    if ($home === 'DE' && $cust_country === 'DE' && (trim($s['company_phone'] ?? '') === '' || trim($s['company_email'] ?? '') === ''))
        throw new Exception(lang('@A German e-invoice needs the seller\'s phone number and email - fill them in under Company Settings.'));
    if ($co_addr['city'] === '' || !tc_zip_ok($co_addr['zip'], $home) || ($co_addr['zip'] === '' && tc_zip_pattern($home) !== null)) throw new Exception(lang('@Your company address needs a postal code and a city (Company Settings) before an e-invoice can be generated.'));
    $address = function (array $a, string $country) use ($esc): string {
        return '<cac:PostalAddress>' . ($a['street'] !== '' ? '<cbc:StreetName>' . $esc($a['street']) . '</cbc:StreetName>' : '')
             . ($a['city'] !== '' ? '<cbc:CityName>' . $esc($a['city']) . '</cbc:CityName>' : '') . ($a['zip'] !== '' ? '<cbc:PostalZone>' . $esc($a['zip']) . '</cbc:PostalZone>' : '')
             . '<cac:Country><cbc:IdentificationCode>' . $esc($country) . '</cbc:IdentificationCode></cac:Country></cac:PostalAddress>';
    };
    $taxcat = function (string $tag, string $cat, float $rate, bool $reason) use ($num, $exempt): string {
        return '<cac:' . $tag . '><cbc:ID>' . $cat . '</cbc:ID><cbc:Percent>' . $num($rate) . '</cbc:Percent>'
             . ($reason && isset($exempt[$cat]) ? ($exempt[$cat][0] !== null ? '<cbc:TaxExemptionReasonCode>' . $exempt[$cat][0] . '</cbc:TaxExemptionReasonCode>' : '') . '<cbc:TaxExemptionReason>' . $exempt[$cat][1] . '</cbc:TaxExemptionReason>' : '')
             . '<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:' . $tag . '>';
    };

    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<' . $root . ' xmlns="urn:oasis:names:specification:ubl:schema:xsd:' . $root . '-2"'
       . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
       . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n"
       . '<cbc:CustomizationID>' . PEPPOL_CUSTOMIZATION . '</cbc:CustomizationID><cbc:ProfileID>' . PEPPOL_PROFILE . '</cbc:ProfileID>' . "\n"
       . '<cbc:ID>' . $esc($doc_no) . '</cbc:ID><cbc:IssueDate>' . $issue . '</cbc:IssueDate>';
    if (!$credit) $x .= '<cbc:DueDate>' . $due . '</cbc:DueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>';
    else          $x .= '<cbc:CreditNoteTypeCode>381</cbc:CreditNoteTypeCode>';
    $note = trim((string)($inv['inv_note'] ?? ''));
    if ($note !== '') $x .= '<cbc:Note>' . $esc($note) . '</cbc:Note>';
    // Køberens reference er påkrævet i Peppol (eller en ordrereference): kundens reference, kontaktperson, ellers dokumentnummeret
    $buyer_ref = trim((string)($inv['cust_reference'] ?? '')) ?: trim((string)($inv['cust_contact_person'] ?? '')) ?: $doc_no;
    $x .= '<cbc:DocumentCurrencyCode>' . $esc($cur) . '</cbc:DocumentCurrencyCode><cbc:BuyerReference>' . $esc($buyer_ref) . '</cbc:BuyerReference>' . "\n";
    if ($credit) {
        $x .= '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>' . $esc($orig['invoice_no']) . '</cbc:ID><cbc:IssueDate>' . date('Y-m-d', strtotime($orig['inv_date'])) . '</cbc:IssueDate></cac:InvoiceDocumentReference></cac:BillingReference>' . "\n";
    }
    // Sælger
    $x .= '<cac:AccountingSupplierParty><cac:Party><cbc:EndpointID schemeID="' . $esc($co_scheme) . '">' . $esc($co_ep) . '</cbc:EndpointID>'
        . '<cac:PartyName><cbc:Name>' . $esc($co_name) . '</cbc:Name></cac:PartyName>' . $address($co_addr, $home)
        . '<cac:PartyTaxScheme><cbc:CompanyID>' . $esc($co_vat) . '</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'
        // (Norge: "Foretaksregisteret" som eget skatteskema TAX - Peppol-reglen NO-R-002)
        . (tc_register_text() !== '' ? '<cac:PartyTaxScheme><cbc:CompanyID>' . $esc(tc_register_text()) . '</cbc:CompanyID><cac:TaxScheme><cbc:ID>TAX</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>' : '')
        // (et skema der ikke er på ISO 6523-listen - Tysklands 9930, Polens 9945 - må ikke stå her, BR-CL-11: id'et uden skema)
        . '<cac:PartyLegalEntity><cbc:RegistrationName>' . $esc($co_name) . '</cbc:RegistrationName><cbc:CompanyID' . ($co_legal !== '' ? ' schemeID="' . $esc($co_legal) . '"' : '') . '>' . $esc($co_id) . '</cbc:CompanyID>'
        . '</cac:PartyLegalEntity>';
    $ph = trim($s['company_phone'] ?? '');  $ml = trim($s['company_email'] ?? '');
    $cn = trim($s['company_contact_person'] ?? '');
    if ($ph !== '' || $ml !== '') $x .= '<cac:Contact>' . ($cn !== '' ? '<cbc:Name>' . $esc($cn) . '</cbc:Name>' : '') . ($ph !== '' ? '<cbc:Telephone>' . $esc($ph) . '</cbc:Telephone>' : '') . ($ml !== '' ? '<cbc:ElectronicMail>' . $esc($ml) . '</cbc:ElectronicMail>' : '') . '</cac:Contact>';
    $x .= '</cac:Party></cac:AccountingSupplierParty>' . "\n";
    // Køber
    $x .= '<cac:AccountingCustomerParty><cac:Party><cbc:EndpointID schemeID="' . $esc($ep[0]) . '">' . $esc($ep[1]) . '</cbc:EndpointID>'
        . '<cac:PartyName><cbc:Name>' . $esc($inv['cust_name']) . '</cbc:Name></cac:PartyName>' . $address($cust_addr, $cust_country);
    // Omvendt betalingspligt kræver køberens momsnummer (BR-AE-02): udledes af firma-id'et som sælgerens eget
    if (isset(array_column($groups, 'cat', 'cat')['AE']) && $cust_vat === '') {
        if ($cust_reg === '') throw new Exception(lang('@The invoice has reverse charge VAT, but the customer has neither a VAT number nor a company number.'));
        // Kun for en kunde i sælgerens eget land - før fik en udenlandsk kunde uden momsnummer sælgerlandets præfiks foran
        // sit eget firma-id (fx "DK" + et tysk registreringsnummer), og landet kan have et momsnummer der ikke er firma-id'et
        $cv_re = (string)tc_country_pack('vat_id.regex', '');
        $cv    = (string)tc_country_pack('vat_id.prefix', $home) . $cust_reg . (string)tc_country_pack('vat_id.suffix', '');
        if ($cust_country !== $home || tc_country_pack('vat_id.derive', true) === false || ($cv_re !== '' && !preg_match('/' . str_replace('/', '\/', $cv_re) . '/', $cv)))
            throw new Exception(lang('@The invoice has reverse charge VAT, but the customer has no VAT number - enter it on the customer card.'));
        $cust_vat = $cv;
    }
    if ($cust_vat !== '') $x .= '<cac:PartyTaxScheme><cbc:CompanyID>' . $esc($cust_vat) . '</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>';
    // Island: en islandsk køber skal have sin kennitala med (IS-R-004), når sælger også er islandsk
    $cust_kt = ($cust_country === 'IS' && tc_company_id_valid($cust_reg, 'is_kennitala')) ? $cust_reg : '';
    if ($home === 'IS' && $cust_country === 'IS' && $cust_kt === '') throw new Exception(lang('@The customer is Icelandic - enter a valid kennitala on the customer card before a Peppol e-invoice can be made.'));
    $x .= '<cac:PartyLegalEntity><cbc:RegistrationName>' . $esc($inv['cust_name']) . '</cbc:RegistrationName>'
        . ($cust_kt !== '' ? '<cbc:CompanyID schemeID="0196">' . $esc($cust_kt) . '</cbc:CompanyID>' : '') . '</cac:PartyLegalEntity>';
    $cn = trim((string)($inv['cust_contact_person'] ?? ''));  $cm = trim((string)($inv['cust_email'] ?? ''));
    if ($cn !== '' || $cm !== '') $x .= '<cac:Contact>' . ($cn !== '' ? '<cbc:Name>' . $esc($cn) . '</cbc:Name>' : '') . ($cm !== '' ? '<cbc:ElectronicMail>' . $esc($cm) . '</cbc:ElectronicMail>' : '') . '</cac:Contact>';
    $x .= '</cac:Party></cac:AccountingCustomerParty>' . "\n";
    // EU-levering (K): leveringsdato og -land er påkrævet (BR-IC-11/12)
    if (isset(array_column($groups, 'cat', 'cat')['K'])) {
        $x .= '<cac:Delivery><cbc:ActualDeliveryDate>' . $issue . '</cbc:ActualDeliveryDate><cac:DeliveryLocation><cac:Address><cac:Country><cbc:IdentificationCode>' . $esc($cust_country) . '</cbc:IdentificationCode></cac:Country></cac:Address></cac:DeliveryLocation></cac:Delivery>' . "\n";
    }
    if (!$credit) {
        // Betalingsreferencen (KID/OCR/RF ...) som PaymentID, ellers fakturanummeret. Med IBAN: kode 58 (SEPA-overførsel)
        $reg = preg_replace('/\D/', '', $s['bank_reg'] ?? '');  $acc = preg_replace('/\D/', '', $s['bank_acc'] ?? '');
        $iban = preg_replace('/[^0-9A-Z]/', '', strtoupper((string)($s['bank_iban'] ?? '')));
        $bic  = preg_replace('/[^0-9A-Z]/', '', strtoupper((string)($s['bank_bic'] ?? '')));
        $pref = tc_payment_ref($inv['invoice_no'] ?? '');
        $pay_id = ($pref && $pref['scheme'] !== 'fik71') ? $pref['ref'] : $doc_no;
        if ($iban !== '') {
            $x .= '<cac:PaymentMeans><cbc:PaymentMeansCode>58</cbc:PaymentMeansCode><cbc:PaymentID>' . $esc($pay_id) . '</cbc:PaymentID>'
                . '<cac:PayeeFinancialAccount><cbc:ID>' . $esc($iban) . '</cbc:ID>' . ($bic !== '' ? '<cac:FinancialInstitutionBranch><cbc:ID>' . $esc($bic) . '</cbc:ID></cac:FinancialInstitutionBranch>' : '')
                . '</cac:PayeeFinancialAccount></cac:PaymentMeans>' . "\n";
        } elseif ($reg !== '' && $acc !== '') {
            $x .= '<cac:PaymentMeans><cbc:PaymentMeansCode>42</cbc:PaymentMeansCode><cbc:PaymentID>' . $esc($pay_id) . '</cbc:PaymentID>'
                . '<cac:PayeeFinancialAccount><cbc:ID>' . $esc($acc) . '</cbc:ID><cac:FinancialInstitutionBranch><cbc:ID>' . $esc($reg) . '</cbc:ID></cac:FinancialInstitutionBranch></cac:PayeeFinancialAccount></cac:PaymentMeans>' . "\n";
        } else {
            $x .= '<cac:PaymentMeans><cbc:PaymentMeansCode>1</cbc:PaymentMeansCode></cac:PaymentMeans>' . "\n";
        }
        $terms = trim((string)($s['default_payment_terms'] ?? ''));
        if ($terms !== '') $x .= '<cac:PaymentTerms><cbc:Note>' . $esc($terms) . '</cbc:Note></cac:PaymentTerms>' . "\n";
    }
    $x .= '<cac:TaxTotal><cbc:TaxAmount' . $cid . '>' . $amt($vat_total) . '</cbc:TaxAmount>';
    foreach ($groups as $g) {
        $x .= '<cac:TaxSubtotal><cbc:TaxableAmount' . $cid . '>' . $amt($g['taxable']) . '</cbc:TaxableAmount><cbc:TaxAmount' . $cid . '>' . $amt($g['tax']) . '</cbc:TaxAmount>'
            . $taxcat('TaxCategory', $g['cat'], $g['rate'], true) . '</cac:TaxSubtotal>';
    }
    $x .= '</cac:TaxTotal>' . "\n"
        . '<cac:LegalMonetaryTotal><cbc:LineExtensionAmount' . $cid . '>' . $amt($subtotal) . '</cbc:LineExtensionAmount>'
        . '<cbc:TaxExclusiveAmount' . $cid . '>' . $amt($subtotal) . '</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount' . $cid . '>' . $amt($total) . '</cbc:TaxInclusiveAmount>'
        . '<cbc:PayableAmount' . $cid . '>' . $amt($total) . '</cbc:PayableAmount></cac:LegalMonetaryTotal>' . "\n";
    $tag = $credit ? 'CreditNoteLine' : 'InvoiceLine';  $qtag = $credit ? 'CreditedQuantity' : 'InvoicedQuantity';
    foreach ($lines as $i => $ln) {
        $x .= '<cac:' . $tag . '><cbc:ID>' . ($i + 1) . '</cbc:ID><cbc:' . $qtag . ' unitCode="C62">' . $num($ln['qty']) . '</cbc:' . $qtag . '>'
            . '<cbc:LineExtensionAmount' . $cid . '>' . $amt($ln['ext']) . '</cbc:LineExtensionAmount>'
            . '<cac:Item><cbc:Description>' . $esc($ln['text']) . '</cbc:Description><cbc:Name>' . $esc(mb_substr($ln['text'], 0, 100)) . '</cbc:Name>'
            . $taxcat('ClassifiedTaxCategory', $ln['cat'], $ln['rate'], false) . '</cac:Item>'
            . '<cac:Price><cbc:PriceAmount' . $cid . '>' . $num($ln['price']) . '</cbc:PriceAmount></cac:Price>'
            . '</cac:' . $tag . '>' . "\n";
    }
    $x .= '</' . $root . '>' . "\n";
    return ['xml' => $x, 'filename' => 'PEPPOL_' . $root . '_' . $doc_no . '.xml', 'kind' => $root, 'no' => $doc_no];
}
