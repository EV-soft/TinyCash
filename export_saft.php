<?php # /export_saft.php v:1.3.0 d:2026-08-30 i:evs
# SAF-T (Standard Audit File for Tax) eksport - erstatter den tidligere døde
# knap i company_settings.php (se [[bugs-batch-12-review]]), som pegede på
# en fil der aldrig blev bygget. Bygger et OECD SAF-T Financial 2.0-lignende
# AuditFile: Header (firmastamdata), MasterFiles (kontoplan, kunder,
# leverandører udledt af udgifternes frittekst-leverandørfelt) og
# GeneralLedgerEntries (hver postering i journal+ledger for den valgte
# periode, inkl. annullerede posteringer OG deres modposteringer - i
# modsætning til en resultatopgørelse er formålet med et audit-file netop
# at vise ALT der reelt blev bogført, ikke et nettoresultat). Samme
# forbehold som annual_report.php: dette er en god-tro, funktionel eksport
# af de reelle data, men er IKKE formelt valideret mod den officielle
# SAF-T-XSD - kontrollér med din revisor/SKAT om en konkret indberetning
# stiller krav ud over dette. Admin-only (samme niveau som balance_sheet.php/
# annual_report.php - fuld regnskabsdata for hele perioden).
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// NYT (§currency-setting-is-cosmetic-label, Fase 2): SAF-T er en dansk
// SKAT-specifik eksport og giver ikke mening for en virksomhed, der bruger
// en anden bogføringsvaluta end DKK.
// Norge har sit eget SAF-T-format (Skatteetaten v1.30, inc/saft_no.inc.php); ellers den danske
$saft_no = tc_pack_feature('saft_no');
if (!$saft_no) require_country_feature($conn, 'saft_dk');

function saft_esc($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function saft_amt($v) { return number_format((float)$v, 2, '.', ''); }

$download = isset($_GET['download']) && $_GET['download'] == '1';
// Året er REGNSKABSÅRET, opkaldt efter sit slutår (1/7/2025-30/6/2026 = 2026) - SAF-T dækker et regnskabsår; før altid 1/1-31/12
$year     = isset($_GET['year']) ? (int)$_GET['year'] : (int)substr(tc_fiscal_year_bounds($conn)[1], 0, 4);

if (!$download) {
    // --- VALG AF PERIODE (år) FØR selve eksporten, samme princip som
    // vat_report.php - lader brugeren se/vælge hvilket år filen dækker,
    // i stedet for at gætte "hele historikken" eller "indeværende år" uden
    // at spørge.
    require_once 'inc/menu.inc.php';
    require_once 'inc/php2htm.lib.php';

    // De regnskabsår der har posteringer (hver dato hører til regnskabsåret der indeholder den)
    $years_res = DB::query($conn, "SELECT MIN(jou_date) AS a, MAX(jou_date) AS b FROM journal");
    $years = [];
    $yr = $years_res ? DB::fetch_assoc($years_res) : null;
    if ($yr && tc_is_date(substr((string)$yr['a'], 0, 10)) && tc_is_date(substr((string)$yr['b'], 0, 10))) {
        for ($d = substr($yr['a'], 0, 10); $d <= substr($yr['b'], 0, 10); $d = date('Y-m-d', strtotime(tc_fiscal_year_bounds($conn, $d)[1] . ' +1 day'))) $years[] = (int)substr(tc_fiscal_year_bounds($conn, $d)[1], 0, 4);
        $years = array_values(array_unique(array_reverse($years)));
    }
    if (empty($years)) $years = [(int)date('Y')];
    if (!in_array($year, $years, true)) $year = $years[0];

    htm_Header(capt: '@Export SAF-T', mwidth: 700);
    showMenu();
    htm_Card_(capt: '@Export SAF-T', wdth: 700);

    if ($saft_no) {
        require_once 'inc/saft_no.inc.php';
        htm_Note(lang('@Exports the chart of accounts, customers, suppliers, VAT codes and every posted general ledger entry for the selected year in the Norwegian SAF-T Financial format (version 1.30, Skatteetaten). Every account must have a grouping code from the business specification (næringsspesifikasjon).'), 'font-size:0.9em;');
        $unsure = count(array_filter(saft_no_mapping($conn), fn($m) => in_array($m['how'], ['near', 'type'], true)));
        if ($unsure) echo '<p style="color:var(--color-warning); font-size:0.85em;"><i class="fa-solid fa-triangle-exclamation"></i> '
            . sprintf(lang('@%d accounts have only a suggested grouping code - check them under SAF-T mapping.'), $unsure) . '</p>';
        if (trim((string)setting('company_contact_person', '')) === '' || trim((string)setting('company_phone', '')) === '')
            echo '<p style="color:var(--color-warning); font-size:0.85em;"><i class="fa-solid fa-triangle-exclamation"></i> ' . lang('@SAF-T needs a contact person and a phone number for the company (Company Settings).') . '</p>';
        htm_Button(icon: 'fa-sitemap', labl: '@SAF-T mapping', type: 'secondary', link: 'saft_no_mapping.php');
    } else {
    htm_Note(lang('@Exports the full chart of accounts, customers, suppliers and every posted general ledger entry for the selected year as an OECD SAF-T Financial (v2.0) style audit file.'), 'font-size:0.9em;');
    echo '<p style="color:var(--color-warning); font-size:0.85em;"><i class="fa-solid fa-triangle-exclamation"></i> ' .
        lang('@This is a good-faith export of your real data, not formally validated against the official SAF-T XSD. Check with your accountant/SKAT whether a specific submission requires more than this.') .
        '</p>';
    }

    echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; margin-top:15px;">';
    $opti = [];
    foreach ($years as $y) { [$fs, $fe] = tc_fiscal_year_of($conn, $y);  $opti[$y] = $y . ($fs !== "$y-01-01" ? ' (' . date(CONF_DATE_FORMAT, strtotime($fs)) . ' - ' . date(CONF_DATE_FORMAT, strtotime($fe)) . ')' : ''); }
    htm_Field(icon: 'fa-calendar', labl: '@Year', name: 'year', valu: $year, type: 'sele', opti: $opti, wdth: '160px');
    echo '<input type="hidden" name="download" value="1">';
    htm_Button(icon: 'fa-download', labl: '@Export SAF-T', type: 'success', attr: 'type="submit" data-hint="'.lang('@Download the SAF-T audit file for the selected year').'"');
    echo '</form>';

    htm_Card_end();
    htm_Footer();
    ob_end_flush();
    exit;
}

// --- SELVE EKSPORTEN ---
if ($saft_no) {
    require_once 'inc/php2htm.lib.php';
    require_once 'inc/saft_no.inc.php';
    try { $doc = saft_no_document($conn, $year); }
    catch (Exception $e) { redirect('export_saft.php?year=' . $year, htmlspecialchars($e->getMessage()), 'error'); }
    $archive_dir = rtrim(tc_account_storage_dir(__DIR__ . '/storage/saf-t'), '/');
    if (is_dir($archive_dir) && is_writable($archive_dir)) @file_put_contents($archive_dir . '/' . $doc['filename'], $doc['xml']);
    ob_end_clean();
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $doc['filename'] . '"');
    header('Content-Length: ' . strlen($doc['xml']));
    echo $doc['xml'];
    exit;
}
[$period_start, $period_end] = tc_fiscal_year_of($conn, $year);
$settings     = get_settings($conn);

$co_name = $settings['company_name'] ?? '';
$co_cvr  = preg_replace('/\D/', '', $settings['company_cvr'] ?? '');
$co_addr = $settings['company_address'] ?? '';
$co_city = $settings['company_city']   ?? '';
$cur     = $settings['currency']       ?? 'DKK';

$x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$x .= '<AuditFile xmlns="urn:StandardAuditFile-Taxation-Financial:DK">' . "\n";

// --- HEADER ---
$x .= '  <Header>' . "\n";
$x .= '    <AuditFileVersion>2.0</AuditFileVersion>' . "\n";
$x .= '    <AuditFileCountry>DK</AuditFileCountry>' . "\n";
$x .= '    <AuditFileDateCreated>' . date('Y-m-d') . '</AuditFileDateCreated>' . "\n";
$x .= '    <SoftwareCompanyName>TinyCash</SoftwareCompanyName>' . "\n";
$x .= '    <SoftwareID>TinyCash</SoftwareID>' . "\n";
$x .= '    <SoftwareVersion>1.3.0</SoftwareVersion>' . "\n";
$x .= '    <Company>' . "\n";
$x .= '      <RegistrationNumber>' . saft_esc($co_cvr) . '</RegistrationNumber>' . "\n";
$x .= '      <Name>' . saft_esc($co_name) . '</Name>' . "\n";
$x .= '      <Address>' . "\n";
$x .= '        <StreetName>' . saft_esc($co_addr) . '</StreetName>' . "\n";
$x .= '        <City>' . saft_esc($co_city) . '</City>' . "\n";
$x .= '        <Country>DK</Country>' . "\n";
$x .= '      </Address>' . "\n";
$x .= '      <TaxRegistration><TaxRegistrationNumber>' . saft_esc($co_cvr) . '</TaxRegistrationNumber></TaxRegistration>' . "\n";
$x .= '    </Company>' . "\n";
$x .= '    <DefaultCurrencyCode>' . saft_esc($cur) . '</DefaultCurrencyCode>' . "\n";
$x .= '    <SelectionCriteria>' . "\n";
$x .= '      <PeriodStart>' . $period_start . '</PeriodStart>' . "\n";
$x .= '      <PeriodEnd>' . $period_end . '</PeriodEnd>' . "\n";
$x .= '    </SelectionCriteria>' . "\n";
$x .= '  </Header>' . "\n";

// --- MASTERFILES: KONTOPLAN ---
$x .= '  <MasterFiles>' . "\n";
$x .= '    <GeneralLedgerAccounts>' . "\n";
// RETTET: kontoplanen manglede primo- og ultimosaldo pr. konto (OpeningDebit/CreditBalance, ClosingDebit/
// CreditBalance) - de er obligatoriske i SAF-T Financial, og uden dem kan modtageren ikke afstemme filens
// posteringer mod saldiene. Primo = alt før perioden, ultimo = alt til og med periodens slutning.
$saft_ps = DB::escape($conn, $period_start);  $saft_pe = DB::escape($conn, $period_end);
$saft_bal = [];
$bres = DB::query($conn, "SELECT l.acc_id,
        COALESCE(SUM(CASE WHEN j.jou_date < '$saft_ps' THEN l.amount ELSE 0 END), 0) AS primo,
        COALESCE(SUM(CASE WHEN j.jou_date <= '$saft_pe' THEN l.amount ELSE 0 END), 0) AS ultimo
    FROM ledger l JOIN journal j ON j.jou_id = l.jou_id GROUP BY l.acc_id");
while ($bres && ($b = DB::fetch_assoc($bres))) $saft_bal[(int)$b['acc_id']] = [round((float)$b['primo'], 2), round((float)$b['ultimo'], 2)];
// (en resultatkonto starter regnskabsåret på 0 - tidligere års resultat står på egenkapitalen, ikke i primo)
$saft_per = [];
$pres = DB::query($conn, "SELECT l.acc_id, COALESCE(SUM(l.amount), 0) AS p FROM ledger l JOIN journal j ON j.jou_id = l.jou_id
    WHERE j.jou_date BETWEEN '$saft_ps' AND '$saft_pe' GROUP BY l.acc_id");
while ($pres && ($b = DB::fetch_assoc($pres))) $saft_per[(int)$b['acc_id']] = round((float)$b['p'], 2);
// Tidligere års resultat, der ikke er lukket ind på egenkapitalen (ingen årsafslutning): det hører til egenkapitalens primo
// og ultimo - ellers summede filens saldi ikke til 0, når resultatkontiene starter på 0
[$saft_prior, $saft_eq] = saft_prior_result($conn, $period_start);
$res = DB::query($conn, "SELECT acc_id, acc_name, acc_type, std_ref_id FROM accounts ORDER BY acc_id ASC");
while ($res && ($a = DB::fetch_assoc($res))) {
    [$o_bal, $c_bal] = $saft_bal[(int)$a['acc_id']] ?? [0.0, 0.0];
    if (in_array($a['acc_type'], ['income', 'expense'], true)) { $o_bal = 0.0;  $c_bal = $saft_per[(int)$a['acc_id']] ?? 0.0; }
    if ((int)$a['acc_id'] === $saft_eq) { $o_bal = round($o_bal + $saft_prior, 2);  $c_bal = round($c_bal + $saft_prior, 2); }
    $x .= '      <Account>' . "\n";
    $x .= '        <AccountID>' . saft_esc($a['acc_id']) . '</AccountID>' . "\n";
    $x .= '        <AccountDescription>' . saft_esc($a['acc_name']) . '</AccountDescription>' . "\n";
    if (!empty($a['std_ref_id'])) $x .= '        <StandardAccountID>' . saft_esc($a['std_ref_id']) . '</StandardAccountID>' . "\n";
    $x .= '        <AccountType>' . saft_esc($a['acc_type']) . '</AccountType>' . "\n";
    $x .= '        <' . ($o_bal < 0 ? 'OpeningCreditBalance' : 'OpeningDebitBalance') . '>' . saft_amt(abs($o_bal)) . '</' . ($o_bal < 0 ? 'OpeningCreditBalance' : 'OpeningDebitBalance') . '>' . "\n";
    $x .= '        <' . ($c_bal < 0 ? 'ClosingCreditBalance' : 'ClosingDebitBalance') . '>' . saft_amt(abs($c_bal)) . '</' . ($c_bal < 0 ? 'ClosingCreditBalance' : 'ClosingDebitBalance') . '>' . "\n";
    $x .= '      </Account>' . "\n";
}
$x .= '    </GeneralLedgerAccounts>' . "\n";

// --- MASTERFILES: KUNDER ---
$x .= '    <Customers>' . "\n";
// (kundens/leverandørens eget land - tomt = Danmark)
$saft_cc = fn($v) => preg_match('/^[A-Z]{2}$/', strtoupper(trim((string)$v))) ? strtoupper(trim((string)$v)) : 'DK';
$res = DB::query($conn, "SELECT cust_id, cust_name, cust_cvr, cust_address, cust_email" . (db_has_column($conn, 'customers', 'cust_country') ? ', cust_country' : '') . " FROM customers ORDER BY cust_id ASC");
while ($res && ($c = DB::fetch_assoc($res))) {
    $x .= '      <Customer>' . "\n";
    $x .= '        <CustomerID>' . saft_esc($c['cust_id']) . '</CustomerID>' . "\n";
    $x .= '        <CompanyName>' . saft_esc($c['cust_name']) . '</CompanyName>' . "\n";
    if (!empty($c['cust_cvr'])) $x .= '        <CustomerTaxID>' . saft_esc(preg_replace('/\D/', '', $c['cust_cvr'])) . '</CustomerTaxID>' . "\n";
    $x .= '        <Address><StreetName>' . saft_esc($c['cust_address']) . '</StreetName><Country>' . $saft_cc($c['cust_country'] ?? '') . '</Country></Address>' . "\n";
    if (!empty($c['cust_email'])) $x .= '        <Email>' . saft_esc($c['cust_email']) . '</Email>' . "\n";
    $x .= '      </Customer>' . "\n";
}
$x .= '    </Customers>' . "\n";

// --- MASTERFILES: LEVERANDØRER ---
// RETTET: denne kommentar (og hele nedenstående syntetiske SupplierID-udledning) beskrev en virkelighed der ikke
// længere er sand efter leverandørmodulet (se db-setup/migrate_suppliers.php) - expenses HAR nu et rigtigt
// leverandør-stamdata-katalog (supplier_id -> suppliers), med et ægte CVR-nummer og en stabil, permanent
// SupplierID på tværs af eksporter (i modsætning til før, hvor ID'et blot var rækkefølgen af unikke navne på selve
// eksporttidspunktet - kunne ændre sig fra én eksport til den næste, hvis nye leverandørnavne dukkede op imellem
// dem). De ægte leverandører eksporteres først (med CVR/adresse/email, samme mønster som Customers ovenfor);
// udgifter der stadig kun har det gamle frie tekstfelt (ingen supplier_id - enten et engangskøb uden en oprettet
// leverandørpost, eller en installation uden leverandørmodulet kørt endnu) falder tilbage til den oprindelige
// syntetiske liste, med NEGATIVE ID'er så de aldrig kan kollidere med et ægte supplier_id.
$x .= '    <Suppliers>' . "\n";
$supplier_ids = []; // navn/id => SAF-T SupplierID, bruges til krydsreferencen nedenfor
$has_supplier_table = db_has_table($conn, 'suppliers');

if ($has_supplier_table) {
    $res = DB::query($conn, "SELECT supplier_id, supplier_name, cvr, address, email" . (db_has_column($conn, 'suppliers', 'country') ? ', country' : '') . " FROM suppliers ORDER BY supplier_id ASC");
    while ($res && ($sp = DB::fetch_assoc($res))) {
        $real_sid = (int)$sp['supplier_id'];
        $supplier_ids['id:' . $real_sid] = $real_sid;
        $x .= '      <Supplier>' . "\n";
        $x .= '        <SupplierID>' . $real_sid . '</SupplierID>' . "\n";
        $x .= '        <CompanyName>' . saft_esc($sp['supplier_name']) . '</CompanyName>' . "\n";
        if (!empty($sp['cvr'])) $x .= '        <SupplierTaxID>' . saft_esc(preg_replace('/\D/', '', $sp['cvr'])) . '</SupplierTaxID>' . "\n";
        if (!empty($sp['address'])) $x .= '        <Address><StreetName>' . saft_esc($sp['address']) . '</StreetName><Country>' . $saft_cc($sp['country'] ?? '') . '</Country></Address>' . "\n";
        if (!empty($sp['email'])) $x .= '        <Email>' . saft_esc($sp['email']) . '</Email>' . "\n";
        $x .= '      </Supplier>' . "\n";
    }
}

$unlinked_sql = "SELECT DISTINCT supplier FROM expenses WHERE supplier IS NOT NULL AND supplier != ''"
    . ($has_supplier_table ? " AND supplier_id IS NULL" : "") . " ORDER BY supplier ASC";
$res = DB::query($conn, $unlinked_sql);
$next_synthetic_id = -1;
while ($res && ($s = DB::fetch_assoc($res))) {
    $supplier_ids['txt:' . $s['supplier']] = $next_synthetic_id;
    $x .= '      <Supplier>' . "\n";
    $x .= '        <SupplierID>' . $next_synthetic_id . '</SupplierID>' . "\n";
    $x .= '        <CompanyName>' . saft_esc($s['supplier']) . '</CompanyName>' . "\n";
    $x .= '      </Supplier>' . "\n";
    $next_synthetic_id--;
}
$x .= '    </Suppliers>' . "\n";
$x .= '  </MasterFiles>' . "\n";

// --- GENERALLEDGERENTRIES ---
// Bevidst INGEN "AND j.trans_type != 'year_end_close'"-udelukkelse her (i
// modsætning til arl_income_statement() i inc/annual_report.lib.php) - en
// årsafslutnings egen lukkepostering ER en reel bogført postering, og et
// audit-file skal netop vise ALT hvad der blev bogført i perioden, ikke et
// nettoresultat. Samme grund til at annullerede posteringer OG deres
// modposteringer begge tages med.
$ps = DB::escape($conn, $period_start);
$pe = DB::escape($conn, $period_end);
$sql = "SELECT j.jou_id, j.jou_date, j.jou_text, j.voucher_no, j.created_at, j.trans_type, j.is_cancelled,
               l.led_id, l.acc_id, l.amount
        FROM journal j JOIN ledger l ON l.jou_id = j.jou_id
        WHERE j.jou_date BETWEEN '$ps' AND '$pe'
        ORDER BY j.jou_date ASC, j.jou_id ASC, l.led_id ASC";
$res = DB::query($conn, $sql);

// RETTET: $supplier_ids (bygget ovenfor til MasterFiles/Suppliers) blev aldrig faktisk brugt til at knytte en
// GeneralLedgerEntries-transaktion til sin leverandør - de to sektioner stod helt urelaterede. En udgifts
// bilagsnummer (voucher_no) er den fælles nøgle til dens journalpost, så et opslag herfra til leverandøren gør
// krydsreferencen reel, hvor den kan afgøres.
$voucher_to_supplier = [];
$exp_cols = "voucher_no, supplier" . ($has_supplier_table ? ", supplier_id" : "");
$exp_res = DB::query($conn, "SELECT $exp_cols FROM expenses WHERE voucher_no IS NOT NULL AND supplier IS NOT NULL AND supplier != ''");
while ($exp_res && ($er = DB::fetch_assoc($exp_res))) {
    if ($has_supplier_table && !empty($er['supplier_id']) && isset($supplier_ids['id:' . $er['supplier_id']])) {
        $voucher_to_supplier[$er['voucher_no']] = $supplier_ids['id:' . $er['supplier_id']];
    } elseif (isset($supplier_ids['txt:' . $er['supplier']])) {
        $voucher_to_supplier[$er['voucher_no']] = $supplier_ids['txt:' . $er['supplier']];
    }
}

$transactions = [];
$total_debit  = 0.0;
$total_credit = 0.0;
$n_entries    = 0;
while ($res && ($r = DB::fetch_assoc($res))) {
    if (!isset($transactions[$r['jou_id']])) {
        $transactions[$r['jou_id']] = [
            'date' => $r['jou_date'], 'text' => $r['jou_text'], 'voucher_no' => $r['voucher_no'],
            'created_at' => $r['created_at'], 'lines' => [],
        ];
        $n_entries++;
    }
    $amt = (float)$r['amount'];
    if ($amt >= 0) $total_debit += $amt; else $total_credit += abs($amt);
    $transactions[$r['jou_id']]['lines'][] = ['led_id' => $r['led_id'], 'acc_id' => $r['acc_id'], 'amount' => $amt];
}

$x .= '  <GeneralLedgerEntries>' . "\n";
$x .= '    <NumberOfEntries>' . $n_entries . '</NumberOfEntries>' . "\n";
$x .= '    <TotalDebit>' . saft_amt($total_debit) . '</TotalDebit>' . "\n";
$x .= '    <TotalCredit>' . saft_amt($total_credit) . '</TotalCredit>' . "\n";
$x .= '    <Journal>' . "\n";
$x .= '      <JournalID>GL</JournalID>' . "\n";
$x .= '      <Description>General Ledger</Description>' . "\n";
foreach ($transactions as $jou_id => $t) {
    $x .= '      <Transaction>' . "\n";
    $x .= '        <TransactionID>' . (int)$jou_id . '</TransactionID>' . "\n";
    $x .= '        <Period>' . (int)date('n', strtotime($t['date'])) . '</Period>' . "\n";
    $x .= '        <TransactionDate>' . date('Y-m-d', strtotime($t['date'])) . '</TransactionDate>' . "\n";
    if (!empty($t['voucher_no'])) $x .= '        <SourceID>' . saft_esc($t['voucher_no']) . '</SourceID>' . "\n";
    if (!empty($t['voucher_no']) && isset($voucher_to_supplier[$t['voucher_no']])) {
        $x .= '        <SupplierID>' . (int)$voucher_to_supplier[$t['voucher_no']] . '</SupplierID>' . "\n";
    }
    $x .= '        <Description>' . saft_esc($t['text']) . '</Description>' . "\n";
    $x .= '        <SystemEntryDate>' . saft_esc(date('Y-m-d\TH:i:s', strtotime($t['created_at'] ? db_ts_local($t['created_at']) : $t['date'])))     /* (journal.created_at er databasens egen tid - UTC på SQLite) */ . '</SystemEntryDate>' . "\n";
    foreach ($t['lines'] as $l) {
        $x .= '        <Line>' . "\n";
        $x .= '          <RecordID>' . (int)$l['led_id'] . '</RecordID>' . "\n";
        $x .= '          <AccountID>' . saft_esc($l['acc_id']) . '</AccountID>' . "\n";
        if ($l['amount'] >= 0) {
            $x .= '          <DebitAmount>' . saft_amt($l['amount']) . '</DebitAmount>' . "\n";
        } else {
            $x .= '          <CreditAmount>' . saft_amt(abs($l['amount'])) . '</CreditAmount>' . "\n";
        }
        $x .= '        </Line>' . "\n";
    }
    $x .= '      </Transaction>' . "\n";
}
$x .= '    </Journal>' . "\n";
$x .= '  </GeneralLedgerEntries>' . "\n";
$x .= '</AuditFile>' . "\n";

// --- ARKIVÉR KOPI (best-effort, samme mønster som export_oioubl.php) ---
$filename     = 'SAFT_' . $year . '_' . date('Ymd_His') . '.xml';
$archive_dir  = rtrim(tc_account_storage_dir(__DIR__ . '/storage/saf-t'), '/');      // (eget regnskab)
if (is_dir($archive_dir) && is_writable($archive_dir)) {
    @file_put_contents($archive_dir . '/' . $filename, $x);
}

// --- SEND SOM DOWNLOAD ---
ob_end_clean();
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($x));
echo $x;
exit;
