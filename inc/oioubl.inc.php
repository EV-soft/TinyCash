<?php # /inc/oioubl.inc.php v:1.0.0 d:2026-11-05 i:evs
// OIOUBL 2.1 e-faktura og e-kreditnota (Erhvervsstyrelsen, schematron 1.17.2) - bygges her, så export_oioubl.php og
// testpakken (tools/tests/, der validerer mod de officielle regler) bruger nøjagtig samme kode.
// oioubl_document() kaster en Exception med en oversat tekst, når dokumentet ikke kan laves gyldigt.

// Kreditnotanummeret som kunden ser det (samme format som invoice_view.php/kontoudtoget)
function oioubl_credit_no($n): string { return 'KN-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT); }

// Samme dokument giver altid samme UUID (navnebaseret, RFC 4122 v5-format) - en ny eksport af en faktura er ikke et
// nyt dokument, og kreditnotaen skal kunne henvise til fakturaens UUID uden at den er gemt nogen steder.
function oioubl_uuid(string $cvr, string $kind, string $no): string {
    $h = sha1("tinycash-oioubl|$cvr|$kind|$no");
    return sprintf('%s-%s-5%s-%x%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), (hexdec($h[16]) & 3) | 8, substr($h, 17, 3), substr($h, 20, 12));
}

// "Vejnavn 12B, 2870 Dyssegård" -> gade, husnummer, postnr., by (OIOUBL er kun dansk - se tc_split_address())
function oioubl_split_address(string $txt, string $zip = '', string $city = ''): array {
    $a = tc_split_address($txt, $zip, $city, 'DK');
    $street = $a['street'];  $no = '';
    if (preg_match('/^(.*\S)\s+(\d+[A-Za-zÆØÅæøå]?(?:[,\s].*)?)$/u', $street, $m)) { $street = $m[1];  $no = trim($m[2]); }
    return ['street' => $street, 'no' => $no, 'zip' => preg_replace('/\D/', '', $a['zip']), 'city' => $a['city']];
}

function oioubl_document($conn, int $inv_id): array {
    $esc  = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $amt  = fn($v) => number_format((float)$v, 2, '.', '');
    $num  = fn($v) => rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');   // antal/enhedspris: op til 4 decimaler
    $ean_sql = db_has_column($conn, 'customers', 'cust_ean') ? ', c.cust_ean' : '';
    $res  = DB::query($conn, "SELECT i.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr, c.cust_phone, c.cust_contact_person$ean_sql
                              FROM invoices i JOIN customers c ON i.cust_id = c.cust_id WHERE i.inv_id = $inv_id");
    $inv  = $res ? DB::fetch_assoc($res) : null;
    if (!$inv) throw new Exception(lang('@Invoice not found.'));
    // En kladde har intet nummer endnu - en ubogført faktura må aldrig kunne arkiveres som en gyldig e-faktura
    if (strtolower($inv['inv_status'] ?? '') === 'draft') throw new Exception(lang('@Invoice must be posted before it can be exported.'));
    $credit = !empty($inv['credit_ref']);
    $s      = get_settings($conn);
    $co_cvr = preg_replace('/\D/', '', $s['company_cvr'] ?? '');
    $co_name = trim($s['company_name'] ?? '');
    // Et formelt dokument må ikke få opdigtede id'er ("00000000") - mangler de, laves dokumentet slet ikke
    if ($co_name === '' || $co_cvr === '') throw new Exception(lang('@Your company name and CVR number must be filled in under Company Settings before an OIOUBL e-invoice can be generated.'));
    // Modtageren adresseres med EAN-nummeret (offentlige kunder) eller ellers CVR-nummeret
    $cust_ean = tc_ean($inv['cust_ean'] ?? '');
    if ($cust_ean === null) throw new Exception(lang('@The EAN number must be 13 digits with a valid check digit, or leave the field empty.'));
    $cust_cvr = preg_replace('/\D/', '', $inv['cust_cvr'] ?? '');
    if ($cust_cvr === '' && $cust_ean === '') throw new Exception(lang('@The customer has neither a CVR nor an EAN number - an OIOUBL e-invoice needs one of them for the receiver.'));
    // DK:CVR er præcis 8 cifre - et udenlandsk momsnummer (fx GB123456789) kan ikke bruges som modtager i OIOUBL
    if (($cust_cvr !== '' && !preg_match('/^(DK)?\s*\d{8}$/i', trim((string)$inv['cust_cvr']))) || strlen($co_cvr) !== 8)
        throw new Exception(lang('@OIOUBL needs Danish CVR numbers (8 digits) for both sender and receiver.'));
    if ($credit) {
        if (empty($inv['credit_note_no'])) throw new Exception(lang('@The credit note has no credit note number yet - run the credit note number migration first.'));
        $orig = DB::fetch_assoc(DB::query($conn, "SELECT invoice_no, inv_date FROM invoices WHERE inv_id = " . (int)$inv['credit_ref']));
        if (!$orig || empty($orig['invoice_no'])) throw new Exception(lang('@The credited invoice was not found.'));
        $doc_no = oioubl_credit_no($inv['credit_note_no']);
    } else {
        $doc_no = (string)$inv['invoice_no'];
    }
    $root  = $credit ? 'CreditNote' : 'Invoice';
    $cur   = strtoupper(preg_replace('/[^A-Za-z]/', '', $inv['currency'] ?? 'DKK')) ?: 'DKK';
    $cid   = ' currencyID="' . $esc($cur) . '"';
    $issue = date('Y-m-d', strtotime($inv['inv_date'] ?: 'now'));
    $due   = date('Y-m-d', strtotime($inv['inv_due_date'] ?: $inv['inv_date'] ?: 'now'));

    // Linjer - beløb afrundes til øre PR. LINJE før summering, så summen af linjerne er præcis lig totalerne
    // (schematronens konsistensregler). En kreditnota gemmes med negativt antal, men OIOUBL vil have positive beløb.
    $lines = [];  $subtotal = 0.0;  $vat_total = 0.0;  $groups = [];
    $res_l = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC");
    while ($res_l && ($l = DB::fetch_assoc($res_l))) {
        $qty   = abs((float)$l['quantity']);
        $price = (float)$l['price_each'];
        // Linjerabat sendes som allerede nedsat enhedspris, så antal × pris stadig er lig linjebeløbet
        $disc  = (float)($l['discount_pct'] ?? 0);
        if ($disc > 0) $price = round($price * (100 - $disc) / 100, 4);
        if ($qty == 0) continue;
        $rate  = (float)($l['line_vat_rate'] ?? $l['vat_rate'] ?? tc_vat_std_rate());
        $ext   = round($qty * $price, 2);
        $vat   = round($ext * $rate / 100, 2);
        $subtotal += $ext;  $vat_total += $vat;
        $rk = number_format($rate, 2, '.', '');
        $groups[$rk]['taxable'] = ($groups[$rk]['taxable'] ?? 0) + $ext;
        $groups[$rk]['tax']     = ($groups[$rk]['tax'] ?? 0) + $vat;
        $lines[] = ['text' => trim((string)$l['line_text']) ?: '-', 'qty' => $qty, 'price' => $price, 'rate' => $rate, 'ext' => $ext, 'vat' => $vat];
    }
    if (!$lines) throw new Exception(lang('@The invoice has no lines.'));
    $total = round($subtotal + $vat_total, 2);

    $taxcat = function (float $rate) use ($esc, $num): string {
        return '<cac:TaxCategory><cbc:ID schemeAgencyID="320" schemeID="urn:oioubl:id:taxcategoryid-1.1">' . ($rate > 0 ? 'StandardRated' : 'ZeroRated') . '</cbc:ID>'
             . '<cbc:Percent>' . $num($rate) . '</cbc:Percent>'
             . '<cac:TaxScheme><cbc:ID schemeAgencyID="320" schemeID="urn:oioubl:id:taxschemeid-1.1">63</cbc:ID><cbc:Name>Moms</cbc:Name></cac:TaxScheme></cac:TaxCategory>';
    };
    $address = function (array $a) use ($esc): string {
        return '<cac:PostalAddress><cbc:AddressFormatCode listAgencyID="320" listID="urn:oioubl:codelist:addressformatcode-1.1">StructuredDK</cbc:AddressFormatCode>'
             . '<cbc:StreetName>' . $esc($a['street']) . '</cbc:StreetName>'
             . ($a['no'] !== '' ? '<cbc:BuildingNumber>' . $esc($a['no']) . '</cbc:BuildingNumber>' : '')
             . '<cbc:CityName>' . $esc($a['city']) . '</cbc:CityName><cbc:PostalZone>' . $esc($a['zip']) . '</cbc:PostalZone>'
             . '<cac:Country><cbc:IdentificationCode>DK</cbc:IdentificationCode></cac:Country></cac:PostalAddress>';
    };
    $party = function (string $cvr, string $ean, string $name, array $addr, string $ref, string $contact, string $phone, string $mail, bool $supplier) use ($esc, $address): string {
        $id = $ean !== '' ? ' schemeAgencyID="9" schemeID="GLN">' . $ean : ' schemeID="DK:CVR">DK' . $cvr;
        $x = '<cac:Party><cbc:EndpointID' . $id . '</cbc:EndpointID>'
           . '<cac:PartyIdentification><cbc:ID' . $id . '</cbc:ID></cac:PartyIdentification>'
           . '<cac:PartyName><cbc:Name>' . $esc($name) . '</cbc:Name></cac:PartyName>' . $address($addr);
        if ($supplier) $x .= '<cac:PartyTaxScheme><cbc:CompanyID schemeID="DK:SE">DK' . $cvr . '</cbc:CompanyID>'
                          . '<cac:TaxScheme><cbc:ID schemeAgencyID="320" schemeID="urn:oioubl:id:taxschemeid-1.1">63</cbc:ID><cbc:Name>Moms</cbc:Name></cac:TaxScheme></cac:PartyTaxScheme>';
        if ($cvr !== '') $x .= '<cac:PartyLegalEntity><cbc:RegistrationName>' . $esc($name) . '</cbc:RegistrationName><cbc:CompanyID schemeID="DK:CVR">DK' . $cvr . '</cbc:CompanyID></cac:PartyLegalEntity>';
        // Kontakt-ID er køberens reference (offentlige kunder: ordre-/personreference) - fakturaens kundereference,
        // ellers kontaktpersonen. En kundekontakt uden ID afvises, så den laves kun når der er en af dem.
        $ref = $ref !== '' ? $ref : $contact;
        // Køberen SKAL have en Contact (F-INV046/F-CRN042) - uden reference og kontaktperson bruges "n/a" som ID
        if (!$supplier && $ref === '') $ref = 'n/a';
        if ($ref !== '' || ($supplier && ($phone !== '' || $mail !== '')))
            $x .= '<cac:Contact>' . ($ref !== '' ? '<cbc:ID>' . $esc($ref) . '</cbc:ID>' : '') . ($contact !== '' ? '<cbc:Name>' . $esc($contact) . '</cbc:Name>' : '') . ($phone !== '' ? '<cbc:Telephone>' . $esc($phone) . '</cbc:Telephone>' : '')
                . ($mail !== '' ? '<cbc:ElectronicMail>' . $esc($mail) . '</cbc:ElectronicMail>' : '') . '</cac:Contact>';
        return $x . '</cac:Party>';
    };

    $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<' . $root . ' xmlns="urn:oasis:names:specification:ubl:schema:xsd:' . $root . '-2"'
        . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
        . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n"
        . '<cbc:UBLVersionID>2.1</cbc:UBLVersionID><cbc:CustomizationID>OIOUBL-2.1</cbc:CustomizationID>'
        . '<cbc:ProfileID schemeAgencyID="320" schemeID="urn:oioubl:id:profileid-1.2">urn:www.nesubl.eu:profiles:profile5:ver2.0</cbc:ProfileID>' . "\n"
        . '<cbc:ID>' . $esc($doc_no) . '</cbc:ID><cbc:CopyIndicator>false</cbc:CopyIndicator>'
        . '<cbc:UUID>' . oioubl_uuid($co_cvr, $root, $doc_no) . '</cbc:UUID><cbc:IssueDate>' . $issue . '</cbc:IssueDate>' . "\n";
    if (!$credit) $x .= '<cbc:InvoiceTypeCode listAgencyID="320" listID="urn:oioubl:codelist:invoicetypecode-1.1">380</cbc:InvoiceTypeCode>';
    $note = trim((string)($inv['inv_note'] ?? ''));
    if ($note !== '') $x .= '<cbc:Note>' . $esc($note) . '</cbc:Note>';
    $x .= '<cbc:DocumentCurrencyCode>' . $esc($cur) . '</cbc:DocumentCurrencyCode>' . "\n";
    if ($credit) {
        $x .= '<cac:DiscrepancyResponse><cbc:ReferenceID>' . $esc($orig['invoice_no']) . '</cbc:ReferenceID><cbc:Description>' . $esc($note !== '' ? $note : lang('@Credit Note')) . '</cbc:Description></cac:DiscrepancyResponse>'
            . '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>' . $esc($orig['invoice_no']) . '</cbc:ID>'
            . '<cbc:UUID>' . oioubl_uuid($co_cvr, 'Invoice', (string)$orig['invoice_no']) . '</cbc:UUID>'
            . '<cbc:IssueDate>' . date('Y-m-d', strtotime($orig['inv_date'])) . '</cbc:IssueDate></cac:InvoiceDocumentReference></cac:BillingReference>' . "\n";
    }
    // OIOUBL's strukturerede danske adresse kræver postnummer og by - hellere en forklaring end en fil der afvises
    $co_addr   = oioubl_split_address((string)($s['company_address'] ?? ''), (string)($s['company_zip'] ?? ''), (string)($s['company_city'] ?? ''));
    $cust_addr = oioubl_split_address((string)$inv['cust_address']);
    if (strlen($co_addr['zip']) !== 4 || $co_addr['city'] === '') throw new Exception(lang('@Your company address needs a 4-digit postal code and a city (Company Settings) before an OIOUBL e-invoice can be generated.'));
    if (strlen($cust_addr['zip']) !== 4 || $cust_addr['city'] === '' || $cust_addr['street'] === '') throw new Exception(lang("@The customer's address must end with postal code and city, e.g. 'Vejnavn 1, 2000 Frederiksberg'."));
    $x .= '<cac:AccountingSupplierParty>' . $party($co_cvr, '', $co_name, $co_addr, '', '', trim($s['company_phone'] ?? ''), trim($s['company_email'] ?? ''), true) . '</cac:AccountingSupplierParty>' . "\n";
    $x .= '<cac:AccountingCustomerParty>' . $party($cust_cvr, $cust_ean, (string)$inv['cust_name'], $cust_addr,
            trim((string)($inv['cust_reference'] ?? '')), trim($inv['cust_contact_person'] ?? ''), trim($inv['cust_phone'] ?? ''), trim($inv['cust_email'] ?? ''), false) . '</cac:AccountingCustomerParty>' . "\n";

    $deliv = trim($inv['delivery_address'] ?? '');
    if ($deliv !== '' && $deliv !== trim($inv['cust_address'] ?? '')) {
        $d = oioubl_split_address($deliv);
        if (strlen($d['zip']) !== 4 || $d['city'] === '') throw new Exception(lang("@The delivery address must end with postal code and city, e.g. 'Vejnavn 1, 2000 Frederiksberg'."));
        $x .= '<cac:Delivery><cac:DeliveryLocation><cac:Address><cbc:AddressFormatCode listAgencyID="320" listID="urn:oioubl:codelist:addressformatcode-1.1">StructuredDK</cbc:AddressFormatCode>'
            . '<cbc:StreetName>' . $esc($d['street']) . '</cbc:StreetName>' . ($d['no'] !== '' ? '<cbc:BuildingNumber>' . $esc($d['no']) . '</cbc:BuildingNumber>' : '')
            . '<cbc:CityName>' . $esc($d['city']) . '</cbc:CityName><cbc:PostalZone>' . $esc($d['zip']) . '</cbc:PostalZone>'
            . '<cac:Country><cbc:IdentificationCode>DK</cbc:IdentificationCode></cac:Country></cac:Address></cac:DeliveryLocation></cac:Delivery>' . "\n";
    }
    if (!$credit) {
        // Bankoverførsel til firmaets konto; uden bankoplysninger kun forfaldsdatoen (ingen opdigtede kontonumre)
        $reg = preg_replace('/\D/', '', $s['bank_reg'] ?? '');  $acc = preg_replace('/\D/', '', $s['bank_acc'] ?? '');
        $x .= '<cac:PaymentMeans><cbc:ID>1</cbc:ID>';
        if ($reg !== '' && $acc !== '') {
            $x .= '<cbc:PaymentMeansCode>42</cbc:PaymentMeansCode><cbc:PaymentDueDate>' . $due . '</cbc:PaymentDueDate>'
                . '<cbc:PaymentChannelCode listAgencyID="320" listID="urn:oioubl:codelist:paymentchannelcode-1.1">DK:BANK</cbc:PaymentChannelCode>'
                . '<cac:PayeeFinancialAccount><cbc:ID>' . $acc . '</cbc:ID><cbc:PaymentNote>' . $esc($doc_no) . '</cbc:PaymentNote>'
                . '<cac:FinancialInstitutionBranch><cbc:ID>' . $reg . '</cbc:ID></cac:FinancialInstitutionBranch></cac:PayeeFinancialAccount>';
        } else {
            $x .= '<cbc:PaymentMeansCode>1</cbc:PaymentMeansCode><cbc:PaymentDueDate>' . $due . '</cbc:PaymentDueDate>';
        }
        $x .= '</cac:PaymentMeans>' . "\n";
        $terms = trim((string)($s['default_payment_terms'] ?? ''));
        $x .= '<cac:PaymentTerms><cbc:ID>1</cbc:ID><cbc:PaymentMeansID>1</cbc:PaymentMeansID>' . ($terms !== '' ? '<cbc:Note>' . $esc($terms) . '</cbc:Note>' : '')
            . '<cbc:Amount' . $cid . '>' . $amt($total) . '</cbc:Amount></cac:PaymentTerms>' . "\n";
    }
    $x .= '<cac:TaxTotal><cbc:TaxAmount' . $cid . '>' . $amt($vat_total) . '</cbc:TaxAmount>';
    foreach ($groups as $rk => $g) {
        $x .= '<cac:TaxSubtotal><cbc:TaxableAmount' . $cid . '>' . $amt($g['taxable']) . '</cbc:TaxableAmount><cbc:TaxAmount' . $cid . '>' . $amt($g['tax']) . '</cbc:TaxAmount>' . $taxcat((float)$rk) . '</cac:TaxSubtotal>';
    }
    // OIOUBL: TaxExclusiveAmount er MOMSBELØBET (ikke beløbet ekskl. moms som i almindelig UBL)
    $x .= '</cac:TaxTotal>' . "\n" . '<cac:LegalMonetaryTotal><cbc:LineExtensionAmount' . $cid . '>' . $amt($subtotal) . '</cbc:LineExtensionAmount>'
        . '<cbc:TaxExclusiveAmount' . $cid . '>' . $amt($vat_total) . '</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount' . $cid . '>' . $amt($total) . '</cbc:TaxInclusiveAmount>'
        . '<cbc:PayableAmount' . $cid . '>' . $amt($total) . '</cbc:PayableAmount></cac:LegalMonetaryTotal>' . "\n";
    $tag = $credit ? 'CreditNoteLine' : 'InvoiceLine';  $qtag = $credit ? 'CreditedQuantity' : 'InvoicedQuantity';
    foreach ($lines as $i => $ln) {
        $x .= '<cac:' . $tag . '><cbc:ID>' . ($i + 1) . '</cbc:ID><cbc:' . $qtag . ' unitCode="EA">' . $num($ln['qty']) . '</cbc:' . $qtag . '>'
            . '<cbc:LineExtensionAmount' . $cid . '>' . $amt($ln['ext']) . '</cbc:LineExtensionAmount>'
            . '<cac:TaxTotal><cbc:TaxAmount' . $cid . '>' . $amt($ln['vat']) . '</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount' . $cid . '>' . $amt($ln['ext']) . '</cbc:TaxableAmount>'
            . '<cbc:TaxAmount' . $cid . '>' . $amt($ln['vat']) . '</cbc:TaxAmount>' . $taxcat($ln['rate']) . '</cac:TaxSubtotal></cac:TaxTotal>'
            . '<cac:Item><cbc:Description>' . $esc($ln['text']) . '</cbc:Description><cbc:Name>' . $esc(mb_substr($ln['text'], 0, 40)) . '</cbc:Name></cac:Item>'
            . '<cac:Price><cbc:PriceAmount' . $cid . '>' . $num($ln['price']) . '</cbc:PriceAmount><cbc:BaseQuantity unitCode="EA">1</cbc:BaseQuantity><cbc:OrderableUnitFactorRate>1</cbc:OrderableUnitFactorRate></cac:Price>'
            . '</cac:' . $tag . '>' . "\n";
    }
    $x .= '</' . $root . '>' . "\n";
    return ['xml' => $x, 'filename' => 'OIOUBL_' . $root . '_' . $doc_no . '.xml', 'kind' => $root, 'no' => $doc_no];
}
