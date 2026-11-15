<?php # /inc/saft_no.inc.php v:1.3.1 d:2026-09-15 i:evs
// Norsk SAF-T Financial v1.30 (Skatteetaten - obligatorisk fra regnskabsår 2025; v1.40 fra 2027 er bagudkompatibel).
// Forskellen fra den danske eksport: hver konto SKAL mappes til næringsspesifikasjonens GroupingCategory/GroupingCode,
// beløb er strukturerede (<DebitAmount><Amount>), der er en TaxTable med standard-mva-koder, og linjer på konti med
// en momskode får TaxInformation. Valideres mod den officielle XSD i testpakken (tools/tests/saft_no/).

// Næringsspesifikasjonens koder: [kode => [kategori, beskrivelse]] (json-data/saft_no_grouping.json, fra Skatteetaten)
function saft_no_codes(): array {
    static $c = null;
    if ($c === null) $c = (array)(json_decode((string)@file_get_contents(__DIR__ . '/../json-data/saft_no_grouping.json'), true)['codes'] ?? []);
    return $c;
}

// Hvilke kategorier en kontotype kan høre til, og koden når intet bedre findes
const SAFT_NO_TYPE_CATS = [
    'income'    => [['salgsinntekt', 'annenDriftsinntekt', 'finansinntekt'], '3000'],
    'expense'   => [['varekostnad', 'loennskostnad', 'annenDriftskostnad', 'finanskostnad', 'skattekostnad', 'resultatDisponeringForSAF-T'], '7700'],
    'asset'     => [['balanseverdiForAnleggsmiddel', 'balanseverdiForOmloepsmiddel'], '1570'],
    'bank'      => [['balanseverdiForOmloepsmiddel'], '1920'],
    'vat'       => [['kortsiktigGjeld'], '2740'],
    'liability' => [['kortsiktigGjeld', 'langsiktigGjeld'], '2990'],
    'equity'    => [['egenkapital', 'resultatDisponeringForSAF-T'], '2050'],
];

// Forslag til en konto: ['code', 'how' => exact|near|type]. Kontonummeret selv, hvis det er en kode af en passende
// kategori (en kontoplan efter NS 4102 rammer oftest præcist); ellers nærmeste lavere kode i samme hundrede/tusinde;
// ellers kontotypens standardkode. "near" og "type" bør gennemgås.
function saft_no_suggest(int $acc_id, string $type): array {
    $codes = saft_no_codes();
    [$cats, $fallback] = SAFT_NO_TYPE_CATS[$type] ?? SAFT_NO_TYPE_CATS['expense'];
    $k = (string)$acc_id;
    if (isset($codes[$k]) && in_array($codes[$k][0], $cats, true)) return ['code' => $k, 'how' => 'exact'];
    foreach ([2, 1] as $len) {
        if (strlen($k) !== 4) break;
        $best = null;
        foreach ($codes as $c => [$cat]) {
            $c = (string)$c;
            if (strlen($c) === 4 && in_array($cat, $cats, true) && strncmp($c, $k, $len) === 0 && $c <= $k) $best = $c;
        }
        if ($best !== null) return ['code' => $best, 'how' => 'near'];
    }
    return ['code' => $fallback, 'how' => 'type'];
}

// Gemte valg (indstillingen saft_no_grouping, JSON {konto: kode}) - kun koder der findes
function saft_no_saved($conn): array {
    $m = json_decode((string)setting('saft_no_grouping', '{}'), true);
    $codes = saft_no_codes();
    return is_array($m) ? array_filter($m, fn($c) => isset($codes[(string)$c])) : [];
}

// Hele kontoplanens mapping: [acc_id => ['acc_name', 'acc_type', 'code', 'category', 'how' => saved|exact|near|type]]
function saft_no_mapping($conn): array {
    $saved = saft_no_saved($conn);
    $codes = saft_no_codes();
    $out = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts ORDER BY acc_id");
    while ($res && ($a = DB::fetch_assoc($res))) {
        $id = (int)$a['acc_id'];
        $s = isset($saved[$id]) ? ['code' => (string)$saved[$id], 'how' => 'saved'] : saft_no_suggest($id, (string)$a['acc_type']);
        $out[$id] = ['acc_name' => (string)$a['acc_name'], 'acc_type' => (string)$a['acc_type'], 'code' => $s['code'],
                     'category' => $codes[$s['code']][0] ?? '', 'how' => $s['how']];
    }
    return $out;
}

// Momskodens standard-mva-kode (Skatteetatens liste) ud fra type og sats; $usage = 'income'/'expense' når typen mangler
function saft_no_standard_tax_code(string $type, float $rate, string $usage = ''): string {
    $r = (int)round($rate);
    switch ($type) {
        case 'sales':                   return [25 => '3', 15 => '31', 12 => '33', 11 => '32'][$r] ?? ($r === 0 ? '5' : '3');
        case 'purchase':                return [25 => '1', 15 => '11', 12 => '13', 11 => '12'][$r] ?? ($r === 0 ? '0' : '1');
        case 'exempt':                  return $usage === 'expense' ? '0' : '6';      // (momsfritaget = "unntatt" (6); kode 5 er "fritatt" = nulsats)
        case 'export':
        case 'eu_goods_sale':
        case 'eu_goods_sale_nonlisted':
        case 'eu_services_sale':        return '52';
        case 'reverse_domestic':        return '51';
        case 'eu_goods_purchase':
        case 'noneu_goods_purchase':    return $r === 15 ? '83' : '81';
        case 'eu_services_purchase':
        case 'noneu_services_purchase': return $r === 12 ? '88' : '86';
    }
    if ($usage === 'income')  return $r > 0 ? (saft_no_standard_tax_code('sales', $rate)) : '6';
    return $r > 0 ? saft_no_standard_tax_code('purchase', $rate) : '0';
}

// "Ola Nordmann" -> ['Ola', 'Nordmann'] (SAF-T kræver for- og efternavn på kontaktpersonen)
function saft_no_name_split(string $n): array {
    $p = preg_split('/\s+/', trim($n));
    $last = count($p) > 1 ? array_pop($p) : '-';
    return [implode(' ', $p) ?: '-', $last];
}

// Bygger filen for regnskabsåret $year. ['xml', 'filename', 'warnings' => [...]]; kaster Exception med en oversat
// tekst, når en obligatorisk oplysning mangler.
function saft_no_document($conn, int $year): array {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $amt = fn($v) => number_format((float)$v, 2, '.', '');
    $s = get_settings($conn);
    $co_id = preg_replace('/\D/', '', (string)($s['company_cvr'] ?? ''));
    $co_name = trim((string)($s['company_name'] ?? ''));
    $contact = trim((string)($s['company_contact_person'] ?? ''));
    $phone = trim((string)($s['company_phone'] ?? ''));
    if ($co_name === '' || strlen($co_id) !== 9) throw new Exception(lang('@SAF-T needs the company name and a 9-digit organisation number (Company Settings).'));
    if ($contact === '' || $phone === '') throw new Exception(lang('@SAF-T needs a contact person and a phone number for the company (Company Settings).'));
    [$ps, $pe] = tc_fiscal_year_of($conn, $year);
    $cur = strtoupper((string)($s['currency'] ?? 'NOK'));
    $warnings = [];

    $addr = function (string $txt, string $zip = '', string $city = '', string $cc = 'NO') use ($esc): string {
        $a = tc_split_address($txt, $zip, $city, $cc);
        if ($a['street'] === '' && $a['city'] === '') return '';
        return '<Address>' . ($a['street'] !== '' ? '<StreetName>' . $esc($a['street']) . '</StreetName>' : '')
             . ($a['city'] !== '' ? '<City>' . $esc($a['city']) . '</City>' : '') . ($a['zip'] !== '' ? '<PostalCode>' . $esc($a['zip']) . '</PostalCode>' : '')
             . '<Country>' . $esc($cc) . '</Country></Address>';
    };
    [$fn, $ln] = saft_no_name_split($contact);

    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<AuditFile xmlns="urn:StandardAuditFile-Taxation-Financial:NO">' . "\n"
       . '<Header><AuditFileVersion>1.30</AuditFileVersion><AuditFileCountry>NO</AuditFileCountry>'
       . '<AuditFileDateCreated>' . date('Y-m-d') . '</AuditFileDateCreated><SoftwareCompanyName>TinyCash</SoftwareCompanyName>'
       . '<SoftwareID>TinyCash</SoftwareID><SoftwareVersion>1.3</SoftwareVersion>' . "\n"
       . '<Company><RegistrationNumber>' . $co_id . '</RegistrationNumber><Name>' . $esc($co_name) . '</Name>'
       . $addr((string)($s['company_address'] ?? ''), (string)($s['company_zip'] ?? ''), (string)($s['company_city'] ?? ''))
       . '<Contact><ContactPerson><FirstName>' . $esc($fn) . '</FirstName><LastName>' . $esc($ln) . '</LastName></ContactPerson>'
       . '<Telephone>' . $esc($phone) . '</Telephone>' . (trim((string)($s['company_email'] ?? '')) !== '' ? '<Email>' . $esc(trim($s['company_email'])) . '</Email>' : '') . '</Contact>'
       . '<TaxRegistration><TaxRegistrationNumber>' . $co_id . 'MVA</TaxRegistrationNumber><TaxAuthority>Skatteetaten</TaxAuthority></TaxRegistration>'
       . '</Company>' . "\n"
       . '<DefaultCurrencyCode>' . $esc($cur) . '</DefaultCurrencyCode>'
       . '<SelectionCriteria><SelectionStartDate>' . $ps . '</SelectionStartDate><SelectionEndDate>' . $pe . '</SelectionEndDate></SelectionCriteria>'
       . '<TaxAccountingBasis>A</TaxAccountingBasis></Header>' . "\n";

    // --- Kontoplan med næringsspesifikasjon og primo/ultimo ---
    $map = saft_no_mapping($conn);
    $bal = [];
    $eps = DB::escape($conn, $ps);  $epe = DB::escape($conn, $pe);
    // (en resultatkonto starter regnskabsåret på 0 - tidligere års resultat står på egenkapitalen, ikke i primo)
    $res = DB::query($conn, "SELECT l.acc_id, a.acc_type, COALESCE(SUM(CASE WHEN j.jou_date < '$eps' THEN l.amount ELSE 0 END), 0) AS o,
                                    COALESCE(SUM(CASE WHEN j.jou_date <= '$epe' THEN l.amount ELSE 0 END), 0) AS c,
                                    COALESCE(SUM(CASE WHEN j.jou_date BETWEEN '$eps' AND '$epe' THEN l.amount ELSE 0 END), 0) AS p
                             FROM ledger l JOIN journal j ON j.jou_id = l.jou_id LEFT JOIN accounts a ON a.acc_id = l.acc_id GROUP BY l.acc_id, a.acc_type");
    while ($res && ($b = DB::fetch_assoc($res))) {
        $pl = in_array($b['acc_type'], ['income', 'expense'], true);
        $bal[(int)$b['acc_id']] = $pl ? [0.0, round((float)$b['p'], 2)] : [round((float)$b['o'], 2), round((float)$b['c'], 2)];
    }
    // (tidligere års resultat uden årsafslutning: på egenkapitalens primo og ultimo, så saldiene summer til 0)
    [$prior, $eq] = saft_prior_result($conn, $ps);
    if ($eq > 0) { [$o, $c] = $bal[$eq] ?? [0.0, 0.0];  $bal[$eq] = [round($o + $prior, 2), round($c + $prior, 2)]; }
    $x .= '<MasterFiles><GeneralLedgerAccounts>' . "\n";
    $unsure = 0;
    foreach ($map as $id => $m) {
        if ($m['how'] === 'near' || $m['how'] === 'type') $unsure++;
        [$o, $c] = $bal[$id] ?? [0.0, 0.0];
        $ot = $o < 0 ? 'OpeningCreditBalance' : 'OpeningDebitBalance';  $ct = $c < 0 ? 'ClosingCreditBalance' : 'ClosingDebitBalance';
        $x .= '<Account><AccountID>' . $id . '</AccountID><AccountDescription>' . $esc($m['acc_name'] !== '' ? $m['acc_name'] : '-') . '</AccountDescription>'
            . '<GroupingCategory>' . $esc($m['category']) . '</GroupingCategory><GroupingCode>' . $esc($m['code']) . '</GroupingCode><AccountType>GL</AccountType>'
            . "<$ot>" . $amt(abs($o)) . "</$ot><$ct>" . $amt(abs($c)) . "</$ct></Account>\n";
    }
    if ($unsure) $warnings[] = sprintf(lang('@%d accounts have only a suggested grouping code - check them under SAF-T mapping.'), $unsure);
    $x .= '</GeneralLedgerAccounts>' . "\n";

    // --- Kunder ---
    $opt = fn(string $t, string $col) => db_has_column($conn, $t, $col) ? ", $col" : '';
    $x .= '<Customers>';
    $res = DB::query($conn, "SELECT cust_id, cust_name, cust_cvr, cust_address, cust_email, cust_phone" . $opt('customers', 'cust_country') . $opt('customers', 'cust_vat_id') . " FROM customers ORDER BY cust_id");
    while ($res && ($c = DB::fetch_assoc($res))) {
        $cc = strtoupper(trim((string)($c['cust_country'] ?? ''))) ?: 'NO';
        $reg = preg_replace('/\D/', '', (string)$c['cust_cvr']);
        $vat = (string)(tc_vat_id_clean($c['cust_vat_id'] ?? '') ?? '');
        $x .= '<Customer>' . ($reg !== '' ? '<RegistrationNumber>' . $esc($reg) . '</RegistrationNumber>' : '') . '<Name>' . $esc(trim((string)$c['cust_name']) ?: '-') . '</Name>'
            . $addr((string)$c['cust_address'], '', '', $cc)
            . ($vat !== '' ? '<TaxRegistration><TaxRegistrationNumber>' . $esc($vat) . '</TaxRegistrationNumber></TaxRegistration>' : '')
            . '<CustomerID>' . (int)$c['cust_id'] . "</CustomerID></Customer>\n";
    }
    $x .= '</Customers>' . "\n";

    // --- Leverandører (rigtige, og udgifternes fritekst-leverandører med negative id'er som i den danske eksport) ---
    $x .= '<Suppliers>';
    $sup_ids = [];
    $has_sup = db_has_table($conn, 'suppliers');
    if ($has_sup) {
        $res = DB::query($conn, "SELECT supplier_id, supplier_name, cvr, address" . $opt('suppliers', 'country') . $opt('suppliers', 'vat_id') . " FROM suppliers ORDER BY supplier_id");
        while ($res && ($p = DB::fetch_assoc($res))) {
            $sid = (int)$p['supplier_id'];  $sup_ids['id:' . $sid] = $sid;
            $cc = strtoupper(trim((string)($p['country'] ?? ''))) ?: 'NO';
            $reg = preg_replace('/\D/', '', (string)$p['cvr']);
            $vat = (string)(tc_vat_id_clean($p['vat_id'] ?? '') ?? '');
            $x .= '<Supplier>' . ($reg !== '' ? '<RegistrationNumber>' . $esc($reg) . '</RegistrationNumber>' : '') . '<Name>' . $esc(trim((string)$p['supplier_name']) ?: '-') . '</Name>'
                . $addr((string)$p['address'], '', '', $cc)
                . ($vat !== '' ? '<TaxRegistration><TaxRegistrationNumber>' . $esc($vat) . '</TaxRegistrationNumber></TaxRegistration>' : '')
                . '<SupplierID>' . $sid . "</SupplierID></Supplier>\n";
        }
    }
    $res = DB::query($conn, "SELECT DISTINCT supplier FROM expenses WHERE supplier IS NOT NULL AND supplier != ''" . ($has_sup ? " AND supplier_id IS NULL" : '') . " ORDER BY supplier");
    $syn = -1;
    while ($res && ($p = DB::fetch_assoc($res))) {
        $sup_ids['txt:' . $p['supplier']] = $syn;
        $x .= '<Supplier><Name>' . $esc($p['supplier']) . '</Name><SupplierID>' . $syn . "</SupplierID></Supplier>\n";
        $syn--;
    }
    $x .= '</Suppliers>' . "\n";

    // --- Momstabel: de momskoder kontoplanen bruger ---
    $has_type = db_has_column($conn, 'vat_codes', 'vat_type');
    $vat = [];        // acc_id => [code, rate, std]
    $tax = [];        // code => [rate, std, name]
    $res = DB::query($conn, "SELECT a.acc_id, a.acc_type, v.vat_id, v.vat_name, v.vat_rate" . ($has_type ? ', v.vat_type' : '') . "
                             FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code");
    while ($res && ($r = DB::fetch_assoc($res))) {
        $std = saft_no_standard_tax_code((string)($r['vat_type'] ?? ''), (float)$r['vat_rate'], $r['acc_type'] === 'income' ? 'income' : 'expense');
        $code = (string)$r['vat_id'];
        $vat[(int)$r['acc_id']] = [$code, (float)$r['vat_rate'], $std];
        $tax[$code] = [(float)$r['vat_rate'], $std, (string)$r['vat_name']];
    }
    if ($tax) {
        $x .= '<TaxTable><TaxTableEntry><TaxType>MVA</TaxType><Description>Merverdiavgift</Description>';
        foreach ($tax as $code => [$rate, $std, $name]) {
            $x .= '<TaxCodeDetails><TaxCode>' . $esc($code) . '</TaxCode>' . ($name !== '' ? '<Description>' . $esc($name) . '</Description>' : '')
                . '<TaxPercentage>' . $amt($rate) . '</TaxPercentage><Country>NO</Country><StandardTaxCode>' . $std . '</StandardTaxCode><BaseRate>100</BaseRate></TaxCodeDetails>';
        }
        $x .= '</TaxTableEntry></TaxTable>' . "\n";
    }
    $x .= '</MasterFiles>' . "\n";

    // --- Posteringer ---
    // Kunde på fakturaens debitorlinje (fakturaen kender sin journalpost), leverandør via udgiftens bilagsnummer
    $deb = (int)setting('conf_acc_debitor', 8100);      // (samme standard som bogføringen)
    $jou_cust = [];
    $no_cust = [];      // (fakturaer bogført før jou_id blev gemt: posteringsteksten "Bogført salgsfaktura #<nr>")
    $res = DB::query($conn, "SELECT jou_id, cust_id, invoice_no FROM invoices WHERE invoice_no IS NOT NULL OR jou_id IS NOT NULL");
    while ($res && ($r = DB::fetch_assoc($res))) {
        if (!empty($r['jou_id'])) $jou_cust[(int)$r['jou_id']] = (int)$r['cust_id'];
        elseif ((string)$r['invoice_no'] !== '') $no_cust[(string)$r['invoice_no']] = (int)$r['cust_id'];
    }
    $vou_sup = [];
    $res = DB::query($conn, "SELECT voucher_no, supplier" . ($has_sup ? ', supplier_id' : '') . " FROM expenses WHERE voucher_no IS NOT NULL AND supplier IS NOT NULL AND supplier != ''");
    while ($res && ($r = DB::fetch_assoc($res))) {
        $sid = $sup_ids['id:' . ($r['supplier_id'] ?? '')] ?? $sup_ids['txt:' . $r['supplier']] ?? null;
        if ($sid !== null) $vou_sup[(string)$r['voucher_no']] = $sid;
    }
    $trans = [];  $deb_sum = 0.0;  $cred_sum = 0.0;
    $res = DB::query($conn, "SELECT j.jou_id, j.jou_date, j.jou_text, j.voucher_no, j.created_at, j.trans_type, l.led_id, l.acc_id, l.amount
                             FROM journal j JOIN ledger l ON l.jou_id = j.jou_id
                             WHERE j.jou_date BETWEEN '$eps' AND '$epe' ORDER BY j.jou_date, j.jou_id, l.led_id");
    while ($res && ($r = DB::fetch_assoc($res))) {
        $jid = (int)$r['jou_id'];
        $trans[$jid] ??= ['date' => substr((string)$r['jou_date'], 0, 10), 'text' => trim((string)$r['jou_text']), 'voucher' => trim((string)$r['voucher_no']),
                          'created' => (string)$r['created_at'], 'type' => (string)$r['trans_type'], 'lines' => []];
        $a = round((float)$r['amount'], 2);
        if ($a >= 0) $deb_sum += $a; else $cred_sum -= $a;
        $trans[$jid]['lines'][] = ['id' => (int)$r['led_id'], 'acc' => (int)$r['acc_id'], 'amt' => $a];
    }
    // (bilagsnummeret er transaktionens id, når det er entydigt i filen)
    $vcount = array_count_values(array_filter(array_column($trans, 'voucher'), 'strlen'));
    $x .= '<GeneralLedgerEntries><NumberOfEntries>' . count($trans) . '</NumberOfEntries><TotalDebit>' . $amt($deb_sum) . '</TotalDebit><TotalCredit>' . $amt($cred_sum) . '</TotalCredit>'
        . '<Journal><JournalID>GL</JournalID><Description>General ledger</Description><Type>GL</Type>' . "\n";
    foreach ($trans as $jid => $t) {
        $tid = ($t['voucher'] !== '' && $vcount[$t['voucher']] === 1) ? $t['voucher'] : ($t['voucher'] !== '' ? $t['voucher'] . '-' . $jid : 'J' . $jid);
        $desc = $t['text'] !== '' ? $t['text'] : '-';
        $entry = $t['created'] !== '' ? date('Y-m-d', strtotime(db_ts_local($t['created']))) : $t['date'];
        $cust = $jou_cust[$jid] ?? (preg_match('/^Bogført salgsfaktura #(\d+)$/u', $t['text'], $m) ? ($no_cust[$m[1]] ?? null) : null);
        $sup = $vou_sup[$t['voucher']] ?? null;
        $x .= '<Transaction><TransactionID>' . $esc($tid) . '</TransactionID><Period>' . (int)substr($t['date'], 5, 2) . '</Period><PeriodYear>' . (int)substr($t['date'], 0, 4) . '</PeriodYear>'
            . '<TransactionDate>' . $t['date'] . '</TransactionDate>' . ($t['type'] !== '' ? '<VoucherType>' . $esc(mb_substr($t['type'], 0, 18)) . '</VoucherType>' : '')
            . '<Description>' . $esc($desc) . '</Description><SystemEntryDate>' . $entry . '</SystemEntryDate><GLPostingDate>' . $t['date'] . '</GLPostingDate>'
            . ($cust !== null ? '<CustomerID>' . $cust . '</CustomerID>' : '') . ($sup !== null ? '<SupplierID>' . $sup . '</SupplierID>' : '');
        foreach ($t['lines'] as $l) {
            $side = $l['amt'] >= 0 ? 'Debit' : 'Credit';
            $x .= '<Line><RecordID>' . $l['id'] . '</RecordID><AccountID>' . $l['acc'] . '</AccountID>'
                . ($cust !== null && $l['acc'] === $deb ? '<CustomerID>' . $cust . '</CustomerID>' : '')
                . '<Description>' . $esc($desc) . '</Description><' . $side . 'Amount><Amount>' . $amt(abs($l['amt'])) . '</Amount></' . $side . 'Amount>';
            // (årsafslutning og kontoplanskift flytter saldi - det er ikke momspligtige transaktioner)
            if (isset($vat[$l['acc']]) && !in_array($t['type'], ['year_end_close', 'chart_switch'], true)) {
                [$code, $rate] = $vat[$l['acc']];
                $x .= '<TaxInformation><TaxType>MVA</TaxType><TaxCode>' . $esc($code) . '</TaxCode><TaxPercentage>' . $amt($rate) . '</TaxPercentage><Country>NO</Country>'
                    . '<TaxBase>' . $amt(abs($l['amt'])) . '</TaxBase><' . $side . 'TaxAmount><Amount>' . $amt(round(abs($l['amt']) * $rate / 100, 2)) . '</Amount></' . $side . 'TaxAmount></TaxInformation>';
            }
            $x .= '</Line>';
        }
        $x .= "</Transaction>\n";
    }
    $x .= '</Journal></GeneralLedgerEntries>' . "\n" . '</AuditFile>' . "\n";
    // Skatteetatens filnavn: SAF-T Financial_<org.nr>_<tidsstempel>.xml
    return ['xml' => $x, 'filename' => 'SAF-T Financial_' . $co_id . '_' . date('YmdHis') . '.xml', 'warnings' => $warnings];
}
